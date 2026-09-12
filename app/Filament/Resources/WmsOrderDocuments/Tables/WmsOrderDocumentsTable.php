<?php

namespace App\Filament\Resources\WmsOrderDocuments\Tables;

use App\Enums\AutoOrder\TransmissionDocumentStatus;
use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasOptimizedFilters;
use App\Models\User;
use App\Models\WmsJxTransmissionLog;
use App\Models\WmsOrderJxDocument;
use App\Services\AutoOrder\OrderTransmissionService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class WmsOrderDocumentsTable
{
    use HasOptimizedFilters;

    /**
     * メッセージIDでXMLファイルを検索（S3）
     */
    public static function findXmlFileByMessageId(string $messageId): ?string
    {
        $baseDir = 'jx-client/requests';

        // S3から全ファイルを取得してメッセージIDで検索
        $allFiles = Storage::disk('s3')->allFiles($baseDir);

        foreach ($allFiles as $file) {
            // ファイル名にメッセージIDが含まれているか確認
            if (str_contains(basename($file), $messageId)) {
                return $file;
            }
        }

        return null;
    }

    private static function canRestoreToPending(WmsOrderJxDocument $record): bool
    {
        if (! in_array($record->status, [
            TransmissionDocumentStatus::CANCELLED,
            TransmissionDocumentStatus::ERROR,
        ], true)) {
            return false;
        }

        return $record->orderCandidates()->exists();
    }

    private static function restoreToPending(WmsOrderJxDocument $record): void
    {
        $record->update([
            'status' => TransmissionDocumentStatus::PENDING,
            'error_message' => null,
        ]);
    }

    private static function resolveXmlMessageId(WmsOrderJxDocument $record): ?string
    {
        if (! empty($record->jx_message_id)) {
            return $record->jx_message_id;
        }

        if (! $record->wms_order_jx_setting_id || ! $record->updated_at) {
            return null;
        }

        return WmsJxTransmissionLog::query()
            ->where('jx_setting_id', $record->wms_order_jx_setting_id)
            ->where('direction', WmsJxTransmissionLog::DIRECTION_SEND)
            ->where('operation_type', WmsJxTransmissionLog::OPERATION_PUT)
            ->where('status', WmsJxTransmissionLog::STATUS_FAILURE)
            ->whereBetween('created_at', [
                $record->updated_at->copy()->subMinutes(5),
                $record->updated_at->copy()->addMinutes(5),
            ])
            ->latest('created_at')
            ->value('message_id');
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->alignEnd()
                    ->width('64px')
                    ->toggleable(),

                TextColumn::make('batch_code')
                    ->label('実行CD')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->formatStateUsing(fn (TransmissionDocumentStatus $state): string => $state->getLabel())
                    ->color(fn (TransmissionDocumentStatus $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('order_date')
                    ->label('発注日')
                    ->date('m/d')
                    ->sortable(),

                TextColumn::make('expected_arrival_date')
                    ->label('入荷日')
                    ->date('m/d')
                    ->sortable(),

                TextColumn::make('contractor.name')
                    ->label('発注先')
                    ->state(fn ($record) => $record->contractor
                        ? "[{$record->contractor->code}]{$record->contractor->name}"
                        : '-')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('order_count')
                    ->label('発注数')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('record_count')
                    ->label('明細数')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('file_size')
                    ->label('ファイルサイズ')
                    ->state(fn ($record) => $record->file_size
                        ? number_format($record->file_size / 1024, 1).'KB'
                        : '-')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('encoding')
                    ->label('文字コード')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('transmitted_at')
                    ->label('送信日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('transmitted_by_display_name')
                    ->label('送信者')
                    ->sortable(false)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_by_display_name')
                    ->label('作成者')
                    ->sortable(false)
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime('m/d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('created_by')
                    ->label('作成者')
                    ->searchable()
                    ->options(fn (): array => static::createdByOptions())
                    ->getSearchResultsUsing(fn (string $search): array => static::createdByOptions($search))
                    ->default(fn () => auth()->id())
                    ->query(function (Builder $query, array $data): void {
                        if (blank($data['value'])) {
                            return;
                        }

                        if ($data['value'] === '0') {
                            $query->whereNull('created_by');

                            return;
                        }

                        $query->where('created_by', (int) $data['value']);
                    }),

                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options(fn () => collect(TransmissionDocumentStatus::cases())
                        ->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()])),

                static::contractorFilter(),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('DAT')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('primary')
                    ->action(function (WmsOrderJxDocument $record) {
                        if (! $record->file_path) {
                            Notification::make()->title('ファイルが見つかりません')->danger()->send();

                            return;
                        }

                        $url = app(OrderTransmissionService::class)->getDownloadUrl($record);
                        if (! $url) {
                            Notification::make()->title('ダウンロードURLの生成に失敗しました')->danger()->send();

                            return;
                        }

                        return redirect($url);
                    }),

                Action::make('downloadCsv')
                    ->label('CSV')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->visible(fn ($record) => ! empty($record->csv_path))
                    ->action(function (WmsOrderJxDocument $record) {
                        $url = Storage::disk('s3')->temporaryUrl($record->csv_path, now()->addHour());

                        return $url ? redirect($url) : Notification::make()->title('CSVファイルが見つかりません')->danger()->send();
                    }),

                Action::make('downloadXml')
                    ->label('XML')
                    ->icon('heroicon-o-code-bracket')
                    ->color('warning')
                    ->visible(fn (WmsOrderJxDocument $record) => ! empty($record->jx_message_id)
                        || $record->status === TransmissionDocumentStatus::ERROR)
                    ->action(function (WmsOrderJxDocument $record) {
                        $messageId = self::resolveXmlMessageId($record);
                        if (! $messageId) {
                            Notification::make()->title('送信XMLのメッセージIDが見つかりません')->danger()->send();

                            return;
                        }

                        $xmlPath = self::findXmlFileByMessageId($messageId);

                        if (! $xmlPath) {
                            Notification::make()->title('送信XMLが見つかりません')->danger()->send();

                            return;
                        }

                        return redirect(route('jx-xml-files.download', ['path' => $xmlPath]));
                    }),

                Action::make('retransmit')
                    ->label('再送信')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('danger')
                    ->visible(fn ($record) => $record->status === TransmissionDocumentStatus::TRANSMITTED)
                    ->requiresConfirmation()
                    ->modalHeading('JXファイルを再生成して再送信')
                    ->modalDescription('送信済みの発注データから新しいJXファイルを作成して送信します。')
                    ->modalSubmitActionLabel('新規作成して再送信')
                    ->modalCancelActionLabel('送信せず閉じる')
                    ->action(function (WmsOrderJxDocument $record) {
                        $result = app(OrderTransmissionService::class)->retransmitDocumentById($record->id);

                        if ($result['success']) {
                            Notification::make()
                                ->title('再送信しました')
                                ->body('新規伝票ID: '.($result['document_id'] ?? '-').' / message_id: '.($result['message_id'] ?? '-'))
                                ->success()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('再送信に失敗しました')
                            ->body($result['error'] ?? '送信失敗')
                            ->danger()
                            ->send();
                    }),

                Action::make('cancelPendingAndRestore')
                    ->label('生成取消')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn ($record) => $record->status === TransmissionDocumentStatus::PENDING)
                    ->requiresConfirmation()
                    ->modalHeading('送信前JXデータを生成取消')
                    ->modalDescription('このJXデータを送信取消にし、紐づく発注候補をJX生成前に戻します。送信済みデータには実行できません。')
                    ->modalSubmitActionLabel('生成取消する')
                    ->modalCancelActionLabel('取消せず閉じる')
                    ->action(function (WmsOrderJxDocument $record) {
                        $result = app(OrderTransmissionService::class)
                            ->cancelPendingJxDocumentAndRestoreCandidates($record->id);

                        if ($result['success'] ?? false) {
                            Notification::make()
                                ->title('JX生成を取消しました')
                                ->body('発注候補 '.($result['restored_count'] ?? 0).'件をJX生成前に戻しました。')
                                ->success()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('JX生成取消に失敗しました')
                            ->body($result['error'] ?? '生成取消できませんでした')
                            ->danger()
                            ->send();
                    }),

                Action::make('restorePending')
                    ->label('送信待ちへ')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (WmsOrderJxDocument $record) => self::canRestoreToPending($record))
                    ->requiresConfirmation()
                    ->modalHeading('JXデータを送信待ちに戻す')
                    ->modalDescription('候補との紐づきが残っている送信取消または送信エラーのJXデータを「送信待ち」に戻します。エラー内容はクリアされます。')
                    ->modalSubmitActionLabel('送信待ちに戻す')
                    ->modalCancelActionLabel('戻さず閉じる')
                    ->action(function (WmsOrderJxDocument $record) {
                        self::restoreToPending($record);

                        Notification::make()
                            ->title('送信待ちに戻しました')
                            ->body('対象行を選択して「選択JX送信」から送信してください')
                            ->success()
                            ->send();
                    }),

                Action::make('delete')
                    ->label('削除')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn ($record) => in_array($record->status, [
                        TransmissionDocumentStatus::DRAFT,
                        TransmissionDocumentStatus::TEST,
                    ]))
                    ->requiresConfirmation()
                    ->action(function (WmsOrderJxDocument $record) {
                        if ($record->file_path && Storage::disk('s3')->exists($record->file_path)) {
                            Storage::disk('s3')->delete($record->file_path);
                        }
                        $record->delete();
                        Notification::make()->title('削除しました')->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('transmitSelectedJx')
                        ->label('選択JX送信')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('選択データをJX送信')
                        ->modalDescription(fn (Collection $records) => "選択した {$records->count()} 件の発注データのうち、JX対象は個別に送信し、JX対象外は送信せず失敗完了にします。選択外の確定済みデータは処理しません。")
                        ->modalSubmitActionLabel('JX送信')
                        ->modalCancelActionLabel('送信せず閉じる')
                        ->action(function (Collection $records) {
                            try {
                                $result = app(OrderTransmissionService::class)->transmitSelectedDocuments($records);
                            } catch (\Throwable $e) {
                                Notification::make()
                                    ->title('JX送信に失敗しました')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();

                                return;
                            }

                            if (! empty($result['transmitted'])) {
                                $count = $result['order_count'] ?? count($result['transmitted']);
                                Notification::make()
                                    ->title("JX送信完了（{$count}件）")
                                    ->success()
                                    ->send();
                            }

                            if (! empty($result['completed'])) {
                                $count = $result['completed_order_count'] ?? count($result['completed']);
                                Notification::make()
                                    ->title("JX対象外を失敗完了にしました（{$count}件）")
                                    ->warning()
                                    ->send();
                            }

                            if (! empty($result['skipped'])) {
                                Notification::make()
                                    ->title('送信中または処理済みのデータをスキップしました（'.count($result['skipped']).'件）')
                                    ->warning()
                                    ->send();
                            }

                            if (! empty($result['errors'])) {
                                $errorMessages = collect($result['errors'])
                                    ->map(fn ($e) => $e['error'] ?? '送信失敗')
                                    ->implode("\n");
                                Notification::make()
                                    ->title('送信エラー')
                                    ->body($errorMessages)
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('bulkCancelPendingAndRestore')
                        ->label('選択JX生成取消')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('選択した送信前JXデータを生成取消')
                        ->modalDescription(fn (Collection $records) => "選択した {$records->count()} 件のうち、送信待ちのJXデータを送信取消にし、発注候補をJX生成前に戻します。送信済みデータは対象外です。")
                        ->modalSubmitActionLabel('生成取消する')
                        ->modalCancelActionLabel('取消せず閉じる')
                        ->action(function (Collection $records) {
                            $service = app(OrderTransmissionService::class);
                            $cancelled = 0;
                            $restored = 0;
                            $errors = [];

                            foreach ($records as $record) {
                                if ($record->status !== TransmissionDocumentStatus::PENDING) {
                                    continue;
                                }

                                $result = $service->cancelPendingJxDocumentAndRestoreCandidates($record->id);
                                if ($result['success'] ?? false) {
                                    $cancelled++;
                                    $restored += (int) ($result['restored_count'] ?? 0);
                                } else {
                                    $errors[] = "ID {$record->id}: ".($result['error'] ?? '生成取消できませんでした');
                                }
                            }

                            if ($cancelled > 0) {
                                Notification::make()
                                    ->title("JX生成を取消しました（{$cancelled}件）")
                                    ->body("発注候補 {$restored}件をJX生成前に戻しました。")
                                    ->success()
                                    ->send();
                            }

                            if (! empty($errors)) {
                                Notification::make()
                                    ->title(count($errors).'件でエラーが発生しました')
                                    ->body(implode("\n", array_slice($errors, 0, 5)))
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('bulkRestorePending')
                        ->label('送信待ちに戻す')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('送信取消・送信エラーを送信待ちに戻す')
                        ->modalDescription(fn (Collection $records) => "選択した {$records->count()} 件のうち、候補との紐づきが残っている送信取消または送信エラーのJXデータを「送信待ち」に戻します。戻した後、対象行を選択して「選択JX送信」から送信できます。")
                        ->modalSubmitActionLabel('送信待ちに戻す')
                        ->modalCancelActionLabel('戻さず閉じる')
                        ->action(function (Collection $records) {
                            $restorable = $records
                                ->filter(fn (WmsOrderJxDocument $record) => self::canRestoreToPending($record));

                            $restorable->each(fn (WmsOrderJxDocument $record) => self::restoreToPending($record));

                            $count = $restorable->count();
                            $skipped = $records->count() - $count;

                            Notification::make()
                                ->title("送信待ちに戻しました（{$count}件）")
                                ->body($skipped > 0
                                    ? "候補紐づきがない、または対象ステータスではない {$skipped}件 はスキップしました。"
                                    : '対象行を選択して「選択JX送信」から送信してください')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private static function createdByOptions(?string $search = null): array
    {
        $documentTable = (new WmsOrderJxDocument)->getTable();
        $currentUserId = auth()->id();

        $query = User::query()
            ->where(function (Builder $query) use ($documentTable, $currentUserId): void {
                $query->whereIn('id', fn ($subQuery) => $subQuery
                    ->select('created_by')
                    ->from($documentTable)
                    ->whereNotNull('created_by')
                    ->distinct());

                if ($currentUserId) {
                    $query->orWhere($query->getModel()->getQualifiedKeyName(), $currentUserId);
                }
            });

        if ($search) {
            $search = mb_convert_kana($search, 'as');
            $query->where(fn (Builder $query) => $query
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%"));
        }

        $results = $query
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (User $user) => [
                $user->id => filled($user->code) ? "[{$user->code}]{$user->name}" : $user->name,
            ])
            ->toArray();

        if (! $search || str_contains('システム', $search)) {
            $results = ['0' => 'システム'] + $results;
        }

        return $results;
    }
}
