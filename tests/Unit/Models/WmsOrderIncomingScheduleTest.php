<?php

namespace Tests\Unit\Models;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Enums\AutoOrder\TransmissionType;
use App\Enums\QuantityType;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderIncomingSchedule;
use App\Models\WmsOrderSlipNumberAssignment;
use RuntimeException;
use Tests\TestCase;

class WmsOrderIncomingScheduleTest extends TestCase
{
    public function test_format_slip_number_uses_eleven_digit_yymmdd_sequence(): void
    {
        $slipNumber = WmsOrderIncomingSchedule::formatSlipNumber('2026-05-06', 1000);

        $this->assertSame('26050601000', $slipNumber);
        $this->assertSame(11, strlen($slipNumber));
    }

    public function test_format_slip_number_rejects_sequences_that_do_not_fit_eleven_digits(): void
    {
        $this->expectException(RuntimeException::class);

        WmsOrderIncomingSchedule::formatSlipNumber('2026-05-06', 100000);
    }

    public function test_purchase_transmission_scope_excludes_transfer_and_internal_contractors(): void
    {
        $query = WmsOrderIncomingSchedule::query()->forPurchaseTransmission();
        $sql = $query->toSql();

        $this->assertSame([
            OrderSource::AUTO->value,
            OrderSource::MANUAL->value,
            OrderSource::RECEIVED->value,
            TransmissionType::INTERNAL->value,
        ], $query->getBindings());
        $this->assertStringContainsString('transfer_candidate_id', $sql);
        $this->assertStringContainsString('source_warehouse_id', $sql);
        $this->assertStringContainsString('stock_transfer_id', $sql);
        $this->assertStringContainsString('wms_contractor_settings', $sql);
        $this->assertStringContainsString('transmission_type', $sql);
    }

    public function test_ready_for_purchase_transmission_scope_limits_to_unqueued_confirmed_selected_warehouse(): void
    {
        $warehouseId = 21;
        $query = WmsOrderIncomingSchedule::query()->readyForPurchaseTransmission($warehouseId);
        $sql = $query->toSql();

        $this->assertSame([
            IncomingScheduleStatus::CONFIRMED->value,
            OrderSource::AUTO->value,
            OrderSource::MANUAL->value,
            OrderSource::RECEIVED->value,
            TransmissionType::INTERNAL->value,
            $warehouseId,
        ], $query->getBindings());
        $this->assertStringContainsString('status', $sql);
        $this->assertStringContainsString('purchase_queue_id', $sql);
        $this->assertStringContainsString('warehouse_id', $sql);
        $this->assertStringContainsString('wms_contractor_settings', $sql);
    }

    public function test_ready_for_incoming_transmission_scope_excludes_transfer_sources_without_excluding_internal_contractors(): void
    {
        $warehouseId = 21;
        $query = WmsOrderIncomingSchedule::query()->readyForIncomingTransmission($warehouseId);
        $sql = $query->toSql();

        $this->assertSame([
            IncomingScheduleStatus::CONFIRMED->value,
            OrderSource::AUTO->value,
            OrderSource::MANUAL->value,
            OrderSource::RECEIVED->value,
            $warehouseId,
        ], $query->getBindings());
        $this->assertStringContainsString('status', $sql);
        $this->assertStringContainsString('order_source', $sql);
        $this->assertStringContainsString('transfer_candidate_id', $sql);
        $this->assertStringContainsString('source_warehouse_id', $sql);
        $this->assertStringContainsString('stock_transfer_id', $sql);
        $this->assertStringContainsString('purchase_queue_id', $sql);
        $this->assertStringContainsString('warehouse_id', $sql);
        $this->assertStringNotContainsString('wms_contractor_settings', $sql);
    }

    public function test_eos_sent_scopes_use_jx_document_slip_assignment_and_received_source_conditions(): void
    {
        $sentQuery = WmsOrderIncomingSchedule::query()->eosSent();
        $notSentQuery = WmsOrderIncomingSchedule::query()->notEosSent();
        $sentSql = $sentQuery->toSql();
        $notSentSql = $notSentQuery->toSql();

        $this->assertStringContainsString('source_received_detail_id', $sentSql);
        $this->assertStringContainsString('source_incoming_schedule_id', $sentSql);
        $this->assertStringContainsString('wms_order_candidates', $sentSql);
        $this->assertStringContainsString('wms_order_jx_document_id', $sentSql);
        $this->assertStringContainsString('wms_order_slip_number_assignments', $sentSql);
        $this->assertStringContainsString('JSON_TABLE', $sentSql);
        $this->assertStringContainsString('NOT', $notSentSql);
        $this->assertStringContainsString('wms_order_slip_number_assignments', $notSentSql);
    }

