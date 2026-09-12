<?php

namespace App\Filament\Resources\WmsJxUnknownIncomingSlips\Tables;

use App\Enums\PaginationOptions;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsIncomingImportError;
use App\Models\WmsIncomingReceivedDetail;
use App\Models\WmsIncomingReceivedFile;
use App\Models\WmsIncomingReceivedSlip;
use App\Services\AutoOrder\IncomingReceiveService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class WmsJxUnknownIncomingSlipsTable
{
    private const REVIEW_ERROR_CODES = [
        'EOS_ASSIGNMENT_NOT_FOUND',
        'EOS_UNASSIGNED_RECEIVED_SCHEDULE_CREATED',
        'ITEM_NOT_FOUND',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'wms-jx-unknown-incoming-slips-table sticky-actions'])
            ->modifyQueryUsing(function (Builder $query): Builder {
                $query->with([
                    'file.contractor',
                    'details.matchedItem',
                    'importErrors',
                ])
                    ->withCount([
                        'details',
                        'details as item_missing_count' => fn (Builder $query): Builder => $query
                            ->whereNull('matched_item_id')
                            ->where(fn (Builder $query): Builder => self::notIgnoredDetailQuery($query)),
                    ]);

                self::applyReviewScope($query);

                return $query->orderByDesc('id');
            })
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->alignCenter()
                    ->width('70px'),

                TextColumn::make('file.created_at')
                    ->label('取込日時')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('-')
                    ->width('120px'),

                TextColumn::make('slip_number')
                    ->label('受信伝票番号')
                    ->searchable()
                    ->copyable()
                    ->placeholder('-')
                    ->width('130px'),

                TextColumn::make('b_shop_code')
                    ->label('店舗CD')
                    ->searchable()
                    ->placeholder('-')
                    ->width('90px'),

                TextColumn::make('b_shop_name')
                    ->label('店舗名')
                    ->searchable()
                    ->placeholder('-')
                    ->limit(18)
                    ->tooltip(fn ($state) => $state),

                TextColumn::make('b_contractor_code')
                    ->label('先方仕入先CD')
                    ->searchable()
                    ->placeholder('-')
                    ->width('110px'),

                TextColumn::make('file.contractor.name')
                    ->label('受信仕入先')
                    ->state(fn (WmsIncomingReceivedSlip $record): string => self::resolvedContractorLabel($record))
                    ->searchable()
                    ->placeholder('-'),

                TextColumn::make('b_delivery_date')
                    ->label('納品日')
                    ->formatStateUsing(fn (?string $state): string => self::formatJxDate($state))
                    ->placeholder('-')
                    ->alignCenter()
                    ->width('100px'),

                TextColumn::make('details_count')
                    ->label('明細')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('item_missing_count')
                    ->label('商品不明')
                    ->numeric()
                    ->alignEnd()
                    ->color(fn ($state): string => (int) $state > 0 ? 'danger' : 'gray')
                    ->width('90px'),

                TextColumn::make('shortage_count')
                    ->label('欠品')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('file.status')
                    ->label('取込状態')
                    ->formatStateUsing(fn (?string $state): string => self::fileStatusLabel($state))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        WmsIncomingReceivedFile::STATUS_PENDING => 'warning',
                        WmsIncomingReceivedFile::STATUS_MATCHED => 'info',
                        WmsIncomingReceivedFile::STATUS_APPLIED => 'success',
                        WmsIncomingReceivedFile::STATUS_ERROR => 'danger',
                        WmsIncomingReceivedFile::STATUS_SKIPPED => 'gray',
                        default => 'gray',
                    })
                    ->width('90px'),

                TextColumn::make('file.received_message_id')
                    ->label('JXメッセージID')
                    ->searchable()
                    ->placeholder('-')
                    ->limit(28)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('match_status')
                    ->label('状態')
                    ->options([
                        'NO_ASSIGNMENT' => '割当なし',
                        'NOT_FOUND' => '該当なし',
                    ]),

                Filter::make('has_item_missing')
                    ->label('商品不明あり')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'details',
                        fn (Builder $detailQuery): Builder => $detailQuery
                            ->whereNull('matched_item_id')
                            ->where(fn (Builder $query): Builder => self::notIgnoredDetailQuery($query))
                    )),

                Filter::make('created_at')
                    ->form([
                        DatePicker::make('from')
                            ->label('取込日 From'),
                        DatePicker::make('until')
                            ->label('取込日 To'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->whereHas('file', function (Builder $fileQuery) use ($data): Builder {
                            return $fileQuery
                                ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date))
                                ->when($data['until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date));
                        });
                    }),
            ])
            ->recordActionsColumnLabel('操作')
            ->recordActions([
                Action::make('resolveMissingItem')
                    ->label('商品確定')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('warning')
                    ->visible(fn (WmsIncomingReceivedSlip $record): bool => self::hasMissingItemDetails($record))
                    ->modalHeading(fn (WmsIncomingReceivedSlip $record): string => "商品不明を確定: {$record->slip_number}")
                    ->modalDescription('商品不明の受信明細を選択し、商品マスタから正しい商品を検索して確定します。商品確定後も自動では入荷確定しません。必要に応じて詳細から入荷確定してください。')
                    ->modalWidth('3xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitActionLabel('商品を確定')
                    ->modalCancelActionLabel('確定せず閉じる')
                    ->fillForm(fn (array $arguments): array => [
                        'detail_id' => $arguments['detail_id'] ?? null,
                    ])
                    ->schema([
                        Select::make('detail_id')
                            ->label('商品不明明細')
                            ->options(fn (WmsIncomingReceivedSlip $record): array => self::missingDetailOptions($record))
                            ->searchable()
                            ->required(),
                        Select::make('item_id')
                            ->label('商品検索')
                            ->helperText('商品CD、商品名、JAN/検索CDで検索できます。')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => self::searchItemOptions($search))
                            ->getOptionLabelUsing(fn ($value): ?string => self::itemOptionLabel($value))
                            ->required(),
                    ])
                    ->action(function (WmsIncomingReceivedSlip $record, array $data): void {
                        $detail = WmsIncomingReceivedDetail::query()
                            ->where('received_slip_id', $record->id)
                            ->whereKey((int) $data['detail_id'])
                            ->firstOrFail();

                        try {
                            app(IncomingReceiveService::class)->resolveUnassignedJxDetailItem(
                                $detail,
                                (int) $data['item_id'],
                                auth()->id()
                            );

                            Notification::make()
                                ->title('商品を確定しました')
                                ->body('商品不明の受信明細の商品を確定しました。入荷確定は詳細から別途実行してください。')
                                ->success()
                                ->send();
                        } catch (\Throwable $throwable) {
                            Notification::make()
                                ->title('商品確定に失敗しました')
                                ->body($throwable->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('ignoreMissingItem')
                    ->label('商品不明を削除')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (WmsIncomingReceivedSlip $record): bool => self::hasMissingItemDetails($record))
                    ->requiresConfirmation()
                    ->modalHeading(fn (WmsIncomingReceivedSlip $record): string => "商品不明を削除: {$record->slip_number}")
                    ->modalDescription('受信原本は削除せず、選択した商品不明明細を入荷確定対象から外します。確定しない明細をリストからなくすための操作です。')
                    ->modalWidth('3xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitActionLabel('商品不明を削除')
                    ->modalCancelActionLabel('削除せず閉じる')
                    ->fillForm(fn (array $arguments): array => [
                        'detail_id' => $arguments['detail_id'] ?? null,
                    ])
                    ->schema([
                        Select::make('detail_id')
                            ->label('商品不明明細')
                            ->options(fn (WmsIncomingReceivedSlip $record): array => self::missingDetailOptions($record))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (WmsIncomingReceivedSlip $record, array $data): void {
                        $detail = WmsIncomingReceivedDetail::query()
                            ->where('received_slip_id', $record->id)
                            ->whereKey((int) $data['detail_id'])
                            ->firstOrFail();

                        try {
                            app(IncomingReceiveService::class)->ignoreUnassignedJxDetail($detail, auth()->id());

                            Notification::make()
                                ->title('商品不明を削除しました')
                                ->body('選択した明細を入荷確定対象から外しました。')
                                ->success()
                                ->send();
                        } catch (\Throwable $throwable) {
                            Notification::make()
                                ->title('商品不明の削除に失敗しました')
                                ->body($throwable->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('viewDetails')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (WmsIncomingReceivedSlip $record): string => "伝票番号不明: {$record->slip_number}")
                    ->modalWidth('7xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(fn (Action $action) => $action
                        ->makeModalSubmitAction('submit', [])
                        ->label('入荷確定')
                        ->color('danger'))
                    ->modalCancelActionLabel('入荷確定せず閉じる')
                    ->infolist(fn (WmsIncomingReceivedSlip $record): array => self::detailInfolist($record))
                    ->action(fn (WmsIncomingReceivedSlip $record): null => self::confirmUnknownIncomingSlip($record)),
            ], position: RecordActionsPosition::BeforeColumns)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('confirmUnknownIncoming')
                        ->label('選択を入荷完了として取込')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('選択した伝票番号不明データを入荷完了として取込')
                        ->modalDescription(fn (Collection $records): string => "選択した {$records->count()} 伝票の数量あり明細から入荷完了データを作成します。伝票番号は先方の受信伝票番号を使用します。仕入キュー作成は行いません。")
                        ->modalSubmitActionLabel('入荷完了として取込')
                        ->modalCancelActionLabel('取込せず閉じる')
                        ->action(function (Collection $records): void {
                            $service = app(IncomingReceiveService::class);
                            $created = 0;
                            $updated = 0;
                            $skipped = 0;
                            $scheduleIds = [];
                            $errors = [];

                            foreach ($records->sortBy('id') as $record) {
                                try {
                                    $result = $service->confirmUnassignedJxSlip($record, auth()->id());
                                    $created += $result['created'];
                                    $updated += $result['updated'];
                                    $skipped += $result['skipped'];
                                    $scheduleIds = array_merge($scheduleIds, $result['schedule_ids']);
                                } catch (\Throwable $throwable) {
                                    $errors[] = "伝票ID {$record->id}: {$throwable->getMessage()}";
                                }
                            }

                            $scheduleCount = count(array_unique($scheduleIds));
                            $body = "入荷完了データ: {$scheduleCount}件 / 新規: {$created}件 / 更新: {$updated}件 / 既存: {$skipped}件";

                            if ($errors !== []) {
                                $body .= "\n失敗: ".count($errors).'件';
                                $body .= "\n".collect($errors)->take(5)->implode("\n");
                            }

                            $notification = Notification::make()
                                ->title($errors === [] ? '入荷完了として取込しました' : '一部の取込に失敗しました')
                                ->body($body);

                            ($errors === [] ? $notification->success() : $notification->warning())->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    private static function confirmUnknownIncomingSlip(WmsIncomingReceivedSlip $record): null
    {
        $service = app(IncomingReceiveService::class);

        try {
            $result = $service->confirmUnassignedJxSlip($record, auth()->id());
            $scheduleCount = count(array_unique($result['schedule_ids']));

            Notification::make()
                ->title('入荷確定しました')
                ->body("入荷完了データ: {$scheduleCount}件 / 新規: {$result['created']}件 / 更新: {$result['updated']}件 / 既存: {$result['skipped']}件")
                ->success()
                ->send();
        } catch (\Throwable $throwable) {
            Notification::make()
                ->title('入荷確定に失敗しました')
                ->body($throwable->getMessage())
                ->danger()
                ->send();
        }

        return null;
    }

    public static function applyReviewScope(Builder $query): Builder
    {
        return $query
            ->whereHas('file', fn (Builder $query): Builder => $query->where('format_type', 'JX'))
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('match_status')
                    ->orWhere('match_status', '!=', 'IGNORED');
            })
            ->where(function (Builder $query): void {
                $query
                    ->where('match_status', 'NO_ASSIGNMENT')
                    ->orWhereHas('importErrors', fn (Builder $errorQuery): Builder => $errorQuery
                        ->whereIn('error_code', self::REVIEW_ERROR_CODES)
                        ->where(function (Builder $resolvedQuery): void {
                            $resolvedQuery
                                ->whereNull('is_resolved')
                                ->orWhere('is_resolved', false);
                        }));
            });
    }

    public static function applySelectedWarehouseScope(Builder $query, ?int $warehouseId): Builder
    {
        if (! $warehouseId) {
            return $query;
        }

        $warehouse = Warehouse::query()->find($warehouseId);
        if (! $warehouse) {
            return $query->whereRaw('1 = 0');
        }

        $codes = self::warehouseCodeCandidates($warehouse);
        if ($codes === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn((new WmsIncomingReceivedSlip)->getTable().'.b_shop_code', $codes);
    }

    private static function notIgnoredDetailQuery(Builder $query): Builder
    {
        return $query
            ->whereNull('match_status')
            ->orWhere('match_status', '!=', 'IGNORED');
    }

    private static function hasMissingItemDetails(WmsIncomingReceivedSlip $record): bool
    {
        if (array_key_exists('item_missing_count', $record->getAttributes())) {
            return (int) $record->item_missing_count > 0;
        }

        return $record->details()
            ->whereNull('matched_item_id')
            ->where(fn (Builder $query): Builder => self::notIgnoredDetailQuery($query))
            ->exists();
    }

    private static function missingDetailOptions(WmsIncomingReceivedSlip $record): array
    {
        return $record->details()
            ->whereNull('matched_item_id')
            ->where(fn (Builder $query): Builder => self::notIgnoredDetailQuery($query))
            ->orderBy('d_line_number')
            ->get()
            ->mapWithKeys(fn (WmsIncomingReceivedDetail $detail): array => [
                (string) $detail->id => self::missingDetailLabel($detail),
            ])
            ->all();
    }

    private static function missingDetailLabel(WmsIncomingReceivedDetail $detail): string
    {
        $line = $detail->d_line_number ?: '-';
        $jan = filled($detail->d_jan_code) ? $detail->d_jan_code : '-';
        $itemCode = filled($detail->d_item_code) ? $detail->d_item_code : '-';
        $name = self::hasVisibleText($detail->d_product_name) ? $detail->d_product_name : '受信商品名なし';

        return "商品名: {$name} / 行{$line} / JAN: {$jan} / 商品CD: {$itemCode} / 総バラ: {$detail->total_quantity}";
    }

    private static function searchItemOptions(string $search): array
    {
        $search = trim(mb_convert_kana($search, 'asKV'));
        if (mb_strlen($search) < 2) {
            return [];
        }

        return Item::query()
            ->with('piece_jan_code_information')
            ->where(function (Builder $query) use ($search): void {
                $query
                    ->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('kana', 'like', "%{$search}%")
                    ->orWhere('abbreviation', 'like', "%{$search}%")
                    ->orWhereHas('item_search_information', function (Builder $query) use ($search): void {
                        $query->where('search_string', 'like', "%{$search}%");
                    });
            })
            ->orderBy('code')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Item $item): array => [
                (string) $item->id => self::itemOptionLabelFromItem($item),
            ])
            ->all();
    }

    private static function itemOptionLabel($value): ?string
    {
        if (! $value) {
            return null;
        }

        $item = Item::query()
            ->with('piece_jan_code_information')
            ->whereKey((int) $value)
            ->first();

        return $item ? self::itemOptionLabelFromItem($item) : null;
    }

    private static function itemOptionLabelFromItem(Item $item): string
    {
        $parts = [
            "[{$item->code}]{$item->name}",
        ];

        $jan = $item->piece_jan_code_information?->search_string;
        if (filled($jan)) {
            $parts[] = "JAN: {$jan}";
        }

        if (filled($item->capacity_case)) {
            $parts[] = "入数: {$item->capacity_case}";
        }

        if (filled($item->volume)) {
            $parts[] = trim((string) $item->volume.(string) ($item->volume_unit ?? ''));
        }

        return implode(' / ', $parts);
    }

    /**
     * JXの店舗CDは4桁ゼロ埋めで届くため、倉庫CDの表記ゆれを吸収する。
     */
    private static function warehouseCodeCandidates(Warehouse $warehouse): array
    {
        $code = trim((string) $warehouse->code);
        if ($code === '') {
            return [];
        }

        $normalized = ltrim($code, '0');
        if ($normalized === '') {
            $normalized = '0';
        }

        $candidates = [$code, $normalized];
        if (ctype_digit($normalized)) {
            $candidates[] = str_pad($normalized, 4, '0', STR_PAD_LEFT);
        }
        if (ctype_digit($code)) {
            $candidates[] = str_pad($code, 4, '0', STR_PAD_LEFT);
        }

        return array_values(array_unique(array_filter($candidates, fn (string $candidate): bool => $candidate !== '')));
    }

    private static function detailInfolist(WmsIncomingReceivedSlip $record): array
    {
        $record->loadMissing(['file.contractor', 'details.matchedItem', 'importErrors']);

        return [
            Section::make('受信伝票')
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('slip_number')
                            ->label('受信伝票番号')
                            ->state($record->slip_number ?? '-')
                            ->copyable(),
                        TextEntry::make('b_shop_code')
                            ->label('店舗CD')
                            ->state($record->b_shop_code ?? '-'),
                        TextEntry::make('b_shop_name')
                            ->label('店舗名')
                            ->state($record->b_shop_name ?? '-'),
                        TextEntry::make('b_delivery_date')
                            ->label('納品日')
                            ->state(self::formatJxDate($record->b_delivery_date)),
                        TextEntry::make('b_contractor_code')
                            ->label('先方仕入先CD')
                            ->state($record->b_contractor_code ?? '-'),
                        TextEntry::make('file_contractor')
                            ->label('受信仕入先')
                            ->state(self::resolvedContractorLabel($record)),
                        TextEntry::make('file_status')
                            ->label('取込状態')
                            ->state(self::fileStatusLabel($record->file?->status)),
                    ]),
                ]),
            Section::make('確認内容')
                ->schema([
                    View::make('filament.components.jx-unknown-incoming-slip-errors')
                        ->viewData([
                            'errors' => $record->importErrors
                                ->sortBy('id')
                                ->map(fn (WmsIncomingImportError $error): array => [
                                    'message' => self::issueMessage($error),
                                ])
                                ->unique('message')
                                ->values(),
                        ]),
                ])
                ->collapsible(),
            Section::make('受信明細')
                ->schema([
                    View::make('filament.components.jx-unknown-incoming-slip-detail-table')
                        ->viewData([
                            'recordKey' => (string) $record->getKey(),
                            'details' => self::detailRows($record),
                        ]),
                ]),
        ];
    }

    private static function detailRows(WmsIncomingReceivedSlip $record): array
    {
        return $record->details
            ->sortBy('d_line_number')
            ->map(fn ($detail): array => [
                'id' => $detail->id,
                'line' => $detail->d_line_number,
                'matched_item_code' => $detail->matchedItem?->code ?: '-',
                'product_name' => self::detailProductName($detail),
                'jan_code' => $detail->d_jan_code ?: '-',
                'pack_quantity' => $detail->d_pack_quantity,
                'case_quantity' => $detail->d_case_quantity,
                'piece_quantity' => $detail->d_piece_quantity,
                'total_quantity' => $detail->total_quantity,
                'match_status' => self::matchStatusLabel($detail->match_status),
                'is_shortage' => $detail->is_shortage ? '欠品' : '',
                'can_adjust_item' => blank($detail->matched_item_id) && $detail->match_status !== 'IGNORED',
            ])
            ->values()
            ->all();
    }

    private static function detailProductName(WmsIncomingReceivedDetail $detail): string
    {
        if (self::hasVisibleText($detail->d_product_name)) {
            return (string) $detail->d_product_name;
        }

        $matchedItemName = (string) ($detail->matchedItem?->name ?: $detail->matchedItem?->name_main ?: '');
        if (self::hasVisibleText($matchedItemName)) {
            return $matchedItemName;
        }

        return '-';
    }

    private static function hasVisibleText(?string $value): bool
    {
        return preg_replace('/[\s　]+/u', '', (string) $value) !== '';
    }

    private static function resolvedContractorLabel(WmsIncomingReceivedSlip $record): string
    {
        $contractorId = app(IncomingReceiveService::class)->resolveUnassignedJxSlipContractorId($record);
        $contractor = $contractorId ? Contractor::query()->find($contractorId) : null;

        if ($contractor) {
            return filled($contractor->code)
                ? "[{$contractor->code}]{$contractor->name}"
                : $contractor->name;
        }

        return $record->file?->contractor?->name ?? '-';
    }

    private static function issueMessages(WmsIncomingReceivedSlip $record, string $separator): string
    {
        return $record->importErrors
            ->map(fn (WmsIncomingImportError $error): string => self::issueMessage($error))
            ->filter()
            ->unique()
            ->implode($separator);
    }

    private static function issueMessage(WmsIncomingImportError $error): string
    {
        $message = trim((string) $error->error_message);

        if ($message === '') {
            return self::issueCodeLabel($error->error_code);
        }

        return self::localizeIssueMessage($message);
    }

    private static function issueCodeLabel(?string $code): string
    {
        return match ($code) {
            'EOS_ASSIGNMENT_EMPTY' => '送信済みEOS伝票番号割当に発注候補IDがありません',
            'EOS_ASSIGNMENT_SCHEDULE_NOT_FOUND' => 'EOS伝票番号割当に対応する入荷予定が見つかりません',
            'EOS_CONTRACTOR_MISMATCH' => 'EOS受信伝票の仕入先が送信済み割当と一致しません',
            'EOS_ASSIGNMENT_NOT_FOUND' => '送信済みEOS伝票番号割当が見つかりません',
            'EOS_UNASSIGNED_CONTRACTOR_NOT_RESOLVED' => '未割当EOS受信伝票の仕入先を解決できません',
            'EOS_UNASSIGNED_WAREHOUSE_NOT_RESOLVED' => '未割当EOS受信伝票の倉庫を解決できません',
            'EOS_UNASSIGNED_NO_RECEIVED_QUANTITY' => '未割当EOS受信伝票に入荷数量のある明細がありません',
            'EOS_UNASSIGNED_RECEIVED_SCHEDULE_CREATED' => '未割当EOS受信伝票から入荷予定を作成済みです',
            'SLIP_NOT_FOUND' => '対応する入荷予定が見つかりません',
            'ITEM_NOT_FOUND' => '商品を特定できません',
            'SCHEDULE_ITEM_NOT_FOUND' => '伝票内の入荷予定に商品がありません',
            'SCHEDULE_STATUS_NOT_APPLICABLE' => '入荷予定が受信適用できない状態です',
            'PRICE_MISMATCH' => '単価が一致しません',
            default => '確認が必要です',
        };
    }

    private static function localizeIssueMessage(string $message): string
    {
        return str_replace([
            'slip_number=',
            'shop_code=',
            'schedule_id=',
            'status=',
            'slip_id=',
            'item_id=',
            'assignment_id=',
            'order_candidate_ids=',
            'b_contractor_code=',
            'file_contractor_id=',
            'detail_count=',
            ' vs ',
        ], [
            '伝票番号=',
            '店舗CD=',
            '入荷予定ID=',
            '状態=',
            '受信伝票ID=',
            '商品ID=',
            '伝票番号割当ID=',
            '発注候補ID=',
            '先方仕入先CD=',
            '受信仕入先ID=',
            '明細数=',
            ' / ',
        ], $message);
    }

    private static function formatJxDate(?string $state): string
    {
        if (! $state) {
            return '-';
        }

        try {
            $value = trim($state);
            if (preg_match('/^\d{8}$/', $value)) {
                return Carbon::createFromFormat('Ymd', $value)->format('Y-m-d');
            }

            if (preg_match('/^\d{6}$/', $value)) {
                return Carbon::createFromFormat('ymd', $value)->format('Y-m-d');
            }
        } catch (\Throwable) {
            return $state;
        }

        return $state;
    }

    private static function matchStatusLabel(?string $status): string
    {
        return match ($status) {
            'UNMATCHED' => '未照合',
            'NO_ASSIGNMENT' => '割当なし',
            'MATCHED' => '照合済み',
            'PARTIAL' => '一部欠品',
            'SHORTAGE' => '欠品',
            'NOT_FOUND' => '該当なし',
            'IGNORED' => '対象外',
            default => $status ? '未確認' : '-',
        };
    }

    private static function fileStatusLabel(?string $status): string
    {
        return match ($status) {
            WmsIncomingReceivedFile::STATUS_PENDING => '未照合',
            WmsIncomingReceivedFile::STATUS_MATCHED => '照合済み',
            WmsIncomingReceivedFile::STATUS_APPLIED => '適用済み',
            WmsIncomingReceivedFile::STATUS_ERROR => 'エラー',
            WmsIncomingReceivedFile::STATUS_SKIPPED => '対象外',
            default => $status ? '未確認' : '-',
        };
    }
}
