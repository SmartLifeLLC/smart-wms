<?php

namespace App\Filament\Resources\WmsPickingTasks;

use App\Filament\Resources\WmsPickingTasks\Pages\ExecuteWmsPickingTask;
use App\Filament\Resources\WmsPickingTasks\Pages\ListWmsPickingTasks;
use App\Models\WmsPickingTask;
use Filament\Support\Enums\IconSize;
use Filament\Resources\Resource;
use Illuminate\Support\HtmlString;
use UnitEnum;
use BackedEnum;

class WmsPickingTaskResource extends Resource
{
    protected static ?string $model = WmsPickingTask::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'ピッキング作業';

    protected static ?string $modelLabel = 'ピッキングタスク';

    protected static ?string $pluralModelLabel = 'ピッキングタスク';

    protected static BackedEnum|UnitEnum|string|null $navigationGroup = 'WMS作業';

    protected static ?int $navigationSort = 10;

    public static function getPages(): array
    {
        return [
            'index' => ListWmsPickingTasks::route('/'),
            'execute' => ExecuteWmsPickingTask::route('/{record}/execute'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::unassigned()->inProgress()->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $count = static::getModel()::unassigned()->inProgress()->count();

        if ($count > 10) {
            return 'danger';
        } elseif ($count > 5) {
            return 'warning';
        } elseif ($count > 0) {
            return 'success';
        }

        return null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return '未割当タスク数';
    }
}
