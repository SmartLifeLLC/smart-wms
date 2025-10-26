<?php

namespace App\Filament\Resources\WmsPickers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class WmsPickerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('基本情報')
                    ->schema([
                        TextInput::make('code')
                            ->label('ピッカーコード')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->placeholder('例: P001')
                            ->helperText('ピッカーを識別するための一意のコード'),

                        TextInput::make('name')
                            ->label('ピッカー名')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('例: 山田太郎'),

                        TextInput::make('password')
                            ->label('パスワード')
                            ->password()
                            ->dehydrateStateUsing(fn ($state) => $state ? Hash::make($state) : null)
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $context): bool => $context === 'create')
                            ->maxLength(255)
                            ->placeholder('8文字以上推奨')
                            ->helperText(fn (string $context): string =>
                                $context === 'edit'
                                    ? '変更する場合のみ入力してください'
                                    : 'ピッカーがログインする際のパスワード'
                            ),
                    ])
                    ->columns(2),

                Section::make('設定')
                    ->schema([
                        Select::make('default_warehouse_id')
                            ->label('デフォルト倉庫')
                            ->options(function () {
                                return DB::connection('sakemaru')
                                    ->table('warehouses')
                                    ->pluck('name', 'id');
                            })
                            ->searchable()
                            ->nullable()
                            ->helperText('このピッカーがメインで作業する倉庫'),

                        Toggle::make('is_active')
                            ->label('有効')
                            ->default(true)
                            ->helperText('無効にすると、このピッカーは選択できなくなります'),
                    ])
                    ->columns(2),
            ]);
    }
}
