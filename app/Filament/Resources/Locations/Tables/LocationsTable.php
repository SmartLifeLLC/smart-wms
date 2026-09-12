<?php

namespace App\Filament\Resources\Locations\Tables;

use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Models\Sakemaru\Location;
use App\Models\Sakemaru\Warehouse;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class LocationsTable
{
    use HasExportAction;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('floor.name')
                    ->label('フロア')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),

                TextColumn::make('code1')
                    ->label('コード1')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('code2')
                    ->label('コード2')
                    ->searchable()
                    ->sortable()
                    ->default('-'),

                TextColumn::make('code3')
                    ->label('コード3')
                    ->searchable()
                    ->sortable()
                    ->default('-'),

                TextColumn::make('joinedLocation')
                    ->label('統合コード')
                    ->badge()
                    ->color('gray')
                    ->searchable(['code1', 'code2', 'code3']),

                TextColumn::make('name')
                    ->label('ロケーション名')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('temperature_type')
                    ->label('温度帯')
                    ->badge()
                    ->color(fn ($state) => $state?->color() ?? 'gray')
                    ->formatStateUsing(fn ($state) => $state?->label() ?? '-')
                    ->sortable(),

                TextColumn::make('is_restricted_area')
                    ->label('制限エリア')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'danger' : 'success')
                    ->formatStateUsing(fn (bool $state): string => $state ? '制限' : '通常')
                    ->sortable(),

                TextColumn::make('available_quantity_flags')
                    ->label('数量タイプ')
                    ->badge()
                    ->formatStateUsing(function (int $state): string {
                        if ($state === 8) {
                            return '無し';
                        }
                        $parts = [];
                        if ($state & 1) {
                            $parts[] = 'ケース';
                        }
                        if ($state & 2) {
                            $parts[] = 'バラ';
                        }
                        if ($state & 4) {
                            $parts[] = 'ボール';
                        }

                        return $parts ? implode('+', $parts) : (string) $state;
                    })
                    ->color(fn (int $state): string => match ($state) {
                        1 => 'info',
                        2 => 'success',
                        3 => 'warning',
                        4 => 'primary',
                        8 => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('更新日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->options(function () {
                        return Warehouse::query()
                            ->orderBy('name')
                            ->pluck('name', 'id');
                    }),
            ])
            ->defaultSort('warehouse_id', 'asc')
            ->recordActions([
                EditAction::make(),
                Action::make('evacuateToZ00')
                    ->label('ロケ解除')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('この棚の商品をロケ解除')
                    ->modalDescription(fn (Location $record): string => self::buildEvacuationDescription($record))
                    ->modalSubmitActionLabel('退避する')
                    ->action(function (Location $record): void {
                        try {
                            $result = self::evacuateToZ00($record);

                            Notification::make()
                                ->title('ロケ解除しました')
                                ->body("ロット {$result['lots']}件、入荷デフォルト {$result['defaults']}件、棚番バックアップ {$result['origin_locations']}件を更新しました。")
                                ->success()
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('ロケ解除に失敗しました')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->visible(fn (Location $record): bool => self::joinedCode($record) !== 'Z00'),
            ])
            ->toolbarActions([
                static::getExportAction(),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function buildEvacuationDescription(Location $record): string
    {
        $db = DB::connection('sakemaru');
        $lotSummary = $db->table('real_stock_lots')
            ->where('location_id', $record->id)
            ->selectRaw('COUNT(*) AS lots')
            ->selectRaw("SUM(CASE WHEN status = 'ACTIVE' THEN 1 ELSE 0 END) AS active_lots")
            ->selectRaw('COALESCE(SUM(current_quantity), 0) AS current_quantity')
            ->selectRaw('COALESCE(SUM(reserved_quantity), 0) AS reserved_quantity')
            ->first();

        $defaultCount = (int) $db->table('item_incoming_default_locations')
            ->where('warehouse_id', $record->warehouse_id)
            ->where('location_id', $record->id)
            ->count();

        return sprintf(
            '%s のロット %d件（ACTIVE %d件、現在庫合計 %d、予約合計 %d）と入荷デフォルト %d件、棚番バックアップの対象商品を同じ倉庫のZ00へ移動します。ロケーション自体は削除しません。',
            self::joinedCode($record),
            (int) ($lotSummary->lots ?? 0),
            (int) ($lotSummary->active_lots ?? 0),
            (int) ($lotSummary->current_quantity ?? 0),
            (int) ($lotSummary->reserved_quantity ?? 0),
            $defaultCount,
        );
    }

    private static function evacuateToZ00(Location $record): array
    {
        return DB::connection('sakemaru')->transaction(function () use ($record): array {
            $db = DB::connection('sakemaru');
            $source = $db->table('locations')
                ->where('id', $record->id)
                ->lockForUpdate()
                ->first();

            if (! $source) {
                throw new RuntimeException('対象ロケーションが見つかりません。');
            }

            $z00 = $db->table('locations')
                ->where('warehouse_id', $source->warehouse_id)
                ->where('is_disabled', false)
                ->whereRaw("CONCAT(COALESCE(code1, ''), COALESCE(code2, ''), COALESCE(code3, '')) = 'Z00'")
                ->orderByRaw("name = 'デフォルト' DESC")
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $z00) {
                throw new RuntimeException('同じ倉庫のZ00ロケーションが見つかりません。');
            }

            if ((int) $source->id === (int) $z00->id) {
                throw new RuntimeException('Z00自身は退避対象にできません。');
            }

            $openReservations = (int) $db->table('wms_reservations')
                ->where('location_id', $source->id)
                ->whereIn('status', ['RESERVED', 'PARTIAL'])
                ->count();

            $openPickingResults = (int) $db->table('wms_picking_item_results')
                ->where('location_id', $source->id)
                ->whereIn('status', ['PENDING', 'PICKING'])
                ->count();

            $reservedLots = (int) $db->table('real_stock_lots')
                ->where('location_id', $source->id)
                ->where('status', 'ACTIVE')
                ->where('reserved_quantity', '<>', 0)
                ->count();

            if ($openReservations || $openPickingResults || $reservedLots) {
                throw new RuntimeException("未完了の予約/ピッキングがあります。予約 {$openReservations}件、ピッキング {$openPickingResults}件、予約数量ありロット {$reservedLots}件。");
            }

            $now = now();
            $itemIds = collect($db->table('real_stock_lots')
                ->join('real_stocks', 'real_stocks.id', '=', 'real_stock_lots.real_stock_id')
                ->where('real_stock_lots.location_id', $source->id)
                ->pluck('real_stocks.item_id'))
                ->merge($db->table('item_incoming_default_locations')
                    ->where('warehouse_id', $source->warehouse_id)
                    ->where('location_id', $source->id)
                    ->pluck('item_id'))
                ->filter()
                ->unique()
                ->values();

            $lots = $db->table('real_stock_lots')
                ->where('location_id', $source->id)
                ->update([
                    'floor_id' => $z00->floor_id,
                    'location_id' => $z00->id,
                    'updated_at' => $now,
                ]);

            $defaults = $db->table('item_incoming_default_locations')
                ->where('warehouse_id', $source->warehouse_id)
                ->where('location_id', $source->id)
                ->update([
                    'location_id' => $z00->id,
                    'updated_at' => $now,
                ]);

            $originLocations = 0;
            if ($itemIds->isNotEmpty()) {
                $sourceCode = self::joinedCodeFromRow($source);
                $originLocations = $db->table('wms_hana_origin_locations')
                    ->where('warehouse_id', $source->warehouse_id)
                    ->whereIn('item_id', $itemIds->all())
                    ->where('oracle_shelf_code', $sourceCode)
                    ->update([
                        'oracle_shelf_code' => 'Z00',
                        'oracle_shelf_code_raw' => 'Z00',
                        'updated_at' => $now,
                    ]);
            }

            $db->table('locations')
                ->where('id', $source->id)
                ->update([
                    'is_disabled' => true,
                    'updated_at' => $now,
                ]);

            return [
                'lots' => $lots,
                'defaults' => $defaults,
                'origin_locations' => $originLocations,
            ];
        });
    }

    private static function joinedCode(Location $record): string
    {
        return Location::formatCode($record->code1, $record->code2, $record->code3);
    }

    private static function joinedCodeFromRow(object $location): string
    {
        return Location::formatCode($location->code1, $location->code2, $location->code3);
    }
}
