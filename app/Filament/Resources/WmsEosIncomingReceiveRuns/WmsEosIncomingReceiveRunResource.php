<?php

namespace App\Filament\Resources\WmsEosIncomingReceiveRuns;

use App\Enums\EMenu;
use App\Filament\Resources\WmsEosIncomingReceiveRuns\Pages\ListWmsEosIncomingReceiveRuns;
use App\Filament\Resources\WmsEosIncomingReceiveRuns\Tables\WmsEosIncomingReceiveRunsTable;
use App\Filament\Support\AdminResource;
use App\Models\WmsEosIncomingReceiveRun;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsEosIncomingReceiveRunResource extends AdminResource
{
    protected static ?string $model = WmsEosIncomingReceiveRun::class;

    protected static string $permissionResource = 'wms-jx-transmission-log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $slug = 'wms-eos-incoming-receive-settings';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_EOS_INCOMING_RECEIVE_SETTINGS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_EOS_INCOMING_RECEIVE_SETTINGS->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_EOS_INCOMING_RECEIVE_SETTINGS->sort();
    }

    public static function getModelLabel(): string
    {
        return 'EOSデータ受信設定';
    }

    public static function getPluralModelLabel(): string
    {
        return 'EOSデータ受信設定';
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['schedule', 'logs']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return WmsEosIncomingReceiveRunsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsEosIncomingReceiveRuns::route('/'),
        ];
    }
}
