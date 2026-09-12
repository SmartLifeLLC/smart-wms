<?php

namespace App\Services\InventoryCount;

use App\Models\WmsInventoryCountItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryJanCodeResolver
{
    /**
     * @param  Collection<int, WmsInventoryCountItem>  $items
     * @return array<int, string>
     */
    public function forItems(Collection $items): array
    {
        $itemIds = $items
            ->pluck('item_id')
            ->filter()
            ->unique()
            ->values();

        if ($itemIds->isEmpty()) {
            return [];
        }

        // JANブックと指示書で同じJANコードを使うため、既存JANブックの条件を共有する。
        $rows = DB::connection('sakemaru')
            ->table('item_search_information as isi')
            ->leftJoin('item_quantity_information as iqi', 'isi.item_quantity_information_id', '=', 'iqi.id')
            ->whereIn('isi.item_id', $itemIds)
            ->where('isi.code_type', 'OTHER')
            ->where('iqi.dm_code', 0)
            ->where('iqi.quantity_code', '00')
            ->whereNotNull('isi.search_string')
            ->where('isi.search_string', '!=', '')
            ->orderBy('isi.item_id')
            ->orderBy('isi.id')
            ->get(['isi.item_id', 'isi.search_string']);

        $janCodes = [];
        foreach ($rows as $row) {
            $itemId = (int) $row->item_id;
            $janCodes[$itemId] ??= (string) $row->search_string;
        }

        $fallbackRows = DB::connection('sakemaru')
            ->table('item_quantity_information as iqi')
            ->whereIn('iqi.item_id', $itemIds)
            ->where('iqi.dm_code', 0)
            ->where('iqi.quantity', 1)
            ->whereNotNull('iqi.own_code')
            ->where('iqi.own_code', '!=', '')
            ->orderBy('iqi.item_id')
            ->orderBy('iqi.quantity_code')
            ->orderBy('iqi.id')
            ->get(['iqi.item_id', 'iqi.own_code']);

        foreach ($fallbackRows as $row) {
            $itemId = (int) $row->item_id;
            $ownCode = $this->normalizeCode($row->own_code);

            if ($ownCode !== null) {
                $janCodes[$itemId] ??= $ownCode;
            }
        }

        return $janCodes;
    }

    private function normalizeCode(?string $code): ?string
    {
        $code = trim((string) $code);

        if ($code === '' || preg_match('/[1-9]/', $code) !== 1) {
            return null;
        }

        return $code;
    }
}
