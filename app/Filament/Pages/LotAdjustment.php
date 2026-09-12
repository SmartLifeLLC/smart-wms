<?php

namespace App\Filament\Pages;

use App\Enums\EMenu;
use App\Filament\Support\AdminPage;
use App\Models\Sakemaru\Warehouse;
use App\Services\LotAdjustment\LotAdjustmentRunner;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;

/**
 * ロット調節（手動実行ツール / Phase 1）
 *
 * 選択倉庫に対し、A 相殺 / B §3.3 再ACTIVE化 / D STLA repoint を
 * プレビュー（DRY_RUN）→確認→実行（APPLIED）で適用する。
 * wave生成へは挿入しない。実行結果は wms_lot_adjustment_logs に記録される。
 */
class LotAdjustment extends AdminPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected string $view = 'filament.pages.lot-adjustment';

    public ?int $warehouseId = null;

    public ?string $warehouseName = null;

    /** @var array<string, mixed>|null 直近のプレビュー/実行結果 */
    public ?array $result = null;

    public ?string $resultMode = null;

    /** プレビューを実行した対象倉庫（APPLY ガード用） */
    public ?int $previewWarehouseId = null;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WAVE_MANAGEMENT_ADJUST_LOT->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WAVE_MANAGEMENT_ADJUST_LOT->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WAVE_MANAGEMENT_ADJUST_LOT->sort();
    }

    public function getTitle(): string
    {
        return 'ロット調節';
    }

    public function mount(): void
    {
        $this->resolveWarehouse();
    }

    protected function resolveWarehouse(): void
    {
        $this->warehouseId = auth()->user()?->getSelectedWarehouseId() ?? 91;
        $this->warehouseName = Warehouse::query()->whereKey($this->warehouseId)->value('name');
    }

    protected function currentWarehouseId(): int
    {
        return auth()->user()?->getSelectedWarehouseId() ?? 91;
    }

    /**
     * APPLY 可能条件:
     * - 直近プレビューが存在する
     * - resultMode === 'DRY_RUN'
     * - プレビュー対象倉庫と現在の選択倉庫が一致する
     * （再読込で result が消える/倉庫変更で不一致 → 実行不可）
     */
    public function canApply(): bool
    {
        return $this->result !== null
            && $this->resultMode === 'DRY_RUN'
            && $this->previewWarehouseId !== null
            && $this->previewWarehouseId === $this->currentWarehouseId();
    }

    /** summary 配列を全項目の日本語1行に整形 */
    protected function summaryLine(array $s): string
    {
        $g = fn (string $k) => (int) ($s[$k] ?? 0);

        return sprintf(
            '相殺 %d / 再ACTIVE %d / 残数0化 %d / 在庫数合わせ %d / 合わせ要手動 %d / STLA修正 %d / 複数棚番 %d / 空棚番 %d / RSLE再利用 %d / RSLE(WMS行) %d / SKIP %d / 棚番中止 %d',
            $g('offset'),
            $g('reactivate'),
            $g('zero_residual'),
            $g('sync_applied'),
            $g('sync_manual'),
            $g('repoint'),
            $g('multi_shelf'),
            $g('blank_location'),
            $g('reserved_reuse_risk'),
            $g('reserved_reuse_wms_exists'),
            $g('skipped'),
            $g('location_aborted'),
        );
    }

    protected function previewSummaryLine(): string
    {
        return $this->summaryLine($this->result['summary'] ?? [])
            .'（適用予定 '.($this->result['affected_count'] ?? 0).' 件）';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('プレビュー（変更しない）')
                ->icon('heroicon-o-magnifying-glass')
                ->color('primary')
                ->action(function (): void {
                    $this->resolveWarehouse();
                    $this->result = app(LotAdjustmentRunner::class)->run($this->warehouseId, false);
                    $this->resultMode = 'DRY_RUN';
                    $this->previewWarehouseId = $this->warehouseId;

                    Notification::make()
                        ->title('プレビューを生成しました（在庫は変更していません）')
                        ->success()
                        ->send();
                }),

            Action::make('apply')
                ->label('調節を実行')
                ->icon('heroicon-o-play')
                ->color('danger')
                ->disabled(fn (): bool => ! $this->canApply())
                ->requiresConfirmation()
                ->modalIcon('heroicon-o-exclamation-triangle')
                ->modalHeading('ロット調節の実行')
                ->modalDescription(fn () => "倉庫「{$this->warehouseName}」に対し、プレビュー結果を適用します。棚番（floor/location）は変更されません。\n\nプレビュー内訳: ".$this->previewSummaryLine())
                ->modalSubmitActionLabel('実行する')
                ->modalCancelActionLabel('実行せず閉じる')
                ->modalFooterActionsAlignment(Alignment::End)
                ->action(function (): void {
                    // 実行直前の必須ガード再検証（プレビュー無し/倉庫不一致/再読込は拒否）
                    if (! $this->canApply()) {
                        Notification::make()
                            ->title('実行できません')
                            ->body('プレビュー未実施、または倉庫が変更されています。再度プレビューしてください。')
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->result = app(LotAdjustmentRunner::class)->run($this->warehouseId, true);
                    $this->resultMode = 'APPLIED';
                    // 再適用防止: 実行後はプレビューを無効化（再実行には新たなプレビューが必要）
                    $this->previewWarehouseId = null;

                    Notification::make()
                        ->title("ロット調節を実行しました（適用 {$this->result['affected_count']} 件）")
                        ->body($this->summaryLine($this->result['summary'] ?? []))
                        ->success()
                        ->send();
                }),
        ];
    }
}
