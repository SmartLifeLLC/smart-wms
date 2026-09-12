<?php

namespace App\Services;

use App\Models\WmsDailyStat;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class WmsStatsService
{
    /**
     * 指定日付・倉庫の統計データを取得または更新
     * 30分ルール: 最終集計から30分未満なら既存データを返す
     *
     * @param  bool  $forceUpdate  強制更新フラグ
     */
    public function getOrUpdateDailyStats(Carbon $date, int $warehouseId, bool $forceUpdate = false): WmsDailyStat
    {
        $stat = WmsDailyStat::where('warehouse_id', $warehouseId)
            ->where('target_date', $date->format('Y-m-d'))
            ->first();

        // データが存在しない、または30分以上経過している、または強制更新の場合は再集計
        if (! $stat || $stat->isStale(30) || $forceUpdate) {
            return $this->calculate($date, $warehouseId);
        }

        return $stat;
    }

    /**
     * 指定日付・倉庫の統計データを集計して保存
     */
    public function calculate(Carbon $date, int $warehouseId): WmsDailyStat
    {
        try {
            DB::connection('sakemaru')->beginTransaction();

            $dateStr = $date->format('Y-m-d');

            // 基本統計の集計
            $basicStats = $this->calculateBasicStats($dateStr, $warehouseId);

            // 欠品統計の集計
            $shortageStats = $this->calculateShortageStats($dateStr, $warehouseId);

            // 金額関連の集計
            $amountStats = $this->calculateAmountStats($dateStr, $warehouseId);

            // カテゴリ別内訳の集計
            $categoryBreakdown = $this->calculateCategoryBreakdown($dateStr, $warehouseId);

            // データを保存
            $stat = WmsDailyStat::updateOrCreate(
                [
                    'warehouse_id' => $warehouseId,
                    'target_date' => $dateStr,
                ],
                array_merge(
                    $basicStats,
                    $shortageStats,
                    $amountStats,
                    [
                        'category_breakdown' => $categoryBreakdown,
                        'last_calculated_at' => now(),
                    ]
                )
            );

            DB::connection('sakemaru')->commit();

            Log::info('WMS Daily Stats calculated', [
                'warehouse_id' => $warehouseId,
                'target_date' => $dateStr,
                'stats_id' => $stat->id,
            ]);

            return $stat;
        } catch (\Exception $e) {
            DB::connection('sakemaru')->rollBack();
            Log::error('Failed to calculate WMS Daily Stats', [
                'warehouse_id' => $warehouseId,
                'target_date' => $dateStr,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 基本統計（伝票数、商品数等）を集計
     */
    private function calculateBasicStats(string $dateStr, int $warehouseId): array
    {
        $earningStats = DB::connection('sakemaru')
            ->table('earnings as e')
            ->where('e.warehouse_id', $warehouseId)
            ->where('e.delivered_date', $dateStr)
            ->where('e.is_active', true)
            ->selectRaw('
                COUNT(DISTINCT e.id) as total_slip_count,
                COUNT(DISTINCT CASE WHEN e.is_delivered = 1 THEN e.id END) as shipped_slip_count,
                COUNT(DISTINCT CASE WHEN e.is_delivered = 0 THEN e.id END) as unshipped_slip_count,
                COUNT(DISTINCT e.buyer_id) as unique_buyer_count,
                COUNT(DISTINCT e.delivery_course_id) as delivery_course_count
            ')
            ->first();

        $pickingStats = DB::connection('sakemaru')
            ->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->where('pt.warehouse_id', $warehouseId)
            ->where('pt.shipment_date', $dateStr)
            ->selectRaw('
                COUNT(DISTINCT pir.trade_id) as slip_count,
                COUNT(pir.id) as item_count,
                COUNT(DISTINCT pir.item_id) as unique_item_count,
                COALESCE(SUM(pir.ordered_qty), 0) as total_order_qty,
                COALESCE(SUM(pir.planned_qty), 0) as total_planned_qty
            ')
            ->first();

        $taskStats = DB::connection('sakemaru')
            ->table('wms_picking_tasks as pt')
            ->where('warehouse_id', $warehouseId)
            ->where('shipment_date', $dateStr)
            ->selectRaw("
                COUNT(*) as picking_task_count,
                COUNT(CASE WHEN status IN ('COMPLETED', 'SHORTAGE', 'SHIPPED') THEN 1 END) as completed_task_count,
                COUNT(CASE WHEN status = 'SHIPPED' THEN 1 END) as shipped_task_count
            ")
            ->first();

        $waveCount = DB::connection('sakemaru')
            ->table('wms_picking_tasks')
            ->where('warehouse_id', $warehouseId)
            ->where('shipment_date', $dateStr)
            ->distinct('wave_id')
            ->count('wave_id');

        $totalShipQty = DB::connection('sakemaru')
            ->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->where('pt.warehouse_id', $warehouseId)
            ->where('pt.shipment_date', $dateStr)
            ->sum('pir.planned_qty');

        return [
            'total_slip_count' => (int) ($earningStats->total_slip_count ?? 0),
            'shipped_slip_count' => (int) ($earningStats->shipped_slip_count ?? 0),
            'unshipped_slip_count' => (int) ($earningStats->unshipped_slip_count ?? 0),
            'unique_buyer_count' => (int) ($earningStats->unique_buyer_count ?? 0),
            'picking_slip_count' => $pickingStats->slip_count ?? 0,
            'picking_item_count' => $pickingStats->item_count ?? 0,
            'unique_item_count' => $pickingStats->unique_item_count ?? 0,
            'delivery_course_count' => (int) ($earningStats->delivery_course_count ?? 0),
            'wave_count' => (int) $waveCount,
            'picking_task_count' => (int) ($taskStats->picking_task_count ?? 0),
            'completed_task_count' => (int) ($taskStats->completed_task_count ?? 0),
            'shipped_task_count' => (int) ($taskStats->shipped_task_count ?? 0),
            'total_ship_qty' => (int) ($totalShipQty ?? 0),
            'total_order_qty' => (int) ($pickingStats->total_order_qty ?? 0),
            'total_planned_qty' => (int) ($pickingStats->total_planned_qty ?? 0),
        ];
    }

    /**
     * 欠品統計を集計
     */
    private function calculateShortageStats(string $dateStr, int $warehouseId): array
    {
        $shortageStats = DB::connection('sakemaru')
            ->table('wms_shortages as ws')
            ->where('ws.warehouse_id', $warehouseId)
            ->where('ws.shipment_date', $dateStr)
            ->selectRaw("
                COUNT(DISTINCT CASE WHEN ws.shortage_qty > 0 THEN ws.item_id END) as unique_count,
                COALESCE(SUM(ws.shortage_qty), 0) as total_count,
                COALESCE(SUM(ws.allocation_shortage_qty), 0) as allocation_shortage_qty,
                COUNT(CASE WHEN ws.status IN ('SHORTAGE', 'PARTIAL_SHORTAGE') THEN 1 END) as confirmed_shortage_count,
                COALESCE(SUM(CASE WHEN ws.status IN ('SHORTAGE', 'PARTIAL_SHORTAGE') THEN ws.shortage_qty ELSE 0 END), 0) as confirmed_shortage_qty,
                COUNT(DISTINCT CASE WHEN ws.shortage_qty > 0 THEN COALESCE(ws.earning_id, ws.trade_id) END) as shortage_slip_count
            ")
            ->first();

        return [
            'stockout_unique_count' => (int) ($shortageStats->unique_count ?? 0),
            'stockout_total_count' => (int) ($shortageStats->total_count ?? 0),
            'allocation_shortage_qty' => (int) ($shortageStats->allocation_shortage_qty ?? 0),
            'confirmed_shortage_count' => (int) ($shortageStats->confirmed_shortage_count ?? 0),
            'confirmed_shortage_qty' => (int) ($shortageStats->confirmed_shortage_qty ?? 0),
            'shortage_slip_count' => (int) ($shortageStats->shortage_slip_count ?? 0),
        ];
    }

    /**
     * 金額関連統計を集計
     */
    private function calculateAmountStats(string $dateStr, int $warehouseId): array
    {
        $amountStats = DB::connection('sakemaru')
            ->table('earnings as e')
            ->join('trade_items as ti', 'e.trade_id', '=', 'ti.trade_id')
            ->where('e.warehouse_id', $warehouseId)
            ->where('e.delivered_date', $dateStr)
            ->where('e.is_active', true)
            ->where('ti.is_active', true)
            ->selectRaw('
                SUM(ti.tax_excluded_amount) as amount_ex,
                SUM(ti.amount) as amount_in,
                SUM(CASE WHEN ti.is_container_included = 1 THEN ti.container_amount ELSE 0 END) as container_deposit
            ')
            ->first();

        $opportunityLoss = DB::connection('sakemaru')
            ->table('wms_shortages as ws')
            ->join('trade_items as ti', 'ws.trade_item_id', '=', 'ti.id')
            ->where('ws.warehouse_id', $warehouseId)
            ->where('ws.shipment_date', $dateStr)
            ->selectRaw('SUM(ws.shortage_qty * ti.price) as total_loss')
            ->value('total_loss');

        return [
            'total_amount_ex' => $amountStats->amount_ex ?? 0,
            'total_amount_in' => $amountStats->amount_in ?? 0,
            'total_container_deposit' => $amountStats->container_deposit ?? 0,
            'total_opportunity_loss' => $opportunityLoss ?? 0,
        ];
    }

    /**
     * カテゴリ別内訳を集計
     */
    private function calculateCategoryBreakdown(string $dateStr, int $warehouseId): array
    {
        if (! Schema::connection('sakemaru')->hasTable('categories')) {
            return ['categories' => []];
        }

        $categoryData = DB::connection('sakemaru')
            ->table('earnings as e')
            ->join('trade_items as ti', 'e.trade_id', '=', 'ti.trade_id')
            ->join('items as i', 'ti.item_id', '=', 'i.id')
            ->leftJoin('categories as c', 'i.category_id', '=', 'c.id')
            ->where('e.warehouse_id', $warehouseId)
            ->where('e.delivered_date', $dateStr)
            ->where('e.is_active', true)
            ->where('ti.is_active', true)
            ->groupBy('c.id', 'c.name')
            ->selectRaw('
                c.id as category_id,
                c.name as category_name,
                SUM(CAST(ti.total_piece_quantity AS UNSIGNED)) as ship_qty,
                SUM(ti.tax_excluded_amount) as amount_ex,
                SUM(ti.amount) as amount_in,
                SUM(CASE WHEN ti.is_container_included = 1 THEN ti.container_amount ELSE 0 END) as container_deposit
            ')
            ->get();

        // カテゴリ別欠品損失額
        $categoryLosses = DB::connection('sakemaru')
            ->table('wms_shortages as ws')
            ->join('wms_picking_item_results as pir', 'ws.source_pick_result_id', '=', 'pir.id')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->join('trade_items as ti', 'pir.trade_item_id', '=', 'ti.id')
            ->join('items as i', 'ws.item_id', '=', 'i.id')
            ->leftJoin('categories as c', 'i.category_id', '=', 'c.id')
            ->where('ws.warehouse_id', $warehouseId)
            ->where('ws.shipment_date', $dateStr)
            ->groupBy('c.id')
            ->selectRaw('
                c.id as category_id,
                SUM(ws.shortage_qty * ti.price) as opportunity_loss
            ')
            ->pluck('opportunity_loss', 'category_id');

        // JSONフォーマットに変換
        $categories = [];
        foreach ($categoryData as $category) {
            $categoryId = $category->category_id ?? 'unknown';
            $categories[$categoryId] = [
                'name' => $category->category_name ?? 'その他',
                'ship_qty' => (int) $category->ship_qty,
                'amount_ex' => (float) $category->amount_ex,
                'amount_in' => (float) $category->amount_in,
                'container_deposit' => (float) $category->container_deposit,
                'opportunity_loss' => (float) ($categoryLosses[$categoryId] ?? 0),
            ];
        }

        return [
            'categories' => $categories,
        ];
    }

    /**
     * @param  array<int>|null  $warehouseIds
     * @return array<string, int|float>
     */
    public function summarize(Carbon $date, ?array $warehouseIds = null, bool $forceUpdate = false): array
    {
        return $this->summarizeStats($this->statsForWarehouses($date, $warehouseIds, $forceUpdate));
    }

    /**
     * 保存済みの日次統計だけを集計する。画面表示時の自動再集計を避ける用途で使う。
     *
     * @param  array<int>|null  $warehouseIds
     * @return array<string, int|float>
     */
    public function summarizeStored(Carbon $date, ?array $warehouseIds = null): array
    {
        return $this->summarizeStats($this->storedStatsForWarehouses($date, $warehouseIds));
    }

    /**
     * @param  Collection<int, WmsDailyStat>  $stats
     * @return array<string, int|float>
     */
    private function summarizeStats(Collection $stats): array
    {
        $numericColumns = [
            'total_slip_count',
            'shipped_slip_count',
            'unshipped_slip_count',
            'unique_buyer_count',
            'picking_slip_count',
            'picking_item_count',
            'unique_item_count',
            'stockout_unique_count',
            'stockout_total_count',
            'allocation_shortage_qty',
            'confirmed_shortage_count',
            'confirmed_shortage_qty',
            'shortage_slip_count',
            'delivery_course_count',
            'wave_count',
            'picking_task_count',
            'completed_task_count',
            'shipped_task_count',
            'total_ship_qty',
            'total_order_qty',
            'total_planned_qty',
            'total_amount_ex',
            'total_amount_in',
            'total_container_deposit',
            'total_opportunity_loss',
        ];

        $summary = [];
        foreach ($numericColumns as $column) {
            $summary[$column] = (float) $stats->sum($column);
        }

        $summary['warehouse_count'] = $stats->count();

        return $summary;
    }

    /**
     * @param  array<int>|null  $warehouseIds
     * @return Collection<int, WmsDailyStat>
     */
    public function statsForWarehouses(Carbon $date, ?array $warehouseIds = null, bool $forceUpdate = false): Collection
    {
        return collect($this->targetWarehouseIds($warehouseIds))
            ->map(fn (int $warehouseId) => $this->getOrUpdateDailyStats($date, $warehouseId, $forceUpdate))
            ->values();
    }

    /**
     * @param  array<int>|null  $warehouseIds
     * @return Collection<int, WmsDailyStat>
     */
    public function storedStatsForWarehouses(Carbon $date, ?array $warehouseIds = null): Collection
    {
        return WmsDailyStat::query()
            ->where('target_date', $date->format('Y-m-d'))
            ->whereIn('warehouse_id', $this->targetWarehouseIds($warehouseIds))
            ->get()
            ->values();
    }

    /**
     * @param  array<int>|null  $warehouseIds
     * @return array<int>
     */
    private function targetWarehouseIds(?array $warehouseIds = null): array
    {
        if ($warehouseIds !== null) {
            return array_values(array_map('intval', $warehouseIds));
        }

        return DB::connection('sakemaru')
            ->table('warehouses')
            ->where('is_active', true)
            ->where('is_virtual', false)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * 複数倉庫の統計を一括で更新
     *
     * @param  array|null  $warehouseIds  nullの場合は全倉庫
     * @return int 更新した倉庫数
     */
    public function bulkCalculate(Carbon $date, ?array $warehouseIds = null): int
    {
        // 倉庫IDが指定されていない場合は、アクティブな全倉庫を対象
        if ($warehouseIds === null) {
            $warehouseIds = DB::connection('sakemaru')
                ->table('warehouses')
                ->where('is_active', true)
                ->where('is_virtual', false)
                ->pluck('id')
                ->toArray();
        }

        $successCount = 0;
        foreach ($warehouseIds as $warehouseId) {
            try {
                $this->calculate($date, $warehouseId);
                $successCount++;
            } catch (\Exception $e) {
                Log::error('Failed to calculate stats for warehouse', [
                    'warehouse_id' => $warehouseId,
                    'date' => $date->format('Y-m-d'),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $successCount;
    }
}
