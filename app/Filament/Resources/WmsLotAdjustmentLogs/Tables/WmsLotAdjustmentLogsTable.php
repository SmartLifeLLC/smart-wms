<?php

namespace App\Filament\Resources\WmsLotAdjustmentLogs\Tables;

use App\Models\Sakemaru\Warehouse;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WmsLotAdjustmentLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('日時')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                TextColumn::make('mode')
                    ->label('種別')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'APPLIED' ? '実行' : 'プレビュー')
                    ->color(fn (string $state): string => $state === 'APPLIED' ? 'success' : 'gray'),

                TextColumn::make('warehouse_id')
                    ->label('倉庫')
                    ->formatStateUsing(fn ($state): string => '['.$state.'] '.(Warehouse::query()->whereKey($state)->value('name') ?? '')),

                TextColumn::make('summary')
                    ->label('内訳')
                    ->tooltip('相殺 / 再ACTIVE / 残数0化 / 在庫数合わせ / STLA修正 / 複数棚番 / 空棚番 / RSLE再利用 / RSLE(WMS行) / SKIP')
                    ->formatStateUsing(function ($state): string {
                        $s = is_array($state) ? $state : (json_decode($state ?? '[]', true) ?: []);
                        $g = fn (string $k) => (int) ($s[$k] ?? 0);

                        return sprintf(
                            '相殺%d / 再ACT%d / 0化%d / 合わせ%d / STLA%d / 複数棚%d / 空棚%d / RSLE%d / RSLE-W%d / SKIP%d',
                            $g('offset'),
                            $g('reactivate'),
                            $g('zero_residual'),
                            $g('sync_applied'),
                            $g('repoint'),
                            $g('multi_shelf'),
                            $g('blank_location'),
                            $g('reserved_reuse_risk'),
                            $g('reserved_reuse_wms_exists'),
                            $g('skipped'),
                        );
                    }),

                TextColumn::make('affected_count')
                    ->label('適用件数')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('実行者')
                    ->default('システム'),
            ])
            ->filters([
                SelectFilter::make('mode')
                    ->label('種別')
                    ->options(['APPLIED' => '実行', 'DRY_RUN' => 'プレビュー']),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('明細')
                    ->icon('heroicon-o-eye')
                    ->modalHeading('ロット調節 明細')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる')
                    ->modalWidth('5xl')
                    ->modalContent(fn ($record) => view(
                        'filament.resources.lot-adjustment-log-detail',
                        ['record' => $record]
                    )),
            ]);
    }
}
