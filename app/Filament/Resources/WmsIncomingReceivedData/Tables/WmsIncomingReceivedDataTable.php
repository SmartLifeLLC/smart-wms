<?php

namespace App\Filament\Resources\WmsIncomingReceivedData\Tables;

use App\Enums\AutoOrder\OrderSource;
use App\Enums\PaginationOptions;
use App\Models\WmsIncomingImportError;
use App\Models\WmsIncomingReceivedFile;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\IncomingReceiveService;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class WmsIncomingReceivedDataTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'incoming-received-table sticky-actions'])
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->width('50px'),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        WmsIncomingReceivedFile::STATUS_PENDING => '未照合',
                        WmsIncomingReceivedFile::STATUS_MATCHED => '照合済み',
                        WmsIncomingReceivedFile::STATUS_APPLIED => '適用済み',
                        WmsIncomingReceivedFile::STATUS_ERROR => 'エラー',
                        WmsIncomingReceivedFile::STATUS_SKIPPED => '対象外',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        WmsIncomingReceivedFile::STATUS_PENDING => 'warning',
                        WmsIncomingReceivedFile::STATUS_MATCHED => 'info',
                        WmsIncomingReceivedFile::STATUS_APPLIED => 'success',
                        WmsIncomingReceivedFile::STATUS_ERROR => 'danger',
                        WmsIncomingReceivedFile::STATUS_SKIPPED => 'gray',
                        default => 'gray',
                    })
                    ->sortable()
                    ->width('90px'),

                TextColumn::make('format_type')
                    ->label('形式')
                    ->badge()
                    ->color('gray')
                    ->width('60px'),

                TextColumn::make('confirm_status')
                    ->label('Confirm')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'PENDING' => '未送信',
                        'SENT' => '送信済',
                        'FAILED' => '失敗',
                        'SKIPPED' => '対象外',
                        default => '-',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'PENDING' => 'warning',
                        'SENT' => 'success',
                        'FAILED' => 'danger',
                        'SKIPPED' => 'gray',
                        default => 'gray',
                    })
                    ->width('80px'),

                TextColumn::make('raw_file_path')
                    ->label('原本')
                    ->formatStateUsing(fn (?string $state): string => $state ? '保存済' : '-')
                    ->badge()
                    ->color(fn (?string $state): string => $state ? 'success' : 'gray')
                    ->width('70px'),

                TextColumn::make('filename')
                    ->label('ファイル名')
                    ->searchable()
                    ->limit(40)
                    ->width('250px'),

                TextColumn::make('a_company_name')
                    ->label('送信元')
                    ->searchable()
                    ->placeholder('-')
                    ->width('150px'),

                TextColumn::make('parsed_slip_count')
                    ->label('伝票数')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('parsed_detail_count')
                    ->label('明細数')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('a_created_date')
                    ->label('データ作成日')
                    ->placeholder('-')
                    ->alignCenter()
                    ->width('100px'),

                TextColumn::make('has_finet_wrapper')
                    ->label('FINET')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'あり' : 'なし')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'info' : 'gray')
                    ->width('70px'),

                TextColumn::make('error_message')
                    ->label('エラー')
                    ->limit(30)
                    ->placeholder('-')
                    ->color('danger')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('received_message_id')
                    ->label('JXメッセージID')
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('confirm_error_message')
                    ->label('Confirmエラー')
                    ->limit(30)
                    ->placeholder('-')
                    ->color('danger')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('取込日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->width('100px'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        WmsIncomingReceivedFile::STATUS_PENDING => '未照合',
                        WmsIncomingReceivedFile::STATUS_MATCHED => '照合済み',
                        WmsIncomingReceivedFile::STATUS_APPLIED => '適用済み',
                        WmsIncomingReceivedFile::STATUS_ERROR => 'エラー',
                        WmsIncomingReceivedFile::STATUS_SKIPPED => '対象外',
                    ]),

                SelectFilter::make('format_type')
                    ->label('形式')
                    ->options([
                        'JX' => 'JX',
                        'CSV' => 'CSV',
                    ]),
            ])
            ->recordActionsColumnLabel('操作')
            ->recordActions([
                Action::make('viewSlips')
                    ->label('伝票一覧')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn ($record) => "受信伝票一覧 (ID: {$record->id})")
                    ->modalWidth('7xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる')
                    ->infolist(function ($record): array {
                        $file = $record->load('slips.details');
                        $slips = $file->slips;

                        if ($slips->isEmpty()) {
                            return [
                                Section::make('伝票なし')
                                    ->schema([
                                        TextEntry::make('empty')
                                            ->label('')
                                            ->state('この受信ファイルに伝票データはありません。'),
                                    ]),
                            ];
                        }

                        $sections = [];

                        foreach ($slips as $index => $slip) {
                            $statusLabel = match ($slip->match_status) {
                                'UNMATCHED' => '未照合',
                                'MATCHED' => '照合済み',
                                'PARTIAL' => '一部欠品',
                                'SHORTAGE' => '欠品',
                                'NOT_FOUND' => '該当なし',
                                default => $slip->match_status,
                            };
                            $statusIcon = match ($slip->match_status) {
                                'MATCHED' => 'heroicon-o-check-circle',
                                'PARTIAL', 'SHORTAGE' => 'heroicon-o-exclamation-triangle',
                                'NOT_FOUND' => 'heroicon-o-x-circle',
                                default => 'heroicon-o-question-mark-circle',
                            };

                            $detailRows = $slip->details->map(function ($detail) {
                                $matchLabel = match ($detail->match_status) {
                                    'MATCHED' => '一致',
                                    'PARTIAL' => '一部欠品',
                                    'SHORTAGE' => '欠品',
                                    'EXTRA' => '対象外',
                                    default => '-',
                                };

                                return [
                                    'line' => $detail->d_line_number,
                                    'item_code' => $detail->d_item_code ?? '-',
                                    'product_name' => $detail->d_product_name ?? '-',
                                    'jan_code' => $detail->d_jan_code ?? '-',
                                    'case_qty' => $detail->d_case_quantity,
                                    'piece_qty' => $detail->d_piece_quantity,
                                    'total_qty' => $detail->total_quantity,
                                    'expected_qty' => $detail->expected_quantity ?? '-',
                                    'match_status' => $matchLabel,
                                    'is_shortage' => $detail->is_shortage ? '欠品' : '',
                                ];
                            })->toArray();

                            $sections[] = Section::make("伝票 #{$slip->slip_number}")
                                ->description("{$statusLabel} | 明細: {$slip->details->count()}件".($slip->shortage_count > 0 ? " | 欠品: {$slip->shortage_count}件" : ''))
                                ->icon($statusIcon)
                                ->collapsed($index > 2)
                                ->schema([
                                    Grid::make(4)->schema([
                                        TextEntry::make("slip_{$slip->id}_status")
                                            ->label('照合ステータス')
                                            ->state($statusLabel)
                                            ->badge()
                                            ->color(match ($slip->match_status) {
                                                'MATCHED' => 'success',
                                                'PARTIAL' => 'warning',
                                                'SHORTAGE' => 'danger',
                                                'NOT_FOUND' => 'danger',
                                                default => 'gray',
                                            }),
                                        TextEntry::make("slip_{$slip->id}_order_date")
                                            ->label('発注日')
                                            ->state($slip->b_order_date ?? '-'),
                                        TextEntry::make("slip_{$slip->id}_delivery_date")
                                            ->label('納品日')
                                            ->state($slip->b_delivery_date ?? '-'),
                                        TextEntry::make("slip_{$slip->id}_contractor")
                                            ->label('取引先CD')
                                            ->state($slip->b_contractor_code ?? '-'),
                                    ]),
                                    View::make('filament.components.incoming-received-detail-table')
                                        ->viewData(['details' => $detailRows]),
                                ]);
                        }

                        return $sections;
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる'),

                Action::make('matchAndApply')
                    ->label('照合・適用')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('info')
                    ->visible(fn ($record) => in_array($record->status, ['PENDING', 'MATCHED']))
                    ->requiresConfirmation()
                    ->modalHeading('照合・適用')
                    ->modalDescription('受信データを入荷予定と照合し、結果を適用します。')
                    ->action(function ($record) {
                        $service = app(IncomingReceiveService::class);

                        try {
                            $matchResult = $service->matchWithSchedules($record);

                            // 照合後に自動適用
                            $record->refresh();
                            $applyResult = ['applied' => 0, 'errors' => []];
                            if ($record->status === 'MATCHED') {
                                $applyResult = $service->applyMatched($record);
                            }

                            $body = "照合: {$matchResult['matched']}件";
                            if ($matchResult['shortage'] > 0) {
                                $body .= " / 欠品: {$matchResult['shortage']}件";
                            }
                            if ($matchResult['unmatched'] > 0) {
                                $body .= " / 未一致: {$matchResult['unmatched']}件";
                            }
                            if ($applyResult['applied'] > 0) {
                                $body .= " / 適用: {$applyResult['applied']}件";
                            }

                            Notification::make()
                                ->title('照合・適用が完了しました')
                                ->body($body)
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('照合・適用エラー')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('matchOnly')
                    ->label('照合のみ')
                    ->icon('heroicon-o-magnifying-glass-circle')
                    ->color('warning')
                    ->visible(fn ($record) => in_array($record->status, ['PENDING', 'MATCHED']))
                    ->requiresConfirmation()
                    ->modalHeading('受信データを照合のみ実行')
                    ->modalDescription('入荷予定との照合だけを実行します。入荷予定への適用は行いません。')
                    ->action(function ($record) {
                        $service = app(IncomingReceiveService::class);

                        try {
                            $result = $service->matchWithSchedules($record);

                            Notification::make()
                                ->title('照合が完了しました')
                                ->body("一致: {$result['matched']}件 / 欠品: {$result['shortage']}件 / 未一致: {$result['unmatched']}件")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('照合エラー')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('applyOnly')
                    ->label('適用のみ')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(fn ($record) => $record->status === 'MATCHED')
                    ->requiresConfirmation()
                    ->modalHeading('照合済みデータを適用')
                    ->modalDescription('照合済みの伝票だけを入荷予定へ適用します。未照合の伝票は適用されません。')
                    ->action(function ($record) {
                        $service = app(IncomingReceiveService::class);

                        try {
                            $result = $service->applyMatched($record);

                            Notification::make()
                                ->title('適用が完了しました')
                                ->body("適用: {$result['applied']}件 / エラー: ".count($result['errors']).'件')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('適用エラー')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('reparseRaw')
                    ->label('原本再パース')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->visible(fn ($record) => filled($record->raw_file_path))
                    ->requiresConfirmation()
                    ->modalHeading('保存済み原本から再パース')
                    ->modalDescription('元の受信データは削除せず、保存済みJX原本から新しい取込レコードを作成します。')
                    ->action(function ($record) {
                        $service = app(IncomingReceiveService::class);

                        try {
                            $newFile = $service->reparseFromRaw($record);

                            Notification::make()
                                ->title('再パースが完了しました')
                                ->body("新しい取込ID: {$newFile->id} / 伝票: {$newFile->parsed_slip_count}件 / 明細: {$newFile->parsed_detail_count}件")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('再パースエラー')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('downloadRaw')
                    ->label('原本DL')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->visible(fn ($record) => filled($record->raw_file_path))
                    ->url(function ($record): string {
                        $path = str_starts_with($record->raw_file_path, 's3:')
                            ? substr($record->raw_file_path, 3)
                            : $record->raw_file_path;

                        return Storage::disk('s3')->temporaryUrl($path, now()->addHour());
                    })
                    ->openUrlInNewTab(),

                Action::make('deleteWithSchedules')
                    ->label('削除')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('受信データを削除')
                    ->modalDescription(function ($record) {
                        $scheduleCount = WmsOrderIncomingSchedule::where('order_source', OrderSource::RECEIVED)
                            ->whereIn('slip_number', $record->slips()->pluck('slip_number'))
                            ->count();

                        return $scheduleCount > 0
                            ? "この受信データと関連する入荷予定 {$scheduleCount}件 を物理削除します。この操作は元に戻せません。"
                            : 'この受信データを物理削除します。この操作は元に戻せません。';
                    })
                    ->action(function ($record) {
                        $slipNumbers = $record->slips()->pluck('slip_number')->toArray();

                        // 関連する入荷予定を物理削除（RECEIVED由来のもの）
                        $deletedSchedules = 0;
                        if (! empty($slipNumbers)) {
                            $deletedSchedules = WmsOrderIncomingSchedule::where('order_source', OrderSource::RECEIVED)
                                ->whereIn('slip_number', $slipNumbers)
                                ->delete();
                        }

                        // エラーレコード削除
                        WmsIncomingImportError::where('received_file_id', $record->id)->delete();

                        // 明細 → 伝票 → ファイルの順で削除
                        foreach ($record->slips as $slip) {
                            $slip->details()->delete();
                        }
                        $record->slips()->delete();
                        $record->delete();

                        Notification::make()
                            ->title('削除しました')
                            ->body("受信データと関連入荷予定 {$deletedSchedules}件 を削除しました")
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('id', 'desc');
    }
}
