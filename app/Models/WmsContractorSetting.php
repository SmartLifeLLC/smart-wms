<?php

namespace App\Models;

use App\Enums\AutoOrder\TransmissionType;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 発注先設定（WMS側で管理）
 *
 * sakemaru本家のcontractorsテーブルは変更せず、
 * WMS固有の送信設定をこのテーブルで管理する。
 */
class WmsContractorSetting extends WmsModel
{
    protected $table = 'wms_contractor_settings';

    protected $fillable = [
        'contractor_id',
        'transmission_contractor_id',
        'transmission_type',
        'wms_order_jx_setting_id',
        'wms_order_ftp_setting_id',
        'supply_warehouse_id',
        'format_strategy_class',
        'transmission_time',
        'is_transmission_sun',
        'is_transmission_mon',
        'is_transmission_tue',
        'is_transmission_wed',
        'is_transmission_thu',
        'is_transmission_fri',
        'is_transmission_sat',
        'is_auto_transmission',
        'is_jx_auto_generation_enabled',
        'jx_generation_time',
        'jx_generation_cutoff_time',
        'jx_generation_sunday_time',
        'jx_generation_sunday_cutoff_time',
        'jx_transmission_time',
        'jx_transmission_sunday_time',
        'auto_order_generation_time',
        'order_mail',
        'order_mail_from',
        'order_mail_title',
        'order_mail_content',
        'is_receive_enabled',
        'receive_format',
        'receive_time',
        'is_receive_sun',
        'is_receive_mon',
        'is_receive_tue',
        'is_receive_wed',
        'is_receive_thu',
        'is_receive_fri',
        'is_receive_sat',
    ];

