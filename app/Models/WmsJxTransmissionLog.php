<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * JX送受信履歴
 */
class WmsJxTransmissionLog extends WmsModel
{
    protected $table = 'wms_jx_transmission_logs';

    public const DIRECTION_SEND = 'send';

    public const DIRECTION_RECEIVE = 'receive';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILURE = 'failure';

    public const OPERATION_PUT = 'PutDocument';

    public const OPERATION_GET = 'GetDocument';

    public const OPERATION_CONFIRM = 'ConfirmDocument';

    public const ENV_PRODUCTION = 'production';

    public const ENV_TEST = 'test';

    protected $fillable = [
        'jx_setting_id',
        'direction',
        'environment',
        'operation_type',
        'message_id',
        'document_type',
        'format_type',
        'sender_id',
        'receiver_id',
        'status',
        'error_message',
        'data_size',
        'file_path',
        'http_code',
        'transmitted_at',
    ];

    protected $casts = [
        'transmitted_at' => 'datetime',
        'data_size' => 'integer',
        'http_code' => 'integer',
    ];

    /**
     * JX接続設定
     */
    public function jxSetting(): BelongsTo
    {
        return $this->belongsTo(WmsOrderJxSetting::class, 'jx_setting_id');
    }

    public function eosImportBatches(): HasMany
    {
        return $this->hasMany(WmsJxEosImportBatch::class, 'wms_jx_transmission_log_id');
    }

    public function currentEosImport(): HasOne
    {
        return $this->hasOne(WmsJxEosImportBatch::class, 'wms_jx_transmission_log_id')
            ->where('is_current', true)
            ->latestOfMany();
    }

    public function incomingReceivedFile(): HasOne
    {
        return $this->hasOne(WmsIncomingReceivedFile::class, 'received_message_id', 'message_id')
            ->where('format_type', 'JX')
            ->latestOfMany();
    }

    /**
     * 送信ログのスコープ
     */
    public function scopeSend(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_SEND);
    }

    /**
     * 受信ログのスコープ
     */
    public function scopeReceive(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_RECEIVE);
    }

    public function scopeEosImportTarget(Builder $query): Builder
    {
        return $query
            ->where('direction', self::DIRECTION_RECEIVE)
            ->where('operation_type', self::OPERATION_GET)
            ->where('status', self::STATUS_SUCCESS)
            ->whereNotNull('file_path')
            ->where('file_path', '<>', '');
    }

    public function scopePendingEosIncomingImport(Builder $query): Builder
    {
        return $query
            ->eosImportTarget()
            ->whereDoesntHave(
                'incomingReceivedFile',
                fn (Builder $query): Builder => $query->whereIn('status', WmsIncomingReceivedFile::TERMINAL_STATUSES)
            );
    }

    /**
     * 成功ログのスコープ
     */
    public function scopeSucceeded(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    /**
     * 失敗ログのスコープ
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILURE);
    }

    /**
     * 本番環境ログのスコープ
     */
    public function scopeProduction(Builder $query): Builder
    {
        return $query->where('environment', self::ENV_PRODUCTION);
    }

    /**
     * テスト環境ログのスコープ
     */
    public function scopeTest(Builder $query): Builder
    {
        return $query->where('environment', self::ENV_TEST);
    }

    /**
     * 送信ログを記録
     */
    public static function logSend(
        ?int $jxSettingId,
        string $operationType,
        string $messageId,
        bool $success,
        ?string $documentType = null,
        ?string $formatType = null,
        ?int $dataSize = null,
        ?string $filePath = null,
        ?int $httpCode = null,
        ?string $errorMessage = null,
        string $environment = self::ENV_PRODUCTION,
    ): self {
        return self::create([
            'jx_setting_id' => $jxSettingId,
            'direction' => self::DIRECTION_SEND,
            'environment' => $environment,
            'operation_type' => $operationType,
            'message_id' => $messageId,
            'document_type' => $documentType,
            'format_type' => $formatType,
            'status' => $success ? self::STATUS_SUCCESS : self::STATUS_FAILURE,
            'error_message' => $errorMessage,
            'data_size' => $dataSize,
            'file_path' => $filePath,
            'http_code' => $httpCode,
            'transmitted_at' => now(),
        ]);
    }

    /**
     * 受信ログを記録
     */
    public static function logReceive(
        ?int $jxSettingId,
        string $operationType,
        string $messageId,
        bool $success,
        ?string $documentType = null,
        ?string $formatType = null,
        ?string $senderId = null,
        ?string $receiverId = null,
        ?int $dataSize = null,
        ?string $filePath = null,
        ?int $httpCode = null,
        ?string $errorMessage = null,
        string $environment = self::ENV_PRODUCTION,
    ): self {
        return self::create([
            'jx_setting_id' => $jxSettingId,
            'direction' => self::DIRECTION_RECEIVE,
            'environment' => $environment,
            'operation_type' => $operationType,
            'message_id' => $messageId,
            'document_type' => $documentType,
            'format_type' => $formatType,
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
            'status' => $success ? self::STATUS_SUCCESS : self::STATUS_FAILURE,
            'error_message' => $errorMessage,
            'data_size' => $dataSize,
            'file_path' => $filePath,
            'http_code' => $httpCode,
            'transmitted_at' => now(),
        ]);
    }

    /**
     * 方向のラベル
     */
    public function getDirectionLabelAttribute(): string
    {
        return match ($this->direction) {
            self::DIRECTION_SEND => '送信',
            self::DIRECTION_RECEIVE => '受信',
            default => $this->direction,
        };
    }

    /**
     * ステータスのラベル
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_SUCCESS => '成功',
            self::STATUS_FAILURE => '失敗',
            default => $this->status,
        };
    }

    /**
     * 環境区分のラベル
     */
    public function getEnvironmentLabelAttribute(): string
    {
        return match ($this->environment) {
            self::ENV_PRODUCTION => '本番',
            self::ENV_TEST => 'テスト',
            default => $this->environment,
        };
    }
}
