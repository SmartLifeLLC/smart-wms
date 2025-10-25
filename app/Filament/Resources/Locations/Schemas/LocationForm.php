<?php

namespace App\Filament\Resources\Locations\Schemas;

use App\Models\Sakemaru\Warehouse;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
                            ->options(function () {
                                return Warehouse::query()
                                    ->orderBy('name')
                                    ->pluck('name', 'id');
                            })
                            ->helperText('このロケーションが属する倉庫')
                            ->columnSpan(2),

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
            ]);
    }
}
