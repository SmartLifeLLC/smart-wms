<?php

namespace App\Filament\Resources\WmsPickingAreas\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class WmsPickingAreaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('warehouse_id')
                    ->label('倉庫')
                    ->required()
                    ->options(function () {
                        return DB::connection('sakemaru')
                            ->table('warehouses')
                            ->pluck('name', 'id');
                    })
                    ->searchable(),
                TextInput::make('code')
                    ->label('エリアコード')
                    ->required()
                    ->maxLength(50)
                    ->helperText('例: 常温、冷蔵、冷凍'),
                TextInput::make('name')
                    ->label('エリア名')
                    ->required()
                    ->maxLength(100)
                    ->helperText('例: 常温エリア、冷蔵エリア、冷凍エリア'),
                TextInput::make('display_order')
                    ->label('表示順序')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->helperText('数値が小さいほど先に表示されます'),
                Toggle::make('is_active')
                    ->label('有効')
                    ->default(true)
                    ->required(),
            ]);
    }
}
