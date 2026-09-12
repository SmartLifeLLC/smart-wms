<?php

namespace Tests\Feature\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\OrderChannel;
use App\Enums\AutoOrder\OrderDataFileChannel;
use App\Enums\AutoOrder\OrderDataFileStatus;
use App\Enums\AutoOrder\OrderEntrySource;
use App\Enums\AutoOrder\TransmissionType;
use App\Enums\QuantityType;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderDataFile;
use App\Models\WmsOrderIncomingSchedule;
use App\Models\WmsOrderJxDocument;
use App\Services\AutoOrder\OrderOutputQuantityResolver;
use App\Services\AutoOrder\OrderRegistrationSearchService;
use App\Services\AutoOrder\OrderRegistrationService;
use App\Services\AutoOrder\OrderTransmissionService;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NewExternalOrderRegistrationIntegrationTest extends TestCase
{
    private bool $sakemaruTransactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-17 10:15:00');
        Storage::fake('s3');

        try {
            DB::connection('sakemaru')->beginTransaction();
            $this->sakemaruTransactionStarted = true;
        } catch (\Throwable $e) {
            $this->markTestSkipped('sakemaru DBに接続できないためスキップ: '.$e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->sakemaruTransactionStarted) {
            DB::connection('sakemaru')->rollBack();
        }

        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_new_external_order_registration_keeps_confirmed_data_pdf_and_jx_generation_compatible(): void
    {
        $warehouseId = $this->warehouseIdByCode('91');
        $this->assertNotNull($warehouseId, '倉庫CD 91 のマスタが必要です。');

        $jxRows = $this->findJxOrderRows($warehouseId, 2);
        $faxRow = $this->findFaxOrderRow($warehouseId);

        if ($jxRows->count() < 2 || ! $faxRow) {
            $this->markTestSkipped('結合テストに必要なJX対象2件/FAX対象1件の商品発注先マスタがありません。');
        }

        $expectedArrivalDate = '2026-08-24';
        $userId = (int) (DB::connection('sakemaru')->table('users')->value('id') ?? 1);
        $lines = [
            $this->lineFromRow($jxRows[0], $warehouseId, OrderChannel::EOS, QuantityType::CASE, 2, $expectedArrivalDate),
            $this->lineFromRow($jxRows[1], $warehouseId, OrderChannel::EOS, QuantityType::PIECE, 7, $expectedArrivalDate),
            $this->lineFromRow($faxRow, $warehouseId, OrderChannel::FAX, QuantityType::CASE, 1, $expectedArrivalDate),
        ];

        $result = app(OrderRegistrationService::class)->register(
            warehouseId: $warehouseId,
            lines: $lines,
            userId: $userId,
            communicationNotes: '結合テスト通信欄'
        );

        $this->assertCount(3, $result['candidate_ids']);
        $this->assertSame(3, $result['incoming_schedule_count']);
        $this->assertTrue($result['data_file_result']['success'], json_encode($result['data_file_result']['errors'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(2, $result['data_file_result']['total_files']);

        $candidates = WmsOrderCandidate::with(['item', 'contractor', 'warehouse'])
            ->whereIn('id', $result['candidate_ids'])
            ->get()
            ->keyBy('id');

        $this->assertCount(3, $candidates);
        $this->assertSame($result['batch_code'], $candidates->first()->batch_code);

        foreach ($candidates as $candidate) {
            $this->assertSame(CandidateStatus::CONFIRMED, $candidate->status);
            $this->assertSame($warehouseId, (int) $candidate->warehouse_id);
            $this->assertSame($expectedArrivalDate, $candidate->expected_arrival_date->toDateString());
            $this->assertSame($expectedArrivalDate, $candidate->original_arrival_date->toDateString());
            $this->assertNotEmpty($candidate->ordering_code);

            $schedule = WmsOrderIncomingSchedule::where('order_candidate_id', $candidate->id)->first();
            $this->assertNotNull($schedule, "candidate_id={$candidate->id} の入荷予定がありません。");
            $this->assertSame($candidate->item_id, $schedule->item_id);
            $this->assertSame($candidate->warehouse_id, $schedule->warehouse_id);
            $this->assertSame($candidate->contractor_id, $schedule->contractor_id);
            $this->assertSame($candidate->supplier_id, $schedule->supplier_id);
            $this->assertSame((int) $candidate->order_quantity, (int) $schedule->expected_quantity);
            $this->assertSame($candidate->quantity_type, $schedule->quantity_type);
            $this->assertSame($candidate->order_channel, $schedule->order_channel);
            $this->assertSame($expectedArrivalDate, $schedule->expected_arrival_date->toDateString());
        }

        $eosCandidates = $candidates->filter(fn (WmsOrderCandidate $candidate): bool => $candidate->order_channel === OrderChannel::EOS)->values();
        $faxCandidate = $candidates->first(fn (WmsOrderCandidate $candidate): bool => $candidate->order_channel === OrderChannel::FAX);

        $this->assertCount(2, $eosCandidates);
        $this->assertNotNull($faxCandidate);

        $dataFiles = WmsOrderDataFile::where('batch_code', $result['batch_code'])->get();
        $this->assertCount(2, $dataFiles);

        $eosDataFile = $dataFiles->first(fn (WmsOrderDataFile $file): bool => $file->order_channel === OrderDataFileChannel::EOS);
        $faxDataFile = $dataFiles->first(fn (WmsOrderDataFile $file): bool => $file->order_channel === OrderDataFileChannel::FAX);
        $this->assertNotNull($eosDataFile);
        $this->assertNotNull($faxDataFile);

        $this->assertTrue($eosDataFile->show_eos_stamp);
        $this->assertTrue($eosDataFile->isEosControlPdf());
        $this->assertFalse($faxDataFile->show_eos_stamp);
        $this->assertFalse($faxDataFile->isEosControlPdf());
        $this->assertEqualsCanonicalizing($eosCandidates->pluck('id')->map(fn ($id) => (int) $id)->all(), $eosDataFile->candidate_ids);
        $this->assertEquals([(int) $faxCandidate->id], array_map('intval', $faxDataFile->candidate_ids));
        $this->assertNotEmpty($eosDataFile->fax_file_path);
        $this->assertNotEmpty($faxDataFile->fax_file_path);
        Storage::disk('s3')->assertExists($eosDataFile->fax_file_path);
        Storage::disk('s3')->assertExists($faxDataFile->fax_file_path);

        $eosDataFile->markAsFaxDownloaded($userId);
        $faxDataFile->markAsFaxDownloaded($userId);
        $this->assertNull($eosDataFile->refresh()->fax_downloaded_at, 'EOS控えはFAX発行済みにしてはいけません。');
        $this->assertSame(OrderDataFileStatus::GENERATED, $eosDataFile->status);
        $this->assertNotNull($faxDataFile->refresh()->fax_downloaded_at, 'FAX発注はFAX発行済みにする必要があります。');
        $this->assertSame(OrderDataFileStatus::DOWNLOADED, $faxDataFile->status);

        $jxResult = app(OrderTransmissionService::class)->generateJxFilesForCandidateIds($result['candidate_ids']);
        $this->assertTrue($jxResult['success'], json_encode($jxResult['errors'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(3, $jxResult['selected_count']);
        $this->assertSame(2, $jxResult['eligible_count']);
        $this->assertSame(1, $jxResult['excluded_fax_channel']);
        $this->assertSame(2, $jxResult['total_orders']);
        $this->assertNotEmpty($jxResult['files']);

        $candidates->each->refresh();
        foreach ($eosCandidates as $candidate) {
            $this->assertNotNull($candidate->refresh()->wms_order_jx_document_id);
        }
        $this->assertNull($faxCandidate->refresh()->wms_order_jx_document_id);

        $documents = WmsOrderJxDocument::whereIn(
            'id',
            $eosCandidates->pluck('wms_order_jx_document_id')->filter()->unique()->all()
        )->get();
        $this->assertNotEmpty($documents);
        $this->assertContainsOnlyInstancesOf(WmsOrderJxDocument::class, $documents);

        $this->assertJxFilesMatchCandidates($jxResult['files'], $eosCandidates, $expectedArrivalDate);
    }

    public function test_eos_registration_rejects_item_not_linked_to_selected_jx_contractor(): void
    {
        $warehouseId = $this->warehouseIdByCode('91');
        if (! $warehouseId) {
            $this->markTestSkipped('倉庫CD 91 のマスタがありません。');
        }

        $jxRows = $this->findJxOrderRows($warehouseId, 1);
        $unlinkedFaxRow = $this->findFaxOrderRowNotLinkedToContractor($warehouseId, (int) ($jxRows->first()->contractor_id ?? 0));

        if ($jxRows->isEmpty() || ! $unlinkedFaxRow) {
            $this->markTestSkipped('EOS未紐付き検証に必要なマスタがありません。');
        }

        $line = $this->lineFromRow(
            row: $unlinkedFaxRow,
            warehouseId: $warehouseId,
            channel: OrderChannel::EOS,
            quantityType: QuantityType::CASE,
            orderQuantity: 1,
            expectedArrivalDate: '2026-08-24'
        );
        $line['contractor_id'] = (int) $jxRows->first()->contractor_id;
        $line['supplier_id'] = (int) ($jxRows->first()->supplier_id ?? 0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('商品は選択した発注先に紐づいていないためEOS発注できません');

        app(OrderRegistrationService::class)->register(
            warehouseId: $warehouseId,
            lines: [$line],
            userId: (int) (DB::connection('sakemaru')->table('users')->value('id') ?? 1)
        );
    }

    public function test_eos_registration_accepts_item_contractor_selected_from_another_incoming_warehouse(): void
    {
        $deliveryWarehouseId = $this->warehouseIdByCode('11');
        $masterWarehouseId = $this->warehouseIdByCode('91');

        if (! $deliveryWarehouseId || ! $masterWarehouseId) {
            $this->markTestSkipped('倉庫CD 11 / 91 のマスタが必要です。');
        }

        $searchService = app(OrderRegistrationSearchService::class);
        $deliveryIncomingWarehouseId = $searchService->incomingWarehouseId($deliveryWarehouseId);
        $masterIncomingWarehouseId = $searchService->incomingWarehouseId($masterWarehouseId);
        $jxContractorIds = $this->representativeJxContractorIds();

        if ($jxContractorIds === []) {
            $this->markTestSkipped('JX対象発注先マスタがありません。');
        }

        $row = (clone $this->itemContractorBaseQuery($masterIncomingWarehouseId))
            ->whereIn('ic.contractor_id', $jxContractorIds)
            ->whereNotNull('isi.search_string')
            ->whereNotExists(function ($query) use ($deliveryIncomingWarehouseId): void {
                $query
                    ->selectRaw('1')
                    ->from('item_contractors as delivery_ic')
                    ->whereColumn('delivery_ic.item_id', 'ic.item_id')
                    ->whereColumn('delivery_ic.contractor_id', 'ic.contractor_id')
                    ->where('delivery_ic.warehouse_id', $deliveryIncomingWarehouseId);
            })
            ->orderBy('i.code')
            ->first();

        if (! $row) {
            $this->markTestSkipped('別倉庫の商品発注先マスタを使うEOS検証に必要なマスタがありません。');
        }

        $line = $this->lineFromRow(
            row: $row,
            warehouseId: $deliveryWarehouseId,
            channel: OrderChannel::EOS,
            quantityType: QuantityType::CASE,
            orderQuantity: 1,
            expectedArrivalDate: '2026-08-24'
        );
        $line['item_contractor_warehouse_id'] = $masterIncomingWarehouseId;

        $result = app(OrderRegistrationService::class)->register(
            warehouseId: $deliveryWarehouseId,
            lines: [$line],
            userId: (int) (DB::connection('sakemaru')->table('users')->value('id') ?? 1)
        );

        $this->assertCount(1, $result['candidate_ids']);

        $candidate = WmsOrderCandidate::query()->find($result['candidate_ids'][0]);
        $this->assertNotNull($candidate);
        $this->assertSame($deliveryWarehouseId, (int) $candidate->warehouse_id);
        $this->assertSame((int) $row->item_id, (int) $candidate->item_id);
        $this->assertSame((int) $row->contractor_id, (int) $candidate->contractor_id);
        $this->assertSame((int) $row->supplier_id, (int) $candidate->supplier_id);
        $this->assertSame(OrderChannel::EOS, $candidate->order_channel);
    }

    private function assertJxFilesMatchCandidates(array $files, Collection $eosCandidates, string $expectedArrivalDate): void
    {
        $recordsByOrderingCode = [];
        $expectedDeliveryDate = Carbon::parse($expectedArrivalDate)->format('ymd');

        foreach ($files as $file) {
            Storage::disk('s3')->assertExists($file['s3_path']);
            $content = Storage::disk('s3')->get($file['s3_path']);
            $records = $this->splitJxRecords($content);

            $this->assertSame((int) $file['record_count'], count($records));
            foreach ($records as $record) {
                $this->assertSame(128, strlen($record));
            }

            $bRecords = array_values(array_filter($records, fn (string $record): bool => $record[0] === 'B'));
            $dRecords = array_values(array_filter($records, fn (string $record): bool => $record[0] === 'D'));

            $this->assertNotEmpty($bRecords);
            $this->assertSame((int) $file['order_count'], count($dRecords));

            foreach ($bRecords as $bRecord) {
                $this->assertSame($expectedDeliveryDate, substr($bRecord, 29, 6));
            }

            foreach ($dRecords as $dRecord) {
                $recordsByOrderingCode[trim(substr($dRecord, 69, 13))] = [
                    'capacity' => (int) substr($dRecord, 88, 6),
                    'case_quantity' => (int) substr($dRecord, 94, 7),
                    'piece_quantity' => (int) substr($dRecord, 101, 7),
                ];
            }
        }

        $resolver = app(OrderOutputQuantityResolver::class);
        foreach ($eosCandidates as $candidate) {
            $candidate->loadMissing('item');
            $expected = $resolver->resolve($candidate);
            $orderingCode = $expected['ordering_code'];

            $this->assertArrayHasKey($orderingCode, $recordsByOrderingCode);
            $this->assertSame((int) $expected['display_capacity'], $recordsByOrderingCode[$orderingCode]['capacity']);
            $this->assertSame((int) $expected['case_quantity'], $recordsByOrderingCode[$orderingCode]['case_quantity']);
            $this->assertSame((int) $expected['piece_quantity'], $recordsByOrderingCode[$orderingCode]['piece_quantity']);
        }
    }

    /**
     * @return array<int, string>
     */
    private function splitJxRecords(string $content): array
    {
        $content = rtrim($content, "\r\n");
        $records = str_split($content, 128);

        $this->assertNotEmpty($records);

        return $records;
    }

    private function warehouseIdByCode(string $code): ?int
    {
        $id = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('code', $code)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function findJxOrderRows(int $warehouseId, int $limit): Collection
    {
        $contractorIds = $this->representativeJxContractorIds();
        if ($contractorIds === []) {
            return collect();
        }

        $contractorId = (clone $this->itemContractorBaseQuery($warehouseId))
            ->select('ic.contractor_id')
            ->whereIn('ic.contractor_id', $contractorIds)
            ->whereNotNull('isi.search_string')
            ->groupBy('ic.contractor_id')
            ->havingRaw('COUNT(*) >= ?', [$limit])
            ->orderByRaw('MIN(CAST(c.code AS UNSIGNED))')
            ->value('ic.contractor_id');

        if (! $contractorId) {
            return collect();
        }

        return (clone $this->itemContractorBaseQuery($warehouseId))
            ->where('ic.contractor_id', (int) $contractorId)
            ->whereNotNull('isi.search_string')
            ->orderBy('i.code')
            ->limit($limit)
            ->get();
    }

    private function findFaxOrderRow(int $warehouseId): ?object
    {
        $jxContractorIds = $this->representativeJxContractorIds();

        return (clone $this->itemContractorBaseQuery($warehouseId))
            ->when($jxContractorIds !== [], fn (QueryBuilder $query) => $query->whereNotIn('ic.contractor_id', $jxContractorIds))
            ->orderByRaw('CAST(c.code AS UNSIGNED)')
            ->orderBy('i.code')
            ->first();
    }

    private function findFaxOrderRowNotLinkedToContractor(int $warehouseId, int $contractorId): ?object
    {
        if ($contractorId < 1) {
            return null;
        }

        return (clone $this->itemContractorBaseQuery($warehouseId))
            ->where('ic.contractor_id', '!=', $contractorId)
            ->whereNotExists(function ($query) use ($warehouseId, $contractorId): void {
                $query
                    ->selectRaw('1')
                    ->from('item_contractors as linked_ic')
                    ->whereColumn('linked_ic.item_id', 'ic.item_id')
                    ->where('linked_ic.warehouse_id', $warehouseId)
                    ->where('linked_ic.contractor_id', $contractorId);
            })
            ->orderByRaw('CAST(c.code AS UNSIGNED)')
            ->orderBy('i.code')
            ->first();
    }

    private function itemContractorBaseQuery(int $warehouseId): QueryBuilder
    {
        return DB::connection('sakemaru')
            ->table('item_contractors as ic')
            ->join('items as i', 'i.id', '=', 'ic.item_id')
            ->join('contractors as c', 'c.id', '=', 'ic.contractor_id')
            ->leftJoin('item_search_information as isi', function ($join): void {
                $join
                    ->on('isi.item_id', '=', 'i.id')
                    ->where('isi.is_active', true)
                    ->where('isi.is_used_for_ordering', true);
            })
            ->where('ic.warehouse_id', $warehouseId)
            ->where('i.end_of_sale_type', 'NORMAL')
            ->where('i.is_ended', false)
            ->select([
                'ic.item_id',
                'i.code as item_code',
                'i.capacity_case',
                'ic.contractor_id',
                'c.code as contractor_code',
                'ic.supplier_id',
                'ic.purchase_unit',
                'isi.search_string',
            ]);
    }

    /**
     * @return array<int>
     */
    private function representativeJxContractorIds(): array
    {
        return WmsContractorSetting::query()
            ->where('transmission_type', TransmissionType::JX_FINET->value)
            ->where(function ($query): void {
                $query
                    ->whereNull('transmission_contractor_id')
                    ->orWhereColumn('transmission_contractor_id', 'contractor_id');
            })
            ->where(function ($query): void {
                $query
                    ->whereExists(function ($subQuery): void {
                        $subQuery
                            ->selectRaw('1')
                            ->from('wms_order_jx_settings')
                            ->whereColumn('wms_order_jx_settings.id', 'wms_contractor_settings.wms_order_jx_setting_id')
                            ->where('wms_order_jx_settings.is_active', true);
                    })
                    ->orWhereExists(function ($subQuery): void {
                        $subQuery
                            ->selectRaw('1')
                            ->from('wms_order_jx_settings')
                            ->whereColumn('wms_order_jx_settings.contractor_id', 'wms_contractor_settings.contractor_id')
                            ->where('wms_order_jx_settings.is_active', true);
                    });
            })
            ->pluck('contractor_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function lineFromRow(
        object $row,
        int $warehouseId,
        OrderChannel $channel,
        QuantityType $quantityType,
        int $orderQuantity,
        string $expectedArrivalDate
    ): array {
        $orderingCode = $this->normalizeOrderingCode($row->search_string ?? (string) $row->item_code);

        return [
            'warehouse_id' => $warehouseId,
            'item_id' => (int) $row->item_id,
            'contractor_id' => (int) $row->contractor_id,
            'supplier_id' => (int) ($row->supplier_id ?? 0),
            'order_quantity' => $orderQuantity,
            'suggested_quantity' => $orderQuantity,
            'calculated_shortage_qty' => $orderQuantity,
            'quantity_type' => $quantityType->value,
            'purchase_unit' => max(1, (int) ($row->purchase_unit ?? $row->capacity_case ?? 1)),
            'expected_arrival_date' => $expectedArrivalDate,
            'entry_source' => OrderEntrySource::SEARCH->value,
            'order_channel' => $channel->value,
            'search_code' => $row->search_string,
            'ordering_code' => $orderingCode,
            'purchase_unit_price' => 1000,
            'purchase_unit_price_source' => 'integration_test',
        ];
    }

    private function normalizeOrderingCode(?string $code): ?string
    {
        $code = trim((string) $code);

        if ($code === '' || preg_match('/^0+$/', $code) === 1) {
            return null;
        }

        return str_pad($code, 13, '0', STR_PAD_LEFT);
    }
}
