<?php

namespace App\Filament\Resources;

use App\Enums\EMenu;
use App\Enums\PaginationOptions;
use App\Filament\Resources\WmsJxEosLines\WmsJxEosLineResource;
use App\Filament\Resources\WmsJxTransmissionLogResource\Pages;
use App\Filament\Support\AdminResource;
use App\Jobs\ProcessEosIncomingReceiveRunJob;
use App\Models\WmsEosIncomingReceiveRun;
use App\Models\WmsIncomingReceivedFile;
use App\Models\WmsJxEosImportBatch;
use App\Models\WmsJxTransmissionLog;
use App\Services\JX\Eos\JxEosIncomingSkipService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class WmsJxTransmissionLogResource extends AdminResource
{
    protected static ?string $model = WmsJxTransmissionLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_JX_TRANSMISSION_LOGS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_JX_TRANSMISSION_LOGS->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_JX_TRANSMISSION_LOGS->sort();
    }

    public static function getModelLabel(): string
    {
        return 'JX受信履歴';
    }

    public static function getPluralModelLabel(): string
    {
        return 'JX受信履歴';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('transmitted_at', 'desc')
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->striped()
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['jxSetting', 'currentEosImport', 'incomingReceivedFile'])
                ->eosImportTarget()
            )
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('jxSetting.name')
                    ->label('JX設定')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('transmitted_at')
                    ->label('送受信日時')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
                TextColumn::make('direction')
                    ->label('方向')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        WmsJxTransmissionLog::DIRECTION_SEND => 'info',
                        WmsJxTransmissionLog::DIRECTION_RECEIVE => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (WmsJxTransmissionLog $record) => $record->direction_label),
                TextColumn::make('environment')
                    ->label('環境')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        WmsJxTransmissionLog::ENV_PRODUCTION => 'danger',
                        WmsJxTransmissionLog::ENV_TEST => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (WmsJxTransmissionLog $record) => $record->environment_label),
                TextColumn::make('operation_type')
                    ->label('操作タイプ')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        WmsJxTransmissionLog::OPERATION_PUT => 'warning',
                        WmsJxTransmissionLog::OPERATION_GET => 'primary',
                        WmsJxTransmissionLog::OPERATION_CONFIRM => 'gray',
                        default => 'gray',
                    }),
                IconColumn::make('status')
                    ->label('結果')
                    ->icon(fn (string $state): string => match ($state) {
                        WmsJxTransmissionLog::STATUS_SUCCESS => 'heroicon-o-check-circle',
                        WmsJxTransmissionLog::STATUS_FAILURE => 'heroicon-o-x-circle',
                        default => 'heroicon-o-question-mark-circle',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        WmsJxTransmissionLog::STATUS_SUCCESS => 'success',
                        WmsJxTransmissionLog::STATUS_FAILURE => 'danger',
                        default => 'gray',
                    })
                    ->alignCenter(),
                TextColumn::make('message_id')
                    ->label('メッセージID')
                    ->searchable()
                    ->limit(30)
                    ->tooltip(fn ($state) => $state),
                TextColumn::make('document_type')
                    ->label('文書タイプ')
                    ->alignCenter(),
                TextColumn::make('data_size')
                    ->label('サイズ')
                    ->formatStateUsing(fn (?int $state) => $state ? number_format($state).' bytes' : '-')
                    ->alignRight(),
                TextColumn::make('currentEosImport.status')
                    ->label('EOS取込')
                    ->badge()
                    ->placeholder('未取込')
                    ->formatStateUsing(fn (?string $state): string => $state ? (WmsJxEosImportBatch::statusLabels()[$state] ?? $state) : '未取込')
                    ->color(fn (?string $state): string => match ($state) {
                        WmsJxEosImportBatch::STATUS_SUCCEEDED => 'success',
                        WmsJxEosImportBatch::STATUS_FAILED => 'danger',
                        WmsJxEosImportBatch::STATUS_IMPORTING => 'warning',
                        WmsJxEosImportBatch::STATUS_SUPERSEDED => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('currentEosImport.finet_code')
                    ->label('FINET')
                    ->placeholder('-')
                    ->alignCenter(),
                TextColumn::make('currentEosImport.line_count')
                    ->label('EOS明細')
                    ->numeric()
                    ->alignRight()
                    ->placeholder('-'),
                TextColumn::make('incomingReceivedFile.status')
                    ->label('入荷予定更新')
                    ->badge()
                    ->placeholder('未取込')
                    ->formatStateUsing(fn (?string $state): string => self::incomingStatusLabel($state))
                    ->color(fn (?string $state): string => match ($state) {
                        WmsIncomingReceivedFile::STATUS_APPLIED => 'success',
                        WmsIncomingReceivedFile::STATUS_MATCHED => 'warning',
                        WmsIncomingReceivedFile::STATUS_PENDING => 'gray',
                        WmsIncomingReceivedFile::STATUS_ERROR => 'danger',
                        WmsIncomingReceivedFile::STATUS_SKIPPED => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('http_code')
                    ->label('HTTP')
                    ->badge()
                    ->color(fn (?int $state): string => match (true) {
                        $state === null => 'gray',
                        $state >= 200 && $state < 300 => 'success',
                        $state >= 400 => 'danger',
                        default => 'warning',
                    })
                    ->alignCenter(),
                TextColumn::make('error_message')
                    ->label('エラー')
                    ->limit(30)
                    ->tooltip(fn ($state) => $state)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('file_path')
                    ->label('ファイルパス')
                    ->limit(30)
                    ->tooltip(fn ($state) => $state)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sender_id')
                    ->label('送信者ID')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('receiver_id')
                    ->label('受信者ID')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('environment')
                    ->label('環境')
                    ->options([
                        WmsJxTransmissionLog::ENV_PRODUCTION => '本番',
                        WmsJxTransmissionLog::ENV_TEST => 'テスト',
                    ]),
                Filter::make('transmitted_at')
                    ->form([
                        DatePicker::make('from')
                            ->label('開始日'),
                        DatePicker::make('until')
                            ->label('終了日'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('transmitted_at', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('transmitted_at', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                Action::make('importEos')
                    ->label('EOS取込/入荷確定/仕入連携')
                    ->icon('heroicon-o-circle-stack')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('EOSデータを取り込み、入荷確定と仕入連携を実行')
                    ->modalDescription('スケジュール実行と同じ処理で、このGetDocumentログだけを対象にEOS取込、入荷予定照合、入荷確定、仕入データ自動生成まで実行します。')
                    ->visible(fn (WmsJxTransmissionLog $record): bool => self::isEosImportable($record))
                    ->action(function (WmsJxTransmissionLog $record): void {
                        try {
                            $ids = [(int) $record->id];
                            $runKey = self::manualRunKey($ids);

                            ProcessEosIncomingReceiveRunJob::dispatch(
                                $runKey,
                                null,
                                WmsEosIncomingReceiveRun::TRIGGER_MANUAL,
                                true,
                                $ids,
                            );

                            Notification::make()
                                ->title('EOS取込・入荷確定・仕入連携を開始しました')
                                ->body("対象ログ: {$record->id} / 実行キー: {$runKey}")
                                ->success()
                                ->send();
                        } catch (\Throwable $throwable) {
                            Notification::make()
                                ->title('EOS取込エラー')
                                ->body($throwable->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('skipEosIncoming')
                    ->label('対象外')
                    ->icon('heroicon-o-no-symbol')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('EOS受信データを取込対象外にする')
                    ->modalDescription('このGetDocumentログはEOS取込・入荷予定照合・入荷予定更新の対象から外します。JX受信履歴と原本ファイルは残ります。')
                    ->visible(fn (WmsJxTransmissionLog $record): bool => self::isEosSkippable($record))
                    ->action(function (WmsJxTransmissionLog $record): void {
                        try {
                            $file = app(JxEosIncomingSkipService::class)->skip($record, auth()->id());

                            Notification::make()
                                ->title('EOS受信データを対象外にしました')
                                ->body("入荷受信ID: {$file->id}（".self::incomingStatusLabel($file->status).'）')
                                ->success()
                                ->send();
                        } catch (\Throwable $throwable) {
                            Notification::make()
                                ->title('対象外処理エラー')
                                ->body($throwable->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('viewEosLines')
                    ->label('EOS明細')
                    ->icon('heroicon-o-list-bullet')
                    ->color('gray')
                    ->url(fn (WmsJxTransmissionLog $record): string => WmsJxEosLineResource::getUrl('index')
                        .'?'.http_build_query(['batch_id' => $record->currentEosImport?->id]))
                    ->visible(fn (WmsJxTransmissionLog $record): bool => filled($record->currentEosImport?->id)),
                Action::make('download')
                    ->label('ダウンロード')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('primary')
                    ->url(fn (WmsJxTransmissionLog $record) => route('jx-transmission-logs.download', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (WmsJxTransmissionLog $record) => ! empty($record->file_path)),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('importSelectedEos')
                        ->label('選択EOS取込/入荷確定/仕入連携')
                        ->icon('heroicon-o-circle-stack')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('選択したGetDocumentログをEOS取込・入荷確定・仕入連携')
                        ->modalDescription('スケジュール実行と同じ処理で、選択した取込可能なGetDocumentログだけを対象に仕入データ自動生成まで実行します。')
                        ->action(function ($records): void {
                            $ids = [];
                            $skipped = 0;

                            foreach ($records as $record) {
                                if (! self::isEosImportable($record)) {
                                    $skipped++;

                                    continue;
                                }

                                $ids[] = (int) $record->id;
                            }

                            if ($ids === []) {
                                Notification::make()
                                    ->title('取込対象がありません')
                                    ->body("スキップ: {$skipped}件")
                                    ->warning()
                                    ->send();

                                return;
                            }

                            try {
                                $runKey = self::manualRunKey($ids);

                                ProcessEosIncomingReceiveRunJob::dispatch(
                                    $runKey,
                                    null,
                                    WmsEosIncomingReceiveRun::TRIGGER_MANUAL,
                                    true,
                                    $ids,
                                );

                                Notification::make()
                                    ->title('選択EOS取込・入荷確定・仕入連携を開始しました')
                                    ->body('対象ログ: '.count($ids)."件 / 手動選択スキップ: {$skipped}件 / 実行キー: {$runKey}")
                                    ->success()
                                    ->send();
                            } catch (\Throwable $throwable) {
                                Notification::make()
                                    ->title('選択EOS取込エラー')
                                    ->body($throwable->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                    BulkAction::make('skipSelectedEosIncoming')
                        ->label('選択EOS対象外')
                        ->icon('heroicon-o-no-symbol')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading('選択したEOS受信データを対象外にする')
                        ->modalDescription('選択したGetDocumentログのうち、未適用のものだけをEOS取込・入荷予定照合・入荷予定更新の対象から外します。')
                        ->action(function ($records): void {
                            $service = app(JxEosIncomingSkipService::class);
                            $processed = 0;
                            $skipped = 0;
                            $failed = 0;
                            $failureMessages = [];

                            foreach ($records as $record) {
                                if (! self::isEosSkippable($record)) {
                                    $skipped++;

                                    continue;
                                }

                                try {
                                    $service->skip($record, auth()->id());
                                    $processed++;
                                } catch (\Throwable $throwable) {
                                    $failed++;

                                    if (count($failureMessages) < 3) {
                                        $failureMessages[] = "ID {$record->id}: {$throwable->getMessage()}";
                                    }

                                    Log::warning('EOS受信データ対象外処理に失敗しました', [
                                        'jx_transmission_log_id' => $record->id,
                                        'exception' => get_class($throwable),
                                        'error' => $throwable->getMessage(),
                                    ]);
                                }
                            }

                            $body = "対象外: {$processed}件 / スキップ: {$skipped}件 / 失敗: {$failed}件";
                            if ($failureMessages !== []) {
                                $body .= ' / 失敗理由: '.implode(' / ', $failureMessages);
                            }

                            $notification = Notification::make()
                                ->title('選択EOS対象外処理が完了しました')
                                ->body($body);

                            ($failed > 0 ? $notification->warning() : $notification->success())->send();
                        }),
                ]),
            ]);
    }

    private static function incomingStatusLabel(?string $state): string
    {
        return match ($state) {
            WmsIncomingReceivedFile::STATUS_PENDING => '照合待ち',
            WmsIncomingReceivedFile::STATUS_MATCHED => '照合済',
            WmsIncomingReceivedFile::STATUS_APPLIED => '更新済',
            WmsIncomingReceivedFile::STATUS_ERROR => 'エラー',
            WmsIncomingReceivedFile::STATUS_SKIPPED => '対象外',
            default => '未取込',
        };
    }

    private static function runResultBody(WmsEosIncomingReceiveRun $run, int $manualSkipped = 0): string
    {
        $body = "対象ログ: {$run->target_jx_log_count}件"
            ." / EOS取込: {$run->eos_imported_count}件"
            ." / 入荷確定: {$run->incoming_confirmed_schedule_count}明細"
            ." / 仕入キュー: {$run->purchase_queue_count}件"
            ." / 仕入明細: {$run->purchase_transmitted_schedule_count}明細"
            ." / 伝票不明: {$run->unknown_slip_count}件"
            ." / 91番除外: {$run->purchase_skipped_warehouse91_count}明細"
            ." / EOS送信履歴なし除外: {$run->purchase_skipped_not_eos_sent_count}明細"
            ." / 自動整理: {$run->shortage_completed_count}件";

        if ($manualSkipped > 0) {
            $body .= " / 手動選択スキップ: {$manualSkipped}件";
        }

        if ($run->error_count > 0) {
            $body .= " / エラー: {$run->error_count}件";
        }

        if (filled($run->error_summary)) {
            $body .= ' / エラー概要: '.$run->error_summary;
        }

        return $body;
    }

    private static function notificationStatus(WmsEosIncomingReceiveRun $run): string
    {
        return match ($run->status) {
            WmsEosIncomingReceiveRun::STATUS_FAILED => 'danger',
            WmsEosIncomingReceiveRun::STATUS_PARTIAL_FAILED => 'warning',
            default => 'success',
        };
    }

    private static function manualRunKey(array $jxTransmissionLogIds): string
    {
        $ids = collect($jxTransmissionLogIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->implode(',');

        return 'manual-jx-eos-'.sha1($ids.'|'.now()->format('YmdHisv').'|'.random_int(100000, 999999));
    }

    private static function isEosImportable(WmsJxTransmissionLog $record): bool
    {
        $receivedFile = $record->relationLoaded('incomingReceivedFile')
            ? $record->incomingReceivedFile
            : $record->incomingReceivedFile()->first();

        return $record->direction === WmsJxTransmissionLog::DIRECTION_RECEIVE
            && $record->environment === WmsJxTransmissionLog::ENV_PRODUCTION
            && $record->operation_type === WmsJxTransmissionLog::OPERATION_GET
            && $record->status === WmsJxTransmissionLog::STATUS_SUCCESS
            && filled($record->file_path)
            && ! in_array((string) ($receivedFile?->status), WmsIncomingReceivedFile::TERMINAL_STATUSES, true);
    }

    private static function isEosSkippable(WmsJxTransmissionLog $record): bool
    {
        return self::isEosImportable($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWmsJxTransmissionLogs::route('/'),
        ];
    }
}
