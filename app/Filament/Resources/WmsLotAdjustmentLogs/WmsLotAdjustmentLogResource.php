<?php

namespace App\Filament\Resources\WmsLotAdjustmentLogs;

use App\Enums\EMenu;
use App\Filament\Resources\WmsLotAdjustmentLogs\Pages\ListWmsLotAdjustmentLogs;
use App\Filament\Resources\WmsLotAdjustmentLogs\Tables\WmsLotAdjustmentLogsTable;
use App\Filament\Support\AdminResource;
use App\Models\WmsLotAdjustmentLog;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsLotAdjustmentLogResource extends AdminResource
{
    protected static ?string $model = WmsLotAdjustmentLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'ロット調節履歴';

    protected static ?string $modelLabel = 'ロット調節履歴';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WAVE_MANAGEMENT_ADJUST_LOT_HISTORY->category()->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WAVE_MANAGEMENT_ADJUST_LOT_HISTORY->sort();
    }

    public static function table(Table $table): Table
    {
        return WmsLotAdjustmentLogsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsLotAdjustmentLogs::route('/'),
        ];
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
}
