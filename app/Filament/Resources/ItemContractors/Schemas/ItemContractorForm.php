<?php

namespace App\Filament\Resources\ItemContractors\Schemas;

use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Supplier;
use App\Models\Sakemaru\Warehouse;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ItemContractorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('基本情報')
                    ->schema([
                        Select::make('item_id')
                            ->label('商品')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => Item::query()
                                ->where('code', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%")
                                ->orderBy('code')
                                ->limit(50)
                                ->get(['id', 'code', 'name'])
                                ->mapWithKeys(fn ($item) => [$item->id => "[{$item->code}] {$item->name}"]))
                            ->getOptionLabelUsing(fn ($value) => ($item = Item::find($value)) ? "[{$item->code}] {$item->name}" : null)
                            ->required()
                            ->helperText('発注対象の商品を選択'),

                        Select::make('warehouse_id')
                            ->label('倉庫')
                            ->options(fn () => Warehouse::where('is_active', true)->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->helperText('この商品を発注する倉庫'),

                        Select::make('contractor_id')
                            ->label('発注先')
                            ->options(fn () => Contractor::where('is_active', true)->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->helperText('発注先の業者'),

                        Select::make('supplier_id')
                            ->label('仕入先')
                            ->options(fn () => Supplier::with('partner')
                                ->get()
                                ->mapWithKeys(fn ($s) => [$s->id => "[{$s->partner?->code}] {$s->partner?->name}"])
                            )
                            ->searchable()
                            ->nullable()
                            ->helperText('仕入先（任意）'),

                        Textarea::make('note')
                            ->label('備考')
                            ->nullable()
                            ->rows(4)
                            ->columnSpanFull()
                            ->helperText('商品発注先に関するメモや備考を自由に入力できます'),
                    ])
                    ->columns(2),

                Section::make('在庫設定')
                    ->schema([
                        TextInput::make('safety_stock')
                            ->label('発注点')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->suffix('個')
                            ->helperText('この数を下回ると発注を検討'),

                        TextInput::make('max_stock')
                            ->label('最大在庫')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->suffix('個')
                            ->helperText('在庫の上限目安'),

                        TextInput::make('min_stock')
                            ->label('最低在庫')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->suffix('個')
                            ->helperText('旧システムの最低在庫数'),

                        TextInput::make('auto_order_quantity')
                            ->label('自動発注数')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->suffix('個')
                            ->helperText('設定時は不足数ではなくこの数量を発注数量に使用'),

                        Toggle::make('is_auto_order')
                            ->label('自動発注対象')
                            ->default(false)
                            ->helperText('自動発注の対象にする場合はON'),

                        Toggle::make('use_safety_stock_auto_update')
                            ->label('安全在庫自動更新')
                            ->default(true)
                            ->helperText('OFFにすると月次の安全在庫同期で上書きされません'),
                    ])
                    ->columns(3),
            ]);
    }
}
