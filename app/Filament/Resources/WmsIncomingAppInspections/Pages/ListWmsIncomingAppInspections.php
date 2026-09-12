<?php

namespace App\Filament\Resources\WmsIncomingAppInspections\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsIncomingAppInspections\WmsIncomingAppInspectionResource;
use App\Models\WmsIncomingAppInspectionDetail;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsIncomingAppInspections extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsIncomingAppInspectionResource::class;

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query->orderByDesc('id'));
    }

    public function getPresetViews(): array
    {
        return [
            'all' => PresetView::make()
                ->favorite()
                ->label('全て')
                ->default(),

            'needs_review' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('result_status', WmsIncomingAppInspectionDetail::RESULT_NEEDS_REVIEW))
                ->favorite()
                ->label('要確認'),

            'eos_history' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('result_status', [
                    WmsIncomingAppInspectionDetail::RESULT_HISTORY_ONLY,
                    WmsIncomingAppInspectionDetail::RESULT_EOS_ALREADY_CONFIRMED,
                ]))
                ->favorite()
                ->label('EOS履歴のみ'),

            'app_created' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('result_status', WmsIncomingAppInspectionDetail::RESULT_APP_UNPLANNED_CREATED))
                ->favorite()
                ->label('予定なし作成'),
        ];
    }
}
