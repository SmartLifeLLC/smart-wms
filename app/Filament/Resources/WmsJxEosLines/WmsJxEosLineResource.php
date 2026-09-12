<?php

namespace App\Filament\Resources\WmsJxEosLines;

use App\Enums\EMenu;
use App\Filament\Resources\WmsJxEosLines\Pages\ListWmsJxEosLines;
use App\Filament\Resources\WmsJxEosLines\Pages\ViewWmsJxEosLine;
use App\Filament\Resources\WmsJxEosLines\Schemas\WmsJxEosLineInfolist;
use App\Filament\Resources\WmsJxEosLines\Tables\WmsJxEosLinesTable;
use App\Filament\Support\AdminResource;
use App\Models\WmsJxEosLine;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsJxEosLineResource extends AdminResource
{
    protected static ?string $model = WmsJxEosLine::class;

    protected static string $permissionResource = 'wms-jx-transmission-log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $slug = 'wms-jx-eos-lines';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_JX_EOS_LINES->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_JX_EOS_LINES->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_JX_EOS_LINES->sort();
    }

    public static function getModelLabel(): string
    {
        return 'EOS受信明細';
    }

    public static function getPluralModelLabel(): string
    {
        return 'EOS受信明細';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return WmsJxEosLineInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WmsJxEosLinesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsJxEosLines::route('/'),
            'view' => ViewWmsJxEosLine::route('/{record}'),
        ];
    }
}
