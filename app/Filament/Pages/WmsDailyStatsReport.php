<?php

namespace App\Filament\Pages;

use App\Enums\EMenu;
use App\Filament\Support\AdminPage;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Warehouse;
use App\Services\WmsStatsService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Support\Icons\Heroicon;

class WmsDailyStatsReport extends AdminPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static ?string $slug = 'wms-daily-stats-report';

    protected static ?string $title = '';

    protected string $view = 'filament.pages.wms-daily-stats-report';

    public string $baseDate = '';

    public string $compareMode = 'previous_day';

    public string $warehouseId = 'all';

    public string $appliedBaseDate = '';

    public string $appliedCompareMode = 'previous_day';

    public string $appliedWarehouseId = 'all';

    public ?string $aggregateMessage = null;

    public ?string $aggregateError = null;

    public function mount(): void
    {
        $this->baseDate = ClientSetting::systemDateYMD();
        $this->search();
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_DAILY_STATS_REPORT->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_DAILY_STATS_REPORT->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_DAILY_STATS_REPORT->sort();
    }

    public function getMaxContentWidth(): ?string
    {
        return 'full';
    }

    public function search(): void
    {
        $this->appliedBaseDate = $this->baseDate ?: ClientSetting::systemDateYMD();
        $this->appliedCompareMode = $this->compareMode;
        $this->appliedWarehouseId = $this->warehouseId ?: 'all';
        $this->aggregateMessage = null;
        $this->aggregateError = null;
    }

    public function runAggregate(): void
    {
        $this->aggregateMessage = null;
        $this->aggregateError = null;

        try {
            $date = Carbon::parse($this->baseDate ?: ClientSetting::systemDateYMD());
            $warehouseIds = $this->selectedWarehouseIds($this->warehouseId);
            app(WmsStatsService::class)->bulkCalculate($date, $warehouseIds);
            $this->search();
            $this->aggregateMessage = $date->format('Y/m/d').' の統計を再集計しました。';
        } catch (\Throwable $e) {
            report($e);
            $this->aggregateError = $e->getMessage();
        }
    }

    public function getViewData(): array
    {
        $date = Carbon::parse($this->appliedBaseDate ?: ClientSetting::systemDateYMD());
        $compareDate = $this->compareDate($date, $this->appliedCompareMode);
        $warehouseIds = $this->selectedWarehouseIds($this->appliedWarehouseId);
        $statsService = app(WmsStatsService::class);

        $summary = $statsService->summarizeStored($date, $warehouseIds);
        $compareSummary = $statsService->summarizeStored($compareDate, $warehouseIds);

        return [
            'warehouses' => $this->warehouseOptions(),
            'compareOptions' => $this->compareOptions(),
            'baseDateLabel' => $date->format('Y/m/d'),
            'compareDateLabel' => $compareDate->format('Y/m/d'),
            'compareModeLabel' => $this->compareOptions()[$this->appliedCompareMode] ?? '比較',
            'summary' => $summary,
            'compareSummary' => $compareSummary,
            'cards' => $this->cards($summary, $compareSummary),
            'comparisonRows' => $this->comparisonRows($summary, $compareSummary),
            'warehouseRows' => $this->warehouseRows($date, $compareDate),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function compareOptions(): array
    {
        return [
            'previous_day' => '前日比',
            'previous_week' => '先週比',
            'previous_year' => '前年同曜日比',
        ];
    }

    private function compareDate(Carbon $date, string $mode): Carbon
    {
        return match ($mode) {
            'previous_week' => $date->copy()->subWeek(),
            'previous_year' => $date->copy()->subWeeks(52),
            default => $date->copy()->subDay(),
        };
    }

    /**
     * @return array<string, string>
     */
    private function warehouseOptions(): array
    {
        return Warehouse::query()
            ->where('is_active', true)
            ->where('is_virtual', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (Warehouse $warehouse) => [
                (string) $warehouse->id => '['.$warehouse->code.'] '.$warehouse->name,
            ])
            ->prepend('全倉庫', 'all')
            ->all();
    }

    /**
     * @return array<int>|null
     */
    private function selectedWarehouseIds(string $warehouseId): ?array
    {
        if ($warehouseId === 'all' || $warehouseId === '') {
            return null;
        }

        return [(int) $warehouseId];
    }

    /**
     * @param  array<string, int|float>  $summary
     * @param  array<string, int|float>  $compareSummary
     * @return array<int, array<string, mixed>>
     */
    private function cards(array $summary, array $compareSummary): array
    {
        return [
            $this->card('受注伝票', 'total_slip_count', $summary, $compareSummary, '件'),
            $this->card('売上金額（税抜）', 'total_amount_ex', $summary, $compareSummary, '円', true),
            $this->card('商品項目数', 'picking_item_count', $summary, $compareSummary, '件'),
            $this->card('引当欠品', 'allocation_shortage_qty', $summary, $compareSummary, '点'),
            $this->card('欠品確定', 'confirmed_shortage_qty', $summary, $compareSummary, '点'),
            $this->card('ユニーク顧客', 'unique_buyer_count', $summary, $compareSummary, '件'),
        ];
    }

    /**
     * @param  array<string, int|float>  $summary
     * @param  array<string, int|float>  $compareSummary
     * @return array<string, mixed>
     */
    private function card(string $label, string $key, array $summary, array $compareSummary, string $unit, bool $money = false): array
    {
        $value = (float) ($summary[$key] ?? 0);
        $compare = (float) ($compareSummary[$key] ?? 0);

        return [
            'label' => $label,
            'value' => $value,
            'compare' => $compare,
            'unit' => $unit,
            'money' => $money,
            'rate' => $compare > 0 ? round(($value / $compare) * 100, 1) : null,
            'delta' => $value - $compare,
        ];
    }

    /**
     * @param  array<string, int|float>  $summary
     * @param  array<string, int|float>  $compareSummary
     * @return array<int, array<string, mixed>>
     */
    private function comparisonRows(array $summary, array $compareSummary): array
    {
        $metrics = [
            ['label' => '受注伝票数', 'key' => 'total_slip_count', 'unit' => '件'],
            ['label' => '出荷済み', 'key' => 'shipped_slip_count', 'unit' => '件'],
            ['label' => '出荷前', 'key' => 'unshipped_slip_count', 'unit' => '件'],
            ['label' => '売上金額合計（税抜）', 'key' => 'total_amount_ex', 'unit' => '円', 'money' => true],
            ['label' => '出荷商品項目数', 'key' => 'picking_item_count', 'unit' => '件'],
            ['label' => 'ユニーク商品数', 'key' => 'unique_item_count', 'unit' => '件'],
            ['label' => '引当欠品数', 'key' => 'allocation_shortage_qty', 'unit' => '点'],
            ['label' => '欠品確定数', 'key' => 'confirmed_shortage_qty', 'unit' => '点'],
            ['label' => 'ユニーク顧客数', 'key' => 'unique_buyer_count', 'unit' => '件'],
            ['label' => '波動数', 'key' => 'wave_count', 'unit' => '件'],
            ['label' => 'ピッキングタスク数', 'key' => 'picking_task_count', 'unit' => '件'],
        ];

        return collect($metrics)
            ->map(function (array $metric) use ($summary, $compareSummary) {
                $current = (float) ($summary[$metric['key']] ?? 0);
                $compare = (float) ($compareSummary[$metric['key']] ?? 0);
                $max = max($current, $compare, 1);

                return $metric + [
                    'current' => $current,
                    'compare' => $compare,
                    'currentWidth' => round(($current / $max) * 100, 1),
                    'compareWidth' => round(($compare / $max) * 100, 1),
                    'rate' => $compare > 0 ? round(($current / $compare) * 100, 1) : null,
                    'delta' => $current - $compare,
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function warehouseRows(Carbon $date, Carbon $compareDate): array
    {
        $warehouseIds = $this->selectedWarehouseIds($this->appliedWarehouseId);
        $warehouses = Warehouse::query()
            ->where('is_active', true)
            ->where('is_virtual', false)
            ->when($warehouseIds !== null, fn ($query) => $query->whereIn('id', $warehouseIds))
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $statsService = app(WmsStatsService::class);

        return $warehouses
            ->map(function (Warehouse $warehouse) use ($date, $compareDate, $statsService) {
                $current = $statsService->summarizeStored($date, [(int) $warehouse->id]);
                $compare = $statsService->summarizeStored($compareDate, [(int) $warehouse->id]);

                return [
                    'warehouse' => '['.$warehouse->code.'] '.$warehouse->name,
                    'total_slip_count' => $current['total_slip_count'] ?? 0,
                    'total_amount_ex' => $current['total_amount_ex'] ?? 0,
                    'picking_item_count' => $current['picking_item_count'] ?? 0,
                    'allocation_shortage_qty' => $current['allocation_shortage_qty'] ?? 0,
                    'confirmed_shortage_qty' => $current['confirmed_shortage_qty'] ?? 0,
                    'unique_buyer_count' => $current['unique_buyer_count'] ?? 0,
                    'sales_rate' => ($compare['total_amount_ex'] ?? 0) > 0
                        ? round((($current['total_amount_ex'] ?? 0) / $compare['total_amount_ex']) * 100, 1)
                        : null,
                ];
            })
            ->all();
    }
}
