<?php

namespace App\Console\Commands\TestData;

use App\Domains\Sakemaru\SakemaruEarning;
use App\Models\Sakemaru\Item;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateTestEarningsCommand extends Command
{
    protected $signature = 'testdata:earnings
                            {--count=5 : Number of test earnings to generate}
                            {--warehouse-id=991 : Warehouse ID}';

    protected $description = 'Generate test earnings data via BoozeCore API';

    private int $warehouseId;
    private int $clientId;
    private int $buyerId;
    private string $buyerCode;
    private string $warehouseCode;
    private array $testItems = [];

    public function handle()
    {
        $this->info('📝 Generating test earnings via API...');
        $this->newLine();

        $this->warehouseId = (int) $this->option('warehouse-id');
        $count = (int) $this->option('count');

        // Initialize client, buyer, and warehouse data
        $this->initializeData();

        // Load test items
        $this->loadTestItems();

        if (empty($this->testItems)) {
            $this->error('No items with stock found for earnings generation');
            return 1;
        }

        // Generate earnings
        $result = $this->generateEarnings($count);

        if ($result['success']) {
            $this->info("✓ Successfully created {$count} test earnings via API");
            return 0;
        } else {
            $this->error("Failed to create earnings: " . ($result['error'] ?? 'Unknown error'));
            return 1;
        }
    }

    private function initializeData(): void
    {
        // Get client ID
        $client = DB::connection('sakemaru')->table('clients')->first();
        $this->clientId = $client->id;

        // Get buyer
        $buyer = DB::connection('sakemaru')->table('partners')
            ->where('is_supplier', 0)
            ->where('is_active', 1)
            ->first();
        $this->buyerId = $buyer->id ?? 1;
        $this->buyerCode = $buyer->code ?? '1';

        // Get warehouse
        $warehouse = DB::connection('sakemaru')->table('warehouses')
            ->where('id', $this->warehouseId)
            ->first();
        $this->warehouseCode = $warehouse->code ?? (string) $this->warehouseId;

        $this->line("Using buyer: {$this->buyerId} (code: {$this->buyerCode}), warehouse: {$this->warehouseId} (code: {$this->warehouseCode})");
        $this->newLine();
    }

    private function loadTestItems(): void
    {
        $this->testItems = Item::where('type', 'ALCOHOL')
            ->where('id', '>', 111099)
            ->whereIn('id', function ($query) {
                $query->select('item_id')
                    ->from('real_stocks')
                    ->where('warehouse_id', $this->warehouseId);
            })
            ->limit(30)
            ->get()
            ->map(fn($item) => ['id' => $item->id, 'code' => $item->code, 'name' => $item->name])
            ->toArray();

        $this->line("Loaded " . count($this->testItems) . " test items");
    }

    private function generateEarnings(int $count): array
    {
        $processDate = now()->format('Y-m-d');
        $shippingDate = now()->addDay()->format('Y-m-d');

        $scenarios = [
            ['name' => 'ケース注文（十分な在庫）', 'qty_type' => 'CASE', 'qty' => 2],
            ['name' => 'バラ注文（十分な在庫）', 'qty_type' => 'PIECE', 'qty' => 15],
            ['name' => 'ケース注文（欠品あり）', 'qty_type' => 'CASE', 'qty' => 200],
            ['name' => 'バラ注文（欠品あり）', 'qty_type' => 'PIECE', 'qty' => 500],
            ['name' => 'ケース・バラ混在注文', 'qty_type' => 'MIXED', 'qty' => 0],
        ];

        $earnings = [];

        for ($i = 0; $i < $count; $i++) {
            $scenario = $scenarios[$i % count($scenarios)];

            // Add items to earning
            $itemCount = $scenario['qty_type'] === 'MIXED' ? rand(3, 5) : rand(2, 3);
            $selectedItems = collect($this->testItems)->random($itemCount);

            $details = [];
            foreach ($selectedItems as $index => $item) {
                $qtyType = $scenario['qty_type'];
                $qty = $scenario['qty'];

                if ($qtyType === 'MIXED') {
                    $qtyType = $index % 2 === 0 ? 'CASE' : 'PIECE';
                    $qty = $qtyType === 'CASE' ? rand(1, 5) : rand(5, 20);
                }

                $details[] = [
                    'item_code' => $item['code'],
                    'quantity' => $qty,
                    'quantity_type' => $qtyType,
                    'order_quantity' => $qty,
                    'order_quantity_type' => $qtyType,
                    'price' => rand(100, 5000),
                    'note' => "{$qtyType} {$qty}個",
                ];
            }

            $earnings[] = [
                'process_date' => $processDate,
                'delivered_date' => $shippingDate,
                'account_date' => $shippingDate,
                'buyer_code' => $this->buyerCode,
                'warehouse_code' => $this->warehouseCode,
                'note' => "WMSテスト: {$scenario['name']}",
                'is_delivered' => false,
                'is_returned' => false,
                'details' => $details,
            ];
        }

        $this->line("Sending {$count} earnings to API...");

        // Call API using SakemaruEarning
        $response = SakemaruEarning::postData([
            'earnings' => $earnings
        ]);

        if (isset($response['success']) && $response['success']) {
            return ['success' => true];
        } else {
            return [
                'success' => false,
                'error' => $response['debug_message'] ?? $response['message'] ?? 'API request failed',
            ];
        }
    }
}
