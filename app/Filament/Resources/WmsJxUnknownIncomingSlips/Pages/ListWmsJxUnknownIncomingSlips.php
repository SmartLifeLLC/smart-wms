<?php

namespace App\Filament\Resources\WmsJxUnknownIncomingSlips\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsJxUnknownIncomingSlips\Tables\WmsJxUnknownIncomingSlipsTable;
use App\Filament\Resources\WmsJxUnknownIncomingSlips\WmsJxUnknownIncomingSlipResource;
use App\Models\Sakemaru\Contractor;
use App\Models\WmsIncomingReceivedSlip;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListWmsJxUnknownIncomingSlips extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsJxUnknownIncomingSlipResource::class;

    protected ?array $presetViewContractorData = null;

    protected function getContractorDataForPresetViews(): array
    {
        if ($this->presetViewContractorData !== null) {
            return $this->presetViewContractorData;
        }

        $warehouseId = auth()->user()?->getSelectedWarehouseId();
        $cacheKey = 'jx_unknown_incoming_slips_contractors_'.($warehouseId ?: 'none');

        $this->presetViewContractorData = cache()->remember($cacheKey, 30, function () use ($warehouseId): array {
            $query = WmsIncomingReceivedSlip::query()
                ->join('wms_incoming_received_files as received_files', 'received_files.id', '=', 'wms_incoming_received_slips.received_file_id')
                ->where('received_files.format_type', 'JX')
                ->whereNotNull('received_files.contractor_id');

            WmsJxUnknownIncomingSlipsTable::applyReviewScope($query);
            WmsJxUnknownIncomingSlipsTable::applySelectedWarehouseScope($query, $warehouseId);

            $contractorIds = $query
                ->distinct()
                ->pluck('received_files.contractor_id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();

            $contractors = Contractor::whereIn('id', $contractorIds)
                ->orderBy('code')
                ->get(['id', 'code', 'name']);

            return [
                'ids' => $contractorIds,
                'contractors' => $contractors,
            ];
        });

        return $this->presetViewContractorData;
    }

    public function getPresetViews(): array
    {
        $warehouseId = auth()->user()?->getSelectedWarehouseId();
        $contractorData = $this->getContractorDataForPresetViews();
        $contractors = $contractorData['contractors'];

        $views = [
            'default' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query): Builder => WmsJxUnknownIncomingSlipsTable::applySelectedWarehouseScope($query, $warehouseId))
                ->favorite()
                ->label('全て')
                ->default(),
        ];

        foreach ($contractors as $contractor) {
            $label = filled($contractor->code)
                ? "[{$contractor->code}]{$contractor->name}"
                : $contractor->name;

            $views["contractor_{$contractor->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query): Builder => WmsJxUnknownIncomingSlipsTable::applySelectedWarehouseScope(
                    $query->whereHas('file', fn (Builder $fileQuery): Builder => $fileQuery->where('contractor_id', $contractor->id)),
                    $warehouseId
                ))
                ->favorite()
                ->label($label);
        }

        return $views;
    }
}