    protected $casts = [
        'transmission_type' => TransmissionType::class,
        'is_transmission_sun' => 'boolean',
        'is_transmission_mon' => 'boolean',
        'is_transmission_tue' => 'boolean',
        'is_transmission_wed' => 'boolean',
        'is_transmission_thu' => 'boolean',
        'is_transmission_fri' => 'boolean',
        'is_transmission_sat' => 'boolean',
        'is_auto_transmission' => 'boolean',
        'is_jx_auto_generation_enabled' => 'boolean',
        'is_receive_enabled' => 'boolean',
        'is_receive_sun' => 'boolean',
        'is_receive_mon' => 'boolean',
        'is_receive_tue' => 'boolean',
        'is_receive_wed' => 'boolean',
        'is_receive_thu' => 'boolean',
        'is_receive_fri' => 'boolean',
        'is_receive_sat' => 'boolean',
    ];

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * 発注データ送信先の発注先
     */
    public function transmissionContractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class, 'transmission_contractor_id');
    }

    /**
     * 実際の送信設定を取得（自身 or 発注データ集約先の設定）
     */
    public function getEffectiveTransmissionSettings(): self
    {
        if ($this->transmission_contractor_id) {
            $transmissionContractorSetting = self::where('contractor_id', $this->transmission_contractor_id)->first();
            if ($transmissionContractorSetting) {
                return $transmissionContractorSetting;
            }
        }

        return $this;
    }

    /**
     * 指定仕入先に集約される子仕入先IDを取得
     */
    public static function getChildContractorIds(int $parentContractorId): array
    {
        return self::where('transmission_contractor_id', $parentContractorId)
            ->pluck('contractor_id')
            ->toArray();
    }

    /**
     * 親 + 子の全仕入先IDを取得（子がいない場合は自身のみ）
     */
    public static function getContractorIdsWithChildren(int $contractorId): array
    {
        $ids = [$contractorId];
        $children = self::getChildContractorIds($contractorId);

        return array_unique(array_merge($ids, $children));
    }

    public function jxSetting(): BelongsTo
    {
        return $this->belongsTo(WmsOrderJxSetting::class, 'wms_order_jx_setting_id');
    }

    public function ftpSetting(): BelongsTo
    {
        return $this->belongsTo(WmsOrderFtpSetting::class, 'wms_order_ftp_setting_id');
    }

    public function supplyWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'supply_warehouse_id');
    }

    /**
     * 送信曜日を日本語で取得
     */
    public function getTransmissionDaysLabelAttribute(): string
    {
        $days = [];
        if ($this->is_transmission_sun) {
            $days[] = '日';
        }
        if ($this->is_transmission_mon) {
            $days[] = '月';
        }
        if ($this->is_transmission_tue) {
            $days[] = '火';
        }
        if ($this->is_transmission_wed) {
            $days[] = '水';
        }
        if ($this->is_transmission_thu) {
            $days[] = '木';
        }
        if ($this->is_transmission_fri) {
            $days[] = '金';
        }
        if ($this->is_transmission_sat) {
            $days[] = '土';
        }

        return empty($days) ? '-' : implode('・', $days);
    }

    /**
     * 指定された曜日に送信するかどうか
     *
     * @param  int  $dayOfWeek  0=日, 1=月, ..., 6=土
     */
    public function shouldTransmitOn(int $dayOfWeek): bool
    {
        return match ($dayOfWeek) {
            0 => $this->is_transmission_sun,
            1 => $this->is_transmission_mon,
            2 => $this->is_transmission_tue,
            3 => $this->is_transmission_wed,
            4 => $this->is_transmission_thu,
            5 => $this->is_transmission_fri,
            6 => $this->is_transmission_sat,
            default => false,
        };
    }

    public function jxGenerationTimeForDay(int $dayOfWeek): ?string
    {
        return $dayOfWeek === 0
            ? $this->jx_generation_sunday_time
            : $this->jx_generation_time;
    }

    public function jxGenerationCutoffTimeForDay(int $dayOfWeek): ?string
    {
        return $dayOfWeek === 0
            ? $this->jx_generation_sunday_cutoff_time
            : $this->jx_generation_cutoff_time;
    }

    public function jxTransmissionTimeForDay(int $dayOfWeek): ?string
    {
        return $dayOfWeek === 0
            ? $this->jx_transmission_sunday_time
            : $this->jx_transmission_time;
    }

    /**
     * 受信曜日を日本語で取得
     */
    public function getReceiveDaysLabelAttribute(): string
    {
        $days = [];
        if ($this->is_receive_sun) {
            $days[] = '日';
        }
        if ($this->is_receive_mon) {
            $days[] = '月';
        }
        if ($this->is_receive_tue) {
            $days[] = '火';
        }
        if ($this->is_receive_wed) {
            $days[] = '水';
        }
        if ($this->is_receive_thu) {
            $days[] = '木';
        }
        if ($this->is_receive_fri) {
            $days[] = '金';
        }
        if ($this->is_receive_sat) {
            $days[] = '土';
        }

        return empty($days) ? '-' : implode('・', $days);
    }

    /**
     * 指定された曜日に受信するかどうか
     *
     * @param  int  $dayOfWeek  0=日, 1=月, ..., 6=土
     */
    public function shouldReceiveOn(int $dayOfWeek): bool
    {
        return match ($dayOfWeek) {
            0 => $this->is_receive_sun,
            1 => $this->is_receive_mon,
            2 => $this->is_receive_tue,
            3 => $this->is_receive_wed,
            4 => $this->is_receive_thu,
            5 => $this->is_receive_fri,
            6 => $this->is_receive_sat,
            default => false,
        };
    }

    /**
     * 発注先IDから設定を取得（なければ作成）
     */
    public static function findOrCreateByContractor(int $contractorId): self
    {
        return self::firstOrCreate(
            ['contractor_id' => $contractorId],
            ['transmission_type' => TransmissionType::MANUAL_CSV]
        );
    }

    /**
     * 発注先が倉庫間移動（INTERNAL）かどうかを判定
     */
    public static function isInternalContractor(int $contractorId): bool
    {
        $setting = self::where('contractor_id', $contractorId)->first();

        return $setting?->transmission_type === TransmissionType::INTERNAL;
    }

    /**
     * 発注先に対応する供給倉庫IDを取得（INTERNAL時）
     */
    public static function getSupplyWarehouseId(int $contractorId): ?int
    {
        return self::where('contractor_id', $contractorId)
            ->where('transmission_type', TransmissionType::INTERNAL)
            ->value('supply_warehouse_id');
    }

    /**
     * 全INTERNAL発注先のマッピングを取得
     * [contractor_id => supply_warehouse_id]
     */
    public static function getAllInternalMappings(): array
    {
        return self::where('transmission_type', TransmissionType::INTERNAL)
            ->whereNotNull('supply_warehouse_id')
            ->pluck('supply_warehouse_id', 'contractor_id')
            ->toArray();
    }
}
