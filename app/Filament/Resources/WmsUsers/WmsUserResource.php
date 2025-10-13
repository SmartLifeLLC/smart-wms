<?php

namespace App\Filament\Resources\WmsUsers;

use App\Filament\Resources\WmsUsers\Pages\CreateWmsUser;
use App\Filament\Resources\WmsUsers\Pages\EditWmsUser;
use App\Filament\Resources\WmsUsers\Pages\ListWmsUsers;
use App\Filament\Resources\WmsUsers\Schemas\WmsUserForm;
use App\Filament\Resources\WmsUsers\Tables\WmsUsersTable;
use App\Models\WmsUser;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsUserResource extends Resource
{
    protected static ?string $model = WmsUser::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function getNavigationGroup(): ?string
    {
        return '管理';
    }

    public static function getNavigationLabel(): string
    {
        return '作業者管理';
    }

    public static function getModelLabel(): string
    {
        return '作業者';
    }

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return WmsUserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WmsUsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsUsers::route('/'),
            'create' => CreateWmsUser::route('/create'),
            'edit' => EditWmsUser::route('/{record}/edit'),
        ];
    }
}
