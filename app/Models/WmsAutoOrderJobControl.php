<?php

namespace App\Models;

use App\Enums\AutoOrder\JobProcessName;
use App\Enums\AutoOrder\JobStatus;
use App\Enums\AutoOrder\SettlementStatus;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 自動発注ジョブ管理モデル
 *
 * @property int $id
 * @property string $process_name
 * @property string $batch_code
 * @property string $status
 * @property \Carbon\Carbon $started_at
 * @property \Carbon\Carbon|null $finished_at
 * @property array|null $target_scope
 * @property int|null $total_records
 * @property int|null $processed_records
 * @property string|null $error_details
 * @property array|null $result_data
 */
class WmsAutoOrderJobControl extends WmsModel
{
    protected $table = 'wms_auto_order_job_controls';

    protected $fillable = [
        'process_name',
        'batch_code',
        'status',
        'settlement_status',
        'created_by',
        'warehouse_id',
        'target_date',
        'snapshot_job_id',
        'started_at',
        'finished_at',
        'target_scope',
        'total_records',
        'processed_records',
        'error_details',
        'result_data',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'target_scope' => 'array',
        'result_data' => 'array',
        'status' => JobStatus::class,
        'process_name' => JobProcessName::class,
        'settlement_status' => SettlementStatus::class,
    ];

    /**
     * 新しいバッチコードを生成
     * フォーマット: YmdHis + 倉庫ID3桁ゼロ埋め（全体実行時は000）
     */
    public static function generateBatchCode(?int $warehouseId = null): string
    {
        $base = now()->format('YmdHis');
        $suffix = $warehouseId
            ? str_pad((string) $warehouseId, 3, '0', STR_PAD_LEFT)
            : '000';

        return $base.$suffix;
    }

    /**
     * ジョブを開始
     *
     * @param  JobProcessName  $processName  プロセス名
     * @param  array|null  $scope  対象スコープ
     * @param  string|null  $batchCode  バッチコード（指定がなければ自動生成）
     * @param  SettlementStatus  $settlementStatus  確定状態（デフォルト: PENDING）
     */
    public static function startJob(
        JobProcessName $processName,
        ?array $scope = null,
        ?string $batchCode = null,
        SettlementStatus $settlementStatus = SettlementStatus::PENDING,
        ?int $createdBy = null,
        ?int $warehouseId = null
    ): self {
        return self::create([
            'process_name' => $processName,
            'batch_code' => $batchCode ?? self::generateBatchCode($warehouseId),
            'status' => JobStatus::RUNNING,
            'settlement_status' => $settlementStatus,
            'created_by' => $createdBy,
            'warehouse_id' => $warehouseId,
            'started_at' => now(),
            'target_date' => ClientSetting::freshSystemDateYMD('auto_order_job_control:start'),
            'target_scope' => $scope,
        ]);
    }

    /**
     * ジョブを成功で完了
     */
    public function markAsSuccess(int $processedRecords = 0, ?array $resultData = null): void
    {
        $data = [
            'status' => JobStatus::SUCCESS,
            'finished_at' => now(),
            'processed_records' => $processedRecords,
        ];

        if ($resultData !== null) {
            $data['result_data'] = $resultData;
        }

        $this->update($data);
    }

    /**
     * ジョブを失敗で完了
     */
    public function markAsFailed(string $errorDetails): void
    {
        $this->update([
            'status' => JobStatus::FAILED,
            'finished_at' => now(),
            'error_details' => $errorDetails,
        ]);
    }

    /**
     * 進捗を更新
     */
    public function updateProgress(int $processed, int $total): void
    {
        $this->update([
            'processed_records' => $processed,
            'total_records' => $total,
        ]);
    }

    /**
     * 実行中のジョブがあるかチェック
     */
    public static function hasRunningJob(JobProcessName $processName): bool
    {
        return self::where('process_name', $processName)
            ->where('status', JobStatus::RUNNING)
            ->exists();
    }

    /**
     * 今日のバッチを取得
     */
    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('started_at', today());
    }

    /**
     * 進捗率を取得
     */
    public function getProgressPercentageAttribute(): ?int
    {
        if (! $this->total_records || $this->total_records === 0) {
            return null;
        }

        return (int) round(($this->processed_records / $this->total_records) * 100);
    }

    /**
     * 参照した在庫スナップショットジョブ
     */
    public function snapshotJob(): BelongsTo
    {
        return $this->belongsTo(self::class, 'snapshot_job_id');
    }

    /**
     * 実行者
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 対象倉庫
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * 確定待ち（PENDING）の最新ジョブを取得
     */
    public static function findPendingSettlement(?int $createdBy = null, ?array $processNames = null): ?self
    {
        $query = self::where('settlement_status', SettlementStatus::PENDING)
            ->whereIn('process_name', $processNames ?? [JobProcessName::ORDER_CALC]);

        if ($createdBy !== null) {
            $query->where('created_by', $createdBy);
        }

        return $query->orderBy('id', 'desc')->first();
    }

    /**
     * 指定倉庫の当日の確定待ち（PENDING）ジョブを取得（batch_code再利用用）
     */
    public static function findPendingSettlementForWarehouse(int $warehouseId, ?int $createdBy = null, ?array $processNames = null): ?self
    {
        $query = self::where('settlement_status', SettlementStatus::PENDING)
            ->whereIn('process_name', $processNames ?? [JobProcessName::ORDER_CALC])
            ->where('warehouse_id', $warehouseId)
            ->whereDate('started_at', today());

        if ($createdBy !== null) {
            $query->where('created_by', $createdBy);
        }

        return $query->orderBy('id', 'desc')->first();
    }

    /**
     * 確定待ち（PENDING）のジョブが存在するかチェック
     */
    public static function hasPendingSettlement(): bool
    {
        return self::where('settlement_status', SettlementStatus::PENDING)
            ->where('process_name', JobProcessName::ORDER_CALC)
            ->exists();
    }

    /**
     * 確定待ち（PENDING）のジョブをキャンセル
     */
    public static function cancelPendingSettlements(?int $warehouseId = null, ?int $createdBy = null): int
    {
        $query = self::where('settlement_status', SettlementStatus::PENDING)
            ->whereIn('process_name', [JobProcessName::ORDER_CALC, JobProcessName::SALES_BASED_CALC]);

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        if ($createdBy !== null) {
            $query->where('created_by', $createdBy);
        }

        return $query->update(['settlement_status' => SettlementStatus::CANCELLED]);
    }

    /**
     * 確定状態を更新
     */
    public function markAsSettled(): void
    {
        $this->update(['settlement_status' => SettlementStatus::CONFIRMED]);
    }
}
