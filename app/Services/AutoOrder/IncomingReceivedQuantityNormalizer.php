<?php

namespace App\Services\AutoOrder;

use Illuminate\Support\Facades\DB;

class IncomingReceivedQuantityNormalizer
{
    private array $orderingUnitQuantityCache = [];

    public function normalize(int $caseQuantity, int $pieceQuantity, int $packQuantity, ?string $janCode): int
    {
        $rawQuantity = $packQuantity > 0
            ? ($caseQuantity * $packQuantity) + $pieceQuantity
            : $pieceQuantity;

        $orderingUnitQuantity = $this->orderingUnitQuantity($janCode);

        return $orderingUnitQuantity !== null
            ? $rawQuantity * $orderingUnitQuantity
            : $rawQuantity;
    }

    public function orderingUnitQuantity(?string $janCode): ?int
    {
        $codes = $this->normalizedSearchCodes($janCode);
        if ($codes === []) {
            return null;
        }

        $cacheKey = implode('|', $codes);
        if (array_key_exists($cacheKey, $this->orderingUnitQuantityCache)) {
            return $this->orderingUnitQuantityCache[$cacheKey];
        }

        $row = DB::connection('sakemaru')
            ->table('item_search_information as isi')
            ->join('item_quantity_information as iqi', 'iqi.id', '=', 'isi.item_quantity_information_id')
            ->join('items as i', 'i.id', '=', 'isi.item_id')
            ->where('isi.is_active', true)
            ->whereIn('isi.search_string', $codes)
            ->where('iqi.quantity', '>', 1)
            ->select('iqi.quantity', 'i.capacity_case')
            ->orderBy('iqi.quantity')
            ->first();

        if (! $row) {
            return $this->orderingUnitQuantityCache[$cacheKey] = null;
        }

        $quantity = (int) $row->quantity;
        $capacityCase = (int) ($row->capacity_case ?? 0);

        if ($quantity <= 1 || ($capacityCase > 1 && $quantity === $capacityCase)) {
            return $this->orderingUnitQuantityCache[$cacheKey] = null;
        }

        return $this->orderingUnitQuantityCache[$cacheKey] = $quantity;
    }

    private function normalizedSearchCodes(?string $code): array
    {
        $code = trim((string) $code);
        if ($code === '' || preg_match('/^0+$/', $code) === 1) {
            return [];
        }

        $codes = [$code];
        $withoutLeadingZeros = ltrim($code, '0');
        if ($withoutLeadingZeros !== '') {
            $codes[] = $withoutLeadingZeros;

            if (strlen($withoutLeadingZeros) < 13) {
                $codes[] = str_pad($withoutLeadingZeros, 13, '0', STR_PAD_LEFT);
            }
        }

        return array_values(array_unique($codes));
    }
}
