<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Enums\AutoOrder\TransmissionType;
use App\Enums\QuantityType;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\IncomingTransmissionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IncomingTransmissionServiceTest extends TestCase
{
    private const TEST_SLIP_PREFIX = 'UTP';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        $queueIds = WmsOrderIncomingSchedule::query()
            ->where('slip_number', 'like', self::TEST_SLIP_PREFIX.'%')
            ->pluck('purchase_queue_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        WmsOrderIncomingSchedule::query()
            ->where('slip_number', 'like', self::TEST_SLIP_PREFIX.'%')
            ->delete();

        $queueQuery = DB::connection('sakemaru')
            ->table('purchase_create_queue')
            ->where('slip_number', 'like', self::TEST_SLIP_PREFIX.'%');

        if ($queueIds !== []) {
            $queueQuery->orWhereIn('id', $queueIds);
        }

        $queueQuery->delete();

        parent::tearDown();
    }

    public function test_transmit_groups_by_slip_number_and_splits_by_arrival_date(): void
    {
        Carbon::setTestNow('2026-07-31 22:00:00');

        $master = $this->purchaseMasterData();
        $slipA = $this->newSlipNumber('A');
        $slipB = $this->newSlipNumber('B');

        $sameSlipFirst = $this->createConfirmedSchedule($master, $slipA, '2026-07-20', 5);
        $sameSlipSecond = $this->createConfirmedSchedule($master, $slipA, '2026-07-20', 7);
        $sameSlipLaterDelivery = $this->createConfirmedSchedule($master, $slipA, '2026-07-21', 3);
        $differentSlip = $this->createConfirmedSchedule($master, $slipB, '2026-07-20', 2);

        $result = app(IncomingTransmissionService::class)->transmitConfirmedIncomings(
            scheduleIds: [
                $sameSlipFirst->id,
                $sameSlipSecond->id,
                $sameSlipLaterDelivery->id,
                $differentSlip->id,
            ],
        );

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['queue_count']);
        $this->assertSame(4, $result['schedule_count']);

        $sameSlipFirst->refresh();
        $sameSlipSecond->refresh();
        $sameSlipLaterDelivery->refresh();
        $differentSlip->refresh();

        $this->assertSame($sameSlipFirst->purchase_queue_id, $sameSlipSecond->purchase_queue_id);
        $this->assertNotSame($sameSlipFirst->purchase_queue_id, $sameSlipLaterDelivery->purchase_queue_id);
        $this->assertNotSame($sameSlipFirst->purchase_queue_id, $differentSlip->purchase_queue_id);

        $queue = DB::connection('sakemaru')
            ->table('purchase_create_queue')
            ->where('id', $sameSlipFirst->purchase_queue_id)
            ->first();
        $payload = json_decode($queue->items, true);

        $this->assertSame($slipA, $queue->slip_number);
        $this->assertSame($slipA, $payload['slip_number']);
        $this->assertSame('2026-07-20', $payload['process_date']);
        $this->assertSame('2026-07-20', $payload['delivered_date']);
        $this->assertCount(2, $payload['details']);
    }

    public function test_transmit_uses_incoming_date_for_process_date_when_order_date_is_old(): void
    {
        Carbon::setTestNow('2026-07-31 22:05:00');

        $master = $this->purchaseMasterData();
        $slip = $this->newSlipNumber('R');
        $schedule = $this->createConfirmedSchedule(
            $master,
            $slip,
            '2026-08-01',
            5,
            orderSource: OrderSource::RECEIVED,
            isReceiveMatched: true,
            purchaseSplitKey: 'UNASSIGNED_JX_SLIP_999',
            orderDate: '2026-06-18',
        );

        $result = app(IncomingTransmissionService::class)->transmitConfirmedIncomings(
            scheduleIds: [$schedule->id],
        );

        $this->assertTrue($result['success'], json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $result['queue_count']);
        $this->assertSame(1, $result['schedule_count']);

        $schedule->refresh();

        $queue = DB::connection('sakemaru')
            ->table('purchase_create_queue')
            ->where('id', $schedule->purchase_queue_id)
            ->first();
        $payload = json_decode($queue->items, true);

        $this->assertSame('2026-08-01', $payload['process_date']);
        $this->assertSame('2026-08-01', $payload['delivered_date']);
        $this->assertSame('2026-08-01', $payload['account_date']);
        $this->assertStringContainsString('元発注日:2026-06-18', $payload['note']);
    }

    public function test_transmit_splits_same_slip_when_purchase_split_key_is_different(): void
    {
        $master = $this->purchaseMasterData();
        $slip = $this->newSlipNumber('X');

        $primary = $this->createConfirmedSchedule($master, $slip, '2026-07-20', 5);
        $duplicateDetail = $this->createConfirmedSchedule(
            $master,
            $slip,
            '2026-07-20',
            3,
            purchaseSplitKey: 'EOS_DETAIL_999',
        );

        $result = app(IncomingTransmissionService::class)->transmitConfirmedIncomings(
            scheduleIds: [
                $primary->id,
                $duplicateDetail->id,
            ],
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['queue_count']);
        $this->assertSame(2, $result['schedule_count']);

        $primary->refresh();
        $duplicateDetail->refresh();

        $this->assertNotSame($primary->purchase_queue_id, $duplicateDetail->purchase_queue_id);

        $payloads = DB::connection('sakemaru')
            ->table('purchase_create_queue')
            ->whereIn('id', [$primary->purchase_queue_id, $duplicateDetail->purchase_queue_id])
            ->pluck('items', 'id')
            ->map(fn (string $items): array => json_decode($items, true))
            ->all();

        $this->assertSame($slip, $payloads[$primary->purchase_queue_id]['slip_number']);
        $this->assertSame($slip, $payloads[$duplicateDetail->purchase_queue_id]['slip_number']);
        $this->assertCount(1, $payloads[$primary->purchase_queue_id]['details']);
        $this->assertCount(1, $payloads[$duplicateDetail->purchase_queue_id]['details']);
    }

    public function test_transmit_skips_received_schedule_without_confirmed_supplier(): void
    {
        $master = $this->purchaseMasterData();
        $master['contractor_id'] = null;
        $slip = $this->newSlipNumber('U');
        $schedule = $this->createConfirmedSchedule(
            $master,
            $slip,
            '2026-07-20',
            5,
            supplierId: null,
            useDefaultSupplier: false,
            orderSource: OrderSource::RECEIVED,
            isReceiveMatched: true,
        );

        $result = app(IncomingTransmissionService::class)->transmitConfirmedIncomings(
            scheduleIds: [$schedule->id],
        );

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['queue_count']);
        $this->assertSame(0, $result['schedule_count']);
        $this->assertNotEmpty($result['errors']);

        $schedule->refresh();

        $this->assertSame(IncomingScheduleStatus::CONFIRMED, $schedule->status);
        $this->assertNull($schedule->purchase_queue_id);
        $this->assertDatabaseMissing('purchase_create_queue', [
            'slip_number' => $slip,
        ], 'sakemaru');
    }

    public function test_transmit_uses_contractor_supplier_code_without_updating_schedule_supplier_id(): void
    {
        $master = $this->purchaseMasterData(requireContractorSupplier: true);

        if (! $master['contractor_supplier_id'] || ! $master['contractor_supplier_code']) {
            $this->markTestSkipped('contractor supplier master data is not available in test DB');
        }

        $alternateSupplier = $this->activeSupplierExcept((int) $master['contractor_supplier_id']);

        if (! $alternateSupplier) {
            $this->markTestSkipped('alternate supplier master data is not available in test DB');
        }

        $slip = $this->newSlipNumber('S');
        $schedule = $this->createConfirmedSchedule(
            $master,
            $slip,
            '2026-07-20',
            5,
            supplierId: (int) $alternateSupplier->id,
            orderSource: OrderSource::RECEIVED,
            isReceiveMatched: true,
        );

        $result = app(IncomingTransmissionService::class)->transmitConfirmedIncomings(
            scheduleIds: [$schedule->id],
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['queue_count']);
        $this->assertSame(1, $result['schedule_count']);

        $schedule->refresh();

        $this->assertSame((int) $alternateSupplier->id, (int) $schedule->supplier_id);
        $this->assertNotNull($schedule->purchase_queue_id);

        $queue = DB::connection('sakemaru')
            ->table('purchase_create_queue')
            ->where('id', $schedule->purchase_queue_id)
            ->first();
        $payload = json_decode($queue->items, true);

        $this->assertSame((string) $master['contractor_supplier_code'], (string) $payload['supplier_code']);
        $this->assertNotSame((string) $alternateSupplier->code, (string) $payload['supplier_code']);
    }

    public function test_auto_received_match_prefers_contractor_supplier_code_for_purchase_queue(): void
    {
        $master = $this->purchaseMasterData(requireContractorSupplier: true);

        if (! $master['contractor_supplier_id'] || ! $master['contractor_supplier_code']) {
            $this->markTestSkipped('contractor supplier master data is not available in test DB');
        }

        $alternateSupplier = $this->activeSupplierExcept((int) $master['contractor_supplier_id']);

        if (! $alternateSupplier) {
            $this->markTestSkipped('alternate supplier master data is not available in test DB');
        }

        $slip = $this->newSlipNumber('A');
        $schedule = $this->createConfirmedSchedule(
            $master,
            $slip,
            '2026-07-20',
            5,
            supplierId: (int) $alternateSupplier->id,
            orderSource: OrderSource::AUTO,
            isReceiveMatched: true,
        );

        $result = app(IncomingTransmissionService::class)->transmitConfirmedIncomings(
            scheduleIds: [$schedule->id],
        );

        $this->assertTrue($result['success']);

        $schedule->refresh();

        $queue = DB::connection('sakemaru')
            ->table('purchase_create_queue')
            ->where('id', $schedule->purchase_queue_id)
            ->first();
        $payload = json_decode($queue->items, true);

        $this->assertSame((string) $master['contractor_supplier_code'], (string) $payload['supplier_code']);
        $this->assertNotSame((string) $alternateSupplier->code, (string) $payload['supplier_code']);
    }

    public function test_transmit_does_not_create_duplicate_queue_for_already_transmitted_schedule(): void
    {
        $master = $this->purchaseMasterData();
        $slip = $this->newSlipNumber('D');
        $schedule = $this->createConfirmedSchedule($master, $slip, '2026-07-20', 5);

        $firstResult = app(IncomingTransmissionService::class)->transmitConfirmedIncomings(
            scheduleIds: [$schedule->id],
        );

        $schedule->refresh();

        $this->assertTrue($firstResult['success']);
        $this->assertSame(1, $firstResult['queue_count']);
        $this->assertSame(1, $firstResult['schedule_count']);
        $this->assertSame(IncomingScheduleStatus::TRANSMITTED, $schedule->status);
        $this->assertNotNull($schedule->purchase_queue_id);

        $secondResult = app(IncomingTransmissionService::class)->transmitConfirmedIncomings(
            scheduleIds: [$schedule->id],
        );

        $this->assertTrue($secondResult['success']);
        $this->assertSame(0, $secondResult['queue_count']);
        $this->assertSame(0, $secondResult['schedule_count']);
        $this->assertSame(1, DB::connection('sakemaru')
            ->table('purchase_create_queue')
            ->where('slip_number', $slip)
            ->count());
    }

    public function test_transmit_can_be_filtered_by_contractor(): void
    {
        $master = $this->purchaseMasterData();
        $otherContractor = $this->activeContractorExcept((int) $master['contractor_id']);

        if (! $otherContractor) {
            $this->markTestSkipped('alternate contractor master data is not available in test DB');
        }

        $targetSlip = $this->newSlipNumber('C');
        $otherSlip = $this->newSlipNumber('O');
        $targetSchedule = $this->createConfirmedSchedule($master, $targetSlip, '2026-07-20', 5);
        $otherMaster = array_merge($master, [
            'contractor_id' => (int) $otherContractor->id,
        ]);
        $otherSchedule = $this->createConfirmedSchedule($otherMaster, $otherSlip, '2026-07-20', 7);

        $result = app(IncomingTransmissionService::class)->transmitConfirmedIncomings(
            warehouseId: (int) $master['warehouse_id'],
            contractorId: (int) $master['contractor_id'],
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['queue_count']);
        $this->assertSame(1, $result['schedule_count']);

        $targetSchedule->refresh();
        $otherSchedule->refresh();

        $this->assertSame(IncomingScheduleStatus::TRANSMITTED, $targetSchedule->status);
        $this->assertNotNull($targetSchedule->purchase_queue_id);
        $this->assertSame(IncomingScheduleStatus::CONFIRMED, $otherSchedule->status);
        $this->assertNull($otherSchedule->purchase_queue_id);
        $this->assertDatabaseMissing('purchase_create_queue', [
            'slip_number' => $otherSlip,
        ], 'sakemaru');
    }

    private function purchaseMasterData(bool $requireContractorSupplier = false): array
    {
        $warehouse = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('is_active', true)
            ->whereNotNull('code')
            ->orderBy('id')
            ->first(['id', 'code']);
        $item = DB::connection('sakemaru')
            ->table('items')
            ->where('is_active', true)
            ->whereNotNull('code')
            ->orderBy('id')
            ->first(['id', 'code']);
        $supplier = DB::connection('sakemaru')
            ->table('suppliers as s')
            ->join('partners as p', 'p.id', '=', 's.partner_id')
            ->where('p.is_active', true)
            ->where('p.is_supplier', true)
            ->whereNotNull('p.code')
            ->orderBy('s.id')
            ->first(['s.id', 'p.code']);
        $contractorQuery = DB::connection('sakemaru')
            ->table('contractors as c')
            ->leftJoin('wms_contractor_settings as wcs', 'wcs.contractor_id', '=', 'c.id')
            ->leftJoin('suppliers as contractor_suppliers', 'contractor_suppliers.id', '=', 'c.supplier_id')
            ->leftJoin('partners as contractor_supplier_partners', 'contractor_supplier_partners.id', '=', 'contractor_suppliers.partner_id')
            ->where('c.is_active', true)
            ->where(function ($query) {
                $query
                    ->whereNull('wcs.transmission_type')
                    ->orWhere('wcs.transmission_type', '!=', TransmissionType::INTERNAL->value);
            })
            ->orderBy('c.id');

        if ($requireContractorSupplier) {
            $contractorQuery
                ->whereNotNull('c.supplier_id')
                ->where('contractor_supplier_partners.is_active', true)
                ->where('contractor_supplier_partners.is_supplier', true)
                ->whereNotNull('contractor_supplier_partners.code');
        }

        $contractor = $contractorQuery->first([
            'c.id',
            'c.supplier_id as contractor_supplier_id',
            'contractor_supplier_partners.code as contractor_supplier_code',
        ]);

        if (! $warehouse || ! $item || ! $supplier || ! $contractor) {
            $this->markTestSkipped('purchase transmission master data is not available in test DB');
        }

        return [
            'warehouse_id' => (int) $warehouse->id,
            'item_id' => (int) $item->id,
            'item_code' => (string) $item->code,
            'supplier_id' => (int) $supplier->id,
            'contractor_id' => (int) $contractor->id,
            'contractor_supplier_id' => $contractor->contractor_supplier_id ? (int) $contractor->contractor_supplier_id : null,
            'contractor_supplier_code' => $contractor->contractor_supplier_code ? (string) $contractor->contractor_supplier_code : null,
        ];
    }

    private function activeSupplierExcept(int $supplierId): ?object
    {
        return DB::connection('sakemaru')
            ->table('suppliers as s')
            ->join('partners as p', 'p.id', '=', 's.partner_id')
            ->where('p.is_active', true)
            ->where('p.is_supplier', true)
            ->whereNotNull('p.code')
            ->where('s.id', '!=', $supplierId)
            ->orderBy('s.id')
            ->first(['s.id', 'p.code']);
    }

    private function activeContractorExcept(int $contractorId): ?object
    {
        return DB::connection('sakemaru')
            ->table('contractors as c')
            ->leftJoin('wms_contractor_settings as wcs', 'wcs.contractor_id', '=', 'c.id')
            ->where('c.is_active', true)
            ->where('c.id', '!=', $contractorId)
            ->where(function ($query) {
                $query
                    ->whereNull('wcs.transmission_type')
                    ->orWhere('wcs.transmission_type', '!=', TransmissionType::INTERNAL->value);
            })
            ->orderBy('c.id')
            ->first(['c.id']);
    }

    private function createConfirmedSchedule(
        array $master,
        string $slipNumber,
        string $actualDate,
        int $quantity,
        ?int $supplierId = null,
        bool $useDefaultSupplier = true,
        OrderSource $orderSource = OrderSource::AUTO,
        bool $isReceiveMatched = false,
        ?string $purchaseSplitKey = null,
        string $orderDate = '2026-07-19',
    ): WmsOrderIncomingSchedule {
        return WmsOrderIncomingSchedule::query()->create([
            'warehouse_id' => $master['warehouse_id'],
            'item_id' => $master['item_id'],
            'item_code' => $master['item_code'],
            'contractor_id' => $master['contractor_id'],
            'supplier_id' => $useDefaultSupplier ? ($supplierId ?? $master['supplier_id']) : null,
            'order_source' => $orderSource,
            'slip_number' => $slipNumber,
            'expected_quantity' => $quantity,
            'received_quantity' => $quantity,
            'quantity_type' => QuantityType::PIECE,
            'order_date' => $orderDate,
            'expected_arrival_date' => $actualDate,
            'actual_arrival_date' => $actualDate,
            'status' => IncomingScheduleStatus::CONFIRMED,
            'confirmed_at' => "{$actualDate} 09:00:00",
            'is_receive_matched' => $isReceiveMatched,
            'purchase_split_key' => $purchaseSplitKey,
        ]);
    }

    private function newSlipNumber(string $suffix): string
    {
        return self::TEST_SLIP_PREFIX.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT).$suffix;
    }
}
