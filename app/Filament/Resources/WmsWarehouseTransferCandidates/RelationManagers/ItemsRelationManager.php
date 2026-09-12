<?php

namespace App\Filament\Resources\WmsWarehouseTransferCandidates\RelationManagers;

use App\Filament\Resources\WmsWarehouseTransferCandidates\WmsWarehouseTransferCandidateResource;
use App\Models\WmsWarehouseTransferCandidate;
use App\Models\WmsWarehouseTransferCandidateItem;
use App\Services\WarehouseTransfer\WarehouseTransferCandidateReceiveService;
use App\Services\WarehouseTransfer\WarehouseTransferStockListService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 倉庫移動候補 明細（PENDING のみ追加/数量修正/削除可）
 */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = '明細';

    protected static ?string $modelLabel = '明細';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->striped()
            ->paginated(false)
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('item_code')
                    ->label('商品CD')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('item_name')
                    ->label('商品名')
                    ->searchable()
                    ->grow(),

                TextColumn::make('location_no')
                    ->label('ロケーション')
                    ->placeholder('-'),

                TextColumn::make('current_available')
                    ->label('現在在庫')
                    ->alignEnd()
                    ->state(fn (WmsWarehouseTransferCandidateItem $record) => number_format($this->currentAvailable($record)))
                    ->color(fn (WmsWarehouseTransferCandidateItem $record) => $this->currentAvailable($record) < (float) $record->transfer_quantity ? 'danger' : null),

                TextColumn::make('case_quantity')
                    ->label('ケース')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => number_format((float) $state)),

                TextColumn::make('piece_quantity')
                    ->label('バラ')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => number_format((float) $state)),

                TextColumn::make('package_quantity')
                    ->label('入数')
                    ->alignEnd(),

                TextColumn::make('transfer_quantity')
                    ->label('総バラ')
                    ->alignEnd()
                    ->weight('bold')
                    ->formatStateUsing(fn ($state) => number_format((float) $state))
                    ->summarize(\Filament\Tables\Columns\Summarizers\Sum::make()->label('合計')->formatStateUsing(fn ($state) => number_format((float) $state))),

                TextColumn::make('stock_allocation_code')
                    ->label('在庫区分CD'),

                TextColumn::make('scanned_code')
                    ->label('読取CD')
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('source_line_count')
                    ->label('HANDY行数')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('line_note')
                    ->label('備考')
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->headerActions([
                Action::make('addItem')
                    ->label('明細追加')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => $this->isEditable())
                    ->modalHeading('明細を追加')
                    ->modalWidth('2xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('追加する')->color('danger'))
                    ->modalCancelActionLabel('追加せず閉じる')
                    ->schema([
                        Select::make('item_id')
                            ->label('商品')
                            ->required()
                            ->searchable()
                            ->searchPrompt('商品CD / 商品名 / JAN で検索')
                            ->getSearchResultsUsing(fn (string $search) => static::itemSearchOptions($search))
                            ->getOptionLabelUsing(function ($value): ?string {
                                $item = app(WarehouseTransferStockListService::class)->itemMaster((int) $value);

                                return $item ? "[{$item->code}]{$item->name}" : null;
                            })
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set): void {
                                $item = $state ? app(WarehouseTransferStockListService::class)->itemMaster((int) $state) : null;
                                $set('package_quantity', max((int) ($item?->capacity_case ?? 1), 1));
                            }),
                        TextInput::make('package_quantity')
                            ->label('入数')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                        TextInput::make('case_quantity')
                            ->label('ケース')
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
                        TextInput::make('piece_quantity')
                            ->label('バラ')
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
                        TextInput::make('stock_allocation_code')
                            ->label('在庫区分CD')
                            ->default('1')
                            ->required()
                            ->maxLength(32),
                        TextInput::make('line_note')
                            ->label('明細備考')
                            ->maxLength(255),
                    ])
                    ->action(function (array $data): void {
                        try {
                            app(WarehouseTransferCandidateReceiveService::class)->addItemFromWeb(
                                $this->getOwnerRecord(),
                                (int) $data['item_id'],
                                (float) ($data['case_quantity'] ?? 0),
                                (float) ($data['piece_quantity'] ?? 0),
                                (int) ($data['package_quantity'] ?? 1),
                                (string) ($data['stock_allocation_code'] ?: '1'),
                                $data['line_note'] ?? null,
                            );
                        } catch (Throwable $e) {
                            Notification::make()->title('明細追加に失敗しました')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('明細を追加しました')->success()->send();
                    }),
            ])
            ->recordActionsColumnLabel('操作')
            ->recordActions([
                Action::make('editQuantity')
                    ->label('数量修正')
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray')
                    ->visible(fn (): bool => $this->isEditable())
                    ->modalHeading(fn (WmsWarehouseTransferCandidateItem $record) => "数量修正: [{$record->item_code}] {$record->item_name}")
                    ->modalWidth('xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('変更を保存')->color('danger'))
                    ->modalCancelActionLabel('変更せず閉じる')
                    ->fillForm(fn (WmsWarehouseTransferCandidateItem $record): array => [
                        'package_quantity' => (int) $record->package_quantity,
                        'case_quantity' => (float) $record->case_quantity,
                        'piece_quantity' => (float) $record->piece_quantity,
                        'stock_allocation_code' => $record->stock_allocation_code,
                        'line_note' => $record->line_note,
                    ])
                    ->schema([
                        TextInput::make('package_quantity')->label('入数')->numeric()->minValue(1)->required(),
                        TextInput::make('case_quantity')->label('ケース')->numeric()->minValue(0)->required(),
                        TextInput::make('piece_quantity')->label('バラ')->numeric()->minValue(0)->required(),
                        TextInput::make('stock_allocation_code')->label('在庫区分CD')->required()->maxLength(32),
                        TextInput::make('line_note')->label('明細備考')->maxLength(255),
                    ])
                    ->action(function (WmsWarehouseTransferCandidateItem $record, array $data): void {
                        if (! $this->isEditable()) {
                            Notification::make()->title('確定済みの候補は編集できません')->danger()->send();

                            return;
                        }

                        $packageQuantity = max((int) $data['package_quantity'], 1);
                        $transferQuantity = WmsWarehouseTransferCandidateItem::calculateTransferQuantity(
                            (float) $data['case_quantity'],
                            $packageQuantity,
                            (float) $data['piece_quantity'],
                        );

                        if ($transferQuantity <= 0) {
                            Notification::make()->title('総バラ数は0より大きくしてください')->danger()->send();

                            return;
                        }

                        $allocationCode = (string) ($data['stock_allocation_code'] ?: '1');
                        $duplicate = WmsWarehouseTransferCandidateItem::query()
                            ->where('candidate_id', $record->candidate_id)
                            ->where('item_id', $record->item_id)
                            ->where('stock_allocation_code', $allocationCode)
                            ->whereKeyNot($record->id)
                            ->exists();

                        if ($duplicate) {
                            Notification::make()->title('同じ商品・在庫区分の明細が既に存在します')->danger()->send();

                            return;
                        }

                        $record->update([
                            'package_quantity' => $packageQuantity,
                            'case_quantity' => (float) $data['case_quantity'],
                            'piece_quantity' => (float) $data['piece_quantity'],
                            'transfer_quantity' => $transferQuantity,
                            'stock_allocation_code' => $allocationCode,
                            'line_note' => $data['line_note'] ?? null,
                        ]);

                        Notification::make()->title('明細を更新しました')->success()->send();
                    }),

                Action::make('deleteItem')
                    ->label('削除')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (): bool => $this->isEditable())
                    ->requiresConfirmation()
                    ->modalHeading(fn (WmsWarehouseTransferCandidateItem $record) => "明細を削除: [{$record->item_code}] {$record->item_name}")
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitActionLabel('削除する')
                    ->modalCancelActionLabel('削除せず閉じる')
                    ->action(function (WmsWarehouseTransferCandidateItem $record): void {
                        if (! $this->isEditable()) {
                            Notification::make()->title('確定済みの候補は編集できません')->danger()->send();

                            return;
                        }

                        DB::connection('sakemaru')->transaction(function () use ($record): void {
                            $record->sources()->delete();
                            $record->delete();
                        });

                        Notification::make()->title('明細を削除しました')->success()->send();
                    }),
            ], position: RecordActionsPosition::AfterColumns)
            ->extraAttributes(['class' => 'sticky-actions']);
    }

    private function isEditable(): bool
    {
        /** @var WmsWarehouseTransferCandidate $owner */
        $owner = $this->getOwnerRecord()->fresh();

        return $owner->isEditable() && WmsWarehouseTransferCandidateResource::canEdit($owner);
    }

    private function currentAvailable(WmsWarehouseTransferCandidateItem $record): float
    {
        static $cache = [];

        $owner = $this->getOwnerRecord();
        $key = "{$owner->from_warehouse_id}:{$record->stock_allocation_code}";

        if (! array_key_exists($key, $cache)) {
            $itemIds = $owner->items()->where('stock_allocation_code', $record->stock_allocation_code)->pluck('item_id')->all();
            $cache[$key] = app(WarehouseTransferStockListService::class)
                ->availableQuantityByItem((int) $owner->from_warehouse_id, $itemIds, (string) $record->stock_allocation_code);
        }

        return (float) ($cache[$key][(int) $record->item_id] ?? 0);
    }

    public static function itemSearchOptions(string $search): array
    {
        $keyword = mb_convert_kana(trim($search), 'as');
        if ($keyword === '') {
            return [];
        }
        $like = "%{$keyword}%";

        $query = DB::connection('sakemaru')
            ->table('items as i')
            ->where('i.is_active', 1)
            ->where(function ($q) use ($keyword, $like): void {
                $q->where('i.code', 'like', $like)
                    ->orWhere('i.name', 'like', $like)
                    ->orWhereExists(function ($sub) use ($keyword): void {
                        $sub->selectRaw('1')
                            ->from('item_search_information as isi')
                            ->whereColumn('isi.item_id', 'i.id')
                            ->where('isi.is_active', 1)
                            ->where(function ($qq) use ($keyword): void {
                                $qq->where('isi.search_string', $keyword)
                                    ->orWhereRaw('LPAD(isi.search_string, 13, "0") = ?', [$keyword]);
                            });
                    });
            })
            ->orderBy('i.code')
            ->limit(50);

        if (config('app.client_id')) {
            $query->where('i.client_id', (int) config('app.client_id'));
        }

        return $query->get(['i.id', 'i.code', 'i.name'])
            ->mapWithKeys(fn ($row) => [$row->id => "[{$row->code}]{$row->name}"])
            ->toArray();
    }
}
