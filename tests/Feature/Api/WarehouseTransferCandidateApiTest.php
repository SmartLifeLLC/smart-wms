<?php

namespace Tests\Feature\Api;

use App\Enums\WarehouseTransferCandidateStatus;
use App\Models\WmsPicker;
use App\Models\WmsWarehouseTransferCandidate;
use App\Services\WarehouseTransfer\WarehouseTransferQueueService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 倉庫移動候補（HANDY）API / 確定処理のテスト
 *
 * RefreshDatabase は使わず、作成した候補・queue は tearDown で個別削除する。
 */
class WarehouseTransferCandidateApiTest extends TestCase
{
    private string $apiKey;

    private ?WmsPicker $picker = null;

    private ?string $token = null;

    /** @var array<int, int> */
    private array $createdCandidateIds = [];

    /** @var array<int, int> */
    private array $createdQueueIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKey = config('api.keys')[0] ?? 'test-key';
        $this->picker = WmsPicker::first();
        if ($this->picker) {
            $this->token = $this->picker->createToken('test-warehouse-transfer')->plainTextToken;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->createdCandidateIds as $id) {
            $candidate = WmsWarehouseTransferCandidate::find($id);
            if ($candidate) {
                $candidate->itemSources()->delete();
                $candidate->uploads()->delete();
                $candidate->items()->delete();
                $candidate->delete();
            }
        }
        foreach ($this->createdQueueIds as $id) {
            DB::connection('sakemaru')->table('stock_transfer_queue')->where('id', $id)->delete();
        }
        $this->picker?->tokens()->where('name', 'test-warehouse-transfer')->delete();

