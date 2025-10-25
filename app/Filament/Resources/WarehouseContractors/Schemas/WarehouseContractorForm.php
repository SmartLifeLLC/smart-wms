<?php

namespace App\Filament\Resources\WarehouseContractors\Schemas;

use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WarehouseContractorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('基本情報')
                    ->schema([
                        Select::make('warehouse_id')
                            ->label('倉庫')
                            ->required()
                            ->searchable()
                            ->options(function () {
                                return Warehouse::query()
                                    ->where('is_active', 1)
                                    ->orderBy('name')
                                    ->pluck('name', 'id');
                            })
                            ->columnSpan(1),

                        Select::make('contractor_id')
                            ->label('仕入先')
                            ->required()
                            ->searchable()
                            ->options(function () {
                                return Contractor::query()
                                    ->orderBy('name')
                                    ->pluck('name', 'id');
                            })
                            ->columnSpan(1),

                        Toggle::make('is_active')
                            ->label('有効')
                            ->default(true)
                            ->helperText('この倉庫・仕入先の組み合わせを有効にする')
                            ->columnSpan(2),
                    ])
                    ->columns(2),

                Section::make('ロット条件')
                    ->description('発注ロットの条件設定')
                    ->schema([
                        TextInput::make('lot_condition_case')
                            ->label('ケースロット')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('ケース単位での最小発注数')
                            ->columnSpan(1),

                        TextInput::make('lot_condition_piece')
                            ->label('バラロット')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('バラ単位での最小発注数')
                            ->columnSpan(1),

                        TextInput::make('lot_condition_price')
                            ->label('価格ロット')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('最小発注金額')
                            ->columnSpan(1),

                        TextInput::make('lot_condition_1')
                            ->label('ロット条件1')
                            ->maxLength(255)
                            ->columnSpan(1),

                        TextInput::make('lot_condition_2')
                            ->label('ロット条件2')
                            ->maxLength(255)
                            ->columnSpan(1),

                        TextInput::make('lot_condition_3')
                            ->label('ロット条件3')
                            ->maxLength(255)
                            ->columnSpan(1),

                        TextInput::make('lot_condition_4')
                            ->label('ロット条件4')
                            ->maxLength(255)
                            ->columnSpan(1),
                    ])
                    ->columns(3)
                    ->collapsible(),

                Section::make('発注設定')
                    ->schema([
                        Toggle::make('prints_recommendation_sheet')
                            ->label('推奨発注書を印刷')
                            ->default(false)
                            ->columnSpan(1),

                        Toggle::make('disable_automatic_order_conversion')
                            ->label('自動発注変換を無効化')
                            ->default(false)
                            ->columnSpan(1),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }
}
