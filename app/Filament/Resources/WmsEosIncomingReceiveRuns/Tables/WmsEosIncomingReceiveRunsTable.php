<?php

namespace App\Filament\Resources\WmsEosIncomingReceiveRuns\Tables;

use App\Enums\PaginationOptions;
use App\Models\WmsEosIncomingReceiveRun;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\View;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsEosIncomingReceiveRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->emptyStateHeading('実行ログはありません')
            ->emptyStateDescription('設定時刻または今すぐ実行でEOSデータ受信を実行すると、ここに結果が表示されます。')
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->alignCenter()
                    ->width('60px'),
                TextColumn::make('started_at')
                    ->label('開始日時')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->width('145px'),
                TextColumn::make('schedule_label')
                    ->label('実行枠')
                    ->state(fn (WmsEosIncomingReceiveRun $record): string => $record->schedule?->label() ?? '手動実行')
                    ->width('140px'),
                TextColumn::make('trigger_type')
                    ->label('実行種別')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        WmsEosIncomingReceiveRun::TRIGGER_SCHEDULED => '定期',
                        WmsEosIncomingReceiveRun::TRIGGER_MANUAL => '手動',
                        default => (string) $state,
                    })
                    ->color(fn (?string $state): string => $state === WmsEosIncomingReceiveRun::TRIGGER_MANUAL ? 'info' : 'gray')
                    ->alignCenter()
                    ->width('70px'),
                TextColumn::make('status')
                    ->label('状態')
                    ->badge()
                    ->formatStateUsing(fn (?string $state, WmsEosIncomingReceiveRun $record): string => $record->statusLabel())
                    ->color(fn (?string $state, WmsEosIncomingReceiveRun $record): string => $record->statusColor())
                    ->alignCenter()
                    ->width('85px'),
                TextColumn::make('received_jx_document_count')
                    ->label('JX受信')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),
                TextColumn::make('target_jx_log_count')
                    ->label('取込対象')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),
                TextColumn::make('incoming_confirmed_schedule_count')
                    ->label('入荷確定')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),
                TextColumn::make('purchase_queue_count')
                    ->label('仕入キュー')
                    ->numeric()
                    ->alignEnd()
                    ->width('75px'),
                TextColumn::make('purchase_transmitted_schedule_count')
                    ->label('仕入明細')
                    ->numeric()
                    ->alignEnd()
                    ->width('75px'),
                TextColumn::make('unknown_slip_count')
                    ->label('伝票不明')
                    ->numeric()
                    ->alignEnd()
                    ->color(fn (?int $state): ?string => $state > 0 ? 'warning' : null)
                    ->width('75px'),
                TextColumn::make('shortage_completed_count')
                    ->label('自動整理')
                    ->numeric()
                    ->alignEnd()
                    ->width('75px'),
                TextColumn::make('error_count')
                    ->label('エラー')
                    ->numeric()
                    ->alignEnd()
                    ->color(fn (?int $state): ?string => $state > 0 ? 'danger' : null)
                    ->width('65px'),
                TextColumn::make('finished_at')
                    ->label('終了日時')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('run_key')
                    ->label('実行キー')
                    ->limit(40)
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('error_summary')
                    ->label('エラー概要')
                    ->limit(40)
                    ->tooltip(fn ($state) => $state)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('状態')
                    ->options([
                        WmsEosIncomingReceiveRun::STATUS_QUEUED => '待機中',
                        WmsEosIncomingReceiveRun::STATUS_RUNNING => '実行中',
                        WmsEosIncomingReceiveRun::STATUS_SUCCEEDED => '完了',
                        WmsEosIncomingReceiveRun::STATUS_PARTIAL_FAILED => '一部失敗',
                        WmsEosIncomingReceiveRun::STATUS_FAILED => '失敗',
                        WmsEosIncomingReceiveRun::STATUS_SKIPPED => 'スキップ',
                    ]),
                Filter::make('started_at')
                    ->label('開始日')
                    ->form([
                        DatePicker::make('from')->label('開始日From'),
                        DatePicker::make('until')->label('開始日To'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn (Builder $query, $date): Builder => $query->whereDate('started_at', '>=', $date))
                        ->when($data['until'], fn (Builder $query, $date): Builder => $query->whereDate('started_at', '<=', $date))
                    ),
            ])
            ->recordActions([
                Action::make('viewLogs')
                    ->label('詳細')
                    ->icon('heroicon-o-list-bullet')
                    ->color('gray')
                    ->modalHeading('EOSデータ受信実行詳細')
                    ->modalWidth('7xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる')
                    ->schema(fn (WmsEosIncomingReceiveRun $record): array => [
                        View::make('filament.components.eos-incoming-receive-run-log-table')
                            ->viewData([
                                'run' => $record->loadMissing('logs'),
                                'logs' => $record->logs()->orderBy('id')->get(),
                            ]),
                    ]),
            ]);
    }
}
