<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Enums\AutoOrder\OrderDataFileChannel;
use App\Enums\AutoOrder\OrderDataFileStatus;
use App\Enums\QuantityType;
use App\Models\Sakemaru\Item;
use App\Models\User;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderDataFile;
use App\Services\AutoOrder\OrderDataFileService;
use Tests\TestCase;

class OrderDataFileServiceTest extends TestCase
{
    public function test_candidates_are_grouped_by_warehouse_contractor_and_expected_arrival_date(): void
    {
        $service = new OrderDataFileService;
        $method = new \ReflectionMethod($service, 'groupCandidatesForDataFiles');
        $method->setAccessible(true);

        $groups = $method->invoke($service, collect([
            $this->candidate(1, 10, 100, 200, '2026-05-20'),
            $this->candidate(2, 10, 100, 200, '2026-05-21'),
            $this->candidate(3, 10, 100, 200, '2026-05-20'),
            $this->candidate(4, 11, 100, 200, '2026-05-20'),
        ]), true);

        $this->assertCount(3, $groups);
        $this->assertSame([1, 3], $groups->get('10_100_200_2026-05-20')->pluck('id')->all());
        $this->assertSame([2], $groups->get('10_100_200_2026-05-21')->pluck('id')->all());
        $this->assertSame([4], $groups->get('11_100_200_2026-05-20')->pluck('id')->all());
    }

    public function test_candidates_are_grouped_by_contractor_and_expected_arrival_date_when_not_split_by_warehouse(): void
    {
        $service = new OrderDataFileService;
        $method = new \ReflectionMethod($service, 'groupCandidatesForDataFiles');
        $method->setAccessible(true);

        $groups = $method->invoke($service, collect([
            $this->candidate(1, 10, 100, 200, '2026-05-20'),
            $this->candidate(2, 11, 100, 200, '2026-05-20'),
            $this->candidate(3, 10, 100, 200, '2026-05-21'),
        ]), false);

        $this->assertCount(2, $groups);
        $this->assertSame([1, 2], $groups->get('100_200_2026-05-20')->pluck('id')->all());
        $this->assertSame([3], $groups->get('100_200_2026-05-21')->pluck('id')->all());
    }

    public function test_candidates_are_grouped_by_supplier_even_when_arrival_date_matches(): void
    {
        $service = new OrderDataFileService;
        $method = new \ReflectionMethod($service, 'groupCandidatesForDataFiles');
        $method->setAccessible(true);

        $groups = $method->invoke($service, collect([
            $this->candidate(1, 10, 100, 200, '2026-05-20'),
            $this->candidate(2, 10, 100, 201, '2026-05-20'),
        ]), true);

        $this->assertCount(2, $groups);
        $this->assertSame([1], $groups->get('10_100_200_2026-05-20')->pluck('id')->all());
        $this->assertSame([2], $groups->get('10_100_201_2026-05-20')->pluck('id')->all());
    }

    public function test_created_by_name_uses_authenticated_user_name(): void
    {
        $this->actingAs((new User)->forceFill([
            'id' => 123,
            'name' => '発注担当者',
        ]));

        $service = new OrderDataFileService;
        $method = new \ReflectionMethod($service, 'resolveCreatedByName');
        $method->setAccessible(true);

        $this->assertSame('発注担当者', $method->invoke($service, 'J20260520182144448', collect()));

        $method = new \ReflectionMethod($service, 'resolveCreatedBy');
        $method->setAccessible(true);

        $this->assertSame([
            'id' => 123,
            'name' => '発注担当者',
        ], $method->invoke($service, 'J20260520182144448', collect()));
    }

    public function test_eos_control_pdf_is_not_marked_as_fax_downloaded(): void
    {
        $dataFile = new WmsOrderDataFile([
            'order_channel' => OrderDataFileChannel::EOS,
            'show_eos_stamp' => true,
            'status' => OrderDataFileStatus::GENERATED,
            'fax_downloaded_at' => null,
            'fax_downloaded_by' => null,
        ]);

        $dataFile->markAsFaxDownloaded(123);

        $this->assertTrue($dataFile->isEosControlPdf());
        $this->assertSame(OrderDataFileStatus::GENERATED, $dataFile->status);
        $this->assertNull($dataFile->fax_downloaded_at);
        $this->assertNull($dataFile->fax_downloaded_by);
    }

    public function test_communication_notes_are_normalized_for_pdf_generation(): void
    {
        $service = new OrderDataFileService;
        $method = new \ReflectionMethod($service, 'normalizeCommunicationNotes');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($service, " \n\t "));
        $this->assertSame("1行目\n2行目", $method->invoke($service, " 1行目\r\n2行目 "));
        $this->assertSame(500, mb_strlen((string) $method->invoke($service, str_repeat('あ', 510))));
    }

    public function test_total_piece_quantity_sums_case_as_capacity_case_and_piece_as_is(): void
    {
        $service = new OrderDataFileService;
        $method = new \ReflectionMethod($service, 'sumTotalPieceQuantity');
        $method->setAccessible(true);

        $caseCandidate = $this->candidate(1, 10, 100, 200, '2026-05-20');
        $caseCandidate->order_quantity = 2;
        $caseCandidate->quantity_type = QuantityType::CASE;
        $caseCandidate->setRelation('item', new Item(['capacity_case' => 24]));

        $pieceCandidate = $this->candidate(2, 10, 100, 200, '2026-05-20');
        $pieceCandidate->order_quantity = 5;
        $pieceCandidate->quantity_type = QuantityType::PIECE;
        $pieceCandidate->setRelation('item', new Item(['capacity_case' => 24]));

        $this->assertSame(53, $method->invoke($service, collect([$caseCandidate, $pieceCandidate])));
    }

    private function candidate(int $id, int $warehouseId, int $contractorId, int $supplierId, string $expectedArrivalDate): WmsOrderCandidate
    {
        $candidate = new WmsOrderCandidate([
            'warehouse_id' => $warehouseId,
            'contractor_id' => $contractorId,
            'supplier_id' => $supplierId,
            'expected_arrival_date' => $expectedArrivalDate,
        ]);
        $candidate->id = $id;

        return $candidate;
    }
}
