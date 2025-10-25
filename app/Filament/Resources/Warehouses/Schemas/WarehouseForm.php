<?php

namespace App\Filament\Resources\Warehouses\Schemas;

use App\Models\Sakemaru\Branch;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WarehouseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('基本情報')
                    ->schema([
                        TextInput::make('name')
                            ->label('倉庫名')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),

                        TextInput::make('kana_name')
                            ->label('倉庫名（カナ）')
                            ->maxLength(255)
                            ->columnSpan(1),

                        TextInput::make('abbreviation')
                            ->label('略称')
                            ->maxLength(255)
                            ->columnSpan(1),

                        TextInput::make('code')
                            ->label('倉庫コード')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->helperText('数値で倉庫を識別するコード')
                            ->columnSpan(1),

                        Select::make('branch_id')
                            ->label('支店')
                            ->searchable()
                            ->options(function () {
                                return Branch::query()
                                    ->orderBy('name')
                                    ->pluck('name', 'id');
                            })
                            ->columnSpan(1),

                        Toggle::make('is_active')
                            ->label('有効')
                            ->default(true)
                            ->helperText('この倉庫を使用可能にする')
                            ->columnSpan(2),
                    ])
                    ->columns(2),

                Section::make('在庫設定')
                    ->schema([
                        Select::make('out_of_stock_option')
                            ->label('在庫切れ時の動作')
                            ->required()
                            ->options([
                                'IGNORE_STOCK' => '在庫を無視（マイナス在庫許可）',
                                'UP_TO_STOCK' => '在庫数まで（在庫制限）',
                            ])
                            ->default('UP_TO_STOCK')
                            ->helperText('受注時に在庫が不足している場合の処理方法')
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),
            ]);
    }
}
