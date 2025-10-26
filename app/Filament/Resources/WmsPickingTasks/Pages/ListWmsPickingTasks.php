<?php

namespace App\Filament\Resources\WmsPickingTasks\Pages;

use App\Filament\Resources\WmsPickingTasks\Tables\WmsPickingTasksTable;
use App\Filament\Resources\WmsPickingTasks\WmsPickingTaskResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsPickingTasks extends ListRecords
{
    protected static string $resource = WmsPickingTaskResource::class;

    protected static ?string $title = 'ピッキング作業一覧';

    public function table(Table $table): Table
    {
        return WmsPickingTasksTable::configure($table)
            ->modifyQueryUsing(fn (Builder $query) =>
                $query->with(['trade', 'warehouse', 'earning.delivery_course', 'picker'])
                    ->withCount('pickingItemResults')
            );
    }

    protected function getHeaderActions(): array
    {
        return [
            // Future: Add action to manually create picking task
        ];
    }
}
