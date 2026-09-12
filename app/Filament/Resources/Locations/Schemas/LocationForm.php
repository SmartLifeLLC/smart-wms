<?php

namespace App\Filament\Resources\Locations\Schemas;

use App\Enums\TemperatureType;
use App\Models\Sakemaru\Floor;
use App\Models\Sakemaru\Warehouse;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LocationForm
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
                            ->live()
                            ->options(function () {
                                return Warehouse::query()
                                    ->orderBy('name')
                                    ->pluck('name', 'id');
                            })
                            ->afterStateUpdated(fn (callable $set) => $set('floor_id', null))
                            ->helperText('このロケーションが属する倉庫')
                            ->columnSpan(1),

                        Select::make('floor_id')
                            ->label('フロア')
                            ->required()
                            ->searchable()
                            ->options(function (callable $get) {
                                $warehouseId = $get('warehouse_id');
                                if (! $warehouseId) {
                                    return [];
                                }

                                return Floor::query()
                                    ->where('warehouse_id', $warehouseId)
                                    ->orderBy('name')
                                    ->pluck('name', 'id');
                            })
                            ->helperText('ピッキングリストのグループ化に使用')
                            ->columnSpan(1),

                        TextInput::make('name')
                            ->label('ロケーション名')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('例: デフォルト、A棚1段目')
                            ->columnSpan(2),
                    ])
                    ->columns(2),

                Section::make('ロケーションコード')
                    ->description('3段階のコード体系でロケーションを管理')
                    ->schema([
                        TextInput::make('code1')
                            ->label('コード1（大分類）')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('例: A, Z, 1')
                            ->helperText('通路やエリアを表すコード')
                            ->columnSpan(1),

                        TextInput::make('code2')
                            ->label('コード2（中分類）')
                            ->maxLength(255)
                            ->placeholder('例: 1, 2, A')
                            ->helperText('棚や列を表すコード')
                            ->columnSpan(1),

                        TextInput::make('code3')
                            ->label('コード3（小分類）')
                            ->maxLength(255)
                            ->placeholder('例: 1, 2, 3')
                            ->helperText('段や細かい位置を表すコード')
                            ->columnSpan(1),
                    ])
                    ->columns(3)
                    ->collapsible(),

                Section::make('WMS設定')
                    ->description('ピッキングタスクのグループ化に使用される属性')
                    ->schema([
                        Select::make('temperature_type')
                            ->label('温度帯')
                            ->options(TemperatureType::options())
                            ->default(TemperatureType::NORMAL->value)
                            ->helperText('保管温度帯を選択してください（常温/冷蔵/冷凍）')
                            ->required()
                            ->columnSpan(1),

                        Toggle::make('is_restricted_area')
                            ->label('制限エリア')
                            ->helperText('ONにすると、制限エリアアクセス権限を持つピッカーのみがこのロケーションのタスクを担当できます')
                            ->default(false)
                            ->inline(false)
                            ->columnSpan(1),

                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }
}
