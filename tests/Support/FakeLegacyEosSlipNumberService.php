<?php

namespace Tests\Support;

use App\Services\AutoOrder\LegacyEosSlipNumberService;
use Carbon\CarbonInterface;

class FakeLegacyEosSlipNumberService extends LegacyEosSlipNumberService
{
    public function __construct(
        private int $sequence = 18628
    ) {}

    public function allocateForWarehouse(mixed $warehouse, CarbonInterface|string|null $orderDate = null): array
    {
        $this->sequence++;
        $storeCode = is_object($warehouse) || filled($warehouse)
            ? $this->resolveStoreCode($warehouse)
            : '91';
        $yearCode = $this->yearCode($orderDate);

        return [
            'slip_number' => $this->formatSlipNumber($storeCode, $yearCode, $this->sequence),
            'store_code' => $storeCode,
            'year_code' => $yearCode,
            'sequence_no' => $this->sequence,
        ];
    }
}
