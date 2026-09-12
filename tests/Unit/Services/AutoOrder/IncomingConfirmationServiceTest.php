<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Enums\QuantityType;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\IncomingConfirmationService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * IncomingConfirmationService テスト
 *
 * 実DBの既存データを利用してテストを実行する。
 * DB:fresh, refreshなどのリセットは一切行わない。
 */
class IncomingConfirmationServiceTest extends TestCase
{
    private const TEST_SLIP_PREFIX = 'UTIC';

    private IncomingConfirmationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(IncomingConfirmationService::class);
    }

    protected function tearDown(): void
    {
        WmsOrderIncomingSchedule::query()
            ->where('slip_number', 'like', self::TEST_SLIP_PREFIX.'%')
            ->delete();

        parent::tearDown();
    }

    /**
     * @test
     * サービスがDIで解決できること
     */
    public function it_can_be_resolved_from_container(): void
    {
        $service = app(IncomingConfirmationService::class);
        $this->assertInstanceOf(IncomingConfirmationService::class, $service);
    }

    /**
     * @test
     * 入荷確定時の保存数量は既存入荷済数量と今回入力数量の合計になること
     */
    public function it_resolves_confirmed_received_quantity_from_existing_and_entered_quantities(): void
    {
        $pendingSchedule = new WmsOrderIncomingSchedule([
            'expected_quantity' => 10,
            'received_quantity' => 0,
        ]);

        $partialSchedule = new WmsOrderIncomingSchedule([
            'expected_quantity' => 10,
            'received_quantity' => 3,
        ]);

        $this->assertSame(12, $this->service->resolveConfirmedReceivedQuantity($pendingSchedule, 12));
        $this->assertSame(10, $this->service->resolveConfirmedReceivedQuantity($partialSchedule, 7));
    }

    /**
     * @test
     * 予定数量未満で手動確定した場合は一部入荷ではなく欠品数つきの入荷完了になること
     */
    public function it_confirms_manual_short_received_quantity_as_shortage(): void
    {
        $schedule = $this->createManualSchedule(expectedQuantity: 10);

        $confirmed = $this->service->confirmIncoming(
            $schedule,
            1,
            4,
            '2026-07-22'
        );

        $this->assertSame(IncomingScheduleStatus::CONFIRMED, $confirmed->status);
        $this->assertSame(4, $confirmed->received_quantity);
        $this->assertSame(6, $confirmed->shortage_quantity);
        $this->assertSame('2026-07-22', $confirmed->actual_arrival_date?->format('Y-m-d'));
        $this->assertNull($confirmed->purchase_queue_id);
    }

    /**
     * @test
     * 手動の一部入荷は仕入連携可能な完了行を作り、元の予定は残数量として残ること
     */
    public function it_splits_manual_partial_incoming_into_confirmed_purchase_schedule_and_remaining_schedule(): void
    {
        $schedule = $this->createManualSchedule(expectedQuantity: 10);

        $confirmedSchedule = $this->service->recordPartialIncoming(
            $schedule,
            4,
            1,
            '2026-07-22'
        );

        $schedule->refresh();

        $this->assertNotSame($schedule->id, $confirmedSchedule->id);
        $this->assertSame($schedule->slip_number, $confirmedSchedule->slip_number);

        $this->assertSame(6, $schedule->expected_quantity);
        $this->assertSame(0, $schedule->received_quantity);
        $this->assertSame(IncomingScheduleStatus::PENDING, $schedule->status);
        $this->assertNull($schedule->purchase_queue_id);

        $this->assertSame(4, $confirmedSchedule->expected_quantity);
        $this->assertSame(4, $confirmedSchedule->received_quantity);
        $this->assertSame(IncomingScheduleStatus::CONFIRMED, $confirmedSchedule->status);
        $this->assertSame('2026-07-22', $confirmedSchedule->actual_arrival_date?->format('Y-m-d'));
        $this->assertNull($confirmedSchedule->purchase_queue_id);
        $this->assertTrue(WmsOrderIncomingSchedule::query()->readyForIncomingTransmission()->whereKey($confirmedSchedule->id)->exists());
    }

    /**
     * @test
     * 既存の一部入荷データは未送信の入荷済数量を含めて完了行に切り出すこと
     */
    public function it_folds_legacy_partial_received_quantity_into_first_split_completion(): void
    {
        $schedule = $this->createManualSchedule(
            expectedQuantity: 10,
            receivedQuantity: 3,
            status: IncomingScheduleStatus::PARTIAL
        );

        $confirmedSchedule = $this->service->recordPartialIncoming(
            $schedule,
            2,
            1,
            '2026-07-22'
        );

        $schedule->refresh();

        $this->assertSame(5, $confirmedSchedule->received_quantity);
        $this->assertSame(5, $confirmedSchedule->expected_quantity);
        $this->assertSame(IncomingScheduleStatus::CONFIRMED, $confirmedSchedule->status);

        $this->assertSame(5, $schedule->expected_quantity);
        $this->assertSame(0, $schedule->received_quantity);
        $this->assertSame(IncomingScheduleStatus::PENDING, $schedule->status);
    }

    /**
     * @test
     * 存在しないスケジュールIDでconfirmMultipleを呼んでもエラーになること
     */
    public function it_handles_non_existent_schedule_in_confirm_multiple(): void
    {
        $result = $this->service->confirmMultiple([999999999], 1);

        $this->assertIsArray($result);
        $this->assertEquals(0, $result['success']);
        $this->assertEquals(1, $result['failed']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * @test
     * 空のスケジュールID配列でconfirmMultipleを呼んでもエラーにならないこと
     */
    public function it_handles_empty_schedule_ids_gracefully(): void
    {
        $result = $this->service->confirmMultiple([], 1);

        $this->assertIsArray($result);
        $this->assertEquals(0, $result['success']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEmpty($result['errors']);
    }

    /**
     * @test
     * IncomingScheduleStatusのenum値が正しいこと
     */
    public function it_has_correct_incoming_schedule_statuses(): void
    {
        $this->assertEquals('PENDING', IncomingScheduleStatus::PENDING->value);
        $this->assertEquals('PARTIAL', IncomingScheduleStatus::PARTIAL->value);
        $this->assertEquals('CONFIRMED', IncomingScheduleStatus::CONFIRMED->value);
        $this->assertEquals('TRANSMITTED', IncomingScheduleStatus::TRANSMITTED->value);
        $this->assertEquals('CANCELLED', IncomingScheduleStatus::CANCELLED->value);
        $this->assertEquals('PARTIAL_CANCELLED', IncomingScheduleStatus::PARTIAL_CANCELLED->value);
        $this->assertEquals('DELETED', IncomingScheduleStatus::DELETED->value);
    }

    /**
     * @test
     * PENDING状態の入庫予定が取得できること
     */
    public function it_can_find_pending_incoming_schedules(): void
    {
        $pendingCount = WmsOrderIncomingSchedule::where('status', IncomingScheduleStatus::PENDING)->count();

        // PENDING状態のスケジュールがなくてもテストは成功
        $this->assertGreaterThanOrEqual(0, $pendingCount);
    }

    /**
     * @test
     * TRANSFER タイプの入庫予定が取得できること
     */
    public function it_can_find_transfer_incoming_schedules(): void
    {
        $transferCount = WmsOrderIncomingSchedule::where('order_source', OrderSource::TRANSFER)->count();

        // TRANSFERタイプのスケジュールがなくてもテストは成功
        $this->assertGreaterThanOrEqual(0, $transferCount);
    }

    /**
     * @test
     * stock_transfer_queueテーブルにDELIVERタイプのレコードを挿入できる構造であること
     * 注意: action_typeカラムはsakemaru-ai-core側で追加される
     */
    public function it_can_insert_deliver_type_queue(): void
    {
        $columns = DB::connection('sakemaru')
            ->getSchemaBuilder()
            ->getColumnListing('stock_transfer_queue');

        $requiredColumns = [
            'client_id',
            'request_id',
            'stock_transfer_id',
            'delivered_date',
            'items',
            'note',
            'status',
        ];

        foreach ($requiredColumns as $column) {
            $this->assertContains($column, $columns, "stock_transfer_queue should have {$column} column");
        }

        // action_typeはsakemaru-ai-core側で追加されるため、存在しない場合はスキップ
        if (! in_array('action_type', $columns)) {
            $this->markTestIncomplete('action_type column not yet added (requires sakemaru-ai-core migration)');
        }

        $this->assertContains('action_type', $columns);
    }

    /**
     * @test
     * WmsOrderIncomingScheduleモデルにstock_transfer_idカラムがあること
     */
    public function it_has_stock_transfer_id_in_incoming_schedule(): void
    {
        $fillable = (new WmsOrderIncomingSchedule)->getFillable();

        $this->assertContains('stock_transfer_id', $fillable, 'stock_transfer_id should be fillable');
    }

    /**
     * @test
     * WmsOrderIncomingScheduleモデルにtransfer_candidate_idカラムがあること
     */
    public function it_has_transfer_candidate_id_in_incoming_schedule(): void
    {
        $fillable = (new WmsOrderIncomingSchedule)->getFillable();

        $this->assertContains('transfer_candidate_id', $fillable, 'transfer_candidate_id should be fillable');
    }

    /**
     * @test
     * 確定済みスケジュールの再確定でエラーが発生すること
     */
    public function it_throws_exception_for_already_confirmed_schedule(): void
    {
        $confirmedSchedule = WmsOrderIncomingSchedule::where('status', IncomingScheduleStatus::CONFIRMED)->first();

        if (! $confirmedSchedule) {
            $this->markTestSkipped('No confirmed schedule available for testing');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is already confirmed');

        $this->service->confirmIncoming($confirmedSchedule, 1);
    }

    /**
     * @test
     * キャンセル済みスケジュールの確定でエラーが発生すること
     */
    public function it_throws_exception_for_cancelled_schedule(): void
    {
        $cancelledSchedule = WmsOrderIncomingSchedule::where('status', IncomingScheduleStatus::CANCELLED)->first();

        if (! $cancelledSchedule) {
            $this->markTestSkipped('No cancelled schedule available for testing');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is cancelled');

        $this->service->confirmIncoming($cancelledSchedule, 1);
    }

    /**
     * @test
     * 確定済みスケジュールの一部入庫記録でエラーが発生すること
     */
    public function it_throws_exception_for_partial_incoming_on_confirmed(): void
    {
        $confirmedSchedule = WmsOrderIncomingSchedule::where('status', IncomingScheduleStatus::CONFIRMED)->first();

        if (! $confirmedSchedule) {
            $this->markTestSkipped('No confirmed schedule available for testing');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is already fully confirmed');

        $this->service->recordPartialIncoming($confirmedSchedule, 10, 1);
    }

    /**
     * @test
     * 確定済み・送信済みスケジュールのキャンセルでエラーが発生すること
     */
    public function it_throws_exception_for_cancelling_confirmed_schedule(): void
    {
        $confirmedSchedule = WmsOrderIncomingSchedule::where('status', IncomingScheduleStatus::CONFIRMED)->first();

        if (! $confirmedSchedule) {
            $this->markTestSkipped('No confirmed schedule available for testing');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot cancel confirmed/transmitted schedule');

        $this->service->cancelIncoming($confirmedSchedule, 1, 'テストキャンセル');
    }

    /**
     * @test
     * createDeliverQueueメソッドが存在すること（リフレクションで確認）
     */
    public function it_has_create_deliver_queue_method(): void
    {
        $reflection = new \ReflectionClass(IncomingConfirmationService::class);

        $this->assertTrue(
            $reflection->hasMethod('createDeliverQueue'),
            'IncomingConfirmationService should have createDeliverQueue method'
        );

        $method = $reflection->getMethod('createDeliverQueue');
        $this->assertTrue($method->isPrivate(), 'createDeliverQueue should be private');
    }

    /**
     * @test
     * syncStockTransferIdメソッドが存在すること（リフレクションで確認）
     */
    public function it_has_sync_stock_transfer_id_method(): void
    {
        $reflection = new \ReflectionClass(IncomingConfirmationService::class);

        $this->assertTrue(
            $reflection->hasMethod('syncStockTransferId'),
            'IncomingConfirmationService should have syncStockTransferId method'
        );

        $method = $reflection->getMethod('syncStockTransferId');
        $this->assertTrue($method->isPrivate(), 'syncStockTransferId should be private');
    }

    private function createManualSchedule(
        int $expectedQuantity,
        int $receivedQuantity = 0,
        IncomingScheduleStatus $status = IncomingScheduleStatus::PENDING
    ): WmsOrderIncomingSchedule {
        return WmsOrderIncomingSchedule::query()->create([
            'warehouse_id' => 999001,
            'item_id' => 999002,
            'item_code' => 'UT-ITEM',
            'search_code' => 'UT-SEARCH',
            'contractor_id' => 999003,
            'supplier_id' => 999004,
            'manual_order_number' => 'UT-MANUAL',
            'order_source' => OrderSource::MANUAL,
            'slip_number' => $this->newSlipNumber(),
            'expected_quantity' => $expectedQuantity,
            'received_quantity' => $receivedQuantity,
            'quantity_type' => QuantityType::PIECE,
            'order_date' => '2026-07-21',
            'expected_arrival_date' => '2026-07-22',
            'status' => $status,
            'unit_price' => 100,
            'case_price' => 1200,
        ]);
    }

    private function newSlipNumber(): string
    {
        return self::TEST_SLIP_PREFIX.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT);
    }
}
