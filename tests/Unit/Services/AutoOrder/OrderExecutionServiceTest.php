<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Enums\QuantityType;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderIncomingSchedule;
use App\Models\WmsOrderSlipNumberAssignment;
use App\Services\AutoOrder\OrderAuditService;
use App\Services\AutoOrder\OrderExecutionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderExecutionServiceTest extends TestCase
{
    public function test_pack_order_incoming_quantity_keeps_candidate_quantity_and_type(): void
    {
        $service = new OrderExecutionService($this->createMock(OrderAuditService::class));

        $reflection = new \ReflectionClass($service);
        $candidate = new WmsOrderCandidate([
            'item_id' => 100,
            'ordering_code' => '4901411004754',
            'quantity_type' => QuantityType::CASE,
            'order_quantity' => 4,
        ]);

        $method = $reflection->getMethod('resolveIncomingQuantity');
        $method->setAccessible(true);

        [$quantity, $quantityType] = $method->invoke($service, $candidate);

        $this->assertSame(4, $quantity);
        $this->assertSame(QuantityType::CASE, $quantityType);
    }

    public function test_regular_case_order_incoming_quantity_stays_case_quantity(): void
    {
        $service = new OrderExecutionService($this->createMock(OrderAuditService::class));

        $reflection = new \ReflectionClass($service);
        $candidate = new WmsOrderCandidate([
            'item_id' => 100,
            'ordering_code' => '4900000000000',
            'quantity_type' => QuantityType::CASE,
            'order_quantity' => 4,
        ]);

        $method = $reflection->getMethod('resolveIncomingQuantity');
        $method->setAccessible(true);

        [$quantity, $quantityType] = $method->invoke($service, $candidate);

        $this->assertSame(4, $quantity);
        $this->assertSame(QuantityType::CASE, $quantityType);
    }

    public function test_ordering_unit_order_incoming_quantity_keeps_candidate_quantity_and_type(): void
    {
        $service = new OrderExecutionService($this->createMock(OrderAuditService::class));

        $reflection = new \ReflectionClass($service);
        $candidate = new WmsOrderCandidate([
            'item_id' => 100,
            'ordering_code' => '4900000000012',
            'quantity_type' => QuantityType::CASE,
            'order_quantity' => 4,
        ]);

        $method = $reflection->getMethod('resolveIncomingQuantity');
        $method->setAccessible(true);

        [$quantity, $quantityType] = $method->invoke($service, $candidate);

        $this->assertSame(4, $quantity);
        $this->assertSame(QuantityType::CASE, $quantityType);
    }

    public function test_expiration_calculation_does_not_mutate_expected_arrival_date(): void
    {
        $service = new OrderExecutionService($this->createMock(OrderAuditService::class));
        $baseDate = Carbon::parse('2026-05-20');

        $method = new \ReflectionMethod($service, 'calculateExpirationDateFromDays');
        $method->setAccessible(true);

        $this->assertSame('2026-11-16', $method->invoke($service, $baseDate, 180));
        $this->assertSame('2026-05-20', $baseDate->format('Y-m-d'));
    }

    public function test_candidate_supplier_id_is_preferred_when_resolving_supplier(): void
    {
        $service = new OrderExecutionService($this->createMock(OrderAuditService::class));
        $candidate = new WmsOrderCandidate([
            'warehouse_id' => 999999991,
            'item_id' => 999999991,
            'contractor_id' => 999999991,
            'supplier_id' => 999999992,
        ]);

        $method = new \ReflectionMethod($service, 'getSupplierIdFromCandidate');
        $method->setAccessible(true);

        $this->assertSame(999999992, $method->invoke($service, $candidate));
    }

    public function test_jx_generated_confirmed_candidate_is_not_reconfirmed(): void
    {
        $this->skipWhenSakemaruTestDatabaseIsUnavailable();

        $audit = $this->createMock(OrderAuditService::class);
        $audit->expects($this->never())->method('logConfirmation');

        $service = new OrderExecutionService($audit);
        $candidate = $this->createCandidate(CandidateStatus::CONFIRMED, 123456789);
        $schedule = $this->createIncomingSchedule($candidate->id, '26080499999');
        $assignment = $this->createSlipAssignment($candidate->id, '21461099999');

        try {
            $result = $service->confirmCandidate($candidate->fresh(), 9900000002);

            $this->assertCount(1, $result);
            $this->assertSame($schedule->id, $result->first()->id);
            $this->assertSame(1, WmsOrderIncomingSchedule::query()->where('order_candidate_id', $candidate->id)->count());
            $this->assertSame('26080499999', $schedule->fresh()->slip_number);
        } finally {
            $assignment->delete();
            WmsOrderIncomingSchedule::query()->where('order_candidate_id', $candidate->id)->delete();
            $candidate->delete();
        }
    }

    public function test_assigned_jx_slip_number_is_used_when_creating_incoming_schedule(): void
    {
        $this->skipWhenSakemaruTestDatabaseIsUnavailable();

        $service = new OrderExecutionService($this->createMock(OrderAuditService::class));
        $candidate = $this->createCandidate(CandidateStatus::CONFIRMED);
        $assignment = $this->createSlipAssignment($candidate->id, '21461099998');

        try {
            $method = new \ReflectionMethod($service, 'resolveIncomingSlipNumber');
            $method->setAccessible(true);

            $this->assertSame('21461099998', $method->invoke($service, $candidate, '2026-08-04'));
        } finally {
            $assignment->delete();
            $candidate->delete();
        }
    }

    private function createCandidate(CandidateStatus $status, ?int $documentId = null): WmsOrderCandidate
    {
        return WmsOrderCandidate::query()->create([
            'batch_code' => 'TEXEC'.now()->format('His').random_int(100, 999),
            'warehouse_id' => 999999991,
            'item_id' => 999999991,
            'item_code' => 'TEST-EXEC',
            'contractor_id' => 999999991,
            'suggested_quantity' => 1,
            'order_quantity' => 1,
            'purchase_unit' => 1,
            'quantity_type' => QuantityType::CASE->value,
            'expected_arrival_date' => '2026-08-05',
            'status' => $status->value,
            'lot_status' => 'RAW',
            'wms_order_jx_document_id' => $documentId,
            'modified_at' => now(),
        ]);
    }

    private function createIncomingSchedule(int $candidateId, string $slipNumber): WmsOrderIncomingSchedule
    {
        return WmsOrderIncomingSchedule::query()->create([
            'warehouse_id' => 999999991,
            'item_id' => 999999991,
            'item_code' => 'TEST-EXEC',
            'search_code' => 'TEST-EXEC',
            'contractor_id' => 999999991,
            'supplier_id' => null,
            'order_candidate_id' => $candidateId,
            'order_source' => OrderSource::AUTO->value,
            'slip_number' => $slipNumber,
            'expected_quantity' => 1,
            'received_quantity' => 0,
            'quantity_type' => QuantityType::CASE->value,
            'order_date' => '2026-08-04',
            'expected_arrival_date' => '2026-08-05',
            'expiration_date' => '2026-12-31',
            'status' => IncomingScheduleStatus::PENDING->value,
        ]);
    }

    private function createSlipAssignment(int $candidateId, string $slipNumber): WmsOrderSlipNumberAssignment
    {
        return WmsOrderSlipNumberAssignment::query()->create([
            'wms_order_jx_document_id' => null,
            'document_type' => 'EOS_ORDER',
            'slip_number' => $slipNumber,
            'store_code' => substr($slipNumber, 0, 2),
            'year_code' => (int) substr($slipNumber, 2, 2),
            'sequence_no' => (int) substr($slipNumber, 6, 5),
            'b_record_sequence' => 1,
            'status' => WmsOrderSlipNumberAssignment::STATUS_TRANSMITTED,
            'order_candidate_ids' => [$candidateId],
        ]);
    }

    private function skipWhenSakemaruTestDatabaseIsUnavailable(): void
    {
        try {
            DB::connection('sakemaru')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('sakemaru test database is unavailable: '.$e->getMessage());
        }
    }
}