        parent::tearDown();
    }

    private function apiHeaders(): array
    {
        return [
            'X-API-Key' => $this->apiKey,
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ];
    }

    /**
     * @return array{from:int, to:int, item:object, real_stock_id:int}
     */
    private function fixture(): array
    {
        if (! $this->picker) {
            $this->markTestSkipped('No pickers found in database');
        }

        $stock = DB::connection('sakemaru')
            ->table('real_stocks as rs')
            ->join('items as i', 'i.id', '=', 'rs.item_id')
            ->where('rs.current_quantity', '>', 0)
            ->where('i.is_active', 1)
            ->orderBy('rs.id')
            ->first(['rs.id as real_stock_id', 'rs.warehouse_id', 'i.id', 'i.code', 'i.capacity_case']);

        $to = $stock
            ? DB::connection('sakemaru')->table('warehouses')->where('id', '!=', $stock->warehouse_id)->where('is_active', 1)->value('id')
            : null;

        if (! $stock || ! $to) {
            $this->markTestSkipped('No stock / destination warehouse available');
        }

        return [
            'from' => (int) $stock->warehouse_id,
            'to' => (int) $to,
            'item' => $stock,
            'real_stock_id' => (int) $stock->real_stock_id,
        ];
    }

    private function payload(array $fixture, string $uploadUuid, array $extraItems = []): array
    {
        return [
            'upload_uuid' => $uploadUuid,
            'device_id' => 'TEST',
            'from_warehouse_id' => $fixture['from'],
            'to_warehouse_id' => $fixture['to'],
            'process_date' => now()->toDateString(),
            'delivered_date' => now()->toDateString(),
            'items' => array_merge([[
                'item_id' => $fixture['item']->id,
                'item_code' => $fixture['item']->code,
                'real_stock_id' => $fixture['real_stock_id'],
                'stock_allocation_code' => '1',
                'case_quantity' => 0,
                'piece_quantity' => 2,
                'package_quantity' => max((int) $fixture['item']->capacity_case, 1),
                'quantity' => 2,
                'request_uuid' => "{$uploadUuid}-1",
            ]], $extraItems),
        ];
    }

    public function test_stock_items_and_jan_codes_are_returned(): void
    {
        $fixture = $this->fixture();

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/wms/warehouse-transfer/stock-items?warehouse_id={$fixture['from']}&per_page=5&compact=1");

        $response->assertOk()
            ->assertJsonPath('is_success', true)
            ->assertJsonStructure(['result' => ['data' => ['items', 'meta' => ['page', 'per_page', 'total', 'last_page']]]]);

        $this->assertNotEmpty($response->json('result.data.items'));
        $this->assertArrayHasKey('available_quantity', $response->json('result.data.items.0'));

        $this->withHeaders($this->apiHeaders())
            ->getJson("/api/wms/warehouse-transfer/jan-codes?warehouse_id={$fixture['from']}")
            ->assertOk()
            ->assertJsonPath('is_success', true);
    }

    public function test_warehouses_exclude_source(): void
    {
        $fixture = $this->fixture();

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/wms/warehouse-transfer/warehouses?exclude_warehouse_id={$fixture['from']}")
            ->assertOk();

        $ids = array_column($response->json('result.data.warehouses'), 'id');
        $this->assertNotContains($fixture['from'], $ids);
    }

    public function test_receive_creates_candidate_and_is_idempotent(): void
    {
        $fixture = $this->fixture();
        $uploadUuid = 'test-'.Str::uuid();

        $payload = $this->payload($fixture, $uploadUuid, [[
            // 同一商品の2行目 → 加算される
            'item_id' => $fixture['item']->id,
            'item_code' => $fixture['item']->code,
            'quantity' => 3,
            'request_uuid' => "{$uploadUuid}-2",
        ], [
            // 存在しない商品 → missing
            'item_id' => 999999999,
            'item_code' => 'X',
            'quantity' => 1,
            'request_uuid' => "{$uploadUuid}-3",
        ]]);

        $first = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/wms/warehouse-transfer-candidates', $payload)
            ->assertCreated()
            ->assertJsonPath('is_success', true)
            ->assertJsonPath('result.data.accepted_count', 2)
            ->assertJsonPath('result.data.missing_item_ids.0', 999999999)
            ->assertJsonPath('result.data.candidate.item_count', 1)
            ->assertJsonPath('result.data.candidate.total_quantity', 5);

        $candidateId = (int) $first->json('result.data.candidate.id');
        $this->createdCandidateIds[] = $candidateId;

        // 同じ upload_uuid の再送 → 二重加算されない
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/wms/warehouse-transfer-candidates', $payload)
            ->assertOk()
            ->assertJsonPath('result.data.duplicated', true)
            ->assertJsonPath('result.data.candidate.total_quantity', 5);

        // 別 upload_uuid だが同じ request_uuid の行 → 二重加算されない
        $payload2 = $payload;
        $payload2['upload_uuid'] = 'test-'.Str::uuid();
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/wms/warehouse-transfer-candidates', $payload2)
            ->assertCreated()
            ->assertJsonPath('result.data.accepted_count', 0)
            ->assertJsonPath('result.data.candidate.id', $candidateId)
            ->assertJsonPath('result.data.candidate.total_quantity', 5);

        $candidate = WmsWarehouseTransferCandidate::findOrFail($candidateId);
        $this->assertSame(WarehouseTransferCandidateStatus::PENDING, $candidate->status);
        $this->assertSame(1, $candidate->items()->count());
        $this->assertSame(2, $candidate->itemSources()->count());
        $this->assertSame(2, $candidate->uploads()->count());
    }

    public function test_validation_rejects_same_warehouse_and_empty_items(): void
    {
        $this->fixture();

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/wms/warehouse-transfer-candidates', [
                'upload_uuid' => 'x',
                'from_warehouse_id' => 1,
                'to_warehouse_id' => 1,
                'process_date' => now()->toDateString(),
                'delivered_date' => now()->toDateString(),
                'items' => [],
            ])
            ->assertStatus(422)
            ->assertJsonPath('is_success', false)
            ->assertJsonStructure(['result' => ['errors' => ['to_warehouse_id', 'items']]]);
    }

    public function test_confirm_creates_single_queue_with_piece_items(): void
    {
        $fixture = $this->fixture();
        $uploadUuid = 'test-'.Str::uuid();

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/wms/warehouse-transfer-candidates', $this->payload($fixture, $uploadUuid))
            ->assertCreated();

        $candidate = WmsWarehouseTransferCandidate::findOrFail((int) $response->json('result.data.candidate.id'));
        $this->createdCandidateIds[] = $candidate->id;

        $deliveryCourseId = DB::connection('sakemaru')->table('delivery_courses')->where('is_active', 1)->value('id');
        if (! $deliveryCourseId) {
            $this->markTestSkipped('No delivery course available');
        }
        $candidate->update(['delivery_course_id' => $deliveryCourseId]);

        $service = app(WarehouseTransferQueueService::class);
        $validation = $service->validateForConfirm($candidate);
        $this->assertTrue($validation['ok'], implode(' / ', $validation['errors']));

        $queueId = $service->enqueue($candidate, null);
        $this->createdQueueIds[] = $queueId;

        // 二重確定しても queue は1件
        $this->assertSame($queueId, $service->enqueue($candidate->fresh(), null));

        $candidate->refresh();
        $this->assertSame(WarehouseTransferCandidateStatus::CONFIRMED, $candidate->status);
        $this->assertSame("wms-warehouse-transfer-{$candidate->id}", $candidate->queue_request_id);
        $this->assertSame($queueId, (int) $candidate->stock_transfer_queue_id);

        $queue = DB::connection('sakemaru')->table('stock_transfer_queue')->where('id', $queueId)->first();
        $this->assertSame('BEFORE', $queue->status);
        $this->assertSame('CREATE', $queue->action_type);
        $this->assertSame($candidate->from_warehouse_code, $queue->from_warehouse_code);
        $this->assertSame($candidate->to_warehouse_code, $queue->to_warehouse_code);

        $items = json_decode($queue->items, true);
        $this->assertCount(1, $items);
        $this->assertSame($fixture['item']->code, $items[0]['item_code']);
        $this->assertSame('PIECE', $items[0]['quantity_type']);
        $this->assertSame('1', $items[0]['stock_allocation_code']);
        $this->assertEquals(2, $items[0]['quantity']);

        $this->assertSame(1, DB::connection('sakemaru')->table('stock_transfer_queue')->where('request_id', $candidate->queue_request_id)->count());

        // 確定後は取消不可
        $this->expectException(\RuntimeException::class);
        $service->cancel($candidate->fresh(), null);
    }
}
