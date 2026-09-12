<?php

namespace App\Filament\Resources\WmsWarehouseTransferCandidates\Pages;

use App\Filament\Resources\WmsWarehouseTransferCandidates\WmsWarehouseTransferCandidateResource;
use App\Models\Sakemaru\Warehouse;
use App\Services\WarehouseTransfer\WarehouseTransferCandidateReceiveService;
use App\Services\WarehouseTransfer\WarehouseTransferStatusSyncService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Alignment;
use Throwable;

class ListWmsWarehouseTransferCandidates extends ListRecords
{
    protected static string $resource = WmsWarehouseTransferCandidateResource::class;

    public function mount(): void
    {
        parent::mount();

        // 一覧表示時に queue 結果を候補へ投影する
        app(WarehouseTransferStatusSyncService::class)->syncAll();
    }

    public function getMaxContentWidth(): ?string
    {
        return 'full';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createFromWeb')
                ->label('候補を手入力で作成')
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => WmsWarehouseTransferCandidateResource::canCreate())
                ->modalHeading('倉庫移動候補を作成')
                ->modalWidth('2xl')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('候補を作成')->color('danger'))
                ->modalCancelActionLabel('作成せず閉じる')
                ->schema([
                    Select::make('from_warehouse_id')
                        ->label('移動元倉庫')
                        ->required()
                        ->searchable()
                        ->options(fn () => static::warehouseOptions())
                        ->getSearchResultsUsing(fn (string $search) => static::warehouseOptions($search)),
                    Select::make('to_warehouse_id')
                        ->label('移動先倉庫')
                        ->required()
                        ->different('from_warehouse_id')
                        ->searchable()
                        ->options(fn () => static::warehouseOptions())
                        ->getSearchResultsUsing(fn (string $search) => static::warehouseOptions($search)),
                    DatePicker::make('process_date')
                        ->label('処理日')
                        ->required()
                        ->default(now()->toDateString()),
                    DatePicker::make('delivered_date')
                        ->label('納品日')
                        ->required()
                        ->default(now()->toDateString()),
                    Textarea::make('memo')
                        ->label('備考')
                        ->rows(2),
                ])
                ->action(function (array $data): void {
                    try {
                        $candidate = app(WarehouseTransferCandidateReceiveService::class)
                            ->createFromWeb($data, auth()->id());
                    } catch (Throwable $e) {
                        Notification::make()->title('作成に失敗しました')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title("候補 {$candidate->candidate_no} を作成しました")->success()->send();

                    $this->redirect(WmsWarehouseTransferCandidateResource::getUrl('view', ['record' => $candidate]));
                }),
        ];
    }

    public static function warehouseOptions(?string $search = null): array
    {
        $search = $search !== null ? mb_convert_kana($search, 'as') : null;

        return Warehouse::query()
            ->where('is_active', true)
            ->when($search, fn ($q) => $q->where(fn ($qq) => $qq
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('code')
            ->limit(100)
            ->get()
            ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"])
            ->toArray();
    }
}
