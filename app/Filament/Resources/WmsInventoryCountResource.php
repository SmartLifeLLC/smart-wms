<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WmsInventoryCount\Pages\ListWmsInventoryCounts;
use App\Filament\Resources\WmsInventoryCount\Pages\ViewWmsInventoryCount;
use App\Filament\Resources\WmsInventoryCount\Pages\ViewWmsInventoryCountLogs;
use App\Filament\Resources\WmsInventoryCount\Tables\WmsInventoryCountTable;
use App\Filament\Support\AdminResource;
use App\Models\WmsInventoryCount;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsInventoryCountResource extends AdminResource
{
    protected static ?string $model = WmsInventoryCount::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return '在庫管理';
    }

    public static function getNavigationLabel(): string
    {
        return '棚卸し';
    }

    public static function getModelLabel(): string
    {
        return '棚卸し';
    }

    public static function getPluralModelLabel(): string
    {
        return '棚卸し';
    }

    public static function table(Table $table): Table
    {
        return WmsInventoryCountTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['createdByUser']);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsInventoryCounts::route('/'),
            'view' => ViewWmsInventoryCount::route('/{record}'),
            'logs' => ViewWmsInventoryCountLogs::route('/{record}/logs'),
        ];
    }
}
