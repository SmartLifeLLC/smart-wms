<?php

namespace App\Filament\Resources\WmsIncomingAppInspections\Tables;

use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Models\WmsIncomingAppInspectionDetail;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Forms\Components\DatePicker;
use Illuminate\Database\Eloquent\Builder;

class WmsIncomingAppInspectionsTable
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
                    ->sortable()
                    ->width('70px'),

                TextColumn::make('inspected_at')
                    ->label('検品日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->width('100px'),

                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->state(fn ($record) => $record->warehouse ? "[{$record->warehouse->code}]{$record->warehouse->name}" : '-')
                    ->searchable()
                    ->width('180px'),

                TextColumn::make('result_status')
                    ->label('結果')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::resultLabel($state))
                    ->color(fn (string $state): string => self::resultColor($state))
                    ->sortable()
                    ->width('120px'),

                TextColumn::make('inspection_policy')
                    ->label('処理方針')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::policyLabel($state))
                    ->color(fn (string $state): string => self::policyColor($state))
                    ->toggleable()
                    ->width('130px'),

                TextColumn::make('slip_number')
                    ->label('伝票番号')
                    ->searchable()
                    ->copyable()
                    ->placeholder('-')
                    ->width('130px'),

                TextColumn::make('item_code')
                    ->label('商品CD')
                    ->searchable()
                    ->copyable()
                    ->placeholder('-')
                    ->width('100px'),

                TextColumn::make('item_name')
                    ->label('商品名')
                    ->searchable()
                    ->grow(),

                TextColumn::make('scanned_code')
                    ->label('読取CD')
                    ->searchable()
                    ->copyable()
                    ->placeholder('-')
                    ->toggleable()
                    ->width('130px'),

                TextColumn::make('contractor.name')
                    ->label('発注先')
                    ->state(fn ($record) => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-')
                    ->searchable()
                    ->width('180px'),

                TextColumn::make('inspected_case_quantity')
                    ->label('ケース')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('inspected_piece_quantity')
                    ->label('バラ')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('inspected_total_piece_quantity')
                    ->label('総バラ')
                    ->numeric()
                    ->alignEnd()
                    ->width('80px'),

                TextColumn::make('applied_piece_quantity')
                    ->label('反映バラ')
                    ->numeric()
                    ->alignEnd()
                    ->width('90px'),

                TextColumn::make('review_reason')
                    ->label('確認内容')
                    ->placeholder('-')
                    ->limit(80)
                    ->tooltip(fn ($state): ?string => filled($state) ? (string) $state : null)
                    ->grow(),

                TextColumn::make('incoming_schedule_id')
                    ->label('予定ID')
                    ->sortable()
                    ->copyable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('90px'),

                TextColumn::make('linked_confirmed_schedule_id')
                    ->label('確定ID')
                    ->sortable()
                    ->copyable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('90px'),

                TextColumn::make('created_schedule_id')
                    ->label('作成ID')
                    ->sortable()
                    ->copyable()
                    ->placeholder('-')
                    ->toggleable()
                    ->width('90px'),

                TextColumn::make('batch.client_batch_uuid')
                    ->label('バッチUUID')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('result_status')
                    ->label('結果')
                    ->multiple()
                    ->options([
                        WmsIncomingAppInspectionDetail::RESULT_CONFIRMED => '入荷確定',
                        WmsIncomingAppInspectionDetail::RESULT_APP_UNPLANNED_CREATED => '予定なし作成',
                        WmsIncomingAppInspectionDetail::RESULT_HISTORY_ONLY => '履歴のみ',
                        WmsIncomingAppInspectionDetail::RESULT_EOS_ALREADY_CONFIRMED => 'EOS確定済み',
                        WmsIncomingAppInspectionDetail::RESULT_NEEDS_REVIEW => '要確認',
                        WmsIncomingAppInspectionDetail::RESULT_ERROR => 'エラー',
                    ]),

                SelectFilter::make('inspection_policy')
                    ->label('処理方針')
                    ->multiple()
                    ->options([
                        WmsIncomingAppInspectionDetail::POLICY_APP_CONFIRM_ALLOWED => 'アプリ確定可',
                        WmsIncomingAppInspectionDetail::POLICY_EOS_HISTORY_ONLY => 'EOS履歴のみ',
                        WmsIncomingAppInspectionDetail::POLICY_EOS_ALREADY_CONFIRMED => 'EOS確定済み',
                        WmsIncomingAppInspectionDetail::POLICY_TRANSFER_WEB_ONLY => '店間移動',
                        WmsIncomingAppInspectionDetail::POLICY_PURCHASE_TRANSMITTED_LOCKED => '仕入連携済み',
                        WmsIncomingAppInspectionDetail::POLICY_NEEDS_REVIEW => '要確認',
                    ]),

                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload(),

                Filter::make('inspected_at')
                    ->label('検品日')
                    ->form([
                        DatePicker::make('from')->label('開始日'),
                        DatePicker::make('until')->label('終了日'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('inspected_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('inspected_at', '<=', $date))
                    ),
            ])
            ->toolbarActions([
                static::getExportAction(),
            ])
            ->defaultSort('id', 'desc');
    }

    private static function resultLabel(string $state): string
    {
        return match ($state) {
            WmsIncomingAppInspectionDetail::RESULT_CONFIRMED => '入荷確定',
            WmsIncomingAppInspectionDetail::RESULT_APP_UNPLANNED_CREATED => '予定なし作成',
            WmsIncomingAppInspectionDetail::RESULT_HISTORY_ONLY => '履歴のみ',
            WmsIncomingAppInspectionDetail::RESULT_EOS_ALREADY_CONFIRMED => 'EOS確定済み',
            WmsIncomingAppInspectionDetail::RESULT_NEEDS_REVIEW => '要確認',
            WmsIncomingAppInspectionDetail::RESULT_ERROR => 'エラー',
            default => $state,
        };
    }

    private static function resultColor(string $state): string
    {
        return match ($state) {
            WmsIncomingAppInspectionDetail::RESULT_CONFIRMED,
            WmsIncomingAppInspectionDetail::RESULT_APP_UNPLANNED_CREATED => 'success',
            WmsIncomingAppInspectionDetail::RESULT_HISTORY_ONLY,
            WmsIncomingAppInspectionDetail::RESULT_EOS_ALREADY_CONFIRMED => 'info',
            WmsIncomingAppInspectionDetail::RESULT_NEEDS_REVIEW => 'warning',
            WmsIncomingAppInspectionDetail::RESULT_ERROR => 'danger',
            default => 'gray',
        };
    }

    private static function policyLabel(string $state): string
    {
        return match ($state) {
            WmsIncomingAppInspectionDetail::POLICY_APP_CONFIRM_ALLOWED => 'アプリ確定可',
            WmsIncomingAppInspectionDetail::POLICY_EOS_HISTORY_ONLY => 'EOS履歴のみ',
            WmsIncomingAppInspectionDetail::POLICY_EOS_ALREADY_CONFIRMED => 'EOS確定済み',
            WmsIncomingAppInspectionDetail::POLICY_TRANSFER_WEB_ONLY => '店間移動',
            WmsIncomingAppInspectionDetail::POLICY_PURCHASE_TRANSMITTED_LOCKED => '仕入連携済み',
            WmsIncomingAppInspectionDetail::POLICY_NEEDS_REVIEW => '要確認',
            default => $state,
        };
    }

    private static function policyColor(string $state): string
    {
        return match ($state) {
            WmsIncomingAppInspectionDetail::POLICY_APP_CONFIRM_ALLOWED => 'success',
            WmsIncomingAppInspectionDetail::POLICY_EOS_HISTORY_ONLY,
            WmsIncomingAppInspectionDetail::POLICY_EOS_ALREADY_CONFIRMED => 'info',
            WmsIncomingAppInspectionDetail::POLICY_TRANSFER_WEB_ONLY,
            WmsIncomingAppInspectionDetail::POLICY_PURCHASE_TRANSMITTED_LOCKED,
            WmsIncomingAppInspectionDetail::POLICY_NEEDS_REVIEW => 'warning',
            default => 'gray',
        };
    }
}
