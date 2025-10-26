<?php

namespace App\Filament\Resources\WmsLocations\Schemas;

use App\Models\Sakemaru\Location;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WmsLocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('基本情報')
                    ->schema([
                        Select::make('location_id')
                            ->label('ロケーション')
                            ->required()
                            ->searchable()
                            ->options(function () {
                                return Location::query()
                                    ->with('warehouse')
                                    ->get()
                                    ->mapWithKeys(function ($location) {
                                        $warehouseName = $location->warehouse?->name ?? '不明';
                                        $locationCode = trim("{$location->code1} {$location->code2} {$location->code3}");
                                        return [
                                            $location->id => "{$warehouseName} - {$locationCode} ({$location->name})"
                                        ];
                                    });
                            })
                            ->helperText('WMS属性を追加する基幹システムのロケーションを選択')
                            ->columnSpanFull(),

                        Select::make('wms_picking_area_id')
                            ->label('ピッキングエリア')
                            ->relationship('pickingArea', 'name')
                            ->searchable()
                            ->preload()
                            ->helperText('このロケーションが属するピッキングエリア（担当ピッカー分けに使用）')
                            ->columnSpanFull(),

                        Select::make('picking_unit_type')
                            ->label('引当可能単位')
                            ->required()
                            ->options([
                                'CASE' => 'ケースのみ',
                                'PIECE' => 'バラのみ',
                                'BOTH' => '両方可能',
                            ])
                            ->default('BOTH')
                            ->helperText('このロケーションから引き当て可能な商品単位')
                            ->columnSpan(1),

                        TextInput::make('walking_order')
                            ->label('動線順序')
                            ->numeric()
                            ->minValue(1)
                            ->step(1)
                            ->helperText('数値が小さいほど優先的にピッキング（通路順など）')
                            ->columnSpan(1),
                    ])
                    ->columns(3),

                Section::make('倉庫物理構造')
                    ->description('通路・棚・段の情報を入力（任意）')
                    ->schema([
                        TextInput::make('aisle')
                            ->label('通路番号')
                            ->maxLength(20)
                            ->placeholder('例: A, 1, A-1')
                            ->columnSpan(1),

                        TextInput::make('rack')
                            ->label('棚番号')
                            ->maxLength(20)
                            ->placeholder('例: 1, 2, A')
                            ->columnSpan(1),

                        TextInput::make('level')
                            ->label('段番号')
                            ->maxLength(20)
                            ->placeholder('例: 1（下段）, 2（中段）, 3（上段）')
                            ->helperText('1段目（下を基準）から順に番号を付けます')
                            ->columnSpan(1),
                    ])
                    ->columns(3)
                    ->collapsible(),
            ]);
    }
}
