<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Enums\QuantityType;
use App\Models\Sakemaru\Contractor;
use App\Models\WmsIncomingImportError;
use App\Models\WmsIncomingPriceCheckSource;
use App\Models\WmsIncomingReceivedDetail;
use App\Models\WmsIncomingReceivedFile;
use App\Models\WmsIncomingReceivedSlip;
use App\Models\WmsJxEosImportBatch;
use App\Models\WmsJxEosLine;
use App\Models\WmsJxEosSlip;
use App\Models\WmsJxTransmissionLog;
use App\Models\WmsOrderIncomingSchedule;
use App\Models\WmsOrderJxDocument;
use App\Models\WmsOrderSlipNumberAssignment;
use App\Services\AutoOrder\IncomingPriceCheckSourceRecorder;
use App\Services\AutoOrder\IncomingReceiveService;
use App\Services\AutoOrder\IncomingTransmissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IncomingReceiveServiceTest extends TestCase
{
    private string $slipNumber;

    protected function setUp(): void
    {
        parent::setUp();

        $this->slipNumber = $this->newEosSlipNumber();
    }

    protected function tearDown(): void
    {
        $fileIds = WmsIncomingReceivedFile::query()
            ->where('filename', 'like', 'test-incoming-receive-%')
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

        $purchaseQueueIds = WmsOrderIncomingSchedule::query()
            ->where(function ($query) {
                $query->where('slip_number', $this->slipNumber)
                    ->orWhere('slip_number', 'FAX-'.$this->slipNumber);
            })
            ->pluck('purchase_queue_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        WmsOrderIncomingSchedule::query()
            ->where(function ($query) {
                $query->where('slip_number', $this->slipNumber)
                    ->orWhere('slip_number', 'FAX-'.$this->slipNumber);
            })
            ->delete();

        if ($purchaseQueueIds !== []) {
            DB::connection('sakemaru')
                ->table('purchase_create_queue')
                ->whereIn('id', $purchaseQueueIds)
                ->delete();
        }

        DB::connection('sakemaru')
            ->table('purchase_create_queue')
            ->where('slip_number', $this->slipNumber)
            ->delete();

        WmsOrderSlipNumberAssignment::query()
            ->where('slip_number', $this->slipNumber)
            ->delete();

        WmsOrderJxDocument::query()
            ->where('file_path', 'like', 'local:jx/test-incoming-receive-%')
            ->delete();

        $logIds = WmsJxTransmissionLog::query()
            ->where('message_id', 'like', 'test-incoming-receive-%')
            ->pluck('id');

        if ($logIds->isNotEmpty()) {
            WmsJxEosLine::query()->whereIn('wms_jx_transmission_log_id', $logIds->all())->delete();
            WmsJxEosSlip::query()->whereIn('wms_jx_transmission_log_id', $logIds->all())->delete();
            WmsJxEosImportBatch::query()->whereIn('wms_jx_transmission_log_id', $logIds->all())->delete();
            WmsJxTransmissionLog::query()->whereIn('id', $logIds->all())->delete();
        }

        parent::tearDown();
    }

    public function test_apply_matched_updates_each_detail_schedule_for_same_slip(): void
    {
        $file = WmsIncomingReceivedFile::create([
            'filename' => 'test-incoming-receive-'.now()->format('YmdHisv').'.dat',
            'format_type' => 'JX',
            'status' => 'MATCHED',
            'parsed_slip_count' => 1,
            'parsed_detail_count' => 2,
        ]);

        $schedule1 = $this->createIncomingSchedule(itemId: 990001, expectedQuantity: 10);
        $schedule2 = $this->createIncomingSchedule(itemId: 990002, expectedQuantity: 20);

        $slip = WmsIncomingReceivedSlip::create([
            'received_file_id' => $file->id,
            'slip_number' => $this->slipNumber,
            'match_status' => 'MATCHED',
            'b_shop_code' => '0001',
            'b_order_date' => '260719',
            'b_delivery_date' => '260720',
            'b_contractor_code' => '1106',
            'matched_schedule_id' => $schedule1->id,
            'detail_count' => 2,
        ]);

        $detail1 = WmsIncomingReceivedDetail::create([
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'd_line_number' => 1,
            'd_jan_code' => '4911111111111',
            'd_pack_quantity' => 10,
            'd_case_quantity' => 1,
            'd_piece_quantity' => 0,
            'd_unit_price' => 12300,
            'total_quantity' => 7,
            'match_status' => 'MATCHED',
            'matched_item_id' => 990001,
        ]);

        $detail2 = WmsIncomingReceivedDetail::create([
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'd_line_number' => 2,
            'd_jan_code' => '4922222222222',
            'd_pack_quantity' => 1,
            'd_case_quantity' => 0,
            'd_piece_quantity' => 20,
            'd_unit_price' => 45600,
            'total_quantity' => 20,
            'match_status' => 'MATCHED',
            'matched_item_id' => 990002,
        ]);

        $result = app(IncomingReceiveService::class)->applyMatched($file);

        $this->assertSame(2, $result['applied']);
        $this->assertEqualsCanonicalizing([$schedule1->id, $schedule2->id], $result['schedule_ids']);

        $schedule1->refresh();
        $schedule2->refresh();

        $this->assertSame(7, $schedule1->received_quantity);
        $this->assertSame(7, $schedule1->shipped_quantity);
        $this->assertSame(3, $schedule1->shortage_quantity);
        $this->assertSame(IncomingScheduleStatus::CONFIRMED, $schedule1->status);
        $this->assertSame('2026-07-20', $schedule1->actual_arrival_date?->format('Y-m-d'));
        $this->assertSame(0, $schedule1->confirmed_by);
        $this->assertNotNull($schedule1->confirmed_at);
        $this->assertSame('CASE', $schedule1->price_type);
        $this->assertEquals('123.00', $schedule1->partner_case_price);

        $this->assertSame(20, $schedule2->received_quantity);
        $this->assertSame(20, $schedule2->shipped_quantity);
        $this->assertSame(0, $schedule2->shortage_quantity);
        $this->assertSame(IncomingScheduleStatus::CONFIRMED, $schedule2->status);
        $this->assertSame('2026-07-20', $schedule2->actual_arrival_date?->format('Y-m-d'));
        $this->assertSame(0, $schedule2->confirmed_by);
        $this->assertNotNull($schedule2->confirmed_at);
        $this->assertSame('PIECE', $schedule2->price_type);
        $this->assertEquals('456.00', $schedule2->partner_unit_price);

        $this->assertDatabaseHas('wms_incoming_price_check_sources', [
            'received_file_id' => $file->id,
            'received_detail_id' => $detail1->id,
            'incoming_schedule_id' => $schedule1->id,
            'received_unit_price_raw' => 12300,
            'received_unit_price' => '123.0000',
            'comparison_price_type' => 'CASE',
        ], 'sakemaru');
        $this->assertDatabaseHas('wms_incoming_price_check_sources', [
            'received_file_id' => $file->id,
            'received_detail_id' => $detail2->id,
            'incoming_schedule_id' => $schedule2->id,
            'received_unit_price_raw' => 45600,
            'received_unit_price' => '456.0000',
            'comparison_price_type' => 'PIECE',
        ], 'sakemaru');

        app(IncomingReceiveService::class)->applyMatched($file->fresh());

        $this->assertSame(
            2,
            WmsIncomingPriceCheckSource::query()->where('received_file_id', $file->id)->count()
        );
    }

    public function test_apply_matched_creates_received_schedule_for_duplicate_detail_on_same_schedule(): void
    {
        $file = WmsIncomingReceivedFile::create([
            'filename' => 'test-incoming-receive-'.now()->format('YmdHisv').'.dat',
            'format_type' => 'JX',
            'status' => 'MATCHED',
            'parsed_slip_count' => 1,
            'parsed_detail_count' => 2,
        ]);

        $schedule = $this->createIncomingSchedule(
            itemId: 990003,
            expectedQuantity: 12,
            casePrice: 123,
        );

        $slip = WmsIncomingReceivedSlip::create([
            'received_file_id' => $file->id,
            'slip_number' => $this->slipNumber,
            'match_status' => 'MATCHED',
            'b_shop_code' => '0001',
            'b_order_date' => '260719',
            'b_delivery_date' => '260720',
            'b_contractor_code' => '1106',
            'matched_schedule_id' => $schedule->id,
            'detail_count' => 2,
        ]);

        $firstDetail = WmsIncomingReceivedDetail::create([
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'd_line_number' => 1,
            'd_jan_code' => '4933333333333',
            'd_item_code' => '990003',
            'd_pack_quantity' => 1,
            'd_case_quantity' => 0,
            'd_piece_quantity' => 12,
            'd_unit_price' => 12300,
            'total_quantity' => 12,
            'match_status' => 'MATCHED',
            'matched_item_id' => 990003,
            'matched_schedule_id' => $schedule->id,
        ]);
        $duplicateDetail = WmsIncomingReceivedDetail::create([
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'd_line_number' => 2,
            'd_jan_code' => '4933333333333',
            'd_item_code' => '990003',
            'd_pack_quantity' => 1,
            'd_case_quantity' => 0,
            'd_piece_quantity' => 6,
            'd_unit_price' => 12400,
            'total_quantity' => 6,
            'match_status' => 'MATCHED',
            'matched_item_id' => 990003,
            'matched_schedule_id' => $schedule->id,
        ]);

        $result = app(IncomingReceiveService::class)->applyMatched($file);

        $this->assertSame(2, $result['applied']);
        $this->assertCount(2, $result['schedule_ids']);

        $schedule->refresh();
        $duplicateDetail->refresh();

        $createdSchedule = WmsOrderIncomingSchedule::query()
            ->where('source_received_detail_id', $duplicateDetail->id)
            ->first();

        $this->assertNotNull($createdSchedule);
        $this->assertSame($schedule->id, (int) $createdSchedule->source_incoming_schedule_id);
        $this->assertSame(OrderSource::RECEIVED, $createdSchedule->order_source);
        $this->assertSame(IncomingScheduleStatus::CONFIRMED, $createdSchedule->status);
        $this->assertSame($this->slipNumber, $createdSchedule->slip_number);
        $this->assertSame(6, $createdSchedule->expected_quantity);
        $this->assertSame(6, $createdSchedule->received_quantity);
        $this->assertSame(6, $createdSchedule->shipped_quantity);
        $this->assertSame(0, $createdSchedule->shortage_quantity);
        $this->assertSame("EOS_DETAIL_{$duplicateDetail->id}", $createdSchedule->purchase_split_key);
        $this->assertTrue($createdSchedule->is_receive_matched);
        $this->assertTrue($createdSchedule->isEosSent());
        $this->assertSame($createdSchedule->id, $duplicateDetail->matched_schedule_id);

        $this->assertSame(12, $schedule->received_quantity);
        $this->assertSame(IncomingScheduleStatus::CONFIRMED, $schedule->status);

        $this->assertDatabaseHas('wms_incoming_price_check_sources', [
            'received_file_id' => $file->id,
            'received_detail_id' => $firstDetail->id,
            'incoming_schedule_id' => $schedule->id,
        ], 'sakemaru');
        $this->assertDatabaseHas('wms_incoming_price_check_sources', [
            'received_file_id' => $file->id,
            'received_detail_id' => $duplicateDetail->id,
            'incoming_schedule_id' => $createdSchedule->id,
        ], 'sakemaru');

        app(IncomingReceiveService::class)->applyMatched($file->fresh());

        $this->assertSame(
            1,
            WmsOrderIncomingSchedule::query()
                ->where('source_received_detail_id', $duplicateDetail->id)
                ->count()
        );
        $this->assertSame(
            2,
            WmsIncomingPriceCheckSource::query()->where('received_file_id', $file->id)->count()
        );
    }

    public function test_price_check_source_keeps_sent_jx_d_record_price_without_duplicates(): void
    {
        Storage::fake('local');

        $candidateId = $this->newCandidateId();
        $localPath = 'jx/test-incoming-receive-'.now()->format('YmdHisv').'.dat';
        Storage::disk('local')->put(
            $localPath,
            $this->buildSentJxContent($this->slipNumber, 1, '4911111111111', '990050', 24, 1, 0, 77700)
        );

        $document = WmsOrderJxDocument::create([
            'batch_code' => 'T'.now()->format('ymdHis'),
            'contractor_id' => $this->contractorId('1106'),
            'order_date' => '2026-07-19',
            'expected_arrival_date' => '2026-07-20',
            'document_type' => 'PURCHASE',
            'status' => 'TRANSMITTED',
            'file_path' => 'local:'.$localPath,
            'file_size' => 256,
            'record_count' => 2,
            'order_count' => 1,
            'total_items' => 1,
            'total_quantity' => 24,
        ]);

        $file = WmsIncomingReceivedFile::create([
            'filename' => 'test-incoming-receive-'.now()->format('YmdHisv').'.dat',
            'format_type' => 'JX',
            'status' => 'MATCHED',
            'parsed_slip_count' => 1,
            'parsed_detail_count' => 1,
            'raw_sha256' => hash('sha256', 'received-source'),
        ]);

        $schedule = $this->createIncomingSchedule(
            itemId: 990050,
            expectedQuantity: 24,
            searchCode: '4911111111111',
            casePrice: 999,
            orderCandidateId: $candidateId,
        );
        $this->createSlipAssignment([$candidateId], $document->id);

        $slip = WmsIncomingReceivedSlip::create([
            'received_file_id' => $file->id,
            'slip_number' => $this->slipNumber,
            'match_status' => 'MATCHED',
            'b_shop_code' => '0001',
            'b_order_date' => '260719',
            'b_delivery_date' => '260720',
            'b_contractor_code' => '1106',
            'matched_schedule_id' => $schedule->id,
            'detail_count' => 1,
        ]);

        $detail = WmsIncomingReceivedDetail::create([
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'd_line_number' => 1,
            'd_jan_code' => '4911111111111',
            'd_item_code' => '990050',
            'd_pack_quantity' => 24,
            'd_case_quantity' => 1,
            'd_piece_quantity' => 0,
            'd_unit_price' => 88800,
            'total_quantity' => 24,
            'match_status' => 'MATCHED',
            'matched_item_id' => 990050,
        ]);

        app(IncomingReceiveService::class)->applyMatched($file);
        app(IncomingReceiveService::class)->applyMatched($file->fresh());

        $this->assertSame(
            1,
            WmsIncomingPriceCheckSource::query()->where('received_file_id', $file->id)->count()
        );
        $this->assertDatabaseHas('wms_incoming_price_check_sources', [
            'received_file_id' => $file->id,
            'received_detail_id' => $detail->id,
            'incoming_schedule_id' => $schedule->id,
            'sent_unit_price_raw' => 77700,
            'sent_unit_price' => '777.0000',
            'received_unit_price_raw' => 88800,
            'received_unit_price' => '888.0000',
        ], 'sakemaru');
    }

    public function test_shortage_case_price_is_not_recorded_as_piece_price_mismatch(): void
    {
        $file = WmsIncomingReceivedFile::create([
            'filename' => 'test-incoming-receive-'.now()->format('YmdHisv').'.dat',
            'format_type' => 'JX',
            'status' => 'MATCHED',
            'parsed_slip_count' => 1,
            'parsed_detail_count' => 1,
        ]);

        $schedule = $this->createIncomingSchedule(
            itemId: 990070,
            expectedQuantity: 1,
            searchCode: '4970707070707',
            unitPrice: 82,
            casePrice: 1640,
            quantityType: QuantityType::CASE,
        );

        $slip = WmsIncomingReceivedSlip::create([
            'received_file_id' => $file->id,
            'slip_number' => $this->slipNumber,
            'match_status' => 'SHORTAGE',
            'b_shop_code' => '0001',
            'b_order_date' => '260719',
            'b_delivery_date' => '260720',
            'b_contractor_code' => '1106',
            'matched_schedule_id' => $schedule->id,
            'detail_count' => 1,
            'shortage_count' => 1,
        ]);

        WmsIncomingReceivedDetail::create([
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'd_line_number' => 1,
            'd_jan_code' => '4970707070707',
            'd_item_code' => '990070',
            'd_pack_quantity' => 20,
            'd_case_quantity' => 0,
            'd_piece_quantity' => 0,
            'd_unit_price' => 164000,
            'total_quantity' => 0,
            'is_shortage' => true,
            'match_status' => 'SHORTAGE',
            'matched_item_id' => 990070,
            'matched_schedule_id' => $schedule->id,
        ]);

        $result = app(IncomingReceiveService::class)->applyMatched($file);

        $this->assertSame(1, $result['applied']);

        $schedule->refresh();
        $this->assertSame('CASE', $schedule->price_type);
        $this->assertNull($schedule->partner_unit_price);
        $this->assertSame('1640.00', $schedule->partner_case_price);

        $source = WmsIncomingPriceCheckSource::query()
            ->where('received_file_id', $file->id)
            ->firstOrFail();
        $this->assertFalse($source->current_price_mismatch);
        $this->assertSame('CASE', $source->comparison_price_type);
        $this->assertSame('1640.0000', $source->comparison_master_price);
        $this->assertSame('1640.0000', $source->comparison_received_price);
        $this->assertSame('0.0000', $source->comparison_price_diff);
    }

    public function test_shortage_piece_price_mismatch_is_still_recorded_when_received_price_is_closer_to_unit_price(): void
    {
        $file = WmsIncomingReceivedFile::create([
            'filename' => 'test-incoming-receive-'.now()->format('YmdHisv').'.dat',
            'format_type' => 'JX',
            'status' => 'MATCHED',
            'parsed_slip_count' => 1,
            'parsed_detail_count' => 1,
        ]);

        $schedule = $this->createIncomingSchedule(
            itemId: 990071,
            expectedQuantity: 1,
            searchCode: '4971717171717',
            unitPrice: 295.45,
            casePrice: 6109,
            quantityType: QuantityType::CASE,
        );

        $slip = WmsIncomingReceivedSlip::create([
            'received_file_id' => $file->id,
            'slip_number' => $this->slipNumber,
            'match_status' => 'SHORTAGE',
            'b_shop_code' => '0001',
            'b_order_date' => '260719',
            'b_delivery_date' => '260720',
            'b_contractor_code' => '1106',
            'matched_schedule_id' => $schedule->id,
            'detail_count' => 1,
            'shortage_count' => 1,
        ]);

        WmsIncomingReceivedDetail::create([
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'd_line_number' => 1,
            'd_jan_code' => '4971717171717',
            'd_item_code' => '990071',
            'd_pack_quantity' => 20,
            'd_case_quantity' => 0,
            'd_piece_quantity' => 0,
            'd_unit_price' => 30545,
            'total_quantity' => 0,
            'is_shortage' => true,
            'match_status' => 'SHORTAGE',
            'matched_item_id' => 990071,
            'matched_schedule_id' => $schedule->id,
        ]);

        $result = app(IncomingReceiveService::class)->applyMatched($file);

        $this->assertSame(1, $result['applied']);

        $schedule->refresh();
        $this->assertSame('PIECE', $schedule->price_type);
        $this->assertSame('305.45', $schedule->partner_unit_price);
        $this->assertNull($schedule->partner_case_price);

        $source = WmsIncomingPriceCheckSource::query()
            ->where('received_file_id', $file->id)
            ->firstOrFail();
        $this->assertTrue($source->current_price_mismatch);
        $this->assertSame('PIECE', $source->comparison_price_type);
        $this->assertSame('295.4500', $source->comparison_master_price);
        $this->assertSame('305.4500', $source->comparison_received_price);
        $this->assertSame('10.0000', $source->comparison_price_diff);
    }

    public function test_price_check_source_keeps_same_eos_raw_record_in_different_slips(): void
    {
        $messageId = 'test-incoming-receive-'.now()->format('YmdHisv').'@FINET';
        $secondSlipNumber = 'FAX-'.$this->slipNumber;

        $log = WmsJxTransmissionLog::create([
            'direction' => WmsJxTransmissionLog::DIRECTION_RECEIVE,
            'environment' => WmsJxTransmissionLog::ENV_PRODUCTION,
            'operation_type' => WmsJxTransmissionLog::OPERATION_GET,
            'message_id' => $messageId,
            'document_type' => '90',
            'status' => WmsJxTransmissionLog::STATUS_SUCCESS,
            'file_path' => 'local:jx/test-incoming-receive-duplicate.dat',
            'transmitted_at' => now(),
        ]);

        $batch = WmsJxEosImportBatch::create([
            'wms_jx_transmission_log_id' => $log->id,
            'import_version' => 1,
            'importer_version' => 'test',
            'status' => WmsJxEosImportBatch::STATUS_SUCCEEDED,
            'is_current' => true,
            'source_disk' => 'local',
            'source_file_path' => 'local:jx/test-incoming-receive-duplicate.dat',
            'source_message_id' => $messageId,
            'source_document_type' => '90',
            'file_sha256' => hash('sha256', 'duplicate-eos-source'),
            'file_size' => 512,
            'record_count' => 4,
            'document_count' => 1,
            'slip_count' => 2,
            'line_count' => 2,
            'imported_at' => now(),
        ]);

        $file = WmsIncomingReceivedFile::create([
            'filename' => 'test-incoming-receive-'.now()->format('YmdHisv').'.dat',
            'format_type' => 'JX',
            'status' => 'MATCHED',
            'parsed_slip_count' => 2,
            'parsed_detail_count' => 2,
            'raw_sha256' => hash('sha256', 'duplicate-eos-source'),
            'received_message_id' => $messageId,
        ]);

        $schedule1 = $this->createIncomingSchedule(itemId: 990060, expectedQuantity: 1);
        $schedule2 = $this->createIncomingSchedule(
            itemId: 990060,
            expectedQuantity: 1,
            slipNumber: $secondSlipNumber,
        );

        $slip1 = WmsIncomingReceivedSlip::create([
            'received_file_id' => $file->id,
            'slip_number' => $this->slipNumber,
            'match_status' => 'MATCHED',
            'b_shop_code' => '0001',
            'b_order_date' => '260719',
            'b_delivery_date' => '260720',
            'b_contractor_code' => '1106',
            'matched_schedule_id' => $schedule1->id,
            'detail_count' => 1,
        ]);
        $slip2 = WmsIncomingReceivedSlip::create([
            'received_file_id' => $file->id,
            'slip_number' => $secondSlipNumber,
            'match_status' => 'MATCHED',
            'b_shop_code' => '0001',
            'b_order_date' => '260719',
            'b_delivery_date' => '260720',
            'b_contractor_code' => '1106',
            'matched_schedule_id' => $schedule2->id,
            'detail_count' => 1,
        ]);

        $eosSlip1 = WmsJxEosSlip::create([
            'import_batch_id' => $batch->id,
            'wms_jx_transmission_log_id' => $log->id,
            'source_record_no' => 2,
            'slip_sequence' => 1,
            'slip_number' => $this->slipNumber,
            'data_type' => '02',
            'shop_code' => '0001',
            'contractor_code' => '1106',
            'detail_count' => 1,
            'raw_record_hash' => hash('sha256', 'duplicate-slip-1'),
        ]);
        $eosSlip2 = WmsJxEosSlip::create([
            'import_batch_id' => $batch->id,
            'wms_jx_transmission_log_id' => $log->id,
            'source_record_no' => 4,
            'slip_sequence' => 2,
            'slip_number' => $secondSlipNumber,
            'data_type' => '02',
            'shop_code' => '0001',
            'contractor_code' => '1106',
            'detail_count' => 1,
            'raw_record_hash' => hash('sha256', 'duplicate-slip-2'),
        ]);

        foreach ([[$slip1, $eosSlip1, 3, $schedule1], [$slip2, $eosSlip2, 5, $schedule2]] as [$slip, $eosSlip, $sourceRecordNo, $schedule]) {
            WmsIncomingReceivedDetail::create([
                'received_file_id' => $file->id,
                'received_slip_id' => $slip->id,
                'd_line_number' => 1,
                'd_jan_code' => '4999999999999',
                'd_item_code' => '990060',
                'd_pack_quantity' => 1,
                'd_case_quantity' => 0,
                'd_piece_quantity' => 1,
                'd_unit_price' => 12300,
                'total_quantity' => 1,
                'match_status' => 'MATCHED',
                'matched_item_id' => 990060,
                'matched_schedule_id' => $schedule->id,
            ]);

            WmsJxEosLine::create([
                'import_batch_id' => $batch->id,
                'slip_id' => $eosSlip->id,
                'wms_jx_transmission_log_id' => $log->id,
                'source_record_no' => $sourceRecordNo,
                'line_sequence' => $sourceRecordNo,
                'line_number' => 1,
                'data_type' => '02',
                'product_name' => 'TEST DUPLICATE',
                'jan_code' => '4999999999999',
                'item_code' => '990060',
                'pack_quantity' => 1,
                'case_quantity' => 0,
                'piece_quantity' => 1,
                'total_quantity' => 1,
                'unit_price_raw' => 12300,
                'unit_price' => 123,
                'amount' => 123,
                'is_shortage' => false,
                'line_hash' => hash('sha256', $log->id.'|'.$sourceRecordNo.'|duplicate-record'),
                'raw_record_hash' => hash('sha256', 'duplicate-record'),
            ]);
        }

        $result = app(IncomingReceiveService::class)->applyMatched($file);

        $this->assertSame(2, $result['applied']);
        $this->assertSame(
            2,
            WmsIncomingPriceCheckSource::query()->where('received_file_id', $file->id)->count()
        );
    }

    public function test_price_check_source_key_stays_stable_when_eos_line_is_resolved_later(): void
    {
        $messageId = 'test-incoming-receive-'.now()->format('YmdHisv').'@FINET';
        $rawHash = hash('sha256', 'stable-source-key-eos-later');

        $log = WmsJxTransmissionLog::create([
            'direction' => WmsJxTransmissionLog::DIRECTION_RECEIVE,
            'environment' => WmsJxTransmissionLog::ENV_PRODUCTION,
            'operation_type' => WmsJxTransmissionLog::OPERATION_GET,
            'message_id' => $messageId,
            'document_type' => '90',
            'status' => WmsJxTransmissionLog::STATUS_SUCCESS,
            'file_path' => 'local:jx/test-incoming-receive-stable-key.dat',
            'transmitted_at' => now(),
        ]);

        $batch = WmsJxEosImportBatch::create([
            'wms_jx_transmission_log_id' => $log->id,
            'import_version' => 1,
            'importer_version' => 'test',
            'status' => WmsJxEosImportBatch::STATUS_SUCCEEDED,
            'is_current' => true,
            'source_disk' => 'local',
            'source_file_path' => 'local:jx/test-incoming-receive-stable-key.dat',
            'source_message_id' => $messageId,
            'source_document_type' => '90',
            'file_sha256' => $rawHash,
            'file_size' => 384,
            'record_count' => 3,
            'document_count' => 1,
            'slip_count' => 1,
            'line_count' => 1,
            'imported_at' => now(),
        ]);

        $file = WmsIncomingReceivedFile::create([
            'filename' => 'test-incoming-receive-'.now()->format('YmdHisv').'.dat',
            'format_type' => 'JX',
            'status' => 'MATCHED',
            'parsed_slip_count' => 1,
            'parsed_detail_count' => 1,
            'raw_sha256' => $rawHash,
        ]);

        $schedule = $this->createIncomingSchedule(itemId: 990061, expectedQuantity: 1);
        $slip = WmsIncomingReceivedSlip::create([
            'received_file_id' => $file->id,
            'slip_number' => $this->slipNumber,
            'match_status' => 'MATCHED',
            'b_shop_code' => '0001',
            'b_order_date' => '260719',
            'b_delivery_date' => '260720',
            'b_contractor_code' => '1106',
            'matched_schedule_id' => $schedule->id,
            'detail_count' => 1,
        ]);
        $detail = WmsIncomingReceivedDetail::create([
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'd_line_number' => 1,
            'd_jan_code' => '4999999999998',
            'd_item_code' => '990061',
            'd_pack_quantity' => 1,
            'd_case_quantity' => 0,
            'd_piece_quantity' => 1,
            'd_unit_price' => 12300,
            'd_amount' => 999999,
            'total_quantity' => 1,
            'match_status' => 'MATCHED',
            'matched_item_id' => 990061,
            'matched_schedule_id' => $schedule->id,
        ]);

        app(IncomingPriceCheckSourceRecorder::class)->record($file, $detail, $schedule);

        $eosSlip = WmsJxEosSlip::create([
            'import_batch_id' => $batch->id,
            'wms_jx_transmission_log_id' => $log->id,
            'source_record_no' => 2,
            'slip_sequence' => 1,
            'slip_number' => $this->slipNumber,
            'data_type' => '02',
            'shop_code' => '0001',
            'contractor_code' => '1106',
            'detail_count' => 1,
            'raw_record_hash' => hash('sha256', 'stable-key-slip'),
        ]);
        $eosLine = WmsJxEosLine::create([
            'import_batch_id' => $batch->id,
            'slip_id' => $eosSlip->id,
            'wms_jx_transmission_log_id' => $log->id,
            'source_record_no' => 3,
            'line_sequence' => 1,
            'line_number' => 1,
            'data_type' => '02',
            'product_name' => 'TEST STABLE KEY',
            'jan_code' => '4999999999998',
            'item_code' => '990061',
            'pack_quantity' => 1,
            'case_quantity' => 0,
            'piece_quantity' => 1,
            'total_quantity' => 1,
            'unit_price_raw' => 12300,
            'unit_price' => 123,
            'amount' => 123,
            'is_shortage' => false,
            'line_hash' => hash('sha256', $log->id.'|3|stable-key-line'),
            'raw_record_hash' => hash('sha256', 'stable-key-line'),
        ]);

        $file->update(['received_message_id' => $messageId]);

        app(IncomingPriceCheckSourceRecorder::class)->record($file->fresh(), $detail->fresh(), $schedule);

        $sources = WmsIncomingPriceCheckSource::query()
            ->where('received_file_id', $file->id)
            ->get();

        $this->assertCount(1, $sources);
        $this->assertSame($eosLine->id, $sources->first()->wms_jx_eos_line_id);
        $this->assertEquals('123.0000', $sources->first()->received_amount);
    }

    public function test_jx_match_uses_sent_assignment_candidate_ids_before_schedule_slip_number(): void
    {
        $targetContractorId = $this->contractorId('1106');
        $otherContractorId = $this->contractorId('1202');
        $targetCandidateId = $this->newCandidateId();

        $file = WmsIncomingReceivedFile::create([
            'filename' => 'test-incoming-receive-'.now()->format('YmdHisv').'.dat',
            'format_type' => 'JX',
            'status' => 'PENDING',
            'parsed_slip_count' => 1,
            'parsed_detail_count' => 1,
        ]);

        $targetSchedule = $this->createIncomingSchedule(
            itemId: 990010,
            expectedQuantity: 10,
            contractorId: $targetContractorId,
            searchCode: '4911111111111',
            casePrice: 999,
            orderCandidateId: $targetCandidateId,
            slipNumber: 'FAX-'.$this->slipNumber,
        );
        $otherSchedule = $this->createIncomingSchedule(
            itemId: 990010,
            expectedQuantity: 10,
            contractorId: $otherContractorId,
            searchCode: '4911111111111',
        );
        $this->createSlipAssignment([$targetCandidateId]);

        $slip = WmsIncomingReceivedSlip::create([
            'received_file_id' => $file->id,
            'slip_number' => $this->slipNumber,
            'match_status' => 'UNMATCHED',
            'b_shop_code' => '0001',
            'b_order_date' => '260719',
            'b_delivery_date' => '260720',
            'b_contractor_code' => '1106',
            'detail_count' => 1,
        ]);

        $detail = WmsIncomingReceivedDetail::create([
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'd_line_number' => 1,
            'd_jan_code' => '4911111111111',
            'd_pack_quantity' => 10,
            'd_case_quantity' => 1,
            'd_piece_quantity' => 0,
            'd_unit_price' => 12300,
            'total_quantity' => 10,
            'match_status' => 'UNMATCHED',
        ]);

        $result = app(IncomingReceiveService::class)->matchWithSchedules($file);

        $this->assertSame(1, $result['matched']);

        $slip->refresh();
        $targetSchedule->refresh();
        $otherSchedule->refresh();

        $this->assertSame($targetSchedule->id, $slip->matched_schedule_id);
        $this->assertTrue($targetSchedule->is_receive_matched);
        $this->assertFalse($otherSchedule->is_receive_matched);
        $this->assertDatabaseHas('wms_incoming_import_errors', [
            'received_file_id' => $file->id,
            'error_code' => 'PRICE_MISMATCH',
            'expected_price' => '999.00',
            'actual_price' => '123.00',
        ], 'sakemaru');

        $file->update(['status' => 'PENDING']);
        app(IncomingReceiveService::class)->matchWithSchedules($file->fresh());

        $this->assertSame(
            1,
            WmsIncomingImportError::query()
                ->where('received_file_id', $file->id)
                ->where('received_detail_id', $detail->id)
                ->where('error_code', 'PRICE_MISMATCH')
                ->count()
        );
        $this->assertSame(
            1,
            WmsIncomingPriceCheckSource::query()
                ->where('received_file_id', $file->id)
                ->where('received_detail_id', $detail->id)
                ->count()
        );
    }

    public function test_kanakan_jx_match_uses_sent_supplier_when_received_code_is_counterparty_code(): void
    {
        $kanakanParentId = $this->contractorId('1106');
        $kanakanChildId = $this->contractorId('1021');
        $candidateId = $this->newCandidateId();

        $file = WmsIncomingReceivedFile::create([
            'filename' => 'test-incoming-receive-'.now()->format('YmdHisv').'.dat',
            'format_type' => 'JX',
            'status' => 'PENDING',
            'contractor_id' => $kanakanParentId,
            'parsed_slip_count' => 1,
            'parsed_detail_count' => 1,
        ]);

        $targetSchedule = $this->createIncomingSchedule(
            itemId: 990020,
            expectedQuantity: 12,
            contractorId: $kanakanChildId,
            searchCode: '4920202020202',
            casePrice: 999,
            orderCandidateId: $candidateId,
        );
        $this->createSlipAssignment([$candidateId]);

        $slip = WmsIncomingReceivedSlip::create([
            'received_file_id' => $file->id,
            'slip_number' => $this->slipNumber,
            'match_status' => 'UNMATCHED',
            'b_shop_code' => '0001',
            'b_order_date' => '260719',
            'b_delivery_date' => '260720',
            'b_contractor_code' => '0010',
            'detail_count' => 1,
        ]);

        $detail = WmsIncomingReceivedDetail::create([
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'd_line_number' => 1,
            'd_jan_code' => '4920202020202',
            'd_item_code' => '990020',
            'd_pack_quantity' => 12,
            'd_case_quantity' => 1,
            'd_piece_quantity' => 0,
            'd_unit_price' => 12300,
            'total_quantity' => 12,
            'match_status' => 'UNMATCHED',
        ]);

        $result = app(IncomingReceiveService::class)->matchWithSchedules($file);

        $this->assertSame(1, $result['matched']);

        $slip->refresh();
        $targetSchedule->refresh();

        $this->assertSame($targetSchedule->id, $slip->matched_schedule_id);
        $this->assertSame($kanakanChildId, (int) $targetSchedule->contractor_id);
        $this->assertTrue($targetSchedule->is_receive_matched);
    }

    public function test_jx_case_schedule_keeps_schedule_quantities_in_case_after_piece_normalization(): void
    {
        $candidateId = $this->newCandidateId();

        $file = WmsIncomingReceivedFile::create([
            'filename' => 'test-incoming-receive-'.now()->format('YmdHisv').'.dat',
            'format_type' => 'JX',
            'status' => 'PENDING',
            'parsed_slip_count' => 1,
            'parsed_detail_count' => 1,
        ]);

        $schedule = $this->createIncomingSchedule(
            itemId: 215032,
            expectedQuantity: 8,
            searchCode: '4909411069100',
            casePrice: 1020,
            orderCandidateId: $candidateId,
            quantityType: QuantityType::CASE,
        );
        $this->createSlipAssignment([$candidateId]);

        $slip = WmsIncomingReceivedSlip::create([
            'received_file_id' => $file->id,
            'slip_number' => $this->slipNumber,
            'match_status' => 'UNMATCHED',
            'b_shop_code' => '0001',
            'b_order_date' => '260719',
            'b_delivery_date' => '260720',
            'b_contractor_code' => '1106',
            'detail_count' => 1,
        ]);

        $detail = WmsIncomingReceivedDetail::create([
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'd_line_number' => 1,
            'd_jan_code' => '4909411069100',
            'd_item_code' => '215032',
            'd_pack_quantity' => 6,
            'd_case_quantity' => 8,
            'd_piece_quantity' => 0,
            'd_unit_price' => 102000,
            'total_quantity' => 48,
            'match_status' => 'UNMATCHED',
        ]);

        $matchResult = app(IncomingReceiveService::class)->matchWithSchedules($file);

        $this->assertSame(1, $matchResult['matched']);

        $detail->refresh();
        $schedule->refresh();

        $this->assertSame(48, $detail->expected_quantity);
        $this->assertSame(QuantityType::CASE, $schedule->quantity_type);
        $this->assertSame(8, $schedule->expected_quantity);
        $this->assertSame(8, $schedule->shipped_quantity);
        $this->assertSame(0, $schedule->received_quantity);
        $this->assertSame(0, $schedule->shortage_quantity);

        $applyResult = app(IncomingReceiveService::class)->applyMatched($file->fresh());

        $this->assertSame(1, $applyResult['applied']);

        $schedule->refresh();

        $this->assertSame(QuantityType::CASE, $schedule->quantity_type);
        $this->assertSame(8, $schedule->expected_quantity);
        $this->assertSame(8, $schedule->received_quantity);
        $this->assertSame(8, $schedule->shipped_quantity);
        $this->assertSame(0, $schedule->shortage_quantity);
        $this->assertSame(IncomingScheduleStatus::CONFIRMED, $schedule->status);
        $this->assertSame('2026-07-20', $schedule->actual_arrival_date?->format('Y-m-d'));
        $this->assertSame(0, $schedule->confirmed_by);
        $this->assertNotNull($schedule->confirmed_at);
    }

    public function test_closed_slip_receipt_creates_separate_schedule_for_later_arrival(): void
    {
        $contractorId = $this->contractorId('1106');
        $closedCandidateId = $this->newCandidateId();

        $file = WmsIncomingReceivedFile::create([
            'filename' => 'test-incoming-receive-'.now()->format('YmdHisv').'.dat',
            'format_type' => 'JX',
            'status' => 'PENDING',
            'parsed_slip_count' => 1,
            'parsed_detail_count' => 1,
        ]);

        $closedSchedule = $this->createIncomingSchedule(
            itemId: 990030,
            expectedQuantity: 10,
            contractorId: $contractorId,
            searchCode: '4933333333333',
            status: IncomingScheduleStatus::CONFIRMED,
            shortageQuantity: 4,
            casePrice: 300,
            orderCandidateId: $closedCandidateId,
            slipNumber: 'FAX-'.$this->slipNumber,
        );
        $this->createSlipAssignment([$closedCandidateId]);

        $slip = WmsIncomingReceivedSlip::create([
            'received_file_id' => $file->id,
            'slip_number' => $this->slipNumber,
            'match_status' => 'UNMATCHED',
            'b_shop_code' => '0001',
            'b_order_date' => '260719',
            'b_delivery_date' => '260720',
            'b_contractor_code' => '1106',
            'detail_count' => 1,
        ]);

        $detail = WmsIncomingReceivedDetail::create([
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'd_line_number' => 1,
            'd_jan_code' => '4933333333333',
            'd_pack_quantity' => 1,
            'd_case_quantity' => 0,
            'd_piece_quantity' => 3,
            'd_unit_price' => 10000,
            'total_quantity' => 3,
            'match_status' => 'UNMATCHED',
        ]);

        $result = app(IncomingReceiveService::class)->matchWithSchedules($file);

        $this->assertSame(0, $result['matched']);
        $this->assertSame(1, $result['shortage']);

        $detail->refresh();

        $createdSchedule = WmsOrderIncomingSchedule::query()
            ->where('slip_number', $this->slipNumber)
            ->where('contractor_id', $contractorId)
            ->where('item_id', 990030)
            ->where('id', '!=', $closedSchedule->id)
            ->firstOrFail();

        $this->assertSame(OrderSource::RECEIVED, $createdSchedule->order_source);
        $this->assertSame(4, $detail->expected_quantity);
        $this->assertSame(IncomingScheduleStatus::PENDING, $createdSchedule->status);
        $this->assertSame(4, $createdSchedule->expected_quantity);
        $this->assertSame(3, $createdSchedule->received_quantity);
        $this->assertSame(1, $createdSchedule->shortage_quantity);
        $this->assertTrue($createdSchedule->is_receive_matched);

        $file->update(['status' => 'PENDING']);
        $secondMatch = app(IncomingReceiveService::class)->matchWithSchedules($file->fresh());
        $this->assertSame(0, $secondMatch['unmatched']);
        $this->assertSame(1, $secondMatch['shortage']);
        $this->assertSame('PARTIAL', $slip->fresh()->match_status);
        $this->assertSame(4, $detail->fresh()->expected_quantity);

        $applyResult = app(IncomingReceiveService::class)->applyMatched($file->fresh());
        $this->assertSame(1, $applyResult['applied']);
        $this->assertSame([$createdSchedule->id], $applyResult['schedule_ids']);
    }

    public function test_mixed_closed_and_pending_slip_receipt_creates_schedule_only_for_closed_lines(): void
    {
        $contractorId = $this->contractorId('1106');
        $closedCandidateId = $this->newCandidateId();
        $pendingCandidateId = $this->newCandidateId();

        $file = WmsIncomingReceivedFile::create([
            'filename' => 'test-incoming-receive-'.now()->format('YmdHisv').'.dat',
            'format_type' => 'JX',
            'status' => 'PENDING',
            'parsed_slip_count' => 1,
            'parsed_detail_count' => 2,
        ]);

        $closedSchedule = $this->createIncomingSchedule(
            itemId: 990040,
            expectedQuantity: 6,
            contractorId: $contractorId,
            searchCode: '4940404040404',
            status: IncomingScheduleStatus::CONFIRMED,
            shortageQuantity: 2,
            orderCandidateId: $closedCandidateId,
        );
        $pendingSchedule = $this->createIncomingSchedule(
            itemId: 990041,
            expectedQuantity: 4,
            contractorId: $contractorId,
            searchCode: '4941414141414',
            status: IncomingScheduleStatus::PENDING,
            orderCandidateId: $pendingCandidateId,
        );
        $this->createSlipAssignment([$closedCandidateId, $pendingCandidateId]);

        $slip = WmsIncomingReceivedSlip::create([
            'received_file_id' => $file->id,
            'slip_number' => $this->slipNumber,
            'match_status' => 'UNMATCHED',
            'b_shop_code' => '0001',
            'b_order_date' => '260719',
            'b_delivery_date' => '260720',
            'b_contractor_code' => '1106',
            'detail_count' => 2,
        ]);

        $closedLine = WmsIncomingReceivedDetail::create([
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'd_line_number' => 1,
            'd_jan_code' => '4940404040404',
            'd_pack_quantity' => 1,
            'd_case_quantity' => 0,
            'd_piece_quantity' => 2,
            'd_unit_price' => 10000,
            'total_quantity' => 2,
            'match_status' => 'UNMATCHED',
        ]);
        $pendingLine = WmsIncomingReceivedDetail::create([
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'd_line_number' => 2,
            'd_jan_code' => '4941414141414',
            'd_pack_quantity' => 1,
            'd_case_quantity' => 0,
            'd_piece_quantity' => 0,
            'd_unit_price' => 20000,
            'total_quantity' => 0,
            'is_shortage' => true,
            'match_status' => 'UNMATCHED',
        ]);

        $result = app(IncomingReceiveService::class)->matchWithSchedules($file);

        $this->assertSame(0, $result['unmatched']);
        $this->assertSame(1, $result['shortage']);

        $closedLine->refresh();
        $pendingLine->refresh();

        $createdSchedule = WmsOrderIncomingSchedule::query()
            ->where('slip_number', $this->slipNumber)
            ->where('contractor_id', $contractorId)
            ->where('item_id', 990040)
            ->where('id', '!=', $closedSchedule->id)
            ->firstOrFail();

        $this->assertSame($createdSchedule->id, $closedLine->matched_schedule_id);
        $this->assertSame($pendingSchedule->id, $pendingLine->matched_schedule_id);
        $this->assertSame(2, $closedLine->expected_quantity);
        $this->assertSame(4, $pendingLine->expected_quantity);
        $this->assertSame(OrderSource::RECEIVED, $createdSchedule->order_source);
        $this->assertSame(IncomingScheduleStatus::PENDING, $createdSchedule->status);
        $this->assertSame(2, $createdSchedule->expected_quantity);
        $this->assertSame(2, $createdSchedule->received_quantity);

        $applyResult = app(IncomingReceiveService::class)->applyMatched($file->fresh());

        $this->assertSame(2, $applyResult['applied']);
        $this->assertEqualsCanonicalizing([$createdSchedule->id, $pendingSchedule->id], $applyResult['schedule_ids']);
    }

    public function test_unassigned_jx_slip_is_kept_for_review_without_creating_schedule(): void
    {
        $contractorId = $this->contractorId('1330');

        $file = WmsIncomingReceivedFile::create([
            'filename' => 'test-incoming-receive-'.now()->format('YmdHisv').'.dat',
            'format_type' => 'JX',
            'status' => 'PENDING',
            'contractor_id' => $contractorId,
            'parsed_slip_count' => 1,
            'parsed_detail_count' => 1,
        ]);

        $slip = WmsIncomingReceivedSlip::create([
            'received_file_id' => $file->id,
            'slip_number' => $this->slipNumber,
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

        $result = app(IncomingReceiveService::class)->matchWithSchedules($file);

        $this->assertSame(0, $result['matched']);
        $this->assertSame(1, $result['unmatched']);

        $slip->refresh();
        $file->refresh();
        $detail->refresh();

        $this->assertSame('NO_ASSIGNMENT', $slip->match_status);
        $this->assertNull($slip->matched_schedule_id);
        $this->assertSame('NO_ASSIGNMENT', $detail->match_status);
        $this->assertSame(162100, $detail->matched_item_id);
        $this->assertNull($detail->expected_quantity);
        $this->assertSame(WmsIncomingReceivedFile::STATUS_PENDING, $file->status);

        $this->assertDatabaseMissing('wms_order_incoming_schedules', [
            'slip_number' => $this->slipNumber,
            'contractor_id' => $contractorId,
            'item_id' => 162100,
            'order_source' => OrderSource::RECEIVED->value,
        ], 'sakemaru');

        $this->assertDatabaseMissing('wms_incoming_import_errors', [
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'error_code' => 'EOS_UNASSIGNED_RECEIVED_SCHEDULE_CREATED',
        ], 'sakemaru');
        $this->assertDatabaseHas('wms_incoming_import_errors', [
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'error_type' => 'ERROR',
            'error_code' => 'EOS_ASSIGNMENT_NOT_FOUND',
        ], 'sakemaru');

        $applyResult = app(IncomingReceiveService::class)->applyMatched($file->fresh());

        $this->assertSame(0, $applyResult['applied']);
        $this->assertSame([], $applyResult['schedule_ids']);
        $this->assertSame(WmsIncomingReceivedFile::STATUS_PENDING, $file->fresh()->status);
    }

    public function test_unassigned_jx_slip_can_be_confirmed_as_received_schedule_without_duplicates(): void
    {
        $contractorId = $this->contractorId('1330');

        $file = WmsIncomingReceivedFile::create([
            'filename' => 'test-incoming-receive-'.now()->format('YmdHisv').'.dat',
            'format_type' => 'JX',
            'status' => 'PENDING',
            'contractor_id' => $contractorId,
            'parsed_slip_count' => 1,
            'parsed_detail_count' => 1,
        ]);

        $slip = WmsIncomingReceivedSlip::create([
            'received_file_id' => $file->id,
            'slip_number' => $this->slipNumber,
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

        $result = app(IncomingReceiveService::class)->confirmUnassignedJxSlip($slip->fresh(), 123);

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['skipped']);
        $this->assertCount(1, $result['schedule_ids']);

        $schedule = WmsOrderIncomingSchedule::query()
            ->where('source_received_detail_id', $detail->id)
            ->firstOrFail();

        $this->assertSame($this->slipNumber, $schedule->slip_number);
        $this->assertSame($contractorId, $schedule->contractor_id);
        $this->assertSame(162100, $schedule->item_id);
        $this->assertSame(OrderSource::RECEIVED, $schedule->order_source);
        $this->assertSame(IncomingScheduleStatus::CONFIRMED, $schedule->status);
        $this->assertSame(3, $schedule->expected_quantity);
        $this->assertSame(3, $schedule->shipped_quantity);
        $this->assertSame(3, $schedule->received_quantity);
        $this->assertSame(0, $schedule->shortage_quantity);
        $this->assertSame(QuantityType::PIECE, $schedule->quantity_type);
        $this->assertSame('2026-07-21', $schedule->actual_arrival_date?->format('Y-m-d'));
        $this->assertSame(123, $schedule->confirmed_by);
        $this->assertSame('PIECE', $schedule->price_type);
        $this->assertEquals('4105.00', $schedule->partner_unit_price);
        $this->assertSame("UNASSIGNED_JX_SLIP_{$slip->id}", $schedule->purchase_split_key);

        $slip->refresh();
        $detail->refresh();
        $file->refresh();

        $this->assertSame('MATCHED', $slip->match_status);
        $this->assertSame($schedule->id, $slip->matched_schedule_id);
        $this->assertSame('MATCHED', $detail->match_status);
        $this->assertSame($schedule->id, $detail->matched_schedule_id);
        $this->assertSame(WmsIncomingReceivedFile::STATUS_APPLIED, $file->status);
        $this->assertDatabaseHas('wms_incoming_import_errors', [
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'error_code' => 'EOS_ASSIGNMENT_NOT_FOUND',
            'is_resolved' => true,
        ], 'sakemaru');
        $this->assertDatabaseHas('wms_incoming_price_check_sources', [
            'received_file_id' => $file->id,
            'received_detail_id' => $detail->id,
            'incoming_schedule_id' => $schedule->id,
            'received_unit_price_raw' => 410500,
            'received_unit_price' => '4105.0000',
            'comparison_price_type' => 'PIECE',
        ], 'sakemaru');

        $secondResult = app(IncomingReceiveService::class)->confirmUnassignedJxSlip($slip->fresh(), 123);

        $this->assertSame(0, $secondResult['created']);
        $this->assertSame(0, $secondResult['updated']);
        $this->assertSame(1, $secondResult['skipped']);
        $this->assertSame(1, WmsOrderIncomingSchedule::query()
            ->where('source_received_detail_id', $detail->id)
            ->count());

        $file->update(['status' => WmsIncomingReceivedFile::STATUS_PENDING]);
        $rematch = app(IncomingReceiveService::class)->matchWithSchedules($file->fresh());

        $this->assertSame(1, $rematch['matched']);
        $this->assertSame(0, $rematch['unmatched']);
        $this->assertSame('MATCHED', $slip->fresh()->match_status);
        $this->assertSame(1, WmsOrderIncomingSchedule::query()
            ->where('source_received_detail_id', $detail->id)
            ->count());
    }

    public function test_unassigned_jx_slip_product_unknown_can_be_resolved_manually_before_confirmation(): void
    {
        $contractorId = $this->contractorId('1330');

        $file = WmsIncomingReceivedFile::create([
            'filename' => 'test-incoming-receive-'.now()->format('YmdHisv').'.dat',
            'format_type' => 'JX',
            'status' => 'PENDING',
            'contractor_id' => $contractorId,
            'parsed_slip_count' => 1,
            'parsed_detail_count' => 1,
        ]);

        $slip = WmsIncomingReceivedSlip::create([
            'received_file_id' => $file->id,
            'slip_number' => $this->slipNumber,
            'match_status' => 'NOT_FOUND',
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
            'd_product_name' => '商品不明テスト',
            'd_pack_quantity' => 6,
            'd_case_quantity' => 0,
            'd_piece_quantity' => 3,
            'd_unit_price' => 410500,
            'total_quantity' => 3,
            'match_status' => 'NOT_FOUND',
        ]);

        WmsIncomingImportError::create([
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'received_detail_id' => $detail->id,
            'error_type' => 'ERROR',
            'error_code' => 'ITEM_NOT_FOUND',
            'error_message' => '商品を特定できません',
            'item_code' => '999999',
        ]);

        app(IncomingReceiveService::class)->resolveUnassignedJxDetailItem($detail, 162100, 123);

        $detail->refresh();
        $slip->refresh();
        $item = DB::connection('sakemaru')
            ->table('items')
            ->where('id', 162100)
            ->first(['name', 'name_main']);
        $expectedProductName = mb_substr((string) (($item?->name ?: $item?->name_main) ?: ''), 0, 64);

        $this->assertSame(162100, (int) $detail->matched_item_id);
        $this->assertSame($expectedProductName, $detail->d_product_name);
        $this->assertSame('NO_ASSIGNMENT', $detail->match_status);
        $this->assertSame('NO_ASSIGNMENT', $slip->match_status);
        $this->assertDatabaseHas('wms_incoming_import_errors', [
            'received_detail_id' => $detail->id,
            'error_code' => 'ITEM_NOT_FOUND',
            'is_resolved' => true,
        ], 'sakemaru');

        $result = app(IncomingReceiveService::class)->confirmUnassignedJxSlip($slip->fresh(), 123);

        $this->assertSame(1, $result['created']);

        $schedule = WmsOrderIncomingSchedule::query()
            ->where('source_received_detail_id', $detail->id)
            ->firstOrFail();

        $this->assertSame(162100, (int) $schedule->item_id);
        $this->assertSame(3, $schedule->received_quantity);
        $this->assertSame('MATCHED', $detail->fresh()->match_status);
    }

    public function test_unassigned_jx_slip_product_unknown_can_be_ignored_without_creating_schedule(): void
    {
        $contractorId = $this->contractorId('1330');

        $file = WmsIncomingReceivedFile::create([
            'filename' => 'test-incoming-receive-'.now()->format('YmdHisv').'.dat',
            'format_type' => 'JX',
            'status' => 'PENDING',
            'contractor_id' => $contractorId,
            'parsed_slip_count' => 1,
            'parsed_detail_count' => 1,
        ]);

        $slip = WmsIncomingReceivedSlip::create([
            'received_file_id' => $file->id,
            'slip_number' => $this->slipNumber,
            'match_status' => 'NOT_FOUND',
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
            'd_jan_code' => '9999999999002',
            'd_item_code' => '999998',
            'd_product_name' => '商品不明削除テスト',
            'd_pack_quantity' => 1,
            'd_case_quantity' => 0,
            'd_piece_quantity' => 1,
            'd_unit_price' => 100000,
            'total_quantity' => 1,
            'match_status' => 'NOT_FOUND',
        ]);

        WmsIncomingImportError::create([
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'received_detail_id' => $detail->id,
            'error_type' => 'ERROR',
            'error_code' => 'ITEM_NOT_FOUND',
            'error_message' => '商品を特定できません',
            'item_code' => '999998',
        ]);
        WmsIncomingImportError::create([
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'error_type' => 'WARNING',
            'error_code' => 'EOS_ASSIGNMENT_NOT_FOUND',
            'error_message' => '送信済みEOS伝票番号割当が見つかりません',
        ]);

        $result = app(IncomingReceiveService::class)->ignoreUnassignedJxDetail($detail, 123);

        $this->assertSame($detail->id, $result['detail_id']);
        $this->assertSame('IGNORED', $result['slip_status']);
        $this->assertSame('IGNORED', $detail->fresh()->match_status);
        $this->assertSame('IGNORED', $slip->fresh()->match_status);
        $this->assertSame(WmsIncomingReceivedFile::STATUS_APPLIED, $file->fresh()->status);
        $this->assertSame(0, WmsOrderIncomingSchedule::query()
            ->where('source_received_detail_id', $detail->id)
            ->count());
        $this->assertSame(0, WmsIncomingImportError::query()
            ->where('received_slip_id', $slip->id)
            ->where(function ($query): void {
                $query
                    ->whereNull('is_resolved')
                    ->orWhere('is_resolved', false);
            })
            ->count());
    }

    public function test_unassigned_jx_slip_uses_item_contractor_before_file_contractor(): void
    {
        $fileContractorId = $this->contractorId('1106');
        $itemContractorId = $this->contractorId('1021');

        $file = WmsIncomingReceivedFile::create([
            'filename' => 'test-incoming-receive-'.now()->format('YmdHisv').'.dat',
            'format_type' => 'JX',
            'status' => 'PENDING',
            'contractor_id' => $fileContractorId,
            'parsed_slip_count' => 1,
            'parsed_detail_count' => 1,
        ]);

        $slip = WmsIncomingReceivedSlip::create([
            'received_file_id' => $file->id,
            'slip_number' => $this->slipNumber,
            'match_status' => 'UNMATCHED',
            'b_shop_code' => '0091',
            'b_order_date' => '260729',
            'b_delivery_date' => '260730',
            'b_contractor_code' => '0010',
            'detail_count' => 1,
        ]);

        $detail = WmsIncomingReceivedDetail::create([
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'd_line_number' => 1,
            'd_jan_code' => '4901004061805',
            'd_item_code' => '490100',
            'd_pack_quantity' => 1,
            'd_case_quantity' => 0,
            'd_piece_quantity' => 1,
            'd_unit_price' => 194000,
            'total_quantity' => 1,
            'match_status' => 'UNMATCHED',
        ]);

        $result = app(IncomingReceiveService::class)->confirmUnassignedJxSlip($slip->fresh(), 123);

        $this->assertSame(1, $result['created']);

        $schedule = WmsOrderIncomingSchedule::query()
            ->where('source_received_detail_id', $detail->id)
            ->firstOrFail();

        $this->assertSame(312085, (int) $schedule->item_id);
        $this->assertSame($itemContractorId, (int) $schedule->contractor_id);
        $this->assertNotSame($fileContractorId, (int) $schedule->contractor_id);
    }

    public function test_unassigned_jx_slip_multiple_details_transmit_as_one_purchase_queue(): void
    {
        $contractorId = $this->contractorId('1330');

        $file = WmsIncomingReceivedFile::create([
            'filename' => 'test-incoming-receive-'.now()->format('YmdHisv').'.dat',
            'format_type' => 'JX',
            'status' => 'PENDING',
            'contractor_id' => $contractorId,
            'parsed_slip_count' => 1,
            'parsed_detail_count' => 2,
        ]);

        $slip = WmsIncomingReceivedSlip::create([
            'received_file_id' => $file->id,
            'slip_number' => $this->slipNumber,
            'match_status' => 'UNMATCHED',
            'b_shop_code' => '0008',
            'b_order_date' => '260720',
            'b_delivery_date' => '260721',
            'b_contractor_code' => '1330',
            'detail_count' => 2,
        ]);

        $firstDetail = WmsIncomingReceivedDetail::create([
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

        $secondDetail = WmsIncomingReceivedDetail::create([
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'd_line_number' => 2,
            'd_jan_code' => '7401005008597',
            'd_item_code' => '162100',
            'd_pack_quantity' => 6,
            'd_case_quantity' => 0,
            'd_piece_quantity' => 2,
            'd_unit_price' => 410500,
            'total_quantity' => 2,
            'match_status' => 'UNMATCHED',
        ]);

        app(IncomingReceiveService::class)->matchWithSchedules($file);

        $confirmResult = app(IncomingReceiveService::class)->confirmUnassignedJxSlip($slip->fresh(), 123);

        $this->assertSame(2, $confirmResult['created']);
        $this->assertSame(0, $confirmResult['updated']);
        $this->assertSame(0, $confirmResult['skipped']);
        $this->assertCount(2, $confirmResult['schedule_ids']);

        $schedules = WmsOrderIncomingSchedule::query()
            ->whereIn('source_received_detail_id', [$firstDetail->id, $secondDetail->id])
            ->orderBy('source_received_detail_id')
            ->get();

        $this->assertCount(2, $schedules);
        $this->assertSame(
            ["UNASSIGNED_JX_SLIP_{$slip->id}"],
            $schedules->pluck('purchase_split_key')->unique()->values()->all()
        );

        $transmitResult = app(IncomingTransmissionService::class)->transmitConfirmedIncomings(
            scheduleIds: $confirmResult['schedule_ids'],
        );

        $this->assertTrue($transmitResult['success'], json_encode($transmitResult['errors'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $transmitResult['queue_count']);
        $this->assertSame(2, $transmitResult['schedule_count']);

        $schedules = $schedules->fresh();
        $this->assertNotNull($schedules[0]->purchase_queue_id);
        $this->assertSame($schedules[0]->purchase_queue_id, $schedules[1]->purchase_queue_id);

        $queue = DB::connection('sakemaru')
            ->table('purchase_create_queue')
            ->where('id', $schedules[0]->purchase_queue_id)
            ->first();
        $payload = json_decode($queue->items, true);

        $this->assertSame($this->slipNumber, $queue->slip_number);
        $this->assertSame($this->slipNumber, $payload['slip_number']);
        $this->assertCount(2, $payload['details']);
        $this->assertSame([3, 2], collect($payload['details'])->pluck('quantity')->all());
    }

    private function contractorId(string $code): int
    {
        return (int) (Contractor::where('code', $code)->value('id') ?? $code);
    }

    private function newEosSlipNumber(): string
    {
        return '99'.now()->format('y').'10'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);
    }

    private function newCandidateId(): int
    {
        return random_int(900000000, 999999999);
    }

    private function createSlipAssignment(array $candidateIds, ?int $documentId = null): WmsOrderSlipNumberAssignment
    {
        return WmsOrderSlipNumberAssignment::create([
            'wms_order_jx_document_id' => $documentId,
            'document_type' => 'EOS_ORDER',
            'slip_number' => $this->slipNumber,
            'store_code' => substr($this->slipNumber, 0, 2),
            'year_code' => (int) substr($this->slipNumber, 2, 2),
            'sequence_no' => (int) substr($this->slipNumber, 6, 5),
            'b_record_sequence' => 1,
            'status' => WmsOrderSlipNumberAssignment::STATUS_TRANSMITTED,
            'order_candidate_ids' => $candidateIds,
        ]);
    }

    private function createIncomingSchedule(
        int $itemId,
        int $expectedQuantity,
        ?int $contractorId = null,
        ?string $searchCode = null,
        OrderSource $orderSource = OrderSource::AUTO,
        IncomingScheduleStatus $status = IncomingScheduleStatus::PENDING,
        int $shortageQuantity = 0,
        ?float $unitPrice = null,
        ?float $casePrice = null,
        ?int $orderCandidateId = null,
        ?string $slipNumber = null,
        QuantityType $quantityType = QuantityType::PIECE,
    ): WmsOrderIncomingSchedule {
        return WmsOrderIncomingSchedule::create([
            'warehouse_id' => 1,
            'item_id' => $itemId,
            'item_code' => (string) $itemId,
            'search_code' => $searchCode,
            'contractor_id' => $contractorId ?? $this->contractorId('1106'),
            'supplier_id' => null,
            'order_source' => $orderSource->value,
            'order_candidate_id' => $orderCandidateId,
            'slip_number' => $slipNumber ?? $this->slipNumber,
            'expected_quantity' => $expectedQuantity,
            'received_quantity' => 0,
            'quantity_type' => $quantityType->value,
            'order_date' => '2026-07-19',
            'expected_arrival_date' => '2026-07-20',
            'expiration_date' => '2026-08-20',
            'status' => $status->value,
            'is_receive_matched' => false,
            'shortage_quantity' => $shortageQuantity,
            'unit_price' => $unitPrice,
            'case_price' => $casePrice,
        ]);
    }

    private function buildSentJxContent(
        string $slipNumber,
        int $lineNumber,
        string $janCode,
        string $itemCode,
        int $packQuantity,
        int $caseQuantity,
        int $pieceQuantity,
        int $unitPriceRaw
    ): string {
        $b = str_repeat(' ', 128);
        $b[0] = 'B';
        $b = $this->putField($b, 3, 11, $slipNumber);

        $d = str_repeat(' ', 128);
        $d[0] = 'D';
        $d = $this->putField($d, 3, 2, str_pad((string) $lineNumber, 2, '0', STR_PAD_LEFT));
        $d = $this->putField($d, 5, 64, 'TEST ITEM');
        $d = $this->putField($d, 69, 13, $janCode);
        $d = $this->putField($d, 82, 6, $itemCode);
        $d = $this->putField($d, 88, 6, str_pad((string) $packQuantity, 6, '0', STR_PAD_LEFT));
        $d = $this->putField($d, 94, 7, str_pad((string) $caseQuantity, 7, '0', STR_PAD_LEFT));
        $d = $this->putField($d, 101, 7, str_pad((string) $pieceQuantity, 7, '0', STR_PAD_LEFT));
        $d = $this->putField($d, 108, 10, str_pad((string) $unitPriceRaw, 10, '0', STR_PAD_LEFT));

        return $b.$d;
    }

    private function putField(string $record, int $offset, int $length, string $value): string
    {
        return substr_replace($record, str_pad(substr($value, 0, $length), $length), $offset, $length);
    }
}
