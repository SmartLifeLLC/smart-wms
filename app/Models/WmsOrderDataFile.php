<?php

namespace App\Models;

use App\Enums\AutoOrder\OrderDataFileChannel;
use App\Enums\AutoOrder\OrderDataFileStatus;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 発注データファイル（共通CSVダウンロード用）
 *
 * 倉庫別×発注先別に生成されるCSVファイルを管理
 */
class WmsOrderDataFile extends WmsModel
{
    protected $table = 'wms_order_data_files';

    protected $fillable = [
        'batch_code',
        'created_by',
        'created_by_name',
        'warehouse_id',
        'contractor_id',
        'candidate_ids',
        'order_channel',
        'show_eos_stamp',
        'order_date',
        'expected_arrival_date',
        'file_path',
        'file_size',
        'fax_file_path',
        'fax_downloaded_at',
        'fax_downloaded_by',
        'mail_to',
        'mail_sent_at',
        'mail_sent_by',
        'order_count',
        'total_quantity',
        'is_mail_order',
        'status',
        'is_test',
        'csv_downloaded_at',
        'csv_downloaded_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_arrival_date' => 'date',
        'csv_downloaded_at' => 'datetime',
        'fax_downloaded_at' => 'datetime',
        'mail_sent_at' => 'datetime',
        'is_mail_order' => 'boolean',
        'show_eos_stamp' => 'boolean',
        'status' => OrderDataFileStatus::class,
        'order_channel' => OrderDataFileChannel::class,
        'candidate_ids' => 'array',
    ];

    // Relationships

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function csvDownloadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'csv_downloaded_by');
    }

    public function faxDownloadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fax_downloaded_by');
    }

    public function mailSentByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mail_sent_by');
    }

    // Scopes

    public function scopeForBatch(Builder $query, string $batchCode): Builder
    {
        return $query->where('batch_code', $batchCode);
    }

    public function scopeForWarehouse(Builder $query, int $warehouseId): Builder
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopeForCreatedBy(Builder $query, ?int $userId): Builder
    {
        if ($userId === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($userId) {
            $q->where('created_by', $userId)
                ->orWhereIn('batch_code', WmsAutoOrderJobControl::query()
                    ->where('created_by', $userId)
                    ->select('batch_code'))
                ->orWhereNotIn('batch_code', WmsAutoOrderJobControl::query()
                    ->select('batch_code'));
        });
    }

    // Methods

    /**
     * CSVダウンロード済みとしてマーク
     */
    public function markAsCsvDownloaded(int $userId): void
    {
        $this->update([
            'status' => OrderDataFileStatus::DOWNLOADED,
            'csv_downloaded_at' => now(),
            'csv_downloaded_by' => $userId,
        ]);
    }

    /**
     * FAXダウンロード済みとしてマーク
     */
    public function markAsFaxDownloaded(int $userId): void
    {
        if ($this->isEosControlPdf()) {
            return;
        }

        $this->update([
            'status' => OrderDataFileStatus::DOWNLOADED,
            'fax_downloaded_at' => now(),
            'fax_downloaded_by' => $userId,
        ]);
    }

    public function isEosControlPdf(): bool
    {
        $channel = $this->order_channel instanceof OrderDataFileChannel
            ? $this->order_channel
            : OrderDataFileChannel::tryFrom((string) $this->order_channel);

        return $channel === OrderDataFileChannel::EOS || (bool) $this->show_eos_stamp;
    }

    /**
     * メール送信済みとしてマーク
     */
    public function markAsMailSent(int $userId, ?string $mailTo = null): void
    {
        $data = [
            'mail_sent_at' => now(),
            'mail_sent_by' => $userId,
        ];

        if ($mailTo !== null) {
            $data['mail_to'] = $mailTo;
        }

        $this->update($data);
    }
}
