<?php

namespace App\Services\WarehouseTransfer;

use App\Enums\EVolumeUnit;
use App\Models\Sakemaru\Location;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HANDY倉庫移動用: 移動元倉庫の在庫リスト / JAN辞書 を返す
 *
 * 棚卸しHANDYと同じ形の在庫リストを返し、端末側で商品スキャンできるようにする。
 * real_stocks を主にし、巨大カラムは取得しない。
 */
class WarehouseTransferStockListService
{
    public const MAX_PER_PAGE = 500;

    /**
     * 移動元倉庫の在庫リスト（ページング）
     */
    public function paginateStockItems(int $warehouseId, int $page = 1, int $perPage = self::MAX_PER_PAGE, bool $includeZero = false, bool $compact = false): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $paginator = $this->stockItemsQuery($warehouseId, $includeZero)
            ->paginate($perPage, ['*'], 'page', max($page, 1));

        $paginator->setCollection(
            collect($paginator->items())
                ->map(fn (object $row) => $this->itemPayload($row, $compact))
                ->values()
        );

        return $paginator;
    }

    /**
     * 移動元倉庫の在庫リスト（全件, 確定時の在庫再計算等で使用）
     *
     * @return array<int, float> item_id => available_quantity
     */
    public function availableQuantityByItem(int $warehouseId, array $itemIds, string $stockAllocationCode = '1'): array
    {
        if ($itemIds === []) {
            return [];
        }

        return $this->baseQuery($warehouseId)
            ->whereIn('rs.item_id', $itemIds)
            ->where('sa.code', $stockAllocationCode)
            ->groupBy('rs.item_id')
            ->selectRaw('rs.item_id, SUM('.$this->availableExpression().') as available_quantity')
            ->pluck('available_quantity', 'item_id')
            ->map(fn ($qty) => (float) $qty)
            ->all();
    }

    /**
     * JAN/検索CD辞書（棚卸しAPIの janCodes() と同じ構造）
     *
     * @return array<string, array<int, array{i:int, rs:int|null, ct:string|null, t:string, q:int}>>
     */
    public function janDictionary(int $warehouseId, bool $includeZero = false): array
    {
        $realStockByItem = $this->baseQuery($warehouseId)
            ->when(! $includeZero, fn (Builder $q) => $q->whereRaw($this->availableExpression().' > 0'))
            ->orderBy('rs.id')
            ->get(['rs.id as real_stock_id', 'rs.item_id'])
            ->groupBy('item_id')
            ->map(fn ($rows) => (int) $rows->first()->real_stock_id)
            ->all();

        $itemIds = array_keys($realStockByItem);
        if ($itemIds === []) {
            return [];
        }

        $dict = [];

        foreach (array_chunk($itemIds, 1000) as $chunk) {
            $rows = DB::connection('sakemaru')
                ->table('item_search_information as isi')
                ->leftJoin('item_quantity_information as iqi', 'isi.item_quantity_information_id', '=', 'iqi.id')
                ->leftJoin('items as i', 'i.id', '=', 'isi.item_id')
                ->whereIn('isi.item_id', $chunk)
                ->where('isi.is_active', 1)
                ->whereNotNull('isi.search_string')
                ->where('isi.search_string', '!=', '')
                ->orderBy('isi.item_id')
                ->orderByRaw("CASE isi.quantity_type WHEN 'PIECE' THEN 0 WHEN 'CASE' THEN 1 WHEN 'CARTON' THEN 2 ELSE 9 END")
                ->orderBy('iqi.quantity')
                ->get([
                    'isi.item_id',
                    'isi.search_string',
                    'isi.code_type',
                    'isi.quantity_type',
                    'iqi.quantity as package_quantity',
                    'i.capacity_case as item_capacity_case',
                ]);

            foreach ($rows as $row) {
                $dict[$row->search_string][] = [
                    'i' => (int) $row->item_id,
                    'rs' => $realStockByItem[(int) $row->item_id] ?? null,
                    'ct' => $row->code_type,
                    't' => self::quantityTypeCode($row->quantity_type),
                    'q' => self::packageQuantity($row),
                ];
            }
        }

        return $dict;
    }

    /**
     * 検索CDから入数を解決（HANDY/Web の総バラ再計算用）
     */
    public function packageQuantityForCode(int $itemId, ?string $searchCode): ?int
    {
        if ($searchCode === null || trim($searchCode) === '') {
            return null;
        }

        $normalizedCode = function_exists('mb_convert_kana')
            ? mb_convert_kana(trim($searchCode), 'as')
            : trim($searchCode);

        $row = DB::connection('sakemaru')
            ->table('item_search_information as isi')
            ->leftJoin('item_quantity_information as iqi', 'isi.item_quantity_information_id', '=', 'iqi.id')
            ->leftJoin('items as i', 'i.id', '=', 'isi.item_id')
            ->where('isi.item_id', $itemId)
            ->where('isi.is_active', 1)
            ->where(function ($query) use ($normalizedCode) {
                $query->where('isi.search_string', $normalizedCode)
                    ->orWhereRaw('LPAD(isi.search_string, 13, "0") = ?', [$normalizedCode]);
            })
            ->first(['isi.quantity_type', 'iqi.quantity as package_quantity', 'i.capacity_case as item_capacity_case']);

        return $row ? self::packageQuantity($row) : null;
    }

    /**
     * 商品マスタ（最小項目）
     */
    public function itemMaster(int $itemId): ?object
    {
        return DB::connection('sakemaru')
            ->table('items')
            ->where('id', $itemId)
            ->first(['id', 'code', 'name', 'volume', 'volume_unit', 'capacity_case', 'capacity_carton', 'is_active']);
    }

    // ------------------------------------------------------------
    // Query builders
    // ------------------------------------------------------------

    private function stockItemsQuery(int $warehouseId, bool $includeZero): Builder
    {
        $lotRanked = DB::raw(
            '(SELECT rsl.real_stock_id, rsl.location_id, rsl.floor_id, ROW_NUMBER() OVER (PARTITION BY rsl.real_stock_id ORDER BY rsl.updated_at DESC, rsl.id DESC) AS rn FROM real_stock_lots rsl WHERE rsl.status = \'ACTIVE\') as lot'
        );

        return $this->baseQuery($warehouseId)
            ->leftJoin($lotRanked, function ($join) {
                $join->on('lot.real_stock_id', '=', 'rs.id')
                    ->where('lot.rn', '=', 1);
            })
            ->leftJoin('locations as l', 'l.id', '=', 'lot.location_id')
            ->leftJoin('floors as f', 'f.id', '=', DB::raw('COALESCE(lot.floor_id, l.floor_id)'))
            ->when(! $includeZero, fn (Builder $q) => $q->whereRaw($this->availableExpression().' > 0'))
            ->select([
                'rs.id as real_stock_id',
                'rs.item_id',
                'i.code as item_code',
                'i.name as item_name',
                'i.volume',
                'i.volume_unit',
                'i.capacity_case',
                'i.capacity_carton',
                DB::raw("(SELECT isi.search_string FROM item_search_information isi WHERE isi.item_id = i.id AND isi.code_type = 'JAN' AND isi.quantity_type = 'PIECE' AND isi.is_active = 1 ORDER BY isi.priority IS NULL, isi.priority, isi.id LIMIT 1) as barcode"),
                'sa.code as stock_allocation_code',
                'sa.name as stock_allocation_name',
                'l.id as location_id',
                'f.name as floor_name',
                'l.code1 as location_code1',
                'l.code2 as location_code2',
                'l.code3 as location_code3',
                DB::raw('rs.current_quantity as current_quantity'),
                DB::raw($this->availableExpression().' as available_quantity'),
            ])
            ->orderBy('f.name')
            ->orderBy('l.code1')
            ->orderBy('l.code2')
            ->orderBy('l.code3')
            ->orderBy('i.code')
            ->orderBy('rs.id');
    }

    private function baseQuery(int $warehouseId): Builder
    {
        $query = DB::connection('sakemaru')
            ->table('real_stocks as rs')
            ->join('items as i', 'i.id', '=', 'rs.item_id')
            ->leftJoin('stock_allocations as sa', 'sa.id', '=', 'rs.stock_allocation_id')
            ->where('rs.warehouse_id', $warehouseId)
            ->where('i.is_active', 1);

        if (config('app.client_id')) {
            $query->where('rs.client_id', (int) config('app.client_id'));
        }

        if (Schema::connection('sakemaru')->hasColumn('items', 'is_managed_stock')) {
            $query->where(function (Builder $q): void {
                $q->whereNull('i.is_managed_stock')->orWhere('i.is_managed_stock', 1);
            });
        }

        return $query;
    }

    /**
     * 利用可能在庫 = 現在庫 - WMS予約 - ピッキング中
     */
    private function availableExpression(): string
    {
        static $expression = null;

        if ($expression === null) {
            $hasPicking = Schema::connection('sakemaru')->hasColumn('real_stocks', 'picking_quantity');
            $expression = $hasPicking
                ? '(rs.current_quantity - COALESCE(rs.reserved_quantity, 0) - COALESCE(rs.picking_quantity, 0))'
                : '(rs.current_quantity - COALESCE(rs.reserved_quantity, 0))';
        }

        return $expression;
    }

    // ------------------------------------------------------------
    // Payload
    // ------------------------------------------------------------

    private function itemPayload(object $row, bool $compact): array
    {
        $capacityCase = max((int) ($row->capacity_case ?? 1), 1);
        $available = (float) $row->available_quantity;
        $availableInt = (int) floor($available);

        $payload = [
            'id' => (int) $row->real_stock_id,
            'real_stock_id' => (int) $row->real_stock_id,
            'item_id' => (int) $row->item_id,
            'item_code' => (string) $row->item_code,
            'item_name' => (string) $row->item_name,
            'barcode' => $row->barcode,
            'volume' => $row->volume !== null ? (string) $row->volume : null,
            'volume_unit' => $row->volume_unit,
            'volume_unit_label' => $this->volumeUnitLabel($row->volume_unit),
            'capacity_case' => $capacityCase,
            'capacity_carton' => $row->capacity_carton !== null ? (int) $row->capacity_carton : null,
            'location' => [
                'id' => $row->location_id !== null ? (int) $row->location_id : null,
                'floor_name' => $row->floor_name,
                'location_no' => Location::formatCode($row->location_code1, $row->location_code2, $row->location_code3),
                'code1' => $row->location_code1,
                'code2' => $row->location_code2,
                'code3' => $row->location_code3,
            ],
            'stock_allocation_code' => (string) ($row->stock_allocation_code ?? '1'),
            'stock_allocation_name' => $row->stock_allocation_name,
            'current_quantity' => (float) $row->current_quantity,
            'available_quantity' => $available,
            'case_quantity' => intdiv(max($availableInt, 0), $capacityCase),
            'piece_quantity' => max($availableInt, 0) % $capacityCase,
        ];

        if (! $compact) {
            $payload['search_codes'] = $this->searchCodes((int) $row->item_id);
        }

        return $payload;
    }

    private function searchCodes(int $itemId): array
    {
        return DB::connection('sakemaru')
            ->table('item_search_information as isi')
            ->leftJoin('item_quantity_information as iqi', 'isi.item_quantity_information_id', '=', 'iqi.id')
            ->leftJoin('items as i', 'i.id', '=', 'isi.item_id')
            ->where('isi.item_id', $itemId)
            ->where('isi.is_active', 1)
            ->orderByRaw("CASE isi.quantity_type WHEN 'PIECE' THEN 0 WHEN 'CASE' THEN 1 WHEN 'CARTON' THEN 2 ELSE 9 END")
            ->orderBy('iqi.quantity')
            ->get([
                'isi.search_string',
                'isi.code_type',
                'isi.quantity_type',
                'iqi.quantity as package_quantity',
                'i.capacity_case as item_capacity_case',
            ])
            ->map(fn ($row) => [
                'c' => $row->search_string,
                'ct' => $row->code_type,
                't' => self::quantityTypeCode($row->quantity_type),
                'q' => self::packageQuantity($row),
            ])
            ->filter(fn ($row) => $row['c'] !== null && $row['c'] !== '')
            ->values()
            ->all();
    }

    private function volumeUnitLabel(?string $volumeUnit): ?string
    {
        $volumeUnit = $volumeUnit !== null ? trim($volumeUnit) : null;

        if ($volumeUnit === null || $volumeUnit === '') {
            return null;
        }

        return EVolumeUnit::tryFrom($volumeUnit)?->name() ?? $volumeUnit;
    }

    public static function packageQuantity(object $row): int
    {
        if (($row->quantity_type ?? null) === 'PIECE') {
            return max((int) ($row->item_capacity_case ?? $row->capacity_case ?? 1), 1);
        }

        return max((int) ($row->package_quantity ?? $row->quantity ?? 1), 1);
    }

    public static function quantityTypeCode(?string $quantityType): string
    {
        return match ($quantityType) {
            'PIECE' => '0',
            'CASE' => '1',
            'CARTON' => '2',
            default => '9',
        };
    }
}
