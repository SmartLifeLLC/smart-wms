<?php

namespace App\Filament\Resources\Waves\Pages;

use App\Enums\QuantityType;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\Waves\WaveResource;
use App\Jobs\ProcessWaveGroupGenerationJob;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Earning;
use App\Models\Sakemaru\Location;
use App\Models\Sakemaru\Warehouse;
use App\Models\Wave;
use App\Models\WaveGroup;
use App\Models\WaveSetting;
use App\Models\WmsPickingItemResult;
use App\Models\WmsQueueProgress;
use App\Services\PickingList\PickingListPdfService;
use App\Services\StockAllocationService;
use App\Services\StockTransferLotAllocationService;
use App\Services\WarehouseResolver;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Alignment;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * 波動一覧ページ
 *
 * 波動生成モーダルでは売上伝票と倉庫移動伝票の両方を対象として表示する
 */
class ListWaves extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WaveResource::class;

    public function getTitle(): string|Htmlable
    {
        $base = '波動';
        $warehouseName = $this->getSelectedWarehouseName();

        return $warehouseName ? "{$base} ({$warehouseName})" : $base;
    }

    public function getPresetViews(): array
    {
        $userWarehouseId = auth()->user()?->getSelectedWarehouseId();
        $defaultFilterData = $userWarehouseId
            ? ['warehouse_id' => ['value' => (string) $userWarehouseId]]
            : [];

        return [
            'today' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('shipping_date', ClientSetting::systemDateYMD()))
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label('当日'),
            'default' => PresetView::make()
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label('全て'),
        ];
    }

    private function getSelectedWarehouseName(): ?string
    {
        $warehouseId = auth()->user()?->getSelectedWarehouseId();

        return $warehouseId ? Warehouse::find($warehouseId)?->name : null;
    }

    private function warehouseHasMultipleFloors(?int $warehouseId): bool
    {
        if (! $warehouseId) {
            return false;
        }

        return DB::connection('sakemaru')
            ->table('floors')
            ->where('warehouse_id', $warehouseId)
            ->limit(2)
            ->count() > 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateWave')
                ->label('出荷波動生成')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->modalHeading(fn () => '出荷波動生成 — '.($this->getSelectedWarehouseName() ?? '倉庫未選択'))
                ->modalWidth('6xl')
                ->extraModalWindowAttributes(['class' => 'wave-modal'])
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn (Action $action) => $action->label('波動を生成')->color('danger'))
                ->modalCancelActionLabel('生成せず閉じる')
                ->schema($this->getWaveSelectionSchema(
                    includeTargetDocumentTypeFilter: true,
                    includePastDefault: false,
                    allowMultipleShippingDates: true
                ))
                ->action(function (array $data): void {
                    $this->generateManualWave($data);
                }),

        ];
    }

    private function applyPickingListShippingDateScope(Builder $query, string $shippingDate): Builder
    {
        return $query->where(function (Builder $query) use ($shippingDate) {
            $query->where('wms_waves.shipping_date', $shippingDate)
                ->orWhereExists(function ($query) use ($shippingDate) {
                    $query->select(DB::raw(1))
                        ->from('wms_picking_tasks as transfer_pt')
                        ->join('wms_picking_item_results as transfer_pir', 'transfer_pir.picking_task_id', '=', 'transfer_pt.id')
                        ->join('stock_transfers as transfer_st', 'transfer_st.id', '=', 'transfer_pir.stock_transfer_id')
                        ->whereColumn('transfer_pt.wave_id', 'wms_waves.id')
                        ->whereDate('transfer_st.delivered_date', $shippingDate);
                });
        });
    }

    private function normalizeTargetDocumentTypes(mixed $targetDocumentTypes): array
    {
        if (is_string($targetDocumentTypes) && in_array($targetDocumentTypes, ['shipment', 'transfer'], true)) {
            return [$targetDocumentTypes];
        }

        if (is_array($targetDocumentTypes)) {
            $filtered = array_values(array_intersect($targetDocumentTypes, ['shipment', 'transfer']));

            return ! empty($filtered) ? $filtered : ['shipment'];
        }

        return ['shipment'];
    }

    private function includesShipmentDocuments(array $targetDocumentTypes): bool
    {
        return in_array('shipment', $targetDocumentTypes, true);
    }

    private function includesTransferDocuments(array $targetDocumentTypes): bool
    {
        return in_array('transfer', $targetDocumentTypes, true);
    }

    private function normalizeShippingDates(mixed $shippingDates): array
    {
        if (! is_array($shippingDates)) {
            $shippingDates = $shippingDates ? [$shippingDates] : [];
        }

        $dates = collect($shippingDates)
            ->filter(fn ($date): bool => is_string($date) && $date !== '')
            ->map(function (string $date): ?string {
                try {
                    return \Carbon\Carbon::parse($date)->format('Y-m-d');
                } catch (\Throwable) {
                    return null;
                }
            })
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $dates;
    }

    private function getShippingDatesFromForm(Get $get, bool $allowMultipleShippingDates): array
    {
        return $this->normalizeShippingDates(
            $allowMultipleShippingDates
                ? $get('shipping_dates')
                : $get('shipping_date')
        );
    }

    private function getShippingDatesFromData(array $data): array
    {
        return $this->normalizeShippingDates($data['shipping_dates'] ?? ($data['shipping_date'] ?? null));
    }

    private function latestShippingDate(array $shippingDates): ?string
    {
        return empty($shippingDates) ? null : max($shippingDates);
    }

    private function applyDateFilter($query, string $column, array $shippingDates, bool $includePast)
    {
        if (empty($shippingDates)) {
            return $query->whereRaw('1 = 0');
        }

        if ($includePast) {
            return $query->where($column, '<=', $this->latestShippingDate($shippingDates));
        }

        return count($shippingDates) === 1
            ? $query->where($column, $shippingDates[0])
            : $query->whereIn($column, $shippingDates);
    }

    private function applyRawDateFilter($query, string $expression, array $shippingDates, bool $includePast)
    {
        if (empty($shippingDates)) {
            return $query->whereRaw('1 = 0');
        }

        if ($includePast) {
            return $query->whereRaw("{$expression} <= ?", [$this->latestShippingDate($shippingDates)]);
        }

        return $query->where(function ($query) use ($expression, $shippingDates) {
            foreach ($shippingDates as $shippingDate) {
                $query->orWhereRaw("{$expression} = ?", [$shippingDate]);
            }
        });
    }

    private function stockTransferPickingDateExpression(): string
    {
        return 'COALESCE(st.picking_date, st.delivered_date)';
    }

    /**
     * 倉庫・出荷日・配送コース選択用のフォームスキーマ。
     * 出荷波動生成・仮ピッキングリスト出力で共有する。
     *
     * @return array<int, mixed>
     */
    protected function getWaveSelectionSchema(
        bool $includeTargetDocumentTypeFilter = false,
        bool $includePastDefault = true,
        bool $allowMultipleShippingDates = false
    ): array {
        return [
            \Filament\Forms\Components\Hidden::make('warehouse_id')
                ->default(fn () => auth()->user()?->default_warehouse_id),

            Grid::make(3)->schema([
                ...($allowMultipleShippingDates ? [
                    ViewField::make('shipping_dates')
                        ->label('出荷日（複数選択可）')
                        ->view('filament.forms.components.multi-date-input')
                        ->default(fn () => [ClientSetting::systemDate()->format('Y-m-d')])
                        ->required()
                        ->live(),
                ] : [
                    ViewField::make('shipping_date')
                        ->label('出荷日')
                        ->view('filament.forms.components.date-input')
                        ->default(fn () => ClientSetting::systemDate()->format('Y-m-d'))
                        ->required()
                        ->live(),
                ]),

                ViewField::make('generation_type')
                    ->label('生成単位')
                    ->view('filament.forms.components.wave-generation-type-tabs')
                    ->default('delivery_course')
                    ->required()
                    ->live(),

                ...($includeTargetDocumentTypeFilter ? [
                    ViewField::make('target_document_types')
                        ->label('出荷タイプ')
                        ->view('filament.forms.components.document-type-toggle')
                        ->default('shipment')
                        ->required()
                        ->live()
                        ->visible(fn (Get $get) => ($get('generation_type') ?? 'delivery_course') === 'delivery_course'),
                ] : []),
            ]),

            Grid::make(2)->schema([
                ViewField::make('delivery_course_ids')
                    ->label('配送コース')
                    ->view('filament.forms.components.checkbox-grid')
                    ->viewData(function (Get $get) use ($allowMultipleShippingDates): array {
                        $warehouseId = $get('warehouse_id');
                        $shippingDates = $this->getShippingDatesFromForm($get, $allowMultipleShippingDates);
                        $includePast = false;
                        $targetDocumentTypes = $this->normalizeTargetDocumentTypes($get('target_document_types'));

                        if (! $warehouseId || empty($shippingDates)) {
                            return ['options' => []];
                        }

                        $warehouseIds = WarehouseResolver::resolveAllWarehouseIds($warehouseId);

                        $earningCounts = collect();
                        if ($this->includesShipmentDocuments($targetDocumentTypes)) {
                            $earningQuery = DB::connection('sakemaru')
                                ->table('earnings')
                                ->join('delivery_courses', 'earnings.delivery_course_id', '=', 'delivery_courses.id')
                                ->whereIn('delivery_courses.warehouse_id', $warehouseIds)
                                ->where('earnings.is_active', true)
                                ->where('earnings.is_delivered', 0)
                                ->where('earnings.picking_status', 'BEFORE')
                                ->whereExists(fn ($query) => $this->activeTradeItemsExistsQuery($query));

                            $earningCounts = $this->applyDateFilter($earningQuery, 'earnings.delivered_date', $shippingDates, $includePast)
                                ->selectRaw('delivery_courses.id as course_id, delivery_courses.code as course_code, delivery_courses.name as course_name, COUNT(*) as count')
                                ->groupBy('delivery_courses.id', 'delivery_courses.code', 'delivery_courses.name')
                                ->get()
                                ->keyBy('course_id');
                        }

                        $stockTransferCounts = collect();
                        if ($this->includesTransferDocuments($targetDocumentTypes)) {
                            $stockTransferQuery = DB::connection('sakemaru')
                                ->table('stock_transfers as st')
                                ->join('trades as st_trade', 'st.trade_id', '=', 'st_trade.id')
                                ->join('delivery_courses as dc', 'st.delivery_course_id', '=', 'dc.id')
                                ->join('warehouses as fw', 'st.from_warehouse_id', '=', 'fw.id')
                                ->join('warehouses as tw', 'st.to_warehouse_id', '=', 'tw.id')
                                ->whereIn('st.from_warehouse_id', $warehouseIds)
                                ->whereIn('dc.warehouse_id', $warehouseIds)
                                ->where('st.is_active', true)
                                ->where('st_trade.is_active', true)
                                ->where('st.picking_status', 'BEFORE')
                                ->where('st.is_delivered', false)
                                ->where('st.is_confirmed', false)
                                ->where(function ($query) {
                                    $query->where(function ($q) {
                                        $q->where('fw.is_virtual', false)
                                            ->orWhere('tw.is_virtual', false);
                                    })
                                        ->where(function ($q) {
                                            $q->whereRaw('COALESCE(fw.stock_warehouse_id, fw.id) != COALESCE(tw.stock_warehouse_id, tw.id)');
                                        });
                                });

                            $stockTransferCounts = $this->applyRawDateFilter($stockTransferQuery, $this->stockTransferPickingDateExpression(), $shippingDates, $includePast)
                                ->selectRaw('dc.id as course_id, dc.code as course_code, dc.name as course_name, COUNT(*) as count')
                                ->groupBy('dc.id', 'dc.code', 'dc.name')
                                ->get()
                                ->keyBy('course_id');
                        }

                        $allCourseIds = $earningCounts->keys()->merge($stockTransferCounts->keys())->unique();

                        $options = [];
                        foreach ($allCourseIds as $courseId) {
                            $earningData = $earningCounts->get($courseId);
                            $stockTransferData = $stockTransferCounts->get($courseId);
                            $name = $earningData->course_name ?? $stockTransferData->course_name ?? '不明';
                            $counts = [];
                            if ($this->includesShipmentDocuments($targetDocumentTypes)) {
                                $counts[] = '出荷'.($earningData->count ?? 0).'件';
                            }
                            if ($this->includesTransferDocuments($targetDocumentTypes)) {
                                $counts[] = '移動'.($stockTransferData->count ?? 0).'件';
                            }
                            $options[] = [
                                'id' => $courseId,
                                'label' => $name.'（'.implode(' / ', $counts).'）',
                            ];
                        }

                        usort($options, fn ($a, $b) => strcmp($a['label'], $b['label']));

                        return [
                            'options' => $options,
                            'searchPlaceholder' => 'コース検索...',
                            'listMaxHeight' => '32rem',
                        ];
                    })
                    ->required()
                    ->live()
                    ->visible(fn (Get $get) => $get('warehouse_id') && ! empty($this->getShippingDatesFromForm($get, $allowMultipleShippingDates)) && ($get('generation_type') ?? 'delivery_course') === 'delivery_course'),

                ViewField::make('buyer_ids')
                    ->label('得意先')
                    ->view('filament.forms.components.checkbox-grid')
                    ->viewData(function (Get $get) use ($allowMultipleShippingDates): array {
                        $warehouseId = $get('warehouse_id');
                        $shippingDates = $this->getShippingDatesFromForm($get, $allowMultipleShippingDates);
                        $includePast = false;

                        if (! $warehouseId || empty($shippingDates)) {
                            return ['options' => []];
                        }

                        $warehouseIds = WarehouseResolver::resolveAllWarehouseIds($warehouseId);

                        $query = DB::connection('sakemaru')
                            ->table('earnings')
                            ->join('delivery_courses', 'earnings.delivery_course_id', '=', 'delivery_courses.id')
                            ->join('buyers', 'earnings.buyer_id', '=', 'buyers.id')
                            ->join('partners', 'buyers.partner_id', '=', 'partners.id')
                            ->whereIn('delivery_courses.warehouse_id', $warehouseIds)
                            ->where('earnings.is_active', true)
                            ->where('earnings.is_delivered', 0)
                            ->where('earnings.picking_status', 'BEFORE')
                            ->whereNotNull('earnings.buyer_id')
                            ->whereExists(fn ($query) => $this->activeTradeItemsExistsQuery($query));

                        $options = $this->applyDateFilter($query, 'earnings.delivered_date', $shippingDates, $includePast)
                            ->selectRaw('buyers.id as buyer_id, partners.code as partner_code, partners.name as partner_name, COUNT(*) as count')
                            ->groupBy('buyers.id', 'partners.code', 'partners.name')
                            ->orderBy('partners.code')
                            ->get()
                            ->map(fn ($row) => [
                                'id' => $row->buyer_id,
                                'label' => "[{$row->partner_code}] {$row->partner_name} ({$row->count}件)",
                            ])
                            ->values()
                            ->toArray();

                        return [
                            'options' => $options,
                            'searchPlaceholder' => '得意先検索...',
                            'listMaxHeight' => '32rem',
                        ];
                    })
                    ->required()
                    ->live()
                    ->visible(fn (Get $get) => $get('warehouse_id') && ! empty($this->getShippingDatesFromForm($get, $allowMultipleShippingDates)) && $get('generation_type') === 'buyer'),

                Placeholder::make('earnings_preview')
                    ->label('対象伝票')
                    ->content(function (Get $get) use ($allowMultipleShippingDates): HtmlString {
                        $warehouseId = $get('warehouse_id');
                        $shippingDates = $this->getShippingDatesFromForm($get, $allowMultipleShippingDates);
                        $deliveryCourseIds = $get('delivery_course_ids');
                        $includePast = false;
                        $targetDocumentTypes = $this->normalizeTargetDocumentTypes($get('target_document_types'));

                        if (! $warehouseId || empty($shippingDates)) {
                            return new HtmlString('<div class="flex flex-col items-center justify-center py-8 text-slate-400 dark:text-gray-500"><i class="fa fa-warehouse text-2xl mb-2"></i><p class="text-sm">倉庫と出荷日を選択してください</p></div>');
                        }

                        if (empty($deliveryCourseIds)) {
                            return new HtmlString('<div class="flex flex-col items-center justify-center py-8 text-slate-400 dark:text-gray-500"><i class="fa fa-route text-2xl mb-2"></i><p class="text-sm">配送コースを選択してください</p></div>');
                        }

                        $warehouseIds = WarehouseResolver::resolveAllWarehouseIds($warehouseId);

                        $earningSummary = collect();
                        if ($this->includesShipmentDocuments($targetDocumentTypes)) {
                            $earningQuery = DB::connection('sakemaru')
                                ->table('earnings')
                                ->join('delivery_courses', 'earnings.delivery_course_id', '=', 'delivery_courses.id')
                                ->whereIn('delivery_courses.warehouse_id', $warehouseIds)
                                ->where('earnings.is_active', true)
                                ->where('earnings.is_delivered', 0)
                                ->where('earnings.picking_status', 'BEFORE')
                                ->whereIn('earnings.delivery_course_id', $deliveryCourseIds)
                                ->whereExists(fn ($query) => $this->activeTradeItemsExistsQuery($query));

                            $earningSummary = $this->applyDateFilter($earningQuery, 'earnings.delivered_date', $shippingDates, $includePast)
                                ->selectRaw('delivery_courses.id as course_id, delivery_courses.name as course_name, COUNT(*) as count')
                                ->groupBy('delivery_courses.id', 'delivery_courses.name')
                                ->orderBy('delivery_courses.name')
                                ->get()
                                ->keyBy('course_id');
                        }

                        $stockTransferSummary = collect();
                        if ($this->includesTransferDocuments($targetDocumentTypes)) {
                            $stockTransferQuery = DB::connection('sakemaru')
                                ->table('stock_transfers as st')
                                ->join('trades as st_trade', 'st.trade_id', '=', 'st_trade.id')
                                ->join('delivery_courses as dc', 'st.delivery_course_id', '=', 'dc.id')
                                ->join('warehouses as fw', 'st.from_warehouse_id', '=', 'fw.id')
                                ->join('warehouses as tw', 'st.to_warehouse_id', '=', 'tw.id')
                                ->whereIn('st.from_warehouse_id', $warehouseIds)
                                ->whereIn('dc.warehouse_id', $warehouseIds)
                                ->where('st.is_active', true)
                                ->where('st_trade.is_active', true)
                                ->where('st.picking_status', 'BEFORE')
                                ->where('st.is_delivered', false)
                                ->where('st.is_confirmed', false)
                                ->whereIn('st.delivery_course_id', $deliveryCourseIds)
                                ->where(function ($query) {
                                    $query->where(function ($q) {
                                        $q->where('fw.is_virtual', false)
                                            ->orWhere('tw.is_virtual', false);
                                    })
                                        ->where(function ($q) {
                                            $q->whereRaw('COALESCE(fw.stock_warehouse_id, fw.id) != COALESCE(tw.stock_warehouse_id, tw.id)');
                                        });
                                });

                            $stockTransferSummary = $this->applyRawDateFilter($stockTransferQuery, $this->stockTransferPickingDateExpression(), $shippingDates, $includePast)
                                ->selectRaw('dc.id as course_id, dc.name as course_name, COUNT(*) as count')
                                ->groupBy('dc.id', 'dc.name')
                                ->orderBy('dc.name')
                                ->get()
                                ->keyBy('course_id');
                        }

                        if ($earningSummary->isEmpty() && $stockTransferSummary->isEmpty()) {
                            return new HtmlString('<div class="flex flex-col items-center justify-center py-8 text-slate-400 dark:text-gray-500"><i class="fa fa-file-alt text-2xl mb-2"></i><p class="text-sm">選択した配送コースに対象伝票がありません</p></div>');
                        }

                        $allCourseIds = $earningSummary->keys()->merge($stockTransferSummary->keys())->unique();
                        $mergedSummary = [];
                        foreach ($allCourseIds as $courseId) {
                            $earningData = $earningSummary->get($courseId);
                            $stockTransferData = $stockTransferSummary->get($courseId);
                            $courseName = $earningData->course_name ?? $stockTransferData->course_name ?? '不明';
                            $mergedSummary[] = [
                                'course_name' => $courseName,
                                'earning_count' => $earningData->count ?? 0,
                                'stock_transfer_count' => $stockTransferData->count ?? 0,
                            ];
                        }

                        usort($mergedSummary, fn ($a, $b) => strcmp($a['course_name'], $b['course_name']));

                        $totalEarningCount = $earningSummary->sum('count');
                        $totalStockTransferCount = $stockTransferSummary->sum('count');
                        $totalCount = $totalEarningCount + $totalStockTransferCount;

                        $html = '<div class="space-y-3">';
                        $html .= '<div class="border border-slate-200 dark:border-gray-700 rounded-lg overflow-hidden">';
                        $html .= '<div class="overflow-y-auto" style="max-height: 28rem">';
                        $html .= '<table class="w-full text-sm">';
                        $html .= '<thead class="bg-slate-50 dark:bg-gray-900 sticky top-0 z-10">';
                        $html .= '<tr>';
                        $html .= '<th class="px-3 py-2 text-left text-xs font-medium text-slate-600 dark:text-gray-400">配送コース</th>';
                        $html .= '<th class="px-3 py-2 text-right text-xs font-medium text-slate-600 dark:text-gray-400">売上伝票</th>';
                        $html .= '<th class="px-3 py-2 text-right text-xs font-medium text-slate-600 dark:text-gray-400">移動伝票</th>';
                        $html .= '<th class="px-3 py-2 text-right text-xs font-medium text-slate-600 dark:text-gray-400">合計</th>';
                        $html .= '</tr></thead>';
                        $html .= '<tbody class="divide-y divide-slate-200 dark:divide-gray-700">';

                        foreach ($mergedSummary as $row) {
                            $rowTotal = $row['earning_count'] + $row['stock_transfer_count'];
                            $html .= '<tr class="hover:bg-slate-50 dark:hover:bg-gray-700 transition-colors">';
                            $html .= "<td class=\"px-3 py-2 text-sm text-slate-700 dark:text-gray-300\">{$row['course_name']}</td>";
                            $html .= "<td class=\"px-3 py-2 text-sm text-right text-slate-700 dark:text-gray-300\">{$row['earning_count']}件</td>";
                            $html .= "<td class=\"px-3 py-2 text-sm text-right text-purple-600 dark:text-purple-400\">{$row['stock_transfer_count']}件</td>";
                            $html .= "<td class=\"px-3 py-2 text-sm text-right font-bold text-slate-800 dark:text-gray-200\">{$rowTotal}件</td>";
                            $html .= '</tr>';
                        }

                        $html .= '</tbody></table></div></div>';
                        $html .= '<div class="flex justify-between items-center p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-100 dark:border-blue-800">';
                        $html .= '<span class="text-sm font-bold text-slate-700 dark:text-gray-200">合計</span>';
                        $html .= '<div class="flex items-center gap-4">';
                        $html .= '<span class="text-xs text-slate-500 dark:text-gray-400">売上: <span class="font-bold text-slate-700 dark:text-gray-200">'.$totalEarningCount.'件</span></span>';
                        $html .= '<span class="text-xs text-purple-500 dark:text-purple-400">移動: <span class="font-bold">'.$totalStockTransferCount.'件</span></span>';
                        $html .= '<span class="text-lg font-bold text-blue-600 dark:text-blue-400">'.$totalCount.'件</span>';
                        $html .= '</div>';
                        $html .= '</div>';

                        if ($totalCount > 100) {
                            $html .= '<div class="flex items-center gap-2 p-3 bg-yellow-50 dark:bg-yellow-900/20 rounded-lg border border-yellow-200 dark:border-yellow-800 text-yellow-700 dark:text-yellow-400 text-xs">';
                            $html .= '<i class="fa fa-exclamation-triangle"></i>';
                            $html .= '<span><span class="font-bold">注意:</span> 伝票数が多いため、生成に時間がかかる場合があります。</span>';
                            $html .= '</div>';
                        }

                        $html .= '</div>';

                        return new HtmlString($html);
                    })
                    ->visible(fn (Get $get) => ($get('generation_type') ?? 'delivery_course') === 'delivery_course'),

                Placeholder::make('buyer_earnings_preview')
                    ->label('対象伝票')
                    ->content(function (Get $get) use ($allowMultipleShippingDates): HtmlString {
                        $warehouseId = $get('warehouse_id');
                        $shippingDates = $this->getShippingDatesFromForm($get, $allowMultipleShippingDates);
                        $buyerIds = $get('buyer_ids');
                        $includePast = false;

                        if (! $warehouseId || empty($shippingDates)) {
                            return new HtmlString('<div class="flex flex-col items-center justify-center py-8 text-slate-400 dark:text-gray-500"><i class="fa fa-warehouse text-2xl mb-2"></i><p class="text-sm">倉庫と出荷日を選択してください</p></div>');
                        }

                        if (empty($buyerIds)) {
                            return new HtmlString('<div class="flex flex-col items-center justify-center py-8 text-slate-400 dark:text-gray-500"><i class="fa fa-user-group text-2xl mb-2"></i><p class="text-sm">得意先を選択してください</p></div>');
                        }

                        $warehouseIds = WarehouseResolver::resolveAllWarehouseIds($warehouseId);

                        $query = DB::connection('sakemaru')
                            ->table('earnings')
                            ->join('delivery_courses', 'earnings.delivery_course_id', '=', 'delivery_courses.id')
                            ->join('buyers', 'earnings.buyer_id', '=', 'buyers.id')
                            ->join('partners', 'buyers.partner_id', '=', 'partners.id')
                            ->whereIn('delivery_courses.warehouse_id', $warehouseIds)
                            ->where('earnings.is_active', true)
                            ->where('earnings.is_delivered', 0)
                            ->where('earnings.picking_status', 'BEFORE')
                            ->whereIn('earnings.buyer_id', $buyerIds)
                            ->whereExists(fn ($query) => $this->activeTradeItemsExistsQuery($query));

                        $summary = $this->applyDateFilter($query, 'earnings.delivered_date', $shippingDates, $includePast)
                            ->selectRaw('buyers.id as buyer_id, partners.code as partner_code, partners.name as partner_name, delivery_courses.name as course_name, COUNT(*) as count')
                            ->groupBy('buyers.id', 'partners.code', 'partners.name', 'delivery_courses.name')
                            ->orderBy('partners.code')
                            ->orderBy('delivery_courses.name')
                            ->get();

                        if ($summary->isEmpty()) {
                            return new HtmlString('<div class="flex flex-col items-center justify-center py-8 text-slate-400 dark:text-gray-500"><i class="fa fa-file-alt text-2xl mb-2"></i><p class="text-sm">選択した得意先に対象伝票がありません</p></div>');
                        }

                        $totalCount = $summary->sum('count');

                        $html = '<div class="space-y-3">';
                        $html .= '<div class="border border-slate-200 dark:border-gray-700 rounded-lg overflow-hidden">';
                        $html .= '<div class="overflow-y-auto" style="max-height: 28rem">';
                        $html .= '<table class="w-full text-sm">';
                        $html .= '<thead class="bg-slate-50 dark:bg-gray-900 sticky top-0 z-10">';
                        $html .= '<tr>';
                        $html .= '<th class="px-3 py-2 text-left text-xs font-medium text-slate-600 dark:text-gray-400">得意先</th>';
                        $html .= '<th class="px-3 py-2 text-left text-xs font-medium text-slate-600 dark:text-gray-400">配送コース</th>';
                        $html .= '<th class="px-3 py-2 text-right text-xs font-medium text-slate-600 dark:text-gray-400">売上伝票</th>';
                        $html .= '</tr></thead>';
                        $html .= '<tbody class="divide-y divide-slate-200 dark:divide-gray-700">';

                        foreach ($summary as $row) {
                            $buyerLabel = "[{$row->partner_code}] {$row->partner_name}";
                            $html .= '<tr class="hover:bg-slate-50 dark:hover:bg-gray-700 transition-colors">';
                            $html .= "<td class=\"px-3 py-2 text-sm text-slate-700 dark:text-gray-300\">{$buyerLabel}</td>";
                            $html .= "<td class=\"px-3 py-2 text-sm text-slate-700 dark:text-gray-300\">{$row->course_name}</td>";
                            $html .= "<td class=\"px-3 py-2 text-sm text-right font-bold text-slate-800 dark:text-gray-200\">{$row->count}件</td>";
                            $html .= '</tr>';
                        }

                        $html .= '</tbody></table></div></div>';
                        $html .= '<div class="flex justify-between items-center p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-100 dark:border-blue-800">';
                        $html .= '<span class="text-sm font-bold text-slate-700 dark:text-gray-200">合計</span>';
                        $html .= '<span class="text-lg font-bold text-blue-600 dark:text-blue-400">'.$totalCount.'件</span>';
                        $html .= '</div>';
                        $html .= '</div>';

                        return new HtmlString($html);
                    })
                    ->visible(fn (Get $get) => $get('generation_type') === 'buyer'),
            ]),
        ];
    }

    protected function generateManualWave(array $data): void
    {
        try {
            $shippingDates = $this->getShippingDatesFromData($data);

            if (empty($shippingDates)) {
                Notification::make()
                    ->title('出荷日を選択してください')
                    ->warning()
                    ->send();

                return;
            }

            $userId = auth()->id() ?? 1;
            $managementShippingDate = $this->latestShippingDate($shippingDates);
            $targetDocumentTypes = $this->normalizeTargetDocumentTypes($data['target_document_types'] ?? null);

            $waveGroup = $this->createWaveGroupForManualGeneration(
                $data,
                $managementShippingDate,
                $targetDocumentTypes,
                $userId
            );

            $progress = WmsQueueProgress::createJob(WmsQueueProgress::JOB_TYPE_WAVE_GENERATION, $userId, [
                'wave_group_id' => $waveGroup->id,
                'group_no' => $waveGroup->group_no,
                'warehouse_id' => $waveGroup->warehouse_id,
                'shipping_dates' => $shippingDates,
            ]);

            ProcessWaveGroupGenerationJob::dispatch($waveGroup->id, $progress->job_id, $userId);

            Notification::make()
                ->title('波動生成を開始しました')
                ->body("生成グループ: {$waveGroup->group_no}。完了まで画面を開いたまま待つ必要はありません。")
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('波動生成に失敗しました')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    private function createWaveGroupForManualGeneration(
        array $data,
        string $managementShippingDate,
        array $targetDocumentTypes,
        int $userId
    ): WaveGroup {
        $conditions = $data;
        $conditions['include_past'] = (bool) ($data['include_past'] ?? false);
        $conditions['shipping_dates'] = $this->getShippingDatesFromData($data);
        $conditions['target_document_types'] = $targetDocumentTypes;

        for ($i = 0; $i < 5; $i++) {
            try {
                return WaveGroup::create([
                    'group_no' => WaveGroup::generateGroupNo($managementShippingDate),
                    'warehouse_id' => (int) $data['warehouse_id'],
                    'shipping_date' => $managementShippingDate,
                    'generation_type' => $data['generation_type'] ?? 'delivery_course',
                    'target_document_types' => $targetDocumentTypes,
                    'conditions' => $conditions,
                    'created_by' => $userId,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($i === 4) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('波動生成グループ番号の採番に失敗しました');
    }

    /**
     * 売上伝票に有効な商品明細があることを保証する。
     */
    protected function activeTradeItemsExistsQuery($query)
    {
        return $query
            ->select(DB::raw(1))
            ->from('trade_items as active_trade_items')
            ->join('trades as active_trades', 'active_trade_items.trade_id', '=', 'active_trades.id')
            ->whereColumn('active_trade_items.trade_id', 'earnings.trade_id')
            ->where('active_trade_items.is_active', true)
            ->where('active_trades.is_active', true);
    }

    /**
     * 配送コース選択データから波動を作成し、作成された wave_id 配列と件数を返す。
     * 通知は送らない。呼び出し側でハンドリングする。
     *
     * @return array{wave_ids: array<int>, earning_count: int, stock_transfer_count: int}
     */
    protected function createWavesFromCourses(array $data): array
    {
        $warehouseId = $data['warehouse_id'];
        $shippingDates = $this->getShippingDatesFromData($data);
        $generationType = $data['generation_type'] ?? 'delivery_course';
        $deliveryCourseIds = $data['delivery_course_ids'] ?? [];
        $buyerIds = $data['buyer_ids'] ?? [];
        $includePast = false;
        $targetDocumentTypes = $this->normalizeTargetDocumentTypes($data['target_document_types'] ?? null);

        if (empty($shippingDates)) {
            return ['wave_ids' => [], 'earning_count' => 0, 'stock_transfer_count' => 0];
        }

        $warehouseIds = WarehouseResolver::resolveAllWarehouseIds($warehouseId);

        if ($generationType === 'buyer') {
            $earningQuery = Earning::query()
                ->join('delivery_courses', 'earnings.delivery_course_id', '=', 'delivery_courses.id')
                ->whereIn('delivery_courses.warehouse_id', $warehouseIds)
                ->where('earnings.is_active', true)
                ->where('earnings.is_delivered', 0)
                ->where('earnings.picking_status', 'BEFORE')
                ->whereNotNull('earnings.delivery_course_id')
                ->whereIn('earnings.buyer_id', $buyerIds)
                ->whereExists(fn ($query) => $this->activeTradeItemsExistsQuery($query))
                ->select('earnings.*');

            $earnings = $this->applyDateFilter($earningQuery, 'earnings.delivered_date', $shippingDates, $includePast)
                ->get();

            $stockTransfers = collect();
        } else {
            $validCourseIds = DB::connection('sakemaru')
                ->table('delivery_courses')
                ->whereIn('warehouse_id', $warehouseIds)
                ->whereIn('id', $deliveryCourseIds)
                ->pluck('id')
                ->toArray();

            $earnings = collect();
            if ($this->includesShipmentDocuments($targetDocumentTypes)) {
                $earningQuery = Earning::query()
                    ->where('is_delivered', 0)
                    ->where('is_active', true)
                    ->where('picking_status', 'BEFORE')
                    ->whereNotNull('delivery_course_id')
                    ->whereIn('delivery_course_id', $validCourseIds)
                    ->whereExists(fn ($query) => $this->activeTradeItemsExistsQuery($query));

                $earnings = $this->applyDateFilter($earningQuery, 'delivered_date', $shippingDates, $includePast)
                    ->get();
            }

            $stockTransfers = collect();
            if ($this->includesTransferDocuments($targetDocumentTypes)) {
                $stockTransfers = $this->getEligibleStockTransfersQuery($shippingDates, $warehouseId, $includePast)
                    ->whereIn('st.delivery_course_id', $validCourseIds)
                    ->get();
            }
        }

        if ($earnings->isEmpty() && $stockTransfers->isEmpty()) {
            return ['wave_ids' => [], 'earning_count' => 0, 'stock_transfer_count' => 0];
        }

        $earningsByShippingDateAndDeliveryCourse = $earnings->groupBy(
            fn ($earning): string => \Carbon\Carbon::parse($earning->delivered_date)->format('Y-m-d').'|'.$earning->delivery_course_id
        );
        $generationShippingDate = \Carbon\Carbon::parse($data['shipping_date'] ?? $this->latestShippingDate($shippingDates))->format('Y-m-d');
        $stockTransfersByShippingDateAndDeliveryCourse = $stockTransfers->groupBy(
            fn ($stockTransfer): string => $generationShippingDate.'|'.$stockTransfer->delivery_course_id
        );

        $shippingDateAndDeliveryCourseKeys = $earningsByShippingDateAndDeliveryCourse->keys()
            ->merge($stockTransfersByShippingDateAndDeliveryCourse->keys())
            ->unique()
            ->sort()
            ->values();

        $createdWaveIds = [];
        $totalEarnings = 0;
        $totalStockTransfers = 0;

        DB::connection('sakemaru')->transaction(function () use (
            $warehouseId,
            $shippingDateAndDeliveryCourseKeys,
            $earningsByShippingDateAndDeliveryCourse,
            $stockTransfersByShippingDateAndDeliveryCourse,
            &$createdWaveIds,
            &$totalEarnings,
            &$totalStockTransfers
        ) {
            $warehouse = DB::connection('sakemaru')
                ->table('warehouses')
                ->where('id', $warehouseId)
                ->first();

            foreach ($shippingDateAndDeliveryCourseKeys as $key) {
                [$shippingDate, $deliveryCourseId] = explode('|', (string) $key, 2);
                $deliveryCourseId = (int) $deliveryCourseId;
                $courseEarnings = $earningsByShippingDateAndDeliveryCourse->get($key, collect());
                $courseStockTransfers = $stockTransfersByShippingDateAndDeliveryCourse->get($key, collect());

                $waveSetting = WaveSetting::where('delivery_course_id', $deliveryCourseId)
                    ->first();

                if (! $waveSetting) {
                    $waveSetting = WaveSetting::create([
                        'delivery_course_id' => $deliveryCourseId,
                        'picking_start_time' => null,
                        'picking_deadline_time' => null,
                        'creator_id' => auth()->id() ?? 1,
                        'last_updater_id' => auth()->id() ?? 1,
                    ]);
                }

                $course = DB::connection('sakemaru')
                    ->table('delivery_courses')
                    ->where('id', $deliveryCourseId)
                    ->first();

                $wave = $this->createWaveSafely($waveSetting, $warehouse, $course, $shippingDate);

                if ($courseEarnings->isNotEmpty()) {
                    $this->processEarningsForWave($wave, $waveSetting, $courseEarnings, $warehouse, $course, $shippingDate);
                    $totalEarnings += $courseEarnings->count();
                }

                if ($courseStockTransfers->isNotEmpty()) {
                    $this->processStockTransfersForWave($wave, $waveSetting, $courseStockTransfers, $warehouse, $course, $shippingDate);
                    $totalStockTransfers += $courseStockTransfers->count();
                }

                $createdWaveIds[] = $wave->id;
            }
        });

        return [
            'wave_ids' => $createdWaveIds,
            'earning_count' => $totalEarnings,
            'stock_transfer_count' => $totalStockTransfers,
        ];
    }

    protected function createWaveSafely(WaveSetting $waveSetting, $warehouse, $course, string $shippingDate): Wave
    {
        $wave = Wave::create([
            'wms_wave_setting_id' => $waveSetting->id,
            'wave_no' => uniqid('TEMP_'),
            'shipping_date' => $shippingDate,
            'status' => 'PENDING',
        ]);

        $waveNo = Wave::generateWaveNo(
            $warehouse->code ?? 0,
            $course->code ?? 0,
            $shippingDate,
            $wave->id
        );
        $wave->update(['wave_no' => $waveNo]);

        return $wave;
    }

    /**
     * 仮ピッキングリストを生成する。
     * 波動を生成せず、対象伝票からPDF出力用データを組み立てる。
     * リスト種別を複数選択した場合は ZIP にまとめてダウンロード。
     */
    protected function generateProvisionalPickingListPdf(array $data)
    {
        // 大量伝票×フロア分割×複数種別の場合、PDF生成で多くのメモリと時間を要するため拡張
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        $shippingDate = $data['shipping_date'];
        $listTypes = $data['list_types'] ?? ['primary'];
        if (! is_array($listTypes)) {
            $listTypes = [$listTypes];
        }
        $listTypes = array_values(array_unique(array_filter($listTypes, fn ($v) => is_string($v) && $v !== '')));
        if (in_array('primary', $listTypes, true) && in_array('primary_total', $listTypes, true)) {
            $listTypes = array_values(array_filter($listTypes, fn (string $listType): bool => $listType !== 'primary'));
        }

        if (empty($listTypes)) {
            Notification::make()->title('リスト種別を選択してください')->warning()->send();

            return null;
        }

        try {
            $rows = $this->buildProvisionalPickingRows($data);

            if ($rows->isEmpty()) {
                Notification::make()
                    ->title('対象伝票がありません')
                    ->warning()
                    ->send();

                return null;
            }

            $pdfService = new PickingListPdfService;
            $separateFloors = $data['separate_floors'] ?? true;

            $pdfs = [];
            foreach ($listTypes as $listType) {
                $pdfs[$listType] = $this->renderProvisionalListPdf(
                    $listType,
                    $rows,
                    $separateFloors,
                    $pdfService,
                    $shippingDate
                );
            }

            $dateStr = str_replace('-', '', $shippingDate);

            // 1種類のみ → 単一PDFをそのままダウンロード
            if (count($pdfs) === 1) {
                $listType = array_key_first($pdfs);
                $pdf = $pdfs[$listType];
                $filename = "provisional-picking-list-{$listType}-{$dateStr}.pdf";

                return response()->streamDownload(
                    fn () => print ($pdf),
                    $filename,
                    ['Content-Type' => 'application/pdf']
                );
            }

            // 複数種別 → ZIP
            $zipPath = tempnam(sys_get_temp_dir(), 'picking-list-').'.zip';
            $zip = new \ZipArchive;
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('ZIPファイルの作成に失敗しました');
            }
            foreach ($pdfs as $listType => $pdf) {
                $zip->addFromString("provisional-picking-list-{$listType}-{$dateStr}.pdf", $pdf);
            }
            $zip->close();

            $zipFilename = "provisional-picking-lists-{$dateStr}.zip";

            return response()->streamDownload(
                function () use ($zipPath) {
                    readfile($zipPath);
                    @unlink($zipPath);
                },
                $zipFilename,
                ['Content-Type' => 'application/zip']
            );
        } catch (\Exception $e) {
            Notification::make()
                ->title('仮ピッキングリスト生成に失敗しました')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return null;
        }
    }

    /**
     * 単一のリスト種別についてPDFバイト列を返す。
     */
    private function renderProvisionalListPdf(
        string $listType,
        \Illuminate\Support\Collection $rows,
        bool $separateFloors,
        PickingListPdfService $pdfService,
        string $shippingDate
    ): string {
        return match ($listType) {
            'primary' => $pdfService->renderBatchPrimaryPdf(
                $this->splitPrimaryPages($this->buildProvisionalPrimaryList($rows, $shippingDate), $separateFloors)
            ),
            'primary_total' => $pdfService->renderBatchPrimaryPdf(
                $this->splitPrimaryPages($this->buildProvisionalPrimaryList($rows, $shippingDate, '1次ピッキングリスト(一括)'), $separateFloors)
            ),
            'shortage' => $pdfService->renderBatchShortagePdf(
                [$this->buildProvisionalShortageList($rows, $shippingDate)]
            ),
            'secondary' => $pdfService->renderCourseGroupedPdf(
                $this->buildProvisionalCourseGroupedLists($rows)
            ),
            'secondary_v2' => $pdfService->renderCourseGroupedPdf(
                $this->buildProvisionalCourseGroupedListsV2($rows)
            ),
            'tertiary' => $pdfService->renderBuyerGroupedPdf(
                $this->buildProvisionalBuyerGroupedLists($rows, $shippingDate)
            ),
            default => throw new \InvalidArgumentException("不明なリスト種別: {$listType}"),
        };
    }

    private function searchItemsForProvisionalFilter(string $search): array
    {
        $normalized = mb_convert_kana($search, 'as');

        return DB::connection('sakemaru')
            ->table('items as i')
            ->leftJoin('srh_searchable_items as ssi', 'ssi.item_id', '=', 'i.id')
            ->where(function ($query) use ($search, $normalized) {
                $query
                    ->where('i.code', 'like', "%{$normalized}%")
                    ->orWhere('i.name', 'like', "%{$search}%")
                    ->orWhere('i.name', 'like', "%{$normalized}%")
                    ->orWhere('ssi.jancode', 'like', "%{$normalized}%");
            })
            ->orderBy('i.code')
            ->limit(50)
            ->selectRaw("i.id, CONCAT('[', i.code, '] ', i.name) as label")
            ->pluck('label', 'id')
            ->toArray();
    }

    private function getItemLabelsForProvisionalFilter(array $values): array
    {
        $ids = collect($values)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (empty($ids)) {
            return [];
        }

        return DB::connection('sakemaru')
            ->table('items')
            ->whereIn('id', $ids)
            ->orderBy('code')
            ->selectRaw("id, CONCAT('[', code, '] ', name) as label")
            ->pluck('label', 'id')
            ->toArray();
    }

    private function buildProvisionalPickingRows(array $data): \Illuminate\Support\Collection
    {
        $warehouseId = $data['warehouse_id'];
        $shippingDate = $data['shipping_date'];
        $includePast = false;
        $dateOperator = $includePast ? '<=' : '=';
        $warehouseIds = WarehouseResolver::resolveAllWarehouseIds($warehouseId);
        $generationType = $data['generation_type'] ?? 'delivery_course';
        $filterItemIds = collect($data['filter_item_ids'] ?? [])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($generationType === 'buyer') {
            $earnings = Earning::query()
                ->join('delivery_courses as dc', 'earnings.delivery_course_id', '=', 'dc.id')
                ->leftJoin('warehouses as wh', 'dc.warehouse_id', '=', 'wh.id')
                ->leftJoin('buyers as b', 'earnings.buyer_id', '=', 'b.id')
                ->leftJoin('partners as p', 'b.partner_id', '=', 'p.id')
                ->leftJoin('trades as t', 'earnings.trade_id', '=', 't.id')
                ->whereIn('dc.warehouse_id', $warehouseIds)
                ->where('earnings.delivered_date', $dateOperator, $shippingDate)
                ->where('earnings.is_active', true)
                ->where('earnings.is_delivered', 0)
                ->where('earnings.picking_status', 'BEFORE')
                ->whereNotNull('earnings.delivery_course_id')
                ->whereIn('earnings.buyer_id', $data['buyer_ids'] ?? [])
                ->whereExists(fn ($query) => $this->activeTradeItemsExistsQuery($query))
                ->select([
                    'earnings.id',
                    'earnings.trade_id',
                    'earnings.buyer_id',
                    'earnings.delivered_date',
                    'dc.id as course_id',
                    'dc.code as course_code',
                    'dc.name as course_name',
                    'dc.warehouse_id',
                    'wh.name as warehouse_name',
                    'p.code as buyer_code',
                    'p.name as buyer_name',
                    't.slip_number',
                ])
                ->get();
            $stockTransfers = collect();
        } else {
            $validCourseIds = DB::connection('sakemaru')
                ->table('delivery_courses')
                ->whereIn('warehouse_id', $warehouseIds)
                ->whereIn('id', $data['delivery_course_ids'] ?? [])
                ->pluck('id')
                ->toArray();

            $earnings = Earning::query()
                ->join('delivery_courses as dc', 'earnings.delivery_course_id', '=', 'dc.id')
                ->leftJoin('warehouses as wh', 'dc.warehouse_id', '=', 'wh.id')
                ->leftJoin('buyers as b', 'earnings.buyer_id', '=', 'b.id')
                ->leftJoin('partners as p', 'b.partner_id', '=', 'p.id')
                ->leftJoin('trades as t', 'earnings.trade_id', '=', 't.id')
                ->where('earnings.delivered_date', $dateOperator, $shippingDate)
                ->where('earnings.is_delivered', 0)
                ->where('earnings.picking_status', 'BEFORE')
                ->whereNotNull('earnings.delivery_course_id')
                ->whereIn('earnings.delivery_course_id', $validCourseIds)
                ->whereExists(fn ($query) => $this->activeTradeItemsExistsQuery($query))
                ->select([
                    'earnings.id',
                    'earnings.trade_id',
                    'earnings.buyer_id',
                    'earnings.delivered_date',
                    'dc.id as course_id',
                    'dc.code as course_code',
                    'dc.name as course_name',
                    'dc.warehouse_id',
                    'wh.name as warehouse_name',
                    'p.code as buyer_code',
                    'p.name as buyer_name',
                    't.slip_number',
                ])
                ->get();

            $stockTransfers = $this->getEligibleStockTransfersQuery($shippingDate, $warehouseId, $includePast)
                ->leftJoin('warehouses as wh', 'dc.warehouse_id', '=', 'wh.id')
                ->whereIn('st.delivery_course_id', $validCourseIds)
                ->addSelect([
                    'dc.id as course_id',
                    'dc.code as course_code',
                    'dc.name as course_name',
                    'dc.warehouse_id',
                    'wh.name as warehouse_name',
                    'tw.code as buyer_code',
                    DB::raw("CONCAT('【移動】', tw.name) as buyer_name"),
                ])
                ->get();
        }

        if (! empty($filterItemIds)) {
            $matchedTradeIds = DB::connection('sakemaru')
                ->table('trade_items')
                ->whereIn('item_id', $filterItemIds)
                ->where('is_active', true)
                ->whereIn('trade_id', $earnings->pluck('trade_id')
                    ->merge($stockTransfers->pluck('trade_id'))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all())
                ->pluck('trade_id')
                ->all();

            $matchedCourseIds = $earnings
                ->whereIn('trade_id', $matchedTradeIds)
                ->pluck('course_id')
                ->merge($stockTransfers->whereIn('trade_id', $matchedTradeIds)->pluck('course_id'))
                ->unique()
                ->values();

            $earnings = $earnings
                ->whereIn('course_id', $matchedCourseIds)
                ->values();
            $stockTransfers = $stockTransfers
                ->whereIn('course_id', $matchedCourseIds)
                ->values();
        }

        $sourcesByTradeId = [];
        foreach ($earnings as $earning) {
            $sourcesByTradeId[$earning->trade_id][] = ['type' => 'EARNING', 'record' => $earning];
        }
        foreach ($stockTransfers as $stockTransfer) {
            $sourcesByTradeId[$stockTransfer->trade_id][] = ['type' => 'STOCK_TRANSFER', 'record' => $stockTransfer];
        }

        if (empty($sourcesByTradeId)) {
            return collect();
        }

        $tradeItems = DB::connection('sakemaru')
            ->table('trade_items as ti')
            ->join('items as i', 'ti.item_id', '=', 'i.id')
            ->join('trades as ti_trade', 'ti.trade_id', '=', 'ti_trade.id')
            ->leftJoin('srh_searchable_items as ssi', 'ssi.item_id', '=', 'i.id')
            ->whereIn('ti.trade_id', array_keys($sourcesByTradeId))
            ->where('ti.is_active', true)
            ->select([
                'ti.id',
                'ti.trade_id',
                'ti.item_id',
                'ti.quantity',
                'ti.quantity_type',
                'i.code as item_code',
                'i.name as item_name',
                'i.capacity_case',
                'i.packaging',
                'ssi.jancode as jan_code',
            ])
            ->orderBy('ti_trade.serial_id')
            ->orderBy('ti.id')
            ->get();

        $rows = collect();
        $stockLots = [];

        foreach ($tradeItems as $tradeItem) {
            foreach ($sourcesByTradeId[$tradeItem->trade_id] ?? [] as $source) {
                $record = $source['record'];
                $quantityType = $tradeItem->quantity_type ?: 'PIECE';
                $allocations = $this->allocateProvisionalRows(
                    (int) $record->warehouse_id,
                    (int) $tradeItem->item_id,
                    (int) $tradeItem->quantity,
                    $quantityType,
                    $source['type'] === 'EARNING' ? ($record->buyer_id ? (int) $record->buyer_id : null) : null,
                    $source['type'],
                    $source['type'] === 'EARNING' ? (int) $record->id : null,
                    (int) $tradeItem->id,
                    $stockLots
                );

                foreach ($allocations as $allocation) {
                    $rows->push(array_merge([
                        'source_type' => $source['type'],
                        'earning_id' => $source['type'] === 'EARNING' ? (int) $record->id : null,
                        'stock_transfer_id' => $source['type'] === 'STOCK_TRANSFER' ? (int) $record->id : null,
                        'trade_id' => (int) $tradeItem->trade_id,
                        'trade_item_id' => (int) $tradeItem->id,
                        'item_id' => (int) $tradeItem->item_id,
                        'item_code' => $tradeItem->item_code,
                        'item_name' => $this->normalizeProvisionalItemName($tradeItem->item_name),
                        'capacity_case' => (int) ($tradeItem->capacity_case ?: 1),
                        'packaging' => $tradeItem->packaging ?? '',
                        'jan_code' => $this->extractProvisionalJanCode($tradeItem->jan_code),
                        'ordered_qty' => (int) $tradeItem->quantity,
                        'planned_qty_type' => $quantityType,
                        'course_id' => (int) $record->course_id,
                        'course_code' => $record->course_code,
                        'course_name' => $record->course_name ?? '',
                        'warehouse_id' => (int) $record->warehouse_id,
                        'warehouse_name' => $record->warehouse_name ?? '',
                        'buyer_code' => $record->buyer_code ?? '',
                        'buyer_name' => $record->buyer_name ?? '',
                        'slip_number' => $record->slip_number ?? (string) $record->id,
                        'shipping_date' => $source['type'] === 'EARNING'
                            ? (string) $record->delivered_date
                            : (string) ($record->delivered_date ?? $shippingDate),
                    ], $allocation));
                }
            }
        }

        return $rows;
    }

    private function allocateProvisionalRows(
        int $warehouseId,
        int $itemId,
        int $needQty,
        string $quantityType,
        ?int $buyerId,
        string $sourceType,
        ?int $sourceId,
        int $sourceLineId,
        array &$stockLots
    ): array {
        $cacheKey = "{$warehouseId}:{$itemId}:{$quantityType}:".($buyerId ?? 'none');
        if (! array_key_exists($cacheKey, $stockLots)) {
            $stockLots[$cacheKey] = $this->getProvisionalStockLots($warehouseId, $itemId, $quantityType, $buyerId)
                ->map(fn ($lot) => [
                    'location_id' => $lot->location_id ? (int) $lot->location_id : null,
                    'code1' => $lot->code1,
                    'code2' => $lot->code2,
                    'code3' => $lot->code3,
                    'floor_id' => $lot->floor_id ? (int) $lot->floor_id : null,
                    'floor_name' => $lot->floor_name ?? '',
                    'remaining' => (int) $lot->available_quantity,
                ])
                ->all();
        }

        $rows = [];
        $remainingNeed = $needQty;

        if ($sourceType === 'EARNING' && $sourceId !== null) {
            foreach ($this->getProvisionalOwnReservedLots($warehouseId, $itemId, $quantityType, $sourceId, $sourceLineId, $buyerId) as $lot) {
                if ($remainingNeed <= 0) {
                    break;
                }

                $allocated = min($remainingNeed, (int) $lot->available_quantity);
                if ($allocated <= 0) {
                    continue;
                }

                $remainingNeed -= $allocated;

                $rows[] = [
                    'location_id' => $lot->location_id ? (int) $lot->location_id : null,
                    'code1' => $lot->code1,
                    'code2' => $lot->code2,
                    'code3' => $lot->code3,
                    'floor_id' => $lot->floor_id ? (int) $lot->floor_id : null,
                    'floor_name' => $lot->floor_name ?? '',
                    'planned_qty' => $allocated,
                    'shortage_qty' => 0,
                ];
            }
        }

        foreach ($stockLots[$cacheKey] as &$lot) {
            if ($remainingNeed <= 0) {
                break;
            }
            if ($lot['remaining'] <= 0) {
                continue;
            }

            $allocated = min($remainingNeed, $lot['remaining']);
            $lot['remaining'] -= $allocated;
            $remainingNeed -= $allocated;

            $rows[] = [
                'location_id' => $lot['location_id'],
                'code1' => $lot['code1'],
                'code2' => $lot['code2'],
                'code3' => $lot['code3'],
                'floor_id' => $lot['floor_id'],
                'floor_name' => $lot['floor_name'],
                'planned_qty' => $allocated,
                'shortage_qty' => 0,
            ];
        }
        unset($lot);

        if ($remainingNeed > 0) {
            $rows[] = [
                'location_id' => null,
                'code1' => null,
                'code2' => null,
                'code3' => null,
                'floor_id' => null,
                'floor_name' => '',
                'planned_qty' => 0,
                'shortage_qty' => $remainingNeed,
            ];
        }

        return $rows;
    }

    private function getProvisionalOwnReservedLots(
        int $warehouseId,
        int $itemId,
        string $quantityType,
        int $sourceId,
        int $sourceLineId,
        ?int $buyerId
    ): \Illuminate\Support\Collection {
        $query = DB::connection('sakemaru')
            ->table('real_stock_lot_earnings as rsle')
            ->join('real_stock_lots as rsl', function ($join) {
                $join->on('rsl.id', '=', 'rsle.real_stock_lot_id')
                    ->where('rsl.status', '=', 'ACTIVE');
            })
            ->join('real_stocks as rs', 'rs.id', '=', 'rsl.real_stock_id')
            ->join('locations as l', 'l.id', '=', 'rsl.location_id')
            ->leftJoin('floors as f', 'l.floor_id', '=', 'f.id')
            ->where('rs.warehouse_id', $warehouseId)
            ->where('rs.item_id', $itemId)
            ->where('rsle.earning_id', $sourceId)
            ->where('rsle.trade_item_id', $sourceLineId)
            ->where('rsle.status', 'RESERVED');

        if ($buyerId !== null) {
            $query->where(function ($q) use ($buyerId) {
                $q->whereNotExists(function ($subQuery) {
                    $subQuery->select(DB::raw(1))
                        ->from('real_stock_lot_buyer_restrictions')
                        ->whereColumn('real_stock_lot_buyer_restrictions.real_stock_lot_id', 'rsl.id');
                })->orWhereExists(function ($subQuery) use ($buyerId) {
                    $subQuery->select(DB::raw(1))
                        ->from('real_stock_lot_buyer_restrictions')
                        ->whereColumn('real_stock_lot_buyer_restrictions.real_stock_lot_id', 'rsl.id')
                        ->where('real_stock_lot_buyer_restrictions.buyer_id', $buyerId);
                });
            });
        }

        $ownReservedExpr = 'SUM(rsle.quantity)';
        $availableExpr = "LEAST({$ownReservedExpr}, GREATEST(0, MAX(rsl.current_quantity) - MAX(rsl.reserved_quantity) + {$ownReservedExpr}))";

        return $query
            ->select([
                'rsl.location_id',
                'l.code1',
                'l.code2',
                'l.code3',
                'l.floor_id',
                'f.name as floor_name',
                DB::raw("{$availableExpr} as available_quantity"),
            ])
            ->groupBy('rsl.id', 'rsl.location_id', 'l.code1', 'l.code2', 'l.code3', 'l.floor_id', 'f.name')
            ->havingRaw("{$availableExpr} > 0")
            ->orderByRaw('rsl.expiration_date IS NULL')
            ->orderBy('rsl.expiration_date')
            ->orderBy('rsl.created_at')
            ->orderBy('rsl.id')
            ->get();
    }

    private function getProvisionalStockLots(int $warehouseId, int $itemId, string $quantityType, ?int $buyerId): \Illuminate\Support\Collection
    {
        $query = DB::connection('sakemaru')
            ->table('real_stocks as rs')
            ->join('real_stock_lots as rsl', function ($join) {
                $join->on('rsl.real_stock_id', '=', 'rs.id')
                    ->where('rsl.status', '=', 'ACTIVE')
                    ->whereRaw('rsl.current_quantity > rsl.reserved_quantity');
            })
            ->join('locations as l', 'l.id', '=', 'rsl.location_id')
            ->leftJoin('floors as f', 'l.floor_id', '=', 'f.id')
            ->where('rs.warehouse_id', $warehouseId)
            ->where('rs.item_id', $itemId);

        if ($buyerId !== null) {
            $query->where(function ($q) use ($buyerId) {
                $q->whereNotExists(function ($subQuery) {
                    $subQuery->select(DB::raw(1))
                        ->from('real_stock_lot_buyer_restrictions')
                        ->whereColumn('real_stock_lot_buyer_restrictions.real_stock_lot_id', 'rsl.id');
                })->orWhereExists(function ($subQuery) use ($buyerId) {
                    $subQuery->select(DB::raw(1))
                        ->from('real_stock_lot_buyer_restrictions')
                        ->whereColumn('real_stock_lot_buyer_restrictions.real_stock_lot_id', 'rsl.id')
                        ->where('real_stock_lot_buyer_restrictions.buyer_id', $buyerId);
                });
            });
        }

        return $query
            ->select([
                'rsl.location_id',
                'l.code1',
                'l.code2',
                'l.code3',
                'l.floor_id',
                'f.name as floor_name',
                DB::raw('(rsl.current_quantity - rsl.reserved_quantity) as available_quantity'),
            ])
            ->orderByRaw('rsl.expiration_date IS NULL')
            ->orderBy('rsl.expiration_date')
            ->orderBy('rsl.created_at')
            ->orderBy('rsl.id')
            ->get();
    }

    private function buildProvisionalPrimaryList(\Illuminate\Support\Collection $rows, string $shippingDate, string $title = '1次ピッキングリスト'): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $key = ($row['location_id'] ?? 0).'|'.$row['item_id'].'|'.$row['planned_qty_type'];
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'item_code' => $row['item_code'],
                    'item_name' => $row['item_name'],
                    'packaging' => $row['packaging'],
                    'location_id' => $row['location_id'],
                    'code1' => $row['code1'],
                    'code2' => $row['code2'],
                    'code3' => $row['code3'],
                    'floor_id' => $row['floor_id'],
                    'floor_name' => $row['floor_name'],
                    'capacity_case' => $row['capacity_case'],
                    'planned_qty_type' => $row['planned_qty_type'],
                    'total_qty' => 0,
                    'shortage_qty' => 0,
                ];
            }
            $grouped[$key]['total_qty'] += (int) $row['planned_qty'];
            $grouped[$key]['shortage_qty'] += (int) $row['shortage_qty'];
        }

        $items = [];
        foreach ($grouped as $item) {
            $qty = (int) $item['total_qty'];
            $capacityCase = max(1, (int) $item['capacity_case']);
            $qtyType = QuantityType::tryFrom($item['planned_qty_type']) ?? QuantityType::PIECE;
            $pieceTotalQty = $qtyType === QuantityType::CASE ? $qty * $capacityCase : $qty;
            $caseQty = $capacityCase > 1 ? intdiv($pieceTotalQty, $capacityCase) : 0;
            $pieceQty = $capacityCase > 1 ? $pieceTotalQty % $capacityCase : $pieceTotalQty;

            $items[] = [
                'item_code' => $item['item_code'],
                'item_name' => $item['item_name'],
                'packaging' => $item['packaging'],
                'location_code' => $item['location_id']
                    ? Location::formatCode($item['code1'], $item['code2'], $item['code3'], '-')
                    : '',
                'total_qty' => $qty,
                'case_qty' => $caseQty,
                'piece_qty' => $pieceQty,
                'shortage_qty' => (int) $item['shortage_qty'],
                'total_piece_qty' => $pieceTotalQty,
                'floor_id' => $item['floor_id'],
                'floor_name' => $item['floor_name'] ?? '',
            ];
        }

        return [
            'header' => [
                'wave_no' => '仮出力',
                'shipping_date' => $shippingDate,
                'warehouse_name' => (string) ($rows->first()['warehouse_name'] ?? ''),
                'list_title' => $title,
            ],
            'items' => $items,
            'summary' => $this->summarizeProvisionalPrimaryItems($items),
        ];
    }

    private function splitPrimaryPages(array $data, bool $separateFloors): array
    {
        if (! $separateFloors || empty($data['items'])) {
            return [$data];
        }

        $grouped = collect($data['items'])->groupBy(fn (array $item) => $item['floor_id'] ?? 'none');
        if ($grouped->count() <= 1) {
            return [$data];
        }

        return $grouped
            ->map(function ($items) use ($data) {
                $items = $items->values()->all();
                $floorName = $items[0]['floor_name'] ?? 'フロア未設定';

                return [
                    'header' => array_merge($data['header'], [
                        'floor_name' => $floorName ?: 'フロア未設定',
                    ]),
                    'items' => $items,
                    'summary' => $this->summarizeProvisionalPrimaryItems($items),
                ];
            })
            ->values()
            ->all();
    }

    private function buildProvisionalShortageList(\Illuminate\Support\Collection $rows, string $shippingDate): array
    {
        $items = [];
        $totalShortage = 0;

        $shortageRows = $rows
            ->filter(fn (array $row) => (int) $row['shortage_qty'] > 0)
            ->sort(function (array $a, array $b): int {
                return $this->compareProvisionalShortageRows($a, $b);
            })
            ->values();

        foreach ($shortageRows as $row) {
            $qtyType = QuantityType::tryFrom($row['planned_qty_type']) ?? QuantityType::PIECE;
            $items[] = [
                'item_code' => $row['item_code'],
                'item_name' => $row['item_name'],
                'packaging' => $row['packaging'],
                'location_code' => $row['location_id']
                    ? Location::formatCode($row['code1'], $row['code2'], $row['code3'], '-')
                    : '',
                'qty_label' => $qtyType->name(),
                'planned_qty' => (int) $row['ordered_qty'],
                'allocated_qty' => max(0, (int) $row['ordered_qty'] - (int) $row['shortage_qty']),
                'shortage_qty' => (int) $row['shortage_qty'],
            ];
            $totalShortage += (int) $row['shortage_qty'];
        }

        return [
            'header' => [
                'wave_no' => '仮出力',
                'shipping_date' => $shippingDate,
                'warehouse_name' => (string) ($rows->first()['warehouse_name'] ?? ''),
            ],
            'items' => $items,
            'summary' => [
                'sku_count' => count($items),
                'total_shortage' => $totalShortage,
            ],
        ];
    }

    private function compareProvisionalShortageRows(array $a, array $b): int
    {
        foreach ([
            'slip_number',
            'buyer_code',
            'code1',
            'code2',
            'code3',
            'item_code',
        ] as $key) {
            $comparison = strcmp(
                $this->provisionalShortageSortValue($a[$key] ?? null),
                $this->provisionalShortageSortValue($b[$key] ?? null)
            );

            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }

    private function provisionalShortageSortValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'ZZZ';
        }

        return (string) $value;
    }

    private function buildProvisionalCourseGroupedLists(\Illuminate\Support\Collection $rows): array
    {
        $results = [];
        $groups = $rows
            ->groupBy(fn (array $row) => $row['course_id'].'|'.($row['floor_id'] ?? 0));

        foreach ($groups as $groupRows) {
            $first = $groupRows->first();
            $items = [];
            $sortedGroupRows = $groupRows
                ->sort(fn (array $a, array $b): int => $this->compareProvisionalCourseGroupedRows($a, $b))
                ->values();

            foreach ($sortedGroupRows->groupBy(fn (array $row) => ($row['location_id'] ?? 0).'|'.$row['item_code']) as $itemRows) {
                $row = $itemRows->first();
                $totalPieces = $this->sumProvisionalPieces($itemRows);
                $capacityCase = max(1, (int) $row['capacity_case']);
                $items[] = [
                    'no' => count($items) + 1,
                    'location_code' => $row['location_id']
                        ? Location::formatCode($row['code1'], $row['code2'], $row['code3'], '-')
                        : '',
                    'item_code' => $row['item_code'],
                    'jan_code' => $row['jan_code'],
                    'item_name' => $row['item_name'],
                    'capacity_case' => $capacityCase,
                    'case_qty' => intdiv($totalPieces, $capacityCase),
                    'piece_qty' => $totalPieces % $capacityCase,
                    'total_pieces' => $totalPieces,
                    'shortage_qty' => (int) $itemRows->sum('shortage_qty'),
                ];
            }

            $slipCount = $groupRows->map(fn (array $row) => $row['earning_id'] ? "E:{$row['earning_id']}" : "ST:{$row['stock_transfer_id']}")->unique()->count();

            $results[] = [
                'header' => [
                    'course_name' => $first['course_name'],
                    'slip_count' => $slipCount,
                    'shipping_date' => $first['shipping_date'],
                    'warehouse_name' => $first['warehouse_name'],
                    'floor_name' => $first['floor_name'],
                ],
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

    private function buildProvisionalCourseGroupedListsV2(\Illuminate\Support\Collection $rows): array
    {
        // 配送コース×フロアでバケット化（YXは別バケット）
        $buckets = [];
        foreach ($rows as $row) {
            $courseId = $row['course_id'] ?? 0;
            $floorId = $row['floor_id'] ?? 0;
            $code1 = $row['code1'] ?? '';
            $isYxGroup = str_starts_with($code1, 'YA') || str_starts_with($code1, 'YB') || str_starts_with($code1, 'YC') || str_starts_with($code1, 'YX');
            $bucketKey = $isYxGroup ? $courseId.'|YX' : $courseId.'|'.$floorId;

            if (! isset($buckets[$bucketKey])) {
                $buckets[$bucketKey] = [
                    'course_code' => $row['course_code'] ?? '',
                    'floor_id' => $isYxGroup ? PHP_INT_MAX : ($floorId ?: PHP_INT_MAX - 1),
                    'header' => [
                        'course_name' => $row['course_name'] ?? '',
                        'shipping_date' => $row['shipping_date'] ?? '',
                        'warehouse_name' => $row['warehouse_name'] ?? '',
                        'floor_name' => $isYxGroup ? 'YX' : ($row['floor_name'] ?? ''),
                    ],
                    '_rows' => collect(),
                ];
            }
            $buckets[$bucketKey]['_rows']->push($row);
        }

        // フロア優先→コース順でソート
        uasort($buckets, function ($a, $b) {
            $floorCmp = $a['floor_id'] <=> $b['floor_id'];

            return $floorCmp !== 0 ? $floorCmp : ($a['course_code'] <=> $b['course_code']);
        });

        $results = [];
        foreach ($buckets as $bucket) {
            $groupRows = $bucket['_rows'];
            $sortedGroupRows = $groupRows
                ->sort(fn (array $a, array $b): int => $this->compareProvisionalCourseGroupedRows($a, $b))
                ->values();

            $items = [];
            foreach ($sortedGroupRows->groupBy(fn (array $row) => ($row['location_id'] ?? 0).'|'.$row['item_code']) as $itemRows) {
                $row = $itemRows->first();
                $totalPieces = $this->sumProvisionalPieces($itemRows);
                $capacityCase = max(1, (int) $row['capacity_case']);
                $items[] = [
                    'no' => count($items) + 1,
                    'location_code' => $row['location_id']
                        ? Location::formatCode($row['code1'], $row['code2'], $row['code3'], '-')
                        : '',
                    'item_code' => $row['item_code'],
                    'jan_code' => $row['jan_code'],
                    'item_name' => $row['item_name'],
                    'capacity_case' => $capacityCase,
                    'case_qty' => intdiv($totalPieces, $capacityCase),
                    'piece_qty' => $totalPieces % $capacityCase,
                    'total_pieces' => $totalPieces,
                    'shortage_qty' => (int) $itemRows->sum('shortage_qty'),
                ];
            }

            $slipCount = $groupRows->map(fn (array $row) => $row['earning_id'] ? "E:{$row['earning_id']}" : "ST:{$row['stock_transfer_id']}")->unique()->count();

            $results[] = [
                'header' => array_merge($bucket['header'], [
                    'slip_count' => $slipCount,
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

    private function compareProvisionalCourseGroupedRows(array $a, array $b): int
    {
        foreach ([
            'code1',
            'code2',
            'code3',
            'item_code',
            'slip_number',
        ] as $key) {
            $comparison = strcmp(
                $this->provisionalShortageSortValue($a[$key] ?? null),
                $this->provisionalShortageSortValue($b[$key] ?? null)
            );

            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }

    private function buildProvisionalBuyerGroupedLists(\Illuminate\Support\Collection $rows, string $shippingDate): array
    {
        $results = [];
        $courseGroups = $rows
            ->groupBy('course_id');

        foreach ($courseGroups as $groupRows) {
            $first = $groupRows->first();
            $items = [];
            $totalCase = 0;
            $totalPiece = 0;
            $totalAll = 0;

            foreach ($groupRows->groupBy(fn (array $row) => ($row['buyer_code'] ?: 'NA').'|'.($row['location_id'] ?? 0).'|'.$row['item_code']) as $itemRows) {
                $row = $itemRows->first();
                $capacityCase = max(1, (int) $row['capacity_case']);
                $totalPieces = $this->sumProvisionalPieces($itemRows);
                $caseQty = intdiv($totalPieces, $capacityCase);
                $pieceQty = $totalPieces % $capacityCase;

                $items[] = [
                    'no' => count($items) + 1,
                    'location_code' => $row['location_id']
                        ? Location::formatCode($row['code1'], $row['code2'], $row['code3'], '-')
                        : '',
                    'buyer_code' => (string) $row['buyer_code'],
                    'buyer_name' => (string) $row['buyer_name'],
                    'item_code' => $row['item_code'],
                    'jan_code' => $row['jan_code'],
                    'item_name' => $row['item_name'],
                    'capacity_case' => $capacityCase,
                    'case_qty' => $caseQty,
                    'piece_qty' => $pieceQty,
                    'total_pieces' => $totalPieces,
                    'shortage_qty' => (int) $itemRows->sum('shortage_qty'),
                ];

                $totalCase += $caseQty;
                $totalPiece += $pieceQty;
                $totalAll += $totalPieces;
            }

            $results[] = [
                'header' => [
                    'course_name' => $first['course_name'],
                    'wave_no' => '仮出力',
                    'shipping_date' => $shippingDate,
                ],
                'items' => $items,
                'summary' => [
                    'row_count' => count($items),
                    'total_case' => $totalCase,
                    'total_piece' => $totalPiece,
                    'total_pieces_all' => $totalAll,
                ],
            ];
        }

        return $results;
    }

    private function summarizeProvisionalPrimaryItems(array $items): array
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

    private function sumProvisionalPieces(\Illuminate\Support\Collection $rows): int
    {
        return (int) $rows->sum(function (array $row): int {
            $qtyType = QuantityType::tryFrom($row['planned_qty_type']) ?? QuantityType::PIECE;

            return $qtyType === QuantityType::CASE
                ? (int) $row['planned_qty'] * max(1, (int) $row['capacity_case'])
                : (int) $row['planned_qty'];
        });
    }

    private function extractProvisionalJanCode(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        $tokens = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);

        return empty($tokens) ? '' : (string) $tokens[0];
    }

    private function normalizeProvisionalItemName(?string $raw): string
    {
        if ($raw === null) {
            return '';
        }

        $normalized = str_replace("\u{3000}", ' ', $raw);
        $normalized = preg_replace('/\s+/u', ' ', $normalized);
        $normalized = trim((string) $normalized);

        return str_replace(' ', "\u{00A0}", $normalized);
    }

    protected function processEarningsForWave(
        Wave $wave,
        WaveSetting $waveSetting,
        $earnings,
        $warehouse,
        $course,
        string $shippingDate
    ): void {
        $earningIds = $earnings->pluck('id')->toArray();
        $tradeIds = $earnings->pluck('trade_id')->toArray();

        DB::connection('sakemaru')
            ->table('wms_picking_tasks')
            ->where('wave_id', $wave->id)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('wms_picking_item_results')
                    ->whereColumn('wms_picking_item_results.picking_task_id', 'wms_picking_tasks.id');
            })
            ->delete();

        DB::connection('sakemaru')
            ->table('wms_reservations')
            ->where('wave_id', $wave->id)
            ->whereIn('source_id', $earningIds)
            ->where('source_type', 'EARNING')
            ->delete();

        $tradeItems = DB::connection('sakemaru')
            ->table('trade_items')
            ->whereIn('trade_id', $tradeIds)
            ->where('is_active', true)
            ->get();

        $tradeIdToEarningId = $earnings->pluck('id', 'trade_id')->toArray();
        $tradeIdToBuyerId = $earnings->pluck('buyer_id', 'trade_id')->toArray();

        $itemsByGroup = [];
        $reservationResults = [];

        foreach ($tradeItems as $tradeItem) {
            $earningId = $tradeIdToEarningId[$tradeItem->trade_id] ?? null;
            $buyerId = $tradeIdToBuyerId[$tradeItem->trade_id] ?? null;
            if (! $earningId) {
                continue;
            }

            $allocationService = new StockAllocationService;
            $result = $allocationService->allocateForItem(
                $wave->id,
                $waveSetting->warehouse_id,
                $tradeItem->item_id,
                $tradeItem->quantity,
                $tradeItem->quantity_type ?? 'PIECE',
                $earningId,
                $tradeItem->id,
                'EARNING',
                $buyerId
            );

            $primaryReservation = DB::connection('sakemaru')
                ->table('wms_reservations')
                ->where('wave_id', $wave->id)
                ->where('item_id', $tradeItem->item_id)
                ->where('source_id', $earningId)
                ->whereNotNull('location_id')
                ->orderBy('qty_each', 'desc')
                ->orderBy('id', 'asc')
                ->first();

            $reservationResult = [
                'allocated_qty' => $result['allocated'],
                'shortage_qty' => $result['shortage'] ?? 0,
                'real_stock_id' => $primaryReservation->real_stock_id ?? null,
                'location_id' => $primaryReservation->location_id ?? null,
                'walking_order' => null,
            ];

            $reservationResults[$tradeItem->id] = $reservationResult;

            $pickingAreaId = null;
            $floorId = null;
            $temperatureType = null;
            $isRestrictedArea = false;

            if ($reservationResult['location_id']) {
                $location = DB::connection('sakemaru')
                    ->table('locations')
                    ->where('id', $reservationResult['location_id'])
                    ->first();
                $floorId = $location->floor_id ?? null;
                $temperatureType = $location->temperature_type ?? null;
                $isRestrictedArea = $location->is_restricted_area ?? false;
                $pickingAreaId = $location->wms_picking_area_id ?? null;
            }

            if ($pickingAreaId === null || $floorId === null) {
                $itemLocation = DB::connection('sakemaru')
                    ->table('real_stocks as rs')
                    ->join('real_stock_lots as rsl', 'rs.id', '=', 'rsl.real_stock_id')
                    ->join('locations as l', 'rsl.location_id', '=', 'l.id')
                    ->where('rs.warehouse_id', $waveSetting->warehouse_id)
                    ->where('rs.item_id', $tradeItem->item_id)
                    ->orderByRaw('l.floor_id IS NULL')
                    ->orderByRaw('l.wms_picking_area_id IS NULL')
                    ->select('l.id as location_id', 'rs.id as real_stock_id', 'l.wms_picking_area_id', 'l.floor_id', 'l.temperature_type', 'l.is_restricted_area')
                    ->first();

                if ($itemLocation) {
                    if ($reservationResult['location_id'] === null) {
                        $reservationResult['location_id'] = $itemLocation->location_id;
                        $reservationResult['real_stock_id'] = $itemLocation->real_stock_id;
                        $reservationResults[$tradeItem->id] = $reservationResult;
                    }
                    $pickingAreaId = $pickingAreaId ?? $itemLocation->wms_picking_area_id;
                    $floorId = $floorId ?? $itemLocation->floor_id;
                    $temperatureType = $temperatureType ?? $itemLocation->temperature_type;
                    $isRestrictedArea = $isRestrictedArea ?? $itemLocation->is_restricted_area;
                } else {
                    $defaultArea = DB::connection('sakemaru')
                        ->table('wms_picking_areas')
                        ->where('warehouse_id', $waveSetting->warehouse_id)
                        ->where('is_active', true)
                        ->orderBy('display_order', 'asc')
                        ->first();
                    $pickingAreaId = $pickingAreaId ?? ($defaultArea->id ?? null);
                }
            }

            $groupKey = ($floorId ?? 'null');
            if (! isset($itemsByGroup[$groupKey])) {
                $itemsByGroup[$groupKey] = [
                    'floor_id' => $floorId,
                    'picking_area_id' => $pickingAreaId,
                    'temperature_type' => $temperatureType,
                    'is_restricted_area' => $isRestrictedArea,
                    'items' => [],
                ];
            }
            $itemsByGroup[$groupKey]['items'][] = $tradeItem;
        }

        foreach ($itemsByGroup as $groupData) {
            if (empty($groupData['items'])) {
                continue;
            }

            $validItems = [];
            $hasRestrictedItem = false;
            foreach ($groupData['items'] as $tradeItem) {
                $reservationResult = $reservationResults[$tradeItem->id] ?? null;
                if ($reservationResult) {
                    $validItems[] = $tradeItem;
                    if ($reservationResult['location_id']) {
                        $location = DB::connection('sakemaru')
                            ->table('locations')
                            ->where('id', $reservationResult['location_id'])
                            ->first();
                        if ($location && $location->is_restricted_area) {
                            $hasRestrictedItem = true;
                        }
                    }
                }
            }

            if (empty($validItems)) {
                continue;
            }

            $pickingTaskId = DB::connection('sakemaru')->table('wms_picking_tasks')->insertGetId([
                'wave_id' => $wave->id,
                'wms_picking_area_id' => $groupData['picking_area_id'],
                'warehouse_id' => $waveSetting->warehouse_id,
                'warehouse_code' => $warehouse->code,
                'floor_id' => $groupData['floor_id'],
                'temperature_type' => $groupData['temperature_type'],
                'is_restricted_area' => $hasRestrictedItem,
                'delivery_course_id' => $waveSetting->delivery_course_id,
                'delivery_course_code' => $course->code,
                'shipment_date' => $shippingDate,
                'status' => 'PENDING',
                'task_type' => 'WAVE',
                'picker_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($validItems as $tradeItem) {
                $reservationResult = $reservationResults[$tradeItem->id];
                $earningId = $tradeIdToEarningId[$tradeItem->trade_id] ?? null;

                if (! $tradeItem->quantity_type) {
                    throw new \RuntimeException(
                        "quantity_type must be specified for trade_item ID {$tradeItem->id}"
                    );
                }

                DB::connection('sakemaru')->table('wms_picking_item_results')->insert([
                    'picking_task_id' => $pickingTaskId,
                    'earning_id' => $earningId,
                    'source_type' => WmsPickingItemResult::SOURCE_TYPE_EARNING,
                    'stock_transfer_id' => null,
                    'trade_id' => $tradeItem->trade_id,
                    'trade_item_id' => $tradeItem->id,
                    'item_id' => $tradeItem->item_id,
                    'real_stock_id' => $reservationResult['real_stock_id'],
                    'location_id' => $reservationResult['location_id'],
                    'walking_order' => $reservationResult['walking_order'],
                    'ordered_qty' => $tradeItem->quantity,
                    'ordered_qty_type' => $tradeItem->quantity_type,
                    'planned_qty' => $reservationResult['allocated_qty'],
                    'planned_qty_type' => $tradeItem->quantity_type,
                    'picked_qty' => 0,
                    'picked_qty_type' => $tradeItem->quantity_type,
                    'shortage_qty' => $reservationResult['shortage_qty'] ?? 0,
                    'status' => 'PENDING',
                    'picker_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::connection('sakemaru')
            ->table('earnings')
            ->whereIn('id', $earningIds)
            ->update([
                'picking_status' => 'BEFORE_PICKING',
                'updated_at' => now(),
            ]);
    }

    protected function processStockTransfersForWave(
        Wave $wave,
        WaveSetting $waveSetting,
        $stockTransfers,
        $warehouse,
        $course,
        string $shippingDate
    ): void {
        $stockTransferIds = $stockTransfers->pluck('id')->toArray();
        $tradeIds = $stockTransfers->pluck('trade_id')->toArray();

        DB::connection('sakemaru')
            ->table('wms_picking_tasks')
            ->where('wave_id', $wave->id)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('wms_picking_item_results')
                    ->whereColumn('wms_picking_item_results.picking_task_id', 'wms_picking_tasks.id');
            })
            ->delete();

        DB::connection('sakemaru')
            ->table('wms_reservations')
            ->where('wave_id', $wave->id)
            ->whereIn('source_id', $stockTransferIds)
            ->where('source_type', 'STOCK_TRANSFER')
            ->delete();

        $tradeIdToStockTransferId = $stockTransfers->pluck('id', 'trade_id')->toArray();

        $tradeItems = DB::connection('sakemaru')
            ->table('trade_items')
            ->whereIn('trade_id', $tradeIds)
            ->where('is_active', true)
            ->get();

        foreach ($tradeItems as $tradeItem) {
            $stockTransferId = $tradeIdToStockTransferId[$tradeItem->trade_id] ?? null;
            if (! $stockTransferId) {
                continue;
            }

            $allocationService = new StockTransferLotAllocationService;
            $result = $allocationService->allocateForTradeItem(
                $wave->id,
                $waveSetting->warehouse_id,
                $stockTransferId,
                $tradeItem
            );

            $primaryReservation = DB::connection('sakemaru')
                ->table('wms_reservations')
                ->where('wave_id', $wave->id)
                ->where('item_id', $tradeItem->item_id)
                ->where('source_id', $stockTransferId)
                ->where('source_type', 'STOCK_TRANSFER')
                ->where('source_line_id', $tradeItem->id)
                ->whereNotNull('location_id')
                ->orderBy('qty_each', 'desc')
                ->orderBy('id', 'asc')
                ->first();

            $reservationResult = [
                'allocated_qty' => $result['allocated'],
                'shortage_qty' => $result['shortage'] ?? 0,
                'real_stock_id' => $primaryReservation->real_stock_id ?? null,
                'location_id' => $primaryReservation->location_id ?? null,
                'walking_order' => null,
            ];

            $pickingAreaId = null;
            $floorId = null;

            if ($reservationResult['location_id']) {
                $location = DB::connection('sakemaru')
                    ->table('locations')
                    ->where('id', $reservationResult['location_id'])
                    ->first();
                $floorId = $location->floor_id ?? null;
                $pickingAreaId = $location->wms_picking_area_id ?? null;
            }

            if ($pickingAreaId === null || $floorId === null) {
                $itemLocation = DB::connection('sakemaru')
                    ->table('real_stocks as rs')
                    ->join('real_stock_lots as rsl', 'rs.id', '=', 'rsl.real_stock_id')
                    ->join('locations as l', 'rsl.location_id', '=', 'l.id')
                    ->where('rs.warehouse_id', $waveSetting->warehouse_id)
                    ->where('rs.item_id', $tradeItem->item_id)
                    ->orderByRaw('l.floor_id IS NULL')
                    ->orderByRaw('l.wms_picking_area_id IS NULL')
                    ->select('l.id as location_id', 'rs.id as real_stock_id', 'l.wms_picking_area_id', 'l.floor_id')
                    ->first();

                if ($itemLocation) {
                    if ($reservationResult['location_id'] === null) {
                        $reservationResult['location_id'] = $itemLocation->location_id;
                        $reservationResult['real_stock_id'] = $itemLocation->real_stock_id;
                    }
                    $pickingAreaId = $pickingAreaId ?? $itemLocation->wms_picking_area_id;
                    $floorId = $floorId ?? $itemLocation->floor_id;
                } else {
                    $defaultArea = DB::connection('sakemaru')
                        ->table('wms_picking_areas')
                        ->where('warehouse_id', $waveSetting->warehouse_id)
                        ->where('is_active', true)
                        ->orderBy('display_order', 'asc')
                        ->first();
                    $pickingAreaId = $pickingAreaId ?? ($defaultArea->id ?? null);
                }
            }

            $existingTask = DB::connection('sakemaru')
                ->table('wms_picking_tasks')
                ->where('wave_id', $wave->id)
                ->where('floor_id', $floorId)
                ->first();

            if ($existingTask) {
                $pickingTaskId = $existingTask->id;
            } else {
                $pickingTaskId = DB::connection('sakemaru')->table('wms_picking_tasks')->insertGetId([
                    'wave_id' => $wave->id,
                    'wms_picking_area_id' => $pickingAreaId,
                    'warehouse_id' => $waveSetting->warehouse_id,
                    'warehouse_code' => $warehouse->code,
                    'floor_id' => $floorId,
                    'delivery_course_id' => $waveSetting->delivery_course_id,
                    'delivery_course_code' => $course->code,
                    'shipment_date' => $shippingDate,
                    'status' => 'PENDING',
                    'task_type' => 'WAVE',
                    'picker_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (! $tradeItem->quantity_type) {
                throw new \RuntimeException(
                    "quantity_type must be specified for trade_item ID {$tradeItem->id}"
                );
            }

            DB::connection('sakemaru')->table('wms_picking_item_results')->insert([
                'picking_task_id' => $pickingTaskId,
                'earning_id' => null,
                'source_type' => WmsPickingItemResult::SOURCE_TYPE_STOCK_TRANSFER,
                'stock_transfer_id' => $stockTransferId,
                'trade_id' => $tradeItem->trade_id,
                'trade_item_id' => $tradeItem->id,
                'item_id' => $tradeItem->item_id,
                'real_stock_id' => $reservationResult['real_stock_id'],
                'location_id' => $reservationResult['location_id'],
                'walking_order' => $reservationResult['walking_order'],
                'ordered_qty' => $tradeItem->quantity,
                'ordered_qty_type' => $tradeItem->quantity_type,
                'planned_qty' => $reservationResult['allocated_qty'],
                'planned_qty_type' => $tradeItem->quantity_type,
                'picked_qty' => 0,
                'picked_qty_type' => $tradeItem->quantity_type,
                'shortage_qty' => $reservationResult['shortage_qty'] ?? 0,
                'status' => 'PENDING',
                'picker_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::connection('sakemaru')
            ->table('stock_transfers')
            ->whereIn('id', $stockTransferIds)
            ->update([
                'picking_status' => 'BEFORE_PICKING',
                'updated_at' => now(),
            ]);
    }

    protected function getEligibleStockTransfersQuery(string|array $shippingDate, int $warehouseId, bool $includePast = false)
    {
        $shippingDates = $this->normalizeShippingDates($shippingDate);
        $warehouseIds = WarehouseResolver::resolveAllWarehouseIds($warehouseId);

        $query = DB::connection('sakemaru')
            ->table('stock_transfers as st')
            ->join('trades as st_trade', 'st.trade_id', '=', 'st_trade.id')
            ->join('delivery_courses as dc', 'st.delivery_course_id', '=', 'dc.id')
            ->join('warehouses as fw', 'st.from_warehouse_id', '=', 'fw.id')
            ->join('warehouses as tw', 'st.to_warehouse_id', '=', 'tw.id')
            ->where('st.is_active', true)
            ->where('st_trade.is_active', true)
            ->where('st.picking_status', 'BEFORE')
            ->where('st.is_delivered', false)
            ->where('st.is_confirmed', false)
            ->whereIn('st.from_warehouse_id', $warehouseIds)
            ->whereIn('dc.warehouse_id', $warehouseIds)
            ->whereNotNull('st.delivery_course_id')
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('fw.is_virtual', false)
                        ->orWhere('tw.is_virtual', false);
                })
                    ->where(function ($q) {
                        $q->whereRaw('COALESCE(fw.stock_warehouse_id, fw.id) != COALESCE(tw.stock_warehouse_id, tw.id)');
                    });
            })
            ->select('st.*');

        return $this->applyRawDateFilter($query, $this->stockTransferPickingDateExpression(), $shippingDates, $includePast);
    }
}
