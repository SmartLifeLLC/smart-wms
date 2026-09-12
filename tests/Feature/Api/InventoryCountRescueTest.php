<?php

namespace Tests\Feature\Api;

use App\Models\WmsInventoryCountRescueData;
use App\Models\WmsPicker;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InventoryCountRescueTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    private $picker;

    private $token;

    private $headers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->picker = WmsPicker::first();
        if (! $this->picker) {
            $this->picker = WmsPicker::create([
                'code' => 'RESCUE_TEST',
                'name' => 'Rescue Test Picker',
                'password' => Hash::make('1234'),
                'default_warehouse_id' => 1,
                'is_active' => true,
            ]);
        }

        $this->token = $this->picker->createToken('test')->plainTextToken;

        $apiKeys = config('api.keys', []);
        $this->headers = [
            'X-API-Key' => ! empty($apiKeys) ? $apiKeys[0] : 'test-api-key-12345',
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ];
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'original_count_id' => 135,
            'original_count_no' => 'IC-20260901-M9ZAFNBP',
            'count_round' => 1,
            'device_id' => 'DENSO',
            'items' => [$this->validItem()],
        ], $overrides);
    }

    private function validItem(array $overrides = []): array
    {
        return array_merge([
            'item_id' => 606928,
            'item_code' => '249833',
            'item_name' => '稲葉 クレイジーソルトナッツ126g',
            'location_no' => 'ZZ1100',
            'case_quantity' => 0,
            'piece_quantity' => 5,
            'total_pieces' => 5,
            'search_code' => '4901234567890',
            'package_quantity' => 12,
            'request_uuid' => 'c3e5a821-18a5-49da-8fe4-44a14fb8aab1',
            'input_at' => 1725236017000,
        ], $overrides);
    }

    public function test_stores_rescue_data_with_valid_input(): void
    {
        $payload = $this->validPayload();

        $response = $this->postJson('/api/wms/inventory-counts/rescue', $payload, $this->headers);

        $response->assertStatus(200)
            ->assertJson([
                'is_success' => true,
                'code' => 'SUCCESS',
            ])
            ->assertJsonStructure([
                'result' => ['data' => ['rescue_id', 'received_count']],
            ]);

        $data = $response->json('result.data');
        $this->assertEquals(1, $data['received_count']);

        $rescue = WmsInventoryCountRescueData::find($data['rescue_id']);
        $this->assertNotNull($rescue);
        $this->assertEquals(135, $rescue->original_count_id);
        $this->assertEquals('IC-20260901-M9ZAFNBP', $rescue->original_count_no);
        $this->assertEquals(1, $rescue->count_round);
        $this->assertEquals('DENSO', $rescue->device_id);
        $this->assertEquals('pending', $rescue->status);
        $this->assertEquals(1, $rescue->item_count);
    }

    public function test_same_upload_uuid_is_idempotent(): void
    {
        $uploadUuid = 'rescue-'.uniqid();
        $payload = $this->validPayload(['upload_uuid' => $uploadUuid]);

        $first = $this->postJson('/api/wms/inventory-counts/rescue', $payload, $this->headers);
        $first->assertStatus(200)->assertJsonPath('result.data.duplicated', false);
        $rescueId = $first->json('result.data.rescue_id');

        $second = $this->postJson('/api/wms/inventory-counts/rescue', $payload, $this->headers);
        $second->assertStatus(200)
            ->assertJsonPath('result.data.duplicated', true)
            ->assertJsonPath('result.data.rescue_id', $rescueId)
            ->assertJsonPath('result.data.received_count', 1);

        $this->assertSame(1, WmsInventoryCountRescueData::where('upload_uuid', $uploadUuid)->count());
    }

    public function test_requests_without_upload_uuid_are_stored_separately(): void
    {
        $payload = $this->validPayload();

        $first = $this->postJson('/api/wms/inventory-counts/rescue', $payload, $this->headers)->json('result.data.rescue_id');
        $second = $this->postJson('/api/wms/inventory-counts/rescue', $payload, $this->headers)->json('result.data.rescue_id');

        $this->assertNotEquals($first, $second);
    }

    public function test_stores_user_and_warehouse_from_auth(): void
    {
        $response = $this->postJson('/api/wms/inventory-counts/rescue', $this->validPayload(), $this->headers);

        $response->assertStatus(200);

        $rescue = WmsInventoryCountRescueData::find($response->json('result.data.rescue_id'));
        $this->assertEquals($this->picker->id, $rescue->user_id);
        $this->assertEquals($this->picker->default_warehouse_id, $rescue->warehouse_id);
    }

    public function test_stores_all_item_fields_in_json(): void
    {
        $item = $this->validItem([
            'item_id' => 999,
            'item_code' => 'TEST001',
            'item_name' => 'テスト商品',
            'location_no' => 'A01',
            'case_quantity' => 2,
            'piece_quantity' => 3,
            'total_pieces' => 27,
            'search_code' => '1234567890123',
            'package_quantity' => 12,
            'request_uuid' => 'test-uuid-001',
            'input_at' => 1725236017000,
        ]);

        $payload = $this->validPayload(['items' => [$item]]);

        $response = $this->postJson('/api/wms/inventory-counts/rescue', $payload, $this->headers);
        $response->assertStatus(200);

        $rescue = WmsInventoryCountRescueData::find($response->json('result.data.rescue_id'));
        $storedItem = $rescue->items[0];

        $this->assertEquals(999, $storedItem['item_id']);
        $this->assertEquals('TEST001', $storedItem['item_code']);
        $this->assertEquals('テスト商品', $storedItem['item_name']);
        $this->assertEquals('A01', $storedItem['location_no']);
        $this->assertEquals(2, $storedItem['case_quantity']);
        $this->assertEquals(3, $storedItem['piece_quantity']);
        $this->assertEquals(27, $storedItem['total_pieces']);
        $this->assertEquals('1234567890123', $storedItem['search_code']);
        $this->assertEquals(12, $storedItem['package_quantity']);
        $this->assertEquals('test-uuid-001', $storedItem['request_uuid']);
        $this->assertEquals(1725236017000, $storedItem['input_at']);
    }

    public function test_stores_multiple_items(): void
    {
        $items = [
            $this->validItem(['item_code' => 'ITEM001', 'request_uuid' => 'uuid-1']),
            $this->validItem(['item_code' => 'ITEM002', 'request_uuid' => 'uuid-2']),
            $this->validItem(['item_code' => 'ITEM003', 'request_uuid' => 'uuid-3']),
        ];

        $response = $this->postJson(
            '/api/wms/inventory-counts/rescue',
            $this->validPayload(['items' => $items]),
            $this->headers
        );

        $response->assertStatus(200);

        $data = $response->json('result.data');
        $this->assertEquals(3, $data['received_count']);

        $rescue = WmsInventoryCountRescueData::find($data['rescue_id']);
        $this->assertCount(3, $rescue->items);
        $this->assertEquals('ITEM001', $rescue->items[0]['item_code']);
        $this->assertEquals('ITEM002', $rescue->items[1]['item_code']);
        $this->assertEquals('ITEM003', $rescue->items[2]['item_code']);
    }

    public function test_accepts_nullable_fields_as_null(): void
    {
        $item = $this->validItem([
            'location_no' => null,
            'search_code' => null,
            'package_quantity' => null,
        ]);

        $response = $this->postJson(
            '/api/wms/inventory-counts/rescue',
            $this->validPayload(['items' => [$item], 'device_id' => null]),
            $this->headers
        );

        $response->assertStatus(200);

        $rescue = WmsInventoryCountRescueData::find($response->json('result.data.rescue_id'));
        $this->assertNull($rescue->device_id);
        $this->assertNull($rescue->items[0]['location_no']);
        $this->assertNull($rescue->items[0]['search_code']);
        $this->assertNull($rescue->items[0]['package_quantity']);
    }

    public function test_validation_error_missing_original_count_id(): void
    {
        $payload = $this->validPayload();
        unset($payload['original_count_id']);

        $response = $this->postJson('/api/wms/inventory-counts/rescue', $payload, $this->headers);

        $response->assertStatus(422)
            ->assertJson(['is_success' => false, 'code' => 'VALIDATION_ERROR']);
    }

    public function test_validation_error_missing_original_count_no(): void
    {
        $payload = $this->validPayload();
        unset($payload['original_count_no']);

        $response = $this->postJson('/api/wms/inventory-counts/rescue', $payload, $this->headers);

        $response->assertStatus(422)
            ->assertJson(['is_success' => false, 'code' => 'VALIDATION_ERROR']);
    }

    public function test_validation_error_invalid_count_round(): void
    {
        $response = $this->postJson(
            '/api/wms/inventory-counts/rescue',
            $this->validPayload(['count_round' => 4]),
            $this->headers
        );

        $response->assertStatus(422)
            ->assertJson(['is_success' => false, 'code' => 'VALIDATION_ERROR']);
    }

    public function test_validation_error_missing_count_round(): void
    {
        $payload = $this->validPayload();
        unset($payload['count_round']);

        $response = $this->postJson('/api/wms/inventory-counts/rescue', $payload, $this->headers);

        $response->assertStatus(422)
            ->assertJson(['is_success' => false, 'code' => 'VALIDATION_ERROR']);
    }

    public function test_validation_error_empty_items(): void
    {
        $response = $this->postJson(
            '/api/wms/inventory-counts/rescue',
            $this->validPayload(['items' => []]),
            $this->headers
        );

        $response->assertStatus(422)
            ->assertJson(['is_success' => false, 'code' => 'VALIDATION_ERROR']);
    }

    public function test_validation_error_missing_items(): void
    {
        $payload = $this->validPayload();
        unset($payload['items']);

        $response = $this->postJson('/api/wms/inventory-counts/rescue', $payload, $this->headers);

        $response->assertStatus(422)
            ->assertJson(['is_success' => false, 'code' => 'VALIDATION_ERROR']);
    }

    public function test_validation_error_item_missing_required_fields(): void
    {
        $incompleteItem = ['item_id' => 1];

        $response = $this->postJson(
            '/api/wms/inventory-counts/rescue',
            $this->validPayload(['items' => [$incompleteItem]]),
            $this->headers
        );

        $response->assertStatus(422)
            ->assertJson(['is_success' => false, 'code' => 'VALIDATION_ERROR']);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $headers = [
            'X-API-Key' => config('api.keys')[0] ?? 'test-key',
            'Accept' => 'application/json',
        ];

        $response = $this->postJson('/api/wms/inventory-counts/rescue', $this->validPayload(), $headers);

        $response->assertStatus(401);
    }

    public function test_default_status_is_pending(): void
    {
        $response = $this->postJson('/api/wms/inventory-counts/rescue', $this->validPayload(), $this->headers);

        $response->assertStatus(200);

        $rescue = WmsInventoryCountRescueData::find($response->json('result.data.rescue_id'));
        $this->assertEquals(WmsInventoryCountRescueData::STATUS_PENDING, $rescue->status);
        $this->assertNull($rescue->processed_count_id);
        $this->assertNull($rescue->note);
    }

    public function test_count_round_accepts_all_valid_values(): void
    {
        foreach ([1, 2, 3] as $round) {
            $response = $this->postJson(
                '/api/wms/inventory-counts/rescue',
                $this->validPayload(['count_round' => $round]),
                $this->headers
            );

            $response->assertStatus(200);

            $rescue = WmsInventoryCountRescueData::find($response->json('result.data.rescue_id'));
            $this->assertEquals($round, $rescue->count_round);
        }
    }
}
