<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        $connection = DB::connection($this->connection);
        $warehouses = $connection->table('warehouses')->get(['id', 'code']);

        if ($warehouses->isEmpty()) {
            return;
        }

        $warehouseIdByCode = $warehouses
            ->mapWithKeys(fn ($warehouse) => [(string) $warehouse->code => (int) $warehouse->id])
            ->all();

        $kanakanContractorIds = $this->kanakanContractorIds($connection);

        $this->upsertForAllWarehouses(
            $connection,
            $kanakanContractorIds,
            $warehouses,
            $this->days(mon: true, tue: true, wed: true, thu: true, fri: true, sat: true, sun: false),
            [
                '22' => $this->days(mon: false, tue: true, wed: false, thu: true, fri: true, sat: true, sun: false),
            ],
            $warehouseIdByCode
        );

        $this->upsertForAllWarehouses(
            $connection,
            $this->contractorIdsByCode($connection, ['1202']),
            $warehouses,
            $this->days(mon: false, tue: true, wed: true, thu: true, fri: true, sat: true, sun: true),
            [
                '22' => $this->days(mon: false, tue: true, wed: false, thu: true, fri: false, sat: true, sun: false),
                '91' => $this->days(mon: true, tue: true, wed: true, thu: true, fri: true, sat: true, sun: false),
            ],
            $warehouseIdByCode
        );

        $this->upsertForAllWarehouses(
            $connection,
            $this->contractorIdsByCode($connection, ['1330']),
            $warehouses,
            $this->days(mon: true, tue: true, wed: true, thu: true, fri: true, sat: true, sun: false),
            [
                '22' => $this->days(mon: true, tue: false, wed: true, thu: false, fri: true, sat: false, sun: false),
            ],
            $warehouseIdByCode
        );

        $this->upsertForAllWarehouses(
            $connection,
            $this->contractorIdsByCode($connection, ['1017']),
            $warehouses,
            $this->days(mon: false, tue: true, wed: false, thu: true, fri: false, sat: true, sun: false),
            [],
            $warehouseIdByCode
        );
    }

    public function down(): void
    {
        // 既存運用データを安全に復元できないため、初期設定upsertの自動rollbackは行わない。
    }

    /**
     * @param  \Illuminate\Database\ConnectionInterface  $connection
     * @param  array<int>  $contractorIds
     * @param  \Illuminate\Support\Collection<int, object>  $warehouses
     * @param  array<string, bool>  $defaultDays
     * @param  array<string, array<string, bool>>  $overridesByWarehouseCode
     * @param  array<string, int>  $warehouseIdByCode
     */
    private function upsertForAllWarehouses(
        $connection,
        array $contractorIds,
        $warehouses,
        array $defaultDays,
        array $overridesByWarehouseCode,
        array $warehouseIdByCode
    ): void {
        if (empty($contractorIds)) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($contractorIds as $contractorId) {
            foreach ($warehouses as $warehouse) {
                $warehouseCode = (string) $warehouse->code;
                $days = $overridesByWarehouseCode[$warehouseCode] ?? $defaultDays;

                $rows[] = [
                    'contractor_id' => (int) $contractorId,
                    'warehouse_id' => (int) ($warehouseIdByCode[$warehouseCode] ?? $warehouse->id),
                    ...$days,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $connection->table('wms_contractor_warehouse_delivery_days')->upsert(
                $chunk,
                ['contractor_id', 'warehouse_id'],
                [
                    'delivery_mon',
                    'delivery_tue',
                    'delivery_wed',
                    'delivery_thu',
                    'delivery_fri',
                    'delivery_sat',
                    'delivery_sun',
                    'updated_at',
                ]
            );
        }
    }

    /**
     * @param  \Illuminate\Database\ConnectionInterface  $connection
     * @return array<int>
     */
    private function kanakanContractorIds($connection): array
    {
        $parentId = $connection->table('contractors')->where('code', '1106')->value('id');
        $fallbackCodes = ['1106', '1021', '1029', '1068', '1126', '1127', '1680'];

        if (! $parentId) {
            return $this->contractorIdsByCode($connection, $fallbackCodes);
        }

        $childIds = $connection
            ->table('wms_contractor_settings')
            ->where('transmission_contractor_id', $parentId)
            ->pluck('contractor_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique(array_merge([(int) $parentId], $childIds)));
    }

    /**
     * @param  \Illuminate\Database\ConnectionInterface  $connection
     * @param  array<string>  $codes
     * @return array<int>
     */
    private function contractorIdsByCode($connection, array $codes): array
    {
        return $connection
            ->table('contractors')
            ->whereIn('code', $codes)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<string, bool>
     */
    private function days(
        bool $mon,
        bool $tue,
        bool $wed,
        bool $thu,
        bool $fri,
        bool $sat,
        bool $sun
    ): array {
        return [
            'delivery_mon' => $mon,
            'delivery_tue' => $tue,
            'delivery_wed' => $wed,
            'delivery_thu' => $thu,
            'delivery_fri' => $fri,
            'delivery_sat' => $sat,
            'delivery_sun' => $sun,
        ];
    }
};
