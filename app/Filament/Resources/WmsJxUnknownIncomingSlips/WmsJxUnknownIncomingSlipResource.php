<?php

namespace App\Filament\Resources\WmsJxUnknownIncomingSlips;

use App\Enums\EMenu;
use App\Filament\Resources\WmsJxUnknownIncomingSlips\Pages\ListWmsJxUnknownIncomingSlips;
use App\Filament\Resources\WmsJxUnknownIncomingSlips\Tables\WmsJxUnknownIncomingSlipsTable;
use App\Filament\Support\AdminResource;
use App\Models\WmsIncomingReceivedSlip;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsJxUnknownIncomingSlipResource extends AdminResource
{
    protected static ?string $model = WmsIncomingReceivedSlip::class;

    protected static string $permissionResource = 'wms-jx-transmission-log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $slug = 'wms-jx-unknown-incoming-slips';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_JX_UNKNOWN_INCOMING_SLIPS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_JX_UNKNOWN_INCOMING_SLIPS->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_JX_UNKNOWN_INCOMING_SLIPS->sort();
    }

    public static function getModelLabel(): string
    {
        return '伝票番号不明';
    }

    public static function getPluralModelLabel(): string
    {
        return '伝票番号不明';
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

    public static function table(Table $table): Table
    {
        return WmsJxUnknownIncomingSlipsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsJxUnknownIncomingSlips::route('/'),
        ];
    }
}