    public function test_with_transfer_source_scope_matches_transfer_source_columns(): void
    {
        $query = WmsOrderIncomingSchedule::query()->withTransferSource();
        $sql = $query->toSql();

        $this->assertSame([OrderSource::TRANSFER->value], $query->getBindings());
        $this->assertStringContainsString('order_source', $sql);
        $this->assertStringContainsString('transfer_candidate_id', $sql);
        $this->assertStringContainsString('source_warehouse_id', $sql);
        $this->assertStringContainsString('stock_transfer_id', $sql);
    }

    public function test_quantity_as_pieces_converts_schedule_unit_to_piece_quantity(): void
    {
        $schedule = new WmsOrderIncomingSchedule([
            'quantity_type' => QuantityType::CASE,
            'expected_quantity' => 2,
            'received_quantity' => 1,
        ]);
        $schedule->setRelation('item', new Item([
            'capacity_case' => 24,
            'capacity_carton' => 6,
        ]));

        $this->assertSame(48, $schedule->expected_piece_quantity);
        $this->assertSame(24, $schedule->received_piece_quantity);
        $this->assertSame(72, $schedule->quantityAsPieces(3));

        $schedule->quantity_type = QuantityType::CARTON;
        $this->assertSame(18, $schedule->quantityAsPieces(3));

        $schedule->quantity_type = QuantityType::PIECE;
        $this->assertSame(3, $schedule->quantityAsPieces(3));
    }

    public function test_is_eos_sent_returns_false_without_order_candidate(): void
    {
        $schedule = new WmsOrderIncomingSchedule;

        $this->assertFalse($schedule->isEosSent());
    }

    public function test_is_eos_sent_returns_true_for_eos_duplicate_received_detail_schedule(): void
    {
        $schedule = new WmsOrderIncomingSchedule([
            'source_received_detail_id' => 12345,
        ]);

        $this->assertTrue($schedule->isEosSent());
    }

    public function test_is_eos_sent_returns_true_when_order_candidate_has_jx_document(): void
    {
        $schedule = new WmsOrderIncomingSchedule([
            'order_candidate_id' => 10,
        ]);
        $schedule->setRelation('orderCandidate', new WmsOrderCandidate([
            'wms_order_jx_document_id' => 20,
        ]));

        $this->assertTrue($schedule->isEosSent());
    }

    public function test_is_eos_sent_returns_true_when_slip_assignment_contains_candidate(): void
    {
        $candidateId = random_int(900000, 999999);

        do {
            $slipNumber = '990101'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        } while (WmsOrderSlipNumberAssignment::query()->where('slip_number', $slipNumber)->exists());

        $assignment = WmsOrderSlipNumberAssignment::query()->create([
            'wms_order_jx_document_id' => null,
            'document_type' => 'EOS_ORDER',
            'slip_number' => $slipNumber,
            'store_code' => '01',
            'year_code' => 9,
            'sequence_no' => (int) substr($slipNumber, 6),
            'status' => WmsOrderSlipNumberAssignment::STATUS_ACTIVE,
            'order_candidate_ids' => [$candidateId],
        ]);

        try {
            $schedule = new WmsOrderIncomingSchedule([
                'order_candidate_id' => $candidateId,
            ]);

            $this->assertTrue($schedule->isEosSent());
        } finally {
            $assignment->delete();
        }
    }

    public function test_is_eos_sent_returns_true_when_transmitted_slip_assignment_matches_slip_and_store(): void
    {
        do {
            $slipNumber = '074610'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        } while (WmsOrderSlipNumberAssignment::query()->where('slip_number', $slipNumber)->exists());

        $assignment = WmsOrderSlipNumberAssignment::query()->create([
            'wms_order_jx_document_id' => null,
            'document_type' => 'EOS_ORDER',
            'slip_number' => $slipNumber,
            'store_code' => '07',
            'year_code' => 46,
            'sequence_no' => (int) substr($slipNumber, 6),
            'status' => WmsOrderSlipNumberAssignment::STATUS_TRANSMITTED,
            'order_candidate_ids' => null,
        ]);

        try {
            $schedule = new WmsOrderIncomingSchedule([
                'slip_number' => $slipNumber,
            ]);
            $schedule->setRelation('warehouse', new Warehouse(['code' => 7]));

            $this->assertTrue($schedule->isEosSent());

            $schedule->setRelation('warehouse', new Warehouse(['code' => 8]));

            $this->assertFalse($schedule->isEosSent());
        } finally {
            $assignment->delete();
        }
    }
}
