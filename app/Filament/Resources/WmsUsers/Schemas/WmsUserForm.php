<?php

namespace App\Filament\Resources\WmsUsers\Schemas;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class WmsUserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('基本情報')
                    ->schema([
                        TextInput::make('code')
                            ->label('作業者コード')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        TextInput::make('name')
                            ->label('作業者名')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('password')
                            ->label('パスワード')
                            ->password()
                            ->required(fn ($context) => $context === 'create')
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? $state : null)
                            ->dehydrated(fn ($state) => filled($state))
                            ->revealable()
                            ->maxLength(255),

                        Select::make('default_warehouse_id')
                            ->label('デフォルト倉庫')
                            ->relationship('defaultWarehouse', 'name')
                            ->searchable()
                            ->preload(),

                        Toggle::make('is_active')
                            ->label('有効')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }
}
