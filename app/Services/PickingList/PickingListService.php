<?php

namespace App\Services\PickingList;

use App\Enums\QuantityType;
use App\Models\Sakemaru\Location;
use Illuminate\Support\Facades\DB;

/**
 * ピッキングリストデータ取得サービス（読み取り専用）
 *
 * 3種類のピッキングリストのデータを取得する:
 * - 1次リスト: Wave集約（商品別の総数量一覧）
 * - 2次リスト: 作業者別実行（棚番順＋納品先内訳）
 * - 3次リスト: 納品先別仕分け（配送コース別→納品先別）
 */
class PickingListService
{
    private function db()
    {
        return DB::connection('sakemaru');
    }

    private function applyPrintablePickingItemScope($query)
    {
        return $query
            ->where(function ($q) {
                $q->where('pir.planned_qty', '>', 0)
                    ->orWhere('pir.shortage_qty', '>', 0);
            })
            ->where(function ($q) {
                $q->whereNull('pir.is_ready_to_shipment')
                    ->orWhere('pir.is_ready_to_shipment', false);
            })
            ->where(function ($q) {
                $q->whereNull('pir.stock_transfer_id')
                    ->orWhereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('stock_transfers as printable_st')
                            ->whereColumn('printable_st.id', 'pir.stock_transfer_id')
                            ->where('printable_st.is_delivered', false)
                            ->where('printable_st.is_confirmed', false);
                    });
            });
    }

    /**
     * 1次ピッキングリスト（Wave集約）
     *
     * @param  bool  $includePast  過去の伝票（出荷日が波動出荷日より前）も含める
     * @param  bool  $includeDelivered  配送済み（COMPLETED）タスクも含める
     * @return array{header: array, items: array, summary: array}
     */
    public function generatePrimaryList(int $waveId, bool $includePast = false, bool $includeDelivered = false): array
    {
        $wave = $this->db()->table('wms_waves as w')
            ->join('wms_wave_settings as ws', 'w.wms_wave_setting_id', '=', 'ws.id')
            ->join('delivery_courses as dc', 'ws.delivery_course_id', '=', 'dc.id')
            ->leftJoin('warehouses as wh', 'dc.warehouse_id', '=', 'wh.id')
            ->where('w.id', $waveId)
            ->select([
                'w.wave_no',
                'w.shipping_date',
                'wh.name as warehouse_name',
            ])
            ->first();

        if (! $wave) {
            return ['header' => [], 'items' => [], 'summary' => []];
        }

        $query = $this->db()->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->join('items as i', 'pir.item_id', '=', 'i.id')
            ->leftJoin('locations as l', 'pir.location_id', '=', 'l.id')
            ->where('pt.wave_id', $waveId);

        $this->applyPrintablePickingItemScope($query);

        if (! $includePast) {
            $query->leftJoin('earnings as e', 'pir.earning_id', '=', 'e.id')
                ->where(function ($q) use ($wave) {
                    $q->whereNull('e.delivered_date')
                        ->orWhere('e.delivered_date', '>=', $wave->shipping_date);
                });
        }

        if (! $includeDelivered) {
            $query->where('pt.status', '!=', 'COMPLETED');
        }

        $items = $query->select([
            'i.code as item_code',
            'i.name as item_name',
            'i.capacity_case',
            'i.packaging',
            'pir.location_id',
            'l.code1',
            'l.code2',
            'l.code3',
            DB::raw('COALESCE(l.floor_id, pt.floor_id) as floor_id'),
            DB::raw('SUM(pir.planned_qty) as total_qty'),
            DB::raw('SUM(pir.shortage_qty) as shortage_qty'),
            'pir.planned_qty_type',
        ])
            ->groupBy('i.id', 'i.code', 'i.name', 'i.capacity_case', 'i.packaging', 'pir.planned_qty_type', 'pir.location_id', 'l.code1', 'l.code2', 'l.code3')
            ->groupByRaw('COALESCE(l.floor_id, pt.floor_id)')
            ->orderByRaw("COALESCE(l.code1, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code2, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code3, 'ZZZ')")
            ->orderBy('i.code')
            ->get();

        $floorNames = $this->db()->table('floors')
            ->whereIn('id', $items->pluck('floor_id')->filter()->unique()->values()->all())
            ->pluck('name', 'id');

        $formattedItems = [];
        $totalQty = 0;
        $totalCase = 0;
        $totalPiece = 0;
        $totalShortage = 0;
        $totalPieceQty = 0;

        foreach ($items as $item) {
            $capacityCase = $item->capacity_case ?: 1;
            $qty = (int) $item->total_qty;
            $shortageQty = (int) $item->shortage_qty;
            $qtyType = QuantityType::tryFrom($item->planned_qty_type) ?? QuantityType::PIECE;

            if ($qtyType === QuantityType::CASE) {
                $caseQty = $qty;
                $pieceQty = 0;
                $pieceTotalQty = $qty * $capacityCase;
            } elseif ($capacityCase > 1) {
                $caseQty = intdiv($qty, $capacityCase);
                $pieceQty = $qty % $capacityCase;
                $pieceTotalQty = $qty;
            } else {
                $caseQty = 0;
                $pieceQty = $qty;
                $pieceTotalQty = $qty;
            }

            $locationCode = $item->location_id
                ? $this->formatLocationCode($item->code1, $item->code2, $item->code3, '-')
                : '';

            $formattedItems[] = [
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'packaging' => $item->packaging ?? '',
                'location_code' => $locationCode,
                'total_qty' => $qty,
                'case_qty' => $caseQty,
                'piece_qty' => $pieceQty,
                'shortage_qty' => $shortageQty,
                'total_piece_qty' => $pieceTotalQty,
                'floor_id' => $item->floor_id ? (int) $item->floor_id : null,
                'floor_name' => $item->floor_id ? ($floorNames[$item->floor_id] ?? '') : '',
            ];

            $totalQty += $qty;
            $totalCase += $caseQty;
            $totalPiece += $pieceQty;
            $totalShortage += $shortageQty;
            $totalPieceQty += $pieceTotalQty;
        }

        return [
            'header' => [
                'wave_no' => $wave->wave_no,
                'shipping_date' => $wave->shipping_date,
                'warehouse_name' => $wave->warehouse_name ?? '',
            ],
            'items' => $formattedItems,
            'summary' => [
                'sku_count' => count($formattedItems),
                'total_qty' => $totalQty,
                'total_case' => $totalCase,
                'total_piece' => $totalPiece,
                'total_shortage' => $totalShortage,
                'total_piece_qty' => $totalPieceQty,
            ],
        ];
    }

    /**
     * 1次ピッキングリストを必要に応じてフロア別ページへ分割する。
     *
     * @return array<int, array{header: array, items: array, summary: array}>
     */
    public function generatePrimaryListPages(int $waveId, bool $separateFloors = true): array
    {
        $data = $this->generatePrimaryList($waveId);

        if (! $separateFloors || empty($data['items'])) {
            return [$data];
        }

        $groupedItems = collect($data['items'])->groupBy(fn (array $item) => $item['floor_id'] ?? 'none');

        if ($groupedItems->count() <= 1) {
            return [$data];
        }

        return $groupedItems
            ->map(function ($items) use ($data) {
                $items = $items->values()->all();
                $floorName = $items[0]['floor_name'] ?? 'フロア未設定';

                return [
                    'header' => array_merge($data['header'], [
                        'floor_name' => $floorName ?: 'フロア未設定',
                    ]),
                    'items' => $items,
                    'summary' => $this->summarizePrimaryItems($items),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 1次ピッキングリストを配送コース単位でまとめる。
     *
     * 同一出荷日・同一倉庫・同一配送コースのWaveは、波動生成が複数回に分かれていても
     * 1つの1次リストとして棚番・商品単位に集計する。
     *
     * @param  array<int>  $waveIds
     * @return array<int, array{header: array, items: array, summary: array}>
     */
    public function generatePrimaryCourseListPages(array $waveIds, bool $separateFloors = true): array
    {
        $waveIds = collect($waveIds)->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        if (empty($waveIds)) {
            return [];
        }

        $waves = $this->db()->table('wms_waves as w')
            ->join('wms_wave_settings as ws', 'w.wms_wave_setting_id', '=', 'ws.id')
            ->join('delivery_courses as dc', 'ws.delivery_course_id', '=', 'dc.id')
            ->leftJoin('warehouses as wh', 'dc.warehouse_id', '=', 'wh.id')
            ->whereIn('w.id', $waveIds)
            ->select([
                'w.id',
                'w.wave_no',
                'w.shipping_date',
                'dc.id as delivery_course_id',
                'dc.code as delivery_course_code',
                'dc.name as delivery_course_name',
                'wh.id as warehouse_id',
                'wh.name as warehouse_name',
            ])
            ->orderBy('w.shipping_date')
            ->orderBy('wh.id')
            ->orderBy('dc.code')
            ->orderBy('w.wave_no')
            ->get();

        return $waves
            ->groupBy(fn ($wave) => implode('|', [
                $wave->shipping_date,
                $wave->warehouse_id ?? 0,
                $wave->delivery_course_id,
            ]))
            ->flatMap(function ($group) use ($separateFloors) {
                $group = $group->sortBy('wave_no')->values();
                $header = [
                    'wave_no' => $this->formatWaveNoSummary($group->pluck('wave_no')->all()),
                    'shipping_date' => $group->first()->shipping_date,
                    'warehouse_name' => $group->first()->warehouse_name ?? '',
                    'delivery_course_code' => $group->first()->delivery_course_code ?? '',
                    'delivery_course_name' => $group->first()->delivery_course_name ?? '',
                    'list_title' => '1次ピッキングリスト',
                ];

                return $this->generatePrimaryTotalListPages(
                    $group->pluck('id')->all(),
                    $separateFloors,
                    $header
                );
            })
            ->values()
            ->all();
    }

    /**
     * 1次ピッキングリスト（一括）
     *
     * 複数Waveをまたいで棚番・商品単位に集計する。波動番号は集計キーに含めない。
     *
     * @param  array<int>  $waveIds
     * @return array{header: array, items: array, summary: array}
     */
    public function generatePrimaryTotalList(array $waveIds, array $header = [], bool $groupByFloor = false): array
    {
        $waveIds = collect($waveIds)->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        if (empty($waveIds)) {
            return ['header' => $header, 'items' => [], 'summary' => $this->summarizePrimaryItems([])];
        }

        $firstWave = $this->db()->table('wms_waves as w')
            ->join('wms_wave_settings as ws', 'w.wms_wave_setting_id', '=', 'ws.id')
            ->join('delivery_courses as dc', 'ws.delivery_course_id', '=', 'dc.id')
            ->leftJoin('warehouses as wh', 'dc.warehouse_id', '=', 'wh.id')
            ->whereIn('w.id', $waveIds)
            ->select([
                'w.shipping_date',
                'wh.name as warehouse_name',
            ])
            ->orderBy('w.wave_no')
            ->first();

        $selectColumns = [
            'i.id as item_id',
            'i.code as item_code',
            'i.name as item_name',
            'i.capacity_case',
            'i.packaging',
            'pir.planned_qty_type',
            'pir.location_id',
            'l.code1',
            'l.code2',
            'l.code3',
            DB::raw($groupByFloor ? 'COALESCE(l.floor_id, pt.floor_id) as floor_id' : 'NULL as floor_id'),
            DB::raw('SUM(pir.planned_qty) as total_qty'),
            DB::raw('SUM(pir.shortage_qty) as shortage_qty'),
        ];

        $items = $this->db()->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->join('items as i', 'pir.item_id', '=', 'i.id')
            ->leftJoin('locations as l', 'pir.location_id', '=', 'l.id')
            ->whereIn('pt.wave_id', $waveIds)
            ->where('pt.status', '!=', 'COMPLETED')
            ->tap(fn ($query) => $this->applyPrintablePickingItemScope($query))
            ->select($selectColumns)
            ->groupBy('i.id', 'i.code', 'i.name', 'i.capacity_case', 'i.packaging', 'pir.planned_qty_type')
            ->groupBy('pir.location_id', 'l.code1', 'l.code2', 'l.code3')
            ->when($groupByFloor, fn ($query) => $query->groupByRaw('COALESCE(l.floor_id, pt.floor_id)'))
            ->orderByRaw("COALESCE(l.code1, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code2, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code3, 'ZZZ')")
            ->orderBy('i.code')
            ->get();

        $floorNames = $this->db()->table('floors')
            ->whereIn('id', $items->pluck('floor_id')->filter()->unique()->values()->all())
            ->pluck('name', 'id');

        $formattedItems = [];

        foreach ($items as $item) {
            $capacityCase = $item->capacity_case ?: 1;
            $qty = (int) $item->total_qty;
            $shortageQty = (int) $item->shortage_qty;
            $qtyType = QuantityType::tryFrom($item->planned_qty_type) ?? QuantityType::PIECE;
            $pieceTotalQty = $qtyType === QuantityType::CASE ? $qty * $capacityCase : $qty;
            $caseQty = $capacityCase > 1 ? intdiv($pieceTotalQty, $capacityCase) : 0;
            $pieceQty = $capacityCase > 1 ? $pieceTotalQty % $capacityCase : $pieceTotalQty;
            $locationCode = $item->location_id
                ? $this->formatLocationCode($item->code1, $item->code2, $item->code3, '-')
                : '';

            $formattedItems[] = [
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'packaging' => $item->packaging ?? '',
                'location_code' => $locationCode,
                'total_qty' => $qty,
                'case_qty' => $caseQty,
                'piece_qty' => $pieceQty,
                'shortage_qty' => $shortageQty,
                'total_piece_qty' => $pieceTotalQty,
                'floor_id' => $item->floor_id ? (int) $item->floor_id : null,
                'floor_name' => $item->floor_id ? ($floorNames[$item->floor_id] ?? '') : '',
            ];
        }

        return [
            'header' => array_merge([
                'wave_no' => '全波動合計',
                'shipping_date' => $firstWave->shipping_date ?? '',
                'warehouse_name' => $firstWave->warehouse_name ?? '',
                'list_title' => '1次ピッキングリスト(一括)',
            ], $header),
            'items' => $formattedItems,
            'summary' => $this->summarizePrimaryItems($formattedItems),
        ];
    }

    /**
     * 1次ピッキングリスト（一括）を必要に応じてフロア別ページへ分割する。
     *
     * @param  array<int>  $waveIds
     * @return array<int, array{header: array, items: array, summary: array}>
     */
    public function generatePrimaryTotalListPages(array $waveIds, bool $separateFloors = true, array $header = []): array
    {
        $data = $this->generatePrimaryTotalList($waveIds, $header, groupByFloor: $separateFloors);

        if (! $separateFloors || empty($data['items'])) {
            return [$data];
        }

        $groupedItems = collect($data['items'])->groupBy(fn (array $item) => $item['floor_id'] ?? 'none');

        if ($groupedItems->count() <= 1) {
            return [$data];
        }

        return $groupedItems
            ->map(function ($items) use ($data) {
                $items = $items->values()->all();
                $floorName = $items[0]['floor_name'] ?? 'フロア未設定';

                return [
                    'header' => array_merge($data['header'], [
                        'floor_name' => $floorName ?: 'フロア未設定',
                    ]),
                    'items' => $items,
                    'summary' => $this->summarizePrimaryItems($items),
                ];
            })
            ->values()
            ->all();
    }

    private function formatWaveNoSummary(array $waveNos): string
    {
        $waveNos = collect($waveNos)->filter()->unique()->values();

        if ($waveNos->count() <= 2) {
            return $waveNos->implode(' / ');
        }

        return $waveNos->first().' 他'.($waveNos->count() - 1).'件';
    }

    private function summarizePrimaryItems(array $items): array
    {
        return [
            'sku_count' => count($items),
            'total_qty' => array_sum(array_column($items, 'total_qty')),
            'total_case' => array_sum(array_column($items, 'case_qty')),
            'total_piece' => array_sum(array_column($items, 'piece_qty')),
            'total_shortage' => array_sum(array_column($items, 'shortage_qty')),
            'total_piece_qty' => array_sum(array_column($items, 'total_piece_qty')),
        ];
    }

    /**
     * 1次欠品リスト（Wave集約・欠品のみ）
     *
     * @return array{header: array, items: array, summary: array}
     */
    public function generateShortageList(int $waveId): array
    {
        return $this->generateShortageListByWaveIds([$waveId]);
    }

    /**
     * 1次欠品リストを配送コース単位でまとめる。
     *
     * @param  array<int>  $waveIds
     * @return array<int, array{header: array, items: array, summary: array}>
     */
    public function generateShortageCourseLists(array $waveIds): array
    {
        $waveIds = collect($waveIds)->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        if (empty($waveIds)) {
            return [];
        }

        $waves = $this->db()->table('wms_waves as w')
            ->join('wms_wave_settings as ws', 'w.wms_wave_setting_id', '=', 'ws.id')
            ->join('delivery_courses as dc', 'ws.delivery_course_id', '=', 'dc.id')
            ->leftJoin('warehouses as wh', 'dc.warehouse_id', '=', 'wh.id')
            ->whereIn('w.id', $waveIds)
            ->select([
                'w.id',
                'w.wave_no',
                'w.shipping_date',
                'dc.id as delivery_course_id',
                'dc.code as delivery_course_code',
                'dc.name as delivery_course_name',
                'wh.id as warehouse_id',
                'wh.name as warehouse_name',
            ])
            ->orderBy('w.shipping_date')
            ->orderBy('wh.id')
            ->orderBy('dc.code')
            ->orderBy('w.wave_no')
            ->get();

        return $waves
            ->groupBy(fn ($wave) => implode('|', [
                $wave->shipping_date,
                $wave->warehouse_id ?? 0,
                $wave->delivery_course_id,
            ]))
            ->map(function ($group) {
                $group = $group->sortBy('wave_no')->values();
                $header = [
                    'wave_no' => $this->formatWaveNoSummary($group->pluck('wave_no')->all()),
                    'shipping_date' => $group->first()->shipping_date,
                    'warehouse_name' => $group->first()->warehouse_name ?? '',
                    'delivery_course_code' => $group->first()->delivery_course_code ?? '',
                    'delivery_course_name' => $group->first()->delivery_course_name ?? '',
                ];

                return $this->generateShortageListByWaveIds($group->pluck('id')->all(), $header);
            })
            ->values()
            ->all();
    }

    /**
     * 1次欠品リスト（複数Wave集約・欠品のみ）
     *
     * @param  array<int>  $waveIds
     * @return array{header: array, items: array, summary: array}
     */
    public function generateShortageListByWaveIds(array $waveIds, array $header = []): array
    {
        $waveIds = collect($waveIds)->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        if (empty($waveIds)) {
            return ['header' => $header, 'items' => [], 'summary' => []];
        }

        $wave = $this->db()->table('wms_waves as w')
            ->join('wms_wave_settings as ws', 'w.wms_wave_setting_id', '=', 'ws.id')
            ->join('delivery_courses as dc', 'ws.delivery_course_id', '=', 'dc.id')
            ->leftJoin('warehouses as wh', 'dc.warehouse_id', '=', 'wh.id')
            ->whereIn('w.id', $waveIds)
            ->select([
                'w.wave_no',
                'w.shipping_date',
                'dc.code as delivery_course_code',
                'dc.name as delivery_course_name',
                'wh.name as warehouse_name',
            ])
            ->orderBy('w.wave_no')
            ->first();

        if (! $wave) {
            return ['header' => [], 'items' => [], 'summary' => []];
        }

        $today = now()->toDateString();

        $rows = $this->db()->table('wms_shortages as ws')
            ->join('items as i', 'ws.item_id', '=', 'i.id')
            ->leftJoin('locations as l', 'ws.location_id', '=', 'l.id')
            ->leftJoin('item_incoming_default_locations as idl', function ($join) {
                $join->on('idl.item_id', '=', 'ws.item_id')
                    ->whereColumn('idl.warehouse_id', 'ws.warehouse_id');
            })
            ->leftJoin('locations as default_l', 'idl.location_id', '=', 'default_l.id')
            ->leftJoin('trades as t', 'ws.trade_id', '=', 't.id')
            ->leftJoin('partners as tp', 't.partner_id', '=', 'tp.id')
            ->leftJoin('earnings as e', 'ws.earning_id', '=', 'e.id')
            ->leftJoin('buyers as b', 'e.buyer_id', '=', 'b.id')
            ->leftJoin(
                DB::raw("(SELECT bd1.buyer_id, bd1.salesman_id
                    FROM buyer_details bd1
                    INNER JOIN (SELECT buyer_id, MAX(start_date) as max_date FROM buyer_details WHERE start_date <= '{$today}' GROUP BY buyer_id) bd2
                    ON bd1.buyer_id = bd2.buyer_id AND bd1.start_date = bd2.max_date
                ) as bd"),
                'bd.buyer_id', '=', 'b.id'
            )
            ->leftJoin('users as salesman', 'salesman.id', '=', 'bd.salesman_id')
            ->whereIn('ws.wave_id', $waveIds)
            ->where('ws.shortage_qty', '>', 0)
            ->select([
                't.serial_id',
                'tp.name as partner_name',
                'salesman.name as salesman_name',
                'i.code as item_code',
                'i.name as item_name',
                'i.packaging',
                DB::raw('COALESCE(ws.location_id, idl.location_id) as location_id'),
                DB::raw('COALESCE(l.code1, default_l.code1) as code1'),
                DB::raw('COALESCE(l.code2, default_l.code2) as code2'),
                DB::raw('COALESCE(l.code3, default_l.code3) as code3'),
                'ws.order_qty',
                'ws.planned_qty',
                'ws.shortage_qty',
                'ws.qty_type_at_order as planned_qty_type',
            ])
            ->orderBy('t.serial_id')
            ->orderByRaw("COALESCE(l.code1, default_l.code1, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code2, default_l.code2, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code3, default_l.code3, 'ZZZ')")
            ->orderBy('i.code')
            ->get();

        $pickingRows = $this->db()->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->join('items as i', 'pir.item_id', '=', 'i.id')
            ->leftJoin('locations as l', 'pir.location_id', '=', 'l.id')
            ->leftJoin('trades as t', 'pir.trade_id', '=', 't.id')
            ->leftJoin('partners as tp', 't.partner_id', '=', 'tp.id')
            ->leftJoin('earnings as e', 'pir.earning_id', '=', 'e.id')
            ->leftJoin('buyers as b', 'e.buyer_id', '=', 'b.id')
            ->leftJoin('item_incoming_default_locations as idl', function ($join) {
                $join->on('idl.item_id', '=', 'pir.item_id')
                    ->whereColumn('idl.warehouse_id', 'pt.warehouse_id');
            })
            ->leftJoin('locations as default_l', 'idl.location_id', '=', 'default_l.id')
            ->leftJoin(
                DB::raw("(SELECT bd1.buyer_id, bd1.salesman_id
                    FROM buyer_details bd1
                    INNER JOIN (SELECT buyer_id, MAX(start_date) as max_date FROM buyer_details WHERE start_date <= '{$today}' GROUP BY buyer_id) bd2
                    ON bd1.buyer_id = bd2.buyer_id AND bd1.start_date = bd2.max_date
                ) as bd"),
                'bd.buyer_id', '=', 'b.id'
            )
            ->leftJoin('users as salesman', 'salesman.id', '=', 'bd.salesman_id')
            ->whereIn('pt.wave_id', $waveIds)
            ->where('pir.shortage_qty', '>', 0)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('wms_shortages as existing_ws')
                    ->whereColumn('existing_ws.wave_id', 'pt.wave_id')
                    ->whereColumn('existing_ws.warehouse_id', 'pt.warehouse_id')
                    ->whereColumn('existing_ws.item_id', 'pir.item_id')
                    ->whereColumn('existing_ws.trade_item_id', 'pir.trade_item_id')
                    ->where('existing_ws.shortage_qty', '>', 0);
            })
            ->select([
                't.serial_id',
                'tp.name as partner_name',
                'salesman.name as salesman_name',
                'i.code as item_code',
                'i.name as item_name',
                'i.packaging',
                DB::raw('COALESCE(pir.location_id, idl.location_id) as location_id'),
                DB::raw('COALESCE(l.code1, default_l.code1) as code1'),
                DB::raw('COALESCE(l.code2, default_l.code2) as code2'),
                DB::raw('COALESCE(l.code3, default_l.code3) as code3'),
                'pir.ordered_qty as order_qty',
                'pir.planned_qty',
                'pir.shortage_qty',
                DB::raw('COALESCE(pir.ordered_qty_type, pir.planned_qty_type) as planned_qty_type'),
            ])
            ->get();

        $rows = $rows
            ->concat($pickingRows)
            ->sort(function ($a, $b): int {
                foreach (['serial_id', 'code1', 'code2', 'code3', 'item_code'] as $key) {
                    $aValue = $a->{$key} ?? 'ZZZ';
                    $bValue = $b->{$key} ?? 'ZZZ';

                    if ($aValue == $bValue) {
                        continue;
                    }

                    return $aValue <=> $bValue;
                }

                return 0;
            })
            ->values();

        $formattedItems = [];
        $totalShortage = 0;

        foreach ($rows as $row) {
            $orderQty = (int) $row->order_qty;
            $plannedQty = (int) $row->planned_qty;
            $shortageQty = (int) $row->shortage_qty;
            $qtyType = QuantityType::tryFrom($row->planned_qty_type) ?? QuantityType::PIECE;
            $qtyLabel = $qtyType === QuantityType::CASE ? 'ケース' : 'バラ';

            $locationCode = $row->location_id
                ? $this->formatLocationCode($row->code1, $row->code2, $row->code3, '-')
                : '';

            $formattedItems[] = [
                'serial_id' => $row->serial_id ?? '',
                'partner_name' => $row->partner_name ?? '',
                'salesman_name' => $row->salesman_name ?? '',
                'item_code' => $row->item_code,
                'item_name' => $row->item_name,
                'packaging' => $row->packaging ?? '',
                'location_code' => $locationCode,
                'qty_label' => $qtyLabel,
                'planned_qty' => $orderQty,
                'allocated_qty' => $plannedQty,
                'shortage_qty' => $shortageQty,
            ];

            $totalShortage += $shortageQty;
        }

        return [
            'header' => array_merge([
                'wave_no' => $wave->wave_no,
                'shipping_date' => $wave->shipping_date,
                'warehouse_name' => $wave->warehouse_name ?? '',
                'delivery_course_code' => $wave->delivery_course_code ?? '',
                'delivery_course_name' => $wave->delivery_course_name ?? '',
            ], $header),
            'items' => $formattedItems,
            'summary' => [
                'sku_count' => count($formattedItems),
                'total_shortage' => $totalShortage,
            ],
        ];
    }

    /**
     * 2次ピッキングリスト（タスク単位）
     *
     * 棚番順にソートし、各棚番・商品ごとに納品先内訳を付加する。
     *
     * @return array{header: array, items: array, summary: array}
     */
    public function generateSecondaryList(int $pickingTaskId): array
    {
        return $this->generateSecondaryListByTaskIds([$pickingTaskId]);
    }

    /**
     * 2次ピッキングリスト一括（ピッカー別）
     *
     * 複数WaveのタスクをピッカーIDでグループ化し、ピッカーごとの2次リストを返す。
     *
     * @return array[] generateSecondaryList と同じ構造の配列
     */
    public function generateSecondaryBatchList(array $waveIds): array
    {
        if (empty($waveIds)) {
            return [];
        }

        $tasks = $this->db()->table('wms_picking_tasks as pt')
            ->leftJoin('wms_waves as w', 'pt.wave_id', '=', 'w.id')
            ->leftJoin('delivery_courses as dc', 'pt.delivery_course_id', '=', 'dc.id')
            ->leftJoin('wms_picking_areas as pa', 'pt.wms_picking_area_id', '=', 'pa.id')
            ->leftJoin('wms_pickers as pk', 'pt.picker_id', '=', 'pk.id')
            ->whereIn('pt.wave_id', $waveIds)
            ->select([
                'pt.id', 'pt.picker_id', 'pt.shipment_date',
                'w.wave_no', 'dc.name as course_name',
                'pa.name as area_name', 'pk.name as picker_name',
            ])
            ->get();

        if ($tasks->isEmpty()) {
            return [];
        }

        $allTaskIds = $tasks->pluck('id')->toArray();
        $rows = $this->fetchPickingItemRows($allTaskIds);

        $rowsByTask = [];
        foreach ($rows as $row) {
            $rowsByTask[$row->picking_task_id][] = $row;
        }

        $pickerGroups = [];
        foreach ($tasks as $task) {
            $pickerKey = $task->picker_id ?? 0;
            if (! isset($pickerGroups[$pickerKey])) {
                $pickerGroups[$pickerKey] = [
                    'header_task' => $task,
                    'rows' => [],
                ];
            }
            foreach ($rowsByTask[$task->id] ?? [] as $row) {
                $pickerGroups[$pickerKey]['rows'][] = $row;
            }
        }

        $results = [];
        foreach ($pickerGroups as $group) {
            $task = $group['header_task'];
            $data = $this->buildSecondaryItems(collect($group['rows']), [
                'wave_no' => $task->wave_no,
                'course_name' => $task->course_name ?? '',
                'area_name' => $task->area_name ?? '',
                'picker_name' => $task->picker_name ?? '',
                'shipping_date' => $task->shipment_date,
            ]);
            if (! empty($data['items'])) {
                $results[] = $data;
            }
        }

        return $results;
    }

    /**
     * 全タスクのpicking_item_resultsを1回のクエリで取得
     */
    private function fetchPickingItemRows(array $taskIds): \Illuminate\Support\Collection
    {
        return $this->db()->table('wms_picking_item_results as pir')
            ->join('items as i', 'pir.item_id', '=', 'i.id')
            ->leftJoin('locations as l', 'pir.location_id', '=', 'l.id')
            ->leftJoin('earnings as e', 'pir.earning_id', '=', 'e.id')
            ->leftJoin('partners as p', 'e.buyer_id', '=', 'p.id')
            ->whereIn('pir.picking_task_id', $taskIds)
            ->tap(fn ($query) => $this->applyPrintablePickingItemScope($query))
            ->select([
                'pir.id',
                'pir.picking_task_id',
                'pir.item_id',
                'i.code as item_code',
                'i.name as item_name',
                'l.code1',
                'l.code2',
                'l.code3',
                'pir.location_id',
                'pir.walking_order',
                'pir.planned_qty',
                'pir.planned_qty_type',
                'pir.earning_id',
                'p.name as buyer_name',
            ])
            ->orderByRaw('COALESCE(pir.walking_order, 999999)')
            ->orderByRaw("COALESCE(l.code1, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code2, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code3, 'ZZZ')")
            ->orderBy('i.code')
            ->get();
    }

    /**
     * 複数タスクIDから2次リストデータを生成
     */
    private function generateSecondaryListByTaskIds(array $taskIds): array
    {
        // ヘッダー情報（最初のタスクから）
        $task = $this->db()->table('wms_picking_tasks as pt')
            ->join('wms_waves as w', 'pt.wave_id', '=', 'w.id')
            ->leftJoin('delivery_courses as dc', 'pt.delivery_course_id', '=', 'dc.id')
            ->leftJoin('wms_picking_areas as pa', 'pt.wms_picking_area_id', '=', 'pa.id')
            ->leftJoin('wms_pickers as pk', 'pt.picker_id', '=', 'pk.id')
            ->whereIn('pt.id', $taskIds)
            ->select([
                'w.wave_no',
                'dc.name as course_name',
                'pa.name as area_name',
                'pk.name as picker_name',
                'pt.shipment_date as shipping_date',
            ])
            ->first();

        if (! $task) {
            return ['header' => [], 'items' => [], 'summary' => []];
        }

        // 明細取得（棚番順）
        $results = $this->db()->table('wms_picking_item_results as pir')
            ->join('items as i', 'pir.item_id', '=', 'i.id')
            ->leftJoin('locations as l', 'pir.location_id', '=', 'l.id')
            ->leftJoin('earnings as e', 'pir.earning_id', '=', 'e.id')
            ->leftJoin('partners as p', 'e.buyer_id', '=', 'p.id')
            ->whereIn('pir.picking_task_id', $taskIds)
            ->tap(fn ($query) => $this->applyPrintablePickingItemScope($query))
            ->select([
                'pir.id',
                'pir.item_id',
                'i.code as item_code',
                'i.name as item_name',
                'l.code1',
                'l.code2',
                'l.code3',
                'pir.location_id',
                'pir.walking_order',
                'pir.planned_qty',
                'pir.planned_qty_type',
                'pir.earning_id',
                'p.name as buyer_name',
            ])
            ->orderByRaw('COALESCE(pir.walking_order, 999999)')
            ->orderByRaw("COALESCE(l.code1, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code2, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code3, 'ZZZ')")
            ->orderBy('i.code')
            ->get();

        return $this->buildSecondaryItems($results, [
            'wave_no' => $task->wave_no,
            'course_name' => $task->course_name ?? '',
            'area_name' => $task->area_name ?? '',
            'picker_name' => $task->picker_name ?? '',
            'shipping_date' => $task->shipping_date,
        ]);
    }

    /**
     * 2次リスト明細行の組み立て（共通処理）
     */
    private function buildSecondaryItems($results, array $header): array
    {
        $grouped = [];
        $locationSet = [];

        foreach ($results as $row) {
            $locationCode = $row->location_id
                ? $this->formatLocationCode($row->code1, $row->code2, $row->code3)
                : '未設定';

            $key = "{$locationCode}|{$row->item_id}";

            if (! isset($grouped[$key])) {
                $qtyType = QuantityType::tryFrom($row->planned_qty_type) ?? QuantityType::PIECE;
                $grouped[$key] = [
                    'location_code' => $locationCode,
                    'item_code' => $row->item_code,
                    'item_name' => $row->item_name,
                    'total_pick_qty' => 0,
                    'qty_type' => $qtyType->name(),
                    'destinations' => [],
                ];
                $locationSet[$locationCode] = true;
            }

            $grouped[$key]['total_pick_qty'] += (int) $row->planned_qty;

            if ($row->buyer_name) {
                $qtyType = QuantityType::tryFrom($row->planned_qty_type) ?? QuantityType::PIECE;
                $grouped[$key]['destinations'][] = [
                    'name' => $row->buyer_name,
                    'qty' => (int) $row->planned_qty,
                    'qty_type' => $qtyType->name(),
                ];
            }
        }

        $items = array_values($grouped);
        $totalQty = array_sum(array_column($items, 'total_pick_qty'));

        return [
            'header' => $header,
            'items' => $items,
            'summary' => [
                'item_count' => count($items),
                'location_count' => count($locationSet),
                'total_qty' => $totalQty,
            ],
        ];
    }

    /**
     * 3次ピッキングリスト（配送コース別仕分け）
     *
     * 2次リストと同じ構造（商品＋納品先内訳）を配送コース別にページ分割する。
     *
     * @return array[] 配送コースごとの2次リスト構造の配列
     */
    public function generateTertiaryList(int $waveId): array
    {
        return $this->generateTertiaryListByWaveIds([$waveId]);
    }

    /**
     * 3次ピッキングリスト一括（複数Wave対応）
     *
     * @return array[] 配送コースごとの2次リスト構造の配列
     */
    public function generateTertiaryListByWaveIds(array $waveIds): array
    {
        if (empty($waveIds)) {
            return [];
        }

        $wave = $this->db()->table('wms_waves as w')
            ->whereIn('w.id', $waveIds)
            ->select(['w.wave_no', 'w.shipping_date'])
            ->first();

        if (! $wave) {
            return [];
        }

        $tasks = $this->db()->table('wms_picking_tasks as pt')
            ->leftJoin('delivery_courses as dc', 'pt.delivery_course_id', '=', 'dc.id')
            ->whereIn('pt.wave_id', $waveIds)
            ->select(['pt.id', 'pt.delivery_course_id', 'dc.code as course_code', 'dc.name as course_name'])
            ->orderBy('dc.code')
            ->get();

        if ($tasks->isEmpty()) {
            return [];
        }

        $allTaskIds = $tasks->pluck('id')->toArray();
        $rows = $this->fetchPickingItemRows($allTaskIds);

        $rowsByTask = [];
        foreach ($rows as $row) {
            $rowsByTask[$row->picking_task_id][] = $row;
        }

        $courseGroups = [];
        foreach ($tasks as $task) {
            $courseKey = $task->delivery_course_id ?? 0;
            if (! isset($courseGroups[$courseKey])) {
                $courseGroups[$courseKey] = [
                    'course_name' => $task->course_name ?? '未設定',
                    'rows' => [],
                ];
            }
            foreach ($rowsByTask[$task->id] ?? [] as $row) {
                $courseGroups[$courseKey]['rows'][] = $row;
            }
        }

        $results = [];
        foreach ($courseGroups as $group) {
            $data = $this->buildTertiaryItems(collect($group['rows']), [
                'wave_no' => $wave->wave_no,
                'course_name' => $group['course_name'],
                'area_name' => '',
                'picker_name' => '',
                'shipping_date' => $wave->shipping_date,
            ]);
            if (! empty($data['items'])) {
                $results[] = $data;
            }
        }

        return $results;
    }

    /**
     * 3次リスト明細行の組み立て。
     *
     * ケースとバラが同一棚番・同一商品に混在しても、片方へ寄せず別列へ集計する。
     */
    private function buildTertiaryItems($results, array $header): array
    {
        $grouped = [];
        $locationSet = [];

        foreach ($results as $row) {
            $locationCode = $row->location_id
                ? $this->formatLocationCode($row->code1, $row->code2, $row->code3)
                : '未設定';

            $key = "{$locationCode}|{$row->item_id}";

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'location_code' => $locationCode,
                    'item_code' => $row->item_code,
                    'item_name' => $row->item_name,
                    'case_qty' => 0,
                    'piece_qty' => 0,
                    'destinations' => [],
                ];
                $locationSet[$locationCode] = true;
            }

            $qty = (int) $row->planned_qty;
            $qtyType = QuantityType::tryFrom($row->planned_qty_type) ?? QuantityType::PIECE;
            $qtyColumn = $qtyType === QuantityType::CASE ? 'case_qty' : 'piece_qty';
            $grouped[$key][$qtyColumn] += $qty;

            if ($row->buyer_name) {
                $destinationKey = $row->buyer_name;

                if (! isset($grouped[$key]['destinations'][$destinationKey])) {
                    $grouped[$key]['destinations'][$destinationKey] = [
                        'name' => $row->buyer_name,
                        'case_qty' => 0,
                        'piece_qty' => 0,
                    ];
                }

                $grouped[$key]['destinations'][$destinationKey][$qtyColumn] += $qty;
            }
        }

        $items = array_map(function (array $item): array {
            $item['destinations'] = array_values($item['destinations']);

            return $item;
        }, array_values($grouped));

        return [
            'header' => $header,
            'items' => $items,
            'summary' => [
                'item_count' => count($items),
                'location_count' => count($locationSet),
                'total_case' => array_sum(array_column($items, 'case_qty')),
                'total_piece' => array_sum(array_column($items, 'piece_qty')),
                'total_qty' => array_sum(array_column($items, 'case_qty')) + array_sum(array_column($items, 'piece_qty')),
            ],
        ];
    }

    /**
     * 配送コース別ピッキングリスト（配送コース・フロア単位ページ）
     *
     * 仮ピッキングリスト出力の2次ピッキングリストとして使用する。
     * 1ページ = 1配送コース×1フロア。配送者名には配送コース名を表示する。
     *
     * @return array[] [['header' => [...], 'items' => [...]], ...]
     */
    public function generateCourseGroupedListByWaveIds(array $waveIds): array
    {
        if (empty($waveIds)) {
            return [];
        }

        $rows = $this->db()->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->leftJoin('earnings as e', 'pir.earning_id', '=', 'e.id')
            ->leftJoin('stock_transfers as st', 'pir.stock_transfer_id', '=', 'st.id')
            ->leftJoin('trades as t', 't.id', '=', DB::raw('COALESCE(e.trade_id, st.trade_id)'))
            ->leftJoin('delivery_courses as dc', 'pt.delivery_course_id', '=', 'dc.id')
            ->leftJoin('warehouses as wh', 'pt.warehouse_id', '=', 'wh.id')
            ->join('items as i', 'pir.item_id', '=', 'i.id')
            ->leftJoin('srh_searchable_items as ssi', 'ssi.item_id', '=', 'i.id')
            ->leftJoin('locations as l', 'pir.location_id', '=', 'l.id')
            ->leftJoin('item_incoming_default_locations as idl', function ($join) {
                $join->on('idl.item_id', '=', 'pir.item_id')
                    ->whereColumn('idl.warehouse_id', 'pt.warehouse_id');
            })
            ->leftJoin('locations as default_l', 'idl.location_id', '=', 'default_l.id')
            ->leftJoin('floors as f', DB::raw('COALESCE(l.floor_id, default_l.floor_id)'), '=', 'f.id')
            ->whereIn('pt.wave_id', $waveIds)
            ->tap(fn ($query) => $this->applyPrintablePickingItemScope($query))
            ->select([
                'pir.id as pir_id',
                'pir.earning_id',
                'pir.stock_transfer_id',
                't.slip_number',
                DB::raw('COALESCE(e.delivered_date, st.delivered_date) as delivered_date'),
                'dc.id as course_id',
                'dc.name as course_name',
                'dc.code as course_code',
                'wh.name as warehouse_name',
                'i.code as item_code',
                'i.name as item_name',
                'i.capacity_case',
                'ssi.jancode as jan_code',
                DB::raw('COALESCE(pir.location_id, idl.location_id) as location_id'),
                DB::raw('COALESCE(l.code1, default_l.code1) as code1'),
                DB::raw('COALESCE(l.code2, default_l.code2) as code2'),
                DB::raw('COALESCE(l.code3, default_l.code3) as code3'),
                DB::raw('COALESCE(l.floor_id, default_l.floor_id) as floor_id'),
                'f.name as floor_name',
                'pir.planned_qty',
                'pir.planned_qty_type',
                'pir.shortage_qty',
            ])
            ->orderBy('dc.code')
            ->orderByRaw('COALESCE(l.floor_id, default_l.floor_id, 999999)')
            ->orderByRaw("COALESCE(l.code1, default_l.code1, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code2, default_l.code2, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code3, default_l.code3, 'ZZZ')")
            ->orderBy('i.code')
            ->orderByRaw('COALESCE(e.id, st.id)')
            ->get();

        // 配送コース×フロア でバケット化
        $byCourseFloor = [];
        foreach ($rows as $row) {
            $courseId = $row->course_id ?? 0;
            $floorId = $row->floor_id ?? 0;
            $key = $courseId.'|'.$floorId;
            if (! isset($byCourseFloor[$key])) {
                $byCourseFloor[$key] = [
                    'header' => [
                        'course_name' => $row->course_name ?? '',
                        'shipping_date' => $row->delivered_date,
                        'warehouse_name' => $row->warehouse_name ?? '',
                        'floor_name' => $row->floor_name ?? '',
                    ],
                    '_source_ids' => [],
                    '_rows' => [],
                ];
            }
            $sourceKey = $row->earning_id ? "E:{$row->earning_id}" : "ST:{$row->stock_transfer_id}";
            $byCourseFloor[$key]['_source_ids'][$sourceKey] = true;
            $byCourseFloor[$key]['_rows'][] = $row;
        }

        $results = [];
        foreach ($byCourseFloor as $key => $bucket) {
            $items = [];
            $rowsByLocationItem = [];

            foreach ($bucket['_rows'] as $row) {
                $key = ($row->location_id ?? 0).'|'.$row->item_code;
                if (! isset($rowsByLocationItem[$key])) {
                    $rowsByLocationItem[$key] = [
                        'item_code' => $row->item_code,
                        'item_name' => $this->normalizeItemName($row->item_name),
                        'capacity_case' => (int) ($row->capacity_case ?: 1),
                        'jan_code' => $this->extractFirstJanCode($row->jan_code),
                        'location_id' => $row->location_id,
                        'code1' => $row->code1,
                        'code2' => $row->code2,
                        'code3' => $row->code3,
                        'total_pieces' => 0,
                        'shortage_qty' => 0,
                        'planned_qty_type' => $row->planned_qty_type,
                    ];
                }

                $qtyType = QuantityType::tryFrom($row->planned_qty_type) ?? QuantityType::PIECE;
                $capacityCase = max(1, (int) ($row->capacity_case ?: 1));
                $qty = (int) $row->planned_qty;
                $piecesContribution = $qtyType === QuantityType::CASE ? $qty * $capacityCase : $qty;

                $rowsByLocationItem[$key]['total_pieces'] += $piecesContribution;
                $rowsByLocationItem[$key]['shortage_qty'] += (int) $row->shortage_qty;
            }

            $no = 0;
            foreach ($rowsByLocationItem as $entry) {
                $no++;
                $capacityCase = max(1, $entry['capacity_case']);
                $totalPieces = $entry['total_pieces'];
                $caseQty = intdiv($totalPieces, $capacityCase);
                $pieceQty = $totalPieces % $capacityCase;

                $locationCode = $entry['location_id']
                    ? $this->formatLocationCode($entry['code1'], $entry['code2'], $entry['code3'], '-')
                    : '';

                $items[] = [
                    'no' => $no,
                    'location_code' => $locationCode,
                    'item_code' => $entry['item_code'],
                    'jan_code' => $entry['jan_code'],
                    'item_name' => $entry['item_name'],
                    'capacity_case' => $capacityCase,
                    'case_qty' => $caseQty,
                    'piece_qty' => $pieceQty,
                    'total_pieces' => $totalPieces,
                    'shortage_qty' => $entry['shortage_qty'],
                ];
            }

            $results[] = [
                'header' => array_merge($bucket['header'], [
                    'slip_count' => count($bucket['_source_ids']),
                ]),
                'items' => $items,
                'summary' => [
                    'case_qty' => array_sum(array_column($items, 'case_qty')),
                    'piece_qty' => array_sum(array_column($items, 'piece_qty')),
                    'total_pieces' => array_sum(array_column($items, 'total_pieces')),
                ],
            ];
        }

        return $results;
    }

    /**
     * 2次ピッキングリスト2（フロア優先→配送コース順）
     *
     * ページ単位は配送コース×フロア（V1と同じ）。
     * ページ順: 全コースの1F → 全コースの2F → 全コースのYX。
     * YX棚番は1Fから分離して末尾に別塊として出力。
     */
    public function generateCourseGroupedListV2ByWaveIds(array $waveIds): array
    {
        if (empty($waveIds)) {
            return [];
        }

        $rows = $this->db()->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->leftJoin('earnings as e', 'pir.earning_id', '=', 'e.id')
            ->leftJoin('stock_transfers as st', 'pir.stock_transfer_id', '=', 'st.id')
            ->leftJoin('trades as t', 't.id', '=', DB::raw('COALESCE(e.trade_id, st.trade_id)'))
            ->leftJoin('delivery_courses as dc', 'pt.delivery_course_id', '=', 'dc.id')
            ->leftJoin('warehouses as wh', 'pt.warehouse_id', '=', 'wh.id')
            ->join('items as i', 'pir.item_id', '=', 'i.id')
            ->leftJoin('srh_searchable_items as ssi', 'ssi.item_id', '=', 'i.id')
            ->leftJoin('locations as l', 'pir.location_id', '=', 'l.id')
            ->leftJoin('item_incoming_default_locations as idl', function ($join) {
                $join->on('idl.item_id', '=', 'pir.item_id')
                    ->whereColumn('idl.warehouse_id', 'pt.warehouse_id');
            })
            ->leftJoin('locations as default_l', 'idl.location_id', '=', 'default_l.id')
            ->leftJoin('floors as f', DB::raw('COALESCE(l.floor_id, default_l.floor_id)'), '=', 'f.id')
            ->whereIn('pt.wave_id', $waveIds)
            ->tap(fn ($query) => $this->applyPrintablePickingItemScope($query))
            ->select([
                'pir.id as pir_id',
                'pir.earning_id',
                'pir.stock_transfer_id',
                't.slip_number',
                DB::raw('COALESCE(e.delivered_date, st.delivered_date) as delivered_date'),
                'dc.id as course_id',
                'dc.name as course_name',
                'dc.code as course_code',
                'wh.name as warehouse_name',
                'i.code as item_code',
                'i.name as item_name',
                'i.capacity_case',
                'ssi.jancode as jan_code',
                DB::raw('COALESCE(pir.location_id, idl.location_id) as location_id'),
                DB::raw('COALESCE(l.code1, default_l.code1) as code1'),
                DB::raw('COALESCE(l.code2, default_l.code2) as code2'),
                DB::raw('COALESCE(l.code3, default_l.code3) as code3'),
                DB::raw('COALESCE(l.floor_id, default_l.floor_id) as floor_id'),
                'f.name as floor_name',
                'pir.planned_qty',
                'pir.planned_qty_type',
                'pir.shortage_qty',
            ])
            ->orderBy('dc.code')
            ->orderByRaw('COALESCE(l.floor_id, default_l.floor_id, 999999)')
            ->orderByRaw("COALESCE(l.code1, default_l.code1, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code2, default_l.code2, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code3, default_l.code3, 'ZZZ')")
            ->orderBy('i.code')
            ->orderByRaw('COALESCE(e.id, st.id)')
            ->get();

        // 配送コース×フロア でバケット化（YXは別バケット）
        $buckets = [];
        foreach ($rows as $row) {
            $courseId = $row->course_id ?? 0;
            $floorId = $row->floor_id ?? 0;
            $code1 = $row->code1 ?? '';
            $isYxGroup = str_starts_with($code1, 'YA') || str_starts_with($code1, 'YB') || str_starts_with($code1, 'YC') || str_starts_with($code1, 'YX');
            $bucketKey = $isYxGroup ? $courseId.'|YX' : $courseId.'|'.$floorId;

            if (! isset($buckets[$bucketKey])) {
                $buckets[$bucketKey] = [
                    'course_code' => $row->course_code ?? '',
                    'floor_id' => $isYxGroup ? PHP_INT_MAX : ($floorId ?: PHP_INT_MAX - 1),
                    'is_yx' => $isYxGroup,
                    'header' => [
                        'course_name' => $row->course_name ?? '',
                        'shipping_date' => $row->delivered_date,
                        'warehouse_name' => $row->warehouse_name ?? '',
                        'floor_name' => $isYxGroup ? 'YX' : ($row->floor_name ?? ''),
                    ],
                    '_source_ids' => [],
                    '_rows' => [],
                ];
            }
            $sourceKey = $row->earning_id ? "E:{$row->earning_id}" : "ST:{$row->stock_transfer_id}";
            $buckets[$bucketKey]['_source_ids'][$sourceKey] = true;
            $buckets[$bucketKey]['_rows'][] = $row;
        }

        // フロア優先→コース順でソート
        uasort($buckets, function ($a, $b) {
            $floorCmp = $a['floor_id'] <=> $b['floor_id'];
            if ($floorCmp !== 0) {
                return $floorCmp;
            }

            return $a['course_code'] <=> $b['course_code'];
        });

        $results = [];
        foreach ($buckets as $bucket) {
            $rowsByLocationItem = [];

            foreach ($bucket['_rows'] as $row) {
                $key = ($row->location_id ?? 0).'|'.$row->item_code;
                if (! isset($rowsByLocationItem[$key])) {
                    $rowsByLocationItem[$key] = [
                        'item_code' => $row->item_code,
                        'item_name' => $this->normalizeItemName($row->item_name),
                        'capacity_case' => (int) ($row->capacity_case ?: 1),
                        'jan_code' => $this->extractFirstJanCode($row->jan_code),
                        'location_id' => $row->location_id,
                        'code1' => $row->code1,
                        'code2' => $row->code2,
                        'code3' => $row->code3,
                        'total_pieces' => 0,
                        'shortage_qty' => 0,
                        'planned_qty_type' => $row->planned_qty_type,
                    ];
                }

                $qtyType = QuantityType::tryFrom($row->planned_qty_type) ?? QuantityType::PIECE;
                $capacityCase = max(1, (int) ($row->capacity_case ?: 1));
                $qty = (int) $row->planned_qty;
                $piecesContribution = $qtyType === QuantityType::CASE ? $qty * $capacityCase : $qty;

                $rowsByLocationItem[$key]['total_pieces'] += $piecesContribution;
                $shortageQty = (int) $row->shortage_qty;
                $shortagePieces = $qtyType === QuantityType::CASE ? $shortageQty * $capacityCase : $shortageQty;
                $rowsByLocationItem[$key]['shortage_qty'] += $shortagePieces;
            }

            $no = 0;
            $items = [];
            foreach ($rowsByLocationItem as $entry) {
                $no++;
                $capacityCase = max(1, $entry['capacity_case']);
                $totalPieces = $entry['total_pieces'];

                $items[] = [
                    'no' => $no,
                    'location_code' => $entry['location_id']
                        ? $this->formatLocationCode($entry['code1'], $entry['code2'], $entry['code3'], '-')
                        : '',
                    'item_code' => $entry['item_code'],
                    'jan_code' => $entry['jan_code'],
                    'item_name' => $entry['item_name'],
                    'capacity_case' => $capacityCase,
                    'case_qty' => intdiv($totalPieces, $capacityCase),
                    'piece_qty' => $totalPieces % $capacityCase,
                    'total_pieces' => $totalPieces,
                    'shortage_qty' => $entry['shortage_qty'],
                ];
            }

            $results[] = [
                'header' => array_merge($bucket['header'], [
                    'slip_count' => count($bucket['_source_ids']),
                ]),
                'items' => $items,
                'summary' => [
                    'case_qty' => array_sum(array_column($items, 'case_qty')),
                    'piece_qty' => array_sum(array_column($items, 'piece_qty')),
                    'total_pieces' => array_sum(array_column($items, 'total_pieces')),
                ],
            ];
        }

        return $results;
    }

    /**
     * 得意先別ピッキングリスト V2（配送コース別＋得意先内訳）
     *
     * 仮ピッキングリスト出力の3次ピッキングリストとして使用する。
     * 1ページ = 1配送コース。明細は得意先（buyer）→棚番順でソート。
     *
     * @return array[] [['header' => [...], 'items' => [...], 'summary' => [...]], ...]
     */
    public function generateBuyerGroupedListByWaveIds(array $waveIds): array
    {
        if (empty($waveIds)) {
            return [];
        }

        $rows = $this->db()->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->join('wms_waves as w', 'pt.wave_id', '=', 'w.id')
            ->leftJoin('earnings as e', 'pir.earning_id', '=', 'e.id')
            ->leftJoin('stock_transfers as st', 'pir.stock_transfer_id', '=', 'st.id')
            ->leftJoin('buyers as b', 'e.buyer_id', '=', 'b.id')
            ->leftJoin('partners as p', 'b.partner_id', '=', 'p.id')
            ->leftJoin('warehouses as dest_wh', 'st.to_warehouse_id', '=', 'dest_wh.id')
            ->leftJoin('delivery_courses as dc', 'pt.delivery_course_id', '=', 'dc.id')
            ->join('items as i', 'pir.item_id', '=', 'i.id')
            ->leftJoin('srh_searchable_items as ssi', 'ssi.item_id', '=', 'i.id')
            ->leftJoin('locations as l', 'pir.location_id', '=', 'l.id')
            ->leftJoin('item_incoming_default_locations as idl', function ($join) {
                $join->on('idl.item_id', '=', 'pir.item_id')
                    ->whereColumn('idl.warehouse_id', 'pt.warehouse_id');
            })
            ->leftJoin('locations as default_l', 'idl.location_id', '=', 'default_l.id')
            ->whereIn('pt.wave_id', $waveIds)
            ->tap(fn ($query) => $this->applyPrintablePickingItemScope($query))
            ->select([
                'pir.id as pir_id',
                'pir.earning_id',
                'pir.stock_transfer_id',
                'w.id as wave_id',
                'w.wave_no',
                'w.shipping_date',
                'dc.id as course_id',
                'dc.code as course_code',
                'dc.name as course_name',
                DB::raw('COALESCE(p.code, dest_wh.code) as buyer_code'),
                DB::raw("COALESCE(p.name, CONCAT('【移動】', dest_wh.name)) as buyer_name"),
                'i.code as item_code',
                'i.name as item_name',
                'i.capacity_case',
                'ssi.jancode as jan_code',
                DB::raw('COALESCE(pir.location_id, idl.location_id) as location_id'),
                DB::raw('COALESCE(l.code1, default_l.code1) as code1'),
                DB::raw('COALESCE(l.code2, default_l.code2) as code2'),
                DB::raw('COALESCE(l.code3, default_l.code3) as code3'),
                'pir.planned_qty',
                'pir.planned_qty_type',
                'pir.shortage_qty',
            ])
            ->orderBy('dc.code')
            ->orderByRaw('COALESCE(p.code, dest_wh.code, 999999999)')
            ->orderByRaw("COALESCE(l.code1, default_l.code1, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code2, default_l.code2, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code3, default_l.code3, 'ZZZ')")
            ->orderBy('i.code')
            ->get();

        // 配送コース別にバケット化
        $byCourse = [];
        foreach ($rows as $row) {
            $courseId = $row->course_id ?? 0;
            if (! isset($byCourse[$courseId])) {
                $byCourse[$courseId] = [
                    'header' => [
                        'course_name' => $row->course_name ?? '',
                        'wave_no' => $row->wave_no ?? '',
                        'shipping_date' => $row->shipping_date,
                    ],
                    '_wave_nos' => [],
                    'rows' => [],
                ];
            }
            $byCourse[$courseId]['_wave_nos'][$row->wave_no] = true;
            $byCourse[$courseId]['rows'][] = $row;
        }

        $results = [];
        foreach ($byCourse as $bucket) {
            // 同じ得意先×棚番×商品 で集約
            $grouped = [];
            foreach ($bucket['rows'] as $row) {
                $key = ($row->buyer_code ?? 'NA').'|'.($row->location_id ?? 0).'|'.$row->item_code;
                if (! isset($grouped[$key])) {
                    $grouped[$key] = [
                        'buyer_code' => $row->buyer_code,
                        'buyer_name' => $row->buyer_name,
                        'location_id' => $row->location_id,
                        'code1' => $row->code1,
                        'code2' => $row->code2,
                        'code3' => $row->code3,
                        'item_code' => $row->item_code,
                        'item_name' => $this->normalizeItemName($row->item_name),
                        'capacity_case' => (int) ($row->capacity_case ?: 1),
                        'jan_code' => $this->extractFirstJanCode($row->jan_code),
                        'total_pieces' => 0,
                        'shortage_qty' => 0,
                    ];
                }

                $qtyType = QuantityType::tryFrom($row->planned_qty_type) ?? QuantityType::PIECE;
                $capacityCase = max(1, (int) ($row->capacity_case ?: 1));
                $qty = (int) $row->planned_qty;
                $piecesContribution = $qtyType === QuantityType::CASE ? $qty * $capacityCase : $qty;

                $grouped[$key]['total_pieces'] += $piecesContribution;
                $grouped[$key]['shortage_qty'] += (int) $row->shortage_qty;
            }

            $items = [];
            $totalCase = 0;
            $totalPiece = 0;
            $totalAll = 0;
            $totalShortage = 0;
            $no = 0;

            foreach ($grouped as $entry) {
                $no++;
                $capacityCase = max(1, $entry['capacity_case']);
                $totalPieces = $entry['total_pieces'];
                $caseQty = intdiv($totalPieces, $capacityCase);
                $pieceQty = $totalPieces % $capacityCase;

                $locationCode = $entry['location_id']
                    ? $this->formatLocationCode($entry['code1'], $entry['code2'], $entry['code3'], '-')
                    : '';

                $items[] = [
                    'no' => $no,
                    'location_code' => $locationCode,
                    'buyer_code' => (string) ($entry['buyer_code'] ?? ''),
                    'buyer_name' => (string) ($entry['buyer_name'] ?? ''),
                    'item_code' => $entry['item_code'],
                    'jan_code' => $entry['jan_code'],
                    'item_name' => $entry['item_name'],
                    'capacity_case' => $capacityCase,
                    'case_qty' => $caseQty,
                    'piece_qty' => $pieceQty,
                    'total_pieces' => $totalPieces,
                    'shortage_qty' => $entry['shortage_qty'],
                ];

                $totalCase += $caseQty;
                $totalPiece += $pieceQty;
                $totalAll += $totalPieces;
                $totalShortage += (int) $entry['shortage_qty'];
            }

            $results[] = [
                'header' => array_merge($bucket['header'], [
                    'wave_no' => $this->formatWaveNoSummary(array_keys($bucket['_wave_nos'])),
                ]),
                'items' => $items,
                'summary' => [
                    'row_count' => count($items),
                    'total_case' => $totalCase,
                    'total_piece' => $totalPiece,
                    'total_pieces_all' => $totalAll,
                    'total_shortage' => $totalShortage,
                ],
            ];
        }

        return $results;
    }

    /**
     * srh_searchable_items.jancode は複数JANをカンマ等で連結している場合がある。
     * 表示用に最初の有効なJANを抽出する。
     */
    private function extractFirstJanCode(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }
        $tokens = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($tokens)) {
            return '';
        }

        return (string) $tokens[0];
    }

    /**
     * items.name に含まれる全角/半角スペースの不揃いを正規化する。
     * - 先頭/末尾のスペース除去
     * - 連続スペースを単一スペースに圧縮
     * - 全角スペースは半角スペースに変換
     * - 残った半角スペースをノーブレークスペース(U+00A0)に変換し、
     *   TCPDFが空白で折り返してLine1に大きな空白が残るのを防ぐ
     *   （文字単位で折り返すようにする）
     */
    private function formatLocationCode(?string $code1, ?string $code2, ?string $code3, string $separator = '-'): string
    {
        if ($code1 === 'Z' && $code2 === '0' && $code3 === '0') {
            return '';
        }

        return Location::formatCode($code1, $code2, $code3, $separator);
    }

    private function normalizeItemName(?string $raw): string
    {
        if ($raw === null) {
            return '';
        }
        // 全角スペース → 半角スペース
        $normalized = str_replace("\u{3000}", ' ', $raw);
        // 連続スペースを1つに
        $normalized = preg_replace('/\s+/u', ' ', $normalized);
        // 先頭/末尾のスペース除去
        $normalized = trim((string) $normalized);

        // 半角スペース → ノーブレークスペース（折返し抑制）
        return str_replace(' ', "\u{00A0}", $normalized);
    }
}
