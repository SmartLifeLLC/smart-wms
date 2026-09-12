<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Enums\QuantityType;
use App\Models\WmsEosIncomingReceiveRun;
use App\Models\WmsEosIncomingReceiveSetting;
use App\Models\WmsIncomingImportError;
use App\Models\WmsIncomingPriceCheckSource;
use App\Models\WmsIncomingReceivedDetail;
use App\Models\WmsIncomingReceivedFile;
use App\Models\WmsIncomingReceivedSlip;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\EosIncomingAutoReceiveService;
use App\Services\AutoOrder\IncomingReceiveService;
use App\Services\AutoOrder\IncomingTransmissionService;
use App\Services\JX\Eos\JxEosIncomingWorkflowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class EosIncomingAutoReceiveServiceTest extends TestCase
{
    private const TEST_SLIP_PREFIX = 'EOSPERF';

    protected function tearDown(): void
    {
        $fileIds = WmsIncomingReceivedFile::query()
            ->where('filename', 'like', 'test-eos-auto-receive-%')
            ->pluck('id');

        if ($fileIds->isNotEmpty()) {
            if (Schema::connection('sakemaru')->hasTable('wms_incoming_price_check_sources')) {
                WmsIncomingPriceCheckSource::query()->whereIn('received_file_id', $fileIds->all())->delete();
            }

            WmsIncomingImportError::query()->whereIn('received_file_id', $fileIds->all())->delete();
            WmsIncomingReceivedDetail::query()->whereIn('received_file_id', $fileIds->all())->delete();
            WmsIncomingReceivedSlip::query()->whereIn('received_file_id', $fileIds->all())->delete();
            WmsIncomingReceivedFile::query()->whereIn('id', $fileIds->all())->delete();
        }

        WmsOrderIncomingSchedule::query()
            ->where('slip_number', 'like', self::TEST_SLIP_PREFIX.'%')
            ->delete();

        WmsEosIncomingReceiveRun::query()
            ->where('run_key', 'like', 'test-eos-auto-receive-%')
            ->delete();

        parent::tearDown();
    }

    public function test_complete_schedules_as_shortage_deletes_old_purchase_schedules(): void
    {
        $master = $this->incomingMasterData();

        $pending = $this->createSchedule($master, 'A', IncomingScheduleStatus::PENDING, expectedQuantity: 5, receivedQuantity: 2);
        $partial = $this->createSchedule($master, 'B', IncomingScheduleStatus::PARTIAL, expectedQuantity: 7, receivedQuantity: 0);
        $confirmed = $this->createSchedule($master, 'C', IncomingScheduleStatus::CONFIRMED, expectedQuantity: 9, receivedQuantity: 9);

        $service = new EosIncomingAutoReceiveService(
            $this->createMock(JxEosIncomingWorkflowService::class),
            $this->createMock(IncomingTransmissionService::class),
        );
        $method = new ReflectionMethod($service, 'completeSchedulesAsShortage');
        $method->setAccessible(true);

        $completed = $method->invoke(
            $service,
            WmsOrderIncomingSchedule::query()->where('slip_number', 'like', self::TEST_SLIP_PREFIX.'%')
        );

        $this->assertSame(2, $completed);

        $pending->refresh();
        $partial->refresh();
        $confirmed->refresh();

        $this->assertSame(IncomingScheduleStatus::DELETED, $pending->status);
        $this->assertSame(0, $pending->received_quantity);
        $this->assertSame(0, $pending->shipped_quantity);
        $this->assertSame(5, $pending->shortage_quantity);
        $this->assertSame('2026-07-01', $pending->actual_arrival_date?->format('Y-m-d'));
        $this->assertSame(0, $pending->confirmed_by);
        $this->assertNotNull($pending->confirmed_at);

        $this->assertSame(IncomingScheduleStatus::DELETED, $partial->status);
        $this->assertSame(0, $partial->received_quantity);
        $this->assertSame(0, $partial->shipped_quantity);
        $this->assertSame(7, $partial->shortage_quantity);
        $this->assertSame('2026-07-01', $partial->actual_arrival_date?->format('Y-m-d'));
        $this->assertSame(0, $partial->confirmed_by);
        $this->assertNotNull($partial->confirmed_at);

        $this->assertSame(IncomingScheduleStatus::CONFIRMED, $confirmed->status);
        $this->assertSame(9, $confirmed->received_quantity);
        $this->assertSame(0, $confirmed->shortage_quantity);
        $this->assertNull($confirmed->confirmed_by);
    }

    public function test_complete_schedules_as_received_marks_transfer_schedules_completed_without_shortage(): void
    {
        $master = $this->incomingMasterData();

        $pending = $this->createSchedule($master, 'T1', IncomingScheduleStatus::PENDING, expectedQuantity: 5, receivedQuantity: 0, orderSource: OrderSource::TRANSFER);
        $partial = $this->createSchedule($master, 'T2', IncomingScheduleStatus::PARTIAL, expectedQuantity: 7, receivedQuantity: 3, orderSource: OrderSource::TRANSFER);
        $confirmed = $this->createSchedule($master, 'T3', IncomingScheduleStatus::CONFIRMED, expectedQuantity: 9, receivedQuantity: 9, orderSource: OrderSource::TRANSFER);

        $service = new EosIncomingAutoReceiveService(
            $this->createMock(JxEosIncomingWorkflowService::class),
            $this->createMock(IncomingTransmissionService::class),
        );
        $method = new ReflectionMethod($service, 'completeSchedulesAsReceived');
        $method->setAccessible(true);

        $completed = $method->invoke(
            $service,
            WmsOrderIncomingSchedule::query()->where('slip_number', 'like', self::TEST_SLIP_PREFIX.'T%')
        );

        $this->assertSame(2, $completed);

        $pending->refresh();
        $partial->refresh();
        $confirmed->refresh();

        $this->assertSame(IncomingScheduleStatus::CONFIRMED, $pending->status);
        $this->assertSame(5, $pending->received_quantity);
        $this->assertSame(5, $pending->shipped_quantity);
        $this->assertSame(0, $pending->shortage_quantity);
        $this->assertNull($pending->purchase_queue_id);
        $this->assertSame('2026-07-01', $pending->actual_arrival_date?->format('Y-m-d'));
        $this->assertSame(0, $pending->confirmed_by);
        $this->assertNotNull($pending->confirmed_at);

        $this->assertSame(IncomingScheduleStatus::CONFIRMED, $partial->status);
        $this->assertSame(7, $partial->received_quantity);
        $this->assertSame(7, $partial->shipped_quantity);
        $this->assertSame(0, $partial->shortage_quantity);
        $this->assertNull($partial->purchase_queue_id);
        $this->assertSame('2026-07-01', $partial->actual_arrival_date?->format('Y-m-d'));

        $this->assertSame(IncomingScheduleStatus::CONFIRMED, $confirmed->status);
        $this->assertSame(9, $confirmed->received_quantity);
        $this->assertSame(0, $confirmed->shortage_quantity);
        $this->assertNull($confirmed->confirmed_by);
    }

    public function test_auto_confirm_unassigned_jx_slips_creates_confirmed_unknown_incoming(): void
    {
        $contractorId = $this->contractorId('1330');
        $slipNumber = self::TEST_SLIP_PREFIX.'U'.random_int(100000, 999999);

        $file = WmsIncomingReceivedFile::create([
            'filename' => 'test-eos-auto-receive-'.now()->format('YmdHisv').'.dat',
            'format_type' => 'JX',
            'status' => 'PENDING',
            'contractor_id' => $contractorId,
            'parsed_slip_count' => 1,
            'parsed_detail_count' => 1,
        ]);

        $slip = WmsIncomingReceivedSlip::create([
            'received_file_id' => $file->id,
            'slip_number' => $slipNumber,
            'match_status' => 'UNMATCHED',
            'b_shop_code' => '0008',
            'b_order_date' => '260720',
            'b_delivery_date' => '260721',
            'b_contractor_code' => '1330',
            'detail_count' => 1,
        ]);

        $detail = WmsIncomingReceivedDetail::create([
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'd_line_number' => 1,
            'd_jan_code' => '7401005008597',
            'd_item_code' => '162100',
            'd_pack_quantity' => 6,
            'd_case_quantity' => 0,
            'd_piece_quantity' => 3,
            'd_unit_price' => 410500,
            'total_quantity' => 3,
            'match_status' => 'UNMATCHED',
        ]);

        app(IncomingReceiveService::class)->matchWithSchedules($file);

        $setting = WmsEosIncomingReceiveSetting::ensureDefault();
        $run = WmsEosIncomingReceiveRun::create([
            'run_key' => 'test-eos-auto-receive-'.now()->format('YmdHisv'),
            'setting_id' => $setting->id,
            'execution_date' => now()->toDateString(),
            'trigger_type' => WmsEosIncomingReceiveRun::TRIGGER_MANUAL,
            'status' => WmsEosIncomingReceiveRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);
        $stats = [
            'incoming_matched_count' => 0,
            'incoming_unmatched_count' => 1,
            'incoming_confirmed_schedule_count' => 0,
            'unknown_slip_count' => 1,
        ];

        $service = new EosIncomingAutoReceiveService(
            $this->createMock(JxEosIncomingWorkflowService::class),
            $this->createMock(IncomingTransmissionService::class),
        );
        $method = new ReflectionMethod($service, 'autoConfirmUnassignedJxSlips');
        $method->setAccessible(true);

        $scheduleIds = $method->invokeArgs($service, [$run, $file->fresh(), &$stats]);

        $this->assertCount(1, $scheduleIds);
        $this->assertSame(1, $stats['incoming_matched_count']);
        $this->assertSame(0, $stats['incoming_unmatched_count']);
        $this->assertSame(1, $stats['incoming_confirmed_schedule_count']);
        $this->assertSame(0, $stats['unknown_slip_count']);

        $schedule = WmsOrderIncomingSchedule::query()
            ->where('source_received_detail_id', $detail->id)
            ->firstOrFail();

        $this->assertSame($schedule->id, $scheduleIds[0]);
        $this->assertSame(IncomingScheduleStatus::CONFIRMED, $schedule->status);
        $this->assertSame(OrderSource::RECEIVED, $schedule->order_source);
        $this->assertTrue($schedule->isUnassignedJxReceived());
        $this->assertSame("UNASSIGNED_JX_SLIP_{$slip->id}", $schedule->purchase_split_key);
        $this->assertSame(3, $schedule->received_quantity);
        $this->assertSame('MATCHED', $slip->fresh()->match_status);
        $this->assertSame('MATCHED', $detail->fresh()->match_status);
        $this->assertSame(WmsIncomingReceivedFile::STATUS_APPLIED, $file->fresh()->status);
    }

    public function test_auto_confirm_unassigned_jx_slips_skips_manually_resolved_unknown_item(): void
    {
        $contractorId = $this->contractorId('1330');
        $slipNumber = self::TEST_SLIP_PREFIX.'R'.random_int(100000, 999999);

        $file = WmsIncomingReceivedFile::create([
            'filename' => 'test-eos-auto-receive-'.now()->format('YmdHisv').'.dat',
            'format_type' => 'JX',
            'status' => 'PENDING',
            'contractor_id' => $contractorId,
            'parsed_slip_count' => 1,
            'parsed_detail_count' => 1,
        ]);

        $slip = WmsIncomingReceivedSlip::create([
            'received_file_id' => $file->id,
            'slip_number' => $slipNumber,
            'match_status' => 'NO_ASSIGNMENT',
            'b_shop_code' => '0008',
            'b_order_date' => '260720',
            'b_delivery_date' => '260721',
            'b_contractor_code' => '1330',
            'detail_count' => 1,
        ]);

        $detail = WmsIncomingReceivedDetail::create([
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'd_line_number' => 1,
            'd_jan_code' => '9999999999001',
            'd_item_code' => '999999',
            'd_product_name' => '商品不明手動確定テスト',
            'd_pack_quantity' => 6,
            'd_case_quantity' => 0,
            'd_piece_quantity' => 3,
            'd_unit_price' => 410500,
            'total_quantity' => 3,
            'matched_item_id' => 162100,
            'match_status' => 'NO_ASSIGNMENT',
        ]);

        WmsIncomingImportError::create([
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'received_detail_id' => $detail->id,
            'error_type' => 'ERROR',
            'error_code' => 'ITEM_NOT_FOUND',
            'error_message' => '商品を特定できません',
            'item_code' => '999999',
            'is_resolved' => true,
            'resolved_by' => 123,
            'resolved_at' => now(),
        ]);

        $setting = WmsEosIncomingReceiveSetting::ensureDefault();
        $run = WmsEosIncomingReceiveRun::create([
            'run_key' => 'test-eos-auto-receive-'.now()->format('YmdHisv'),
            'setting_id' => $setting->id,
            'execution_date' => now()->toDateString(),
            'trigger_type' => WmsEosIncomingReceiveRun::TRIGGER_MANUAL,
            'status' => WmsEosIncomingReceiveRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);
        $stats = [
            'incoming_matched_count' => 0,
            'incoming_unmatched_count' => 1,
            'incoming_confirmed_schedule_count' => 0,
            'unknown_slip_count' => 1,
        ];

        $service = new EosIncomingAutoReceiveService(
            $this->createMock(JxEosIncomingWorkflowService::class),
            $this->createMock(IncomingTransmissionService::class),
        );
        $method = new ReflectionMethod($service, 'autoConfirmUnassignedJxSlips');
        $method->setAccessible(true);

        $scheduleIds = $method->invokeArgs($service, [$run, $file->fresh(), &$stats]);

        $this->assertSame([], $scheduleIds);
        $this->assertSame(0, WmsOrderIncomingSchedule::query()
            ->where('source_received_detail_id', $detail->id)
            ->count());
        $this->assertSame('NO_ASSIGNMENT', $slip->fresh()->match_status);
        $this->assertSame('NO_ASSIGNMENT', $detail->fresh()->match_status);
        $this->assertSame(0, $stats['incoming_matched_count']);
        $this->assertSame(1, $stats['incoming_unmatched_count']);
        $this->assertSame(0, $stats['incoming_confirmed_schedule_count']);
        $this->assertSame(1, $stats['unknown_slip_count']);
    }

    private function incomingMasterData(): array
    {
        $warehouse = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('is_active', true)
            ->whereNotNull('code')
            ->orderBy('id')
            ->first(['id']);
        $item = DB::connection('sakemaru')
            ->table('items')
            ->where('is_active', true)
            ->whereNotNull('code')
            ->orderBy('id')
            ->first(['id', 'code']);
        $contractor = DB::connection('sakemaru')
            ->table('contractors')
            ->where('is_active', true)
            ->orderBy('id')
            ->first(['id']);

        if (! $warehouse || ! $item || ! $contractor) {
            $this->markTestSkipped('incoming schedule master data is not available in test DB');
        }

        return [
            'warehouse_id' => (int) $warehouse->id,
            'item_id' => (int) $item->id,
            'item_code' => (string) $item->code,
            'contractor_id' => (int) $contractor->id,
        ];
    }

    private function createSchedule(
        array $master,
        string $suffix,
        IncomingScheduleStatus $status,
        int $expectedQuantity,
        int $receivedQuantity,
        OrderSource $orderSource = OrderSource::AUTO,
    ): WmsOrderIncomingSchedule {
        return WmsOrderIncomingSchedule::query()->create([
            'warehouse_id' => $master['warehouse_id'],
            'item_id' => $master['item_id'],
            'item_code' => $master['item_code'],
            'contractor_id' => $master['contractor_id'],
            'order_source' => $orderSource,
            'slip_number' => self::TEST_SLIP_PREFIX.$suffix.random_int(100000, 999999),
            'expected_quantity' => $expectedQuantity,
            'received_quantity' => $receivedQuantity,
            'shortage_quantity' => max(0, $expectedQuantity - $receivedQuantity),
            'quantity_type' => QuantityType::PIECE,
            'order_date' => '2026-06-30',
            'expected_arrival_date' => '2026-07-01',
            'actual_arrival_date' => $status === IncomingScheduleStatus::CONFIRMED ? '2026-07-01' : null,
            'status' => $status,
            'confirmed_at' => $status === IncomingScheduleStatus::CONFIRMED ? '2026-07-01 09:00:00' : null,
        ]);
    }

    private function contractorId(string $code): int
    {
        $contractorId = DB::connection('sakemaru')
            ->table('contractors')
            ->where('code', $code)
            ->value('id');

        if (! $contractorId) {
            $this->markTestSkipped("contractor {$code} is not available in test DB");
        }

        return (int) $contractorId;
    }
}
