<?php

namespace App\Filament\Resources\ItemContractors\Tables;

use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Supplier;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ItemContractorsTable
{
    use HasExportAction;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'sticky-actions'])
            ->columns([
                ToggleColumn::make('is_auto_order')
                    ->label('自動発注')
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('item.code')
                    ->label('商品CD')
                    ->searchable()
                    ->sortable()
                    ->width('80px'),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->searchable()
                    ->sortable()
                    ->grow(),

                TextColumn::make('warehouse.code')
                    ->label('倉庫CD')
                    ->sortable()
                    ->width('70px'),

                TextColumn::make('warehouse.name')
                    ->label('倉庫名')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('contractor.code')
                    ->label('発注先CD')
                    ->sortable()
                    ->width('80px'),

                TextColumn::make('contractor.name')
                    ->label('発注先名')
                    ->sortable(),

                TextColumn::make('supplier.partner.code')
                    ->label('仕入先CD')
                    ->sortable()
                    ->width('80px'),

                TextColumn::make('supplier.partner.name')
                    ->label('仕入先名')
                    ->sortable(),

                TextColumn::make('note')
                    ->label('備考')
                    ->limit(40)
                    ->tooltip(fn (?string $state): ?string => filled($state) ? $state : null)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextInputColumn::make('safety_stock')
                    ->label('発注点')
                    ->type('number')
                    ->rules(['integer', 'min:0'])
                    ->sortable()
                    ->alignEnd()
                    ->width('100px'),

                TextInputColumn::make('max_stock')
                    ->label('最大在庫')
                    ->type('number')
                    ->rules(['integer', 'min:0'])
                    ->sortable()
                    ->alignEnd()
                    ->width('100px'),

                TextInputColumn::make('min_stock')
                    ->label('最低在庫')
                    ->type('number')
                    ->rules(['integer', 'min:0'])
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('100px'),

                TextInputColumn::make('auto_order_quantity')
                    ->label('自動発注数')
                    ->type('number')
                    ->rules(['integer', 'min:0'])
                    ->sortable()
                    ->alignEnd()
                    ->width('110px'),

                ToggleColumn::make('use_safety_stock_auto_update')
                    ->label('在庫自動更新')
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('item_code')
                    ->label('商品CD')
                    ->schema([
                        TextInput::make('item_code')
                            ->label('商品CD')
                            ->placeholder('商品コードを入力'),
                    ])
                    ->query(fn ($query, array $data) => $query->when(
                        $data['item_code'] ?? null,
                        fn ($q, $code) => $q->whereHas('item', fn ($q) => $q->where('code', $code))
                    )),

                SelectFilter::make('contractor_id')
                    ->label('発注先')
                    ->options(fn () => Contractor::where('is_active', true)
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}] {$c->name}"])
                        ->toArray())
                    ->searchable(),

                SelectFilter::make('supplier_id')
                    ->label('仕入先')
                    ->options(fn () => Supplier::with('partner')
                        ->get()
                        ->mapWithKeys(fn ($s) => [$s->id => "[{$s->partner?->code}] {$s->partner?->name}"])
                        ->toArray())
                    ->searchable(),

                TernaryFilter::make('is_auto_order')
                    ->label('自動発注')
                    ->placeholder('すべて')
                    ->trueLabel('自動発注のみ')
                    ->falseLabel('手動発注のみ'),

            ])
            ->recordActionsColumnLabel('操作')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                static::getExportAction(),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('item_id', 'asc');
    }
}
