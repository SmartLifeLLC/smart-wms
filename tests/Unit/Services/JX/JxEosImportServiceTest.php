<?php

namespace Tests\Unit\Services\JX;

use App\Models\WmsJxEosDocument;
use App\Models\WmsJxEosImportBatch;
use App\Models\WmsJxEosLine;
use App\Models\WmsJxEosSlip;
use App\Models\WmsJxTransmissionLog;
use App\Services\AutoOrder\IncomingReceivedQuantityNormalizer;
use App\Services\JX\Eos\JxEosImportService;
use Tests\TestCase;

class JxEosImportServiceTest extends TestCase
{
    private ?string $messageId = null;

    protected function tearDown(): void
    {
        if ($this->messageId) {
            $logIds = WmsJxTransmissionLog::query()
                ->where('message_id', $this->messageId)
                ->pluck('id');

            if ($logIds->isNotEmpty()) {
                $batchIds = WmsJxEosImportBatch::query()
                    ->whereIn('wms_jx_transmission_log_id', $logIds->all())
                    ->pluck('id');

                if ($batchIds->isNotEmpty()) {
                    WmsJxEosLine::query()
                        ->whereIn('import_batch_id', $batchIds->all())
                        ->delete();
                    WmsJxEosSlip::query()
                        ->whereIn('import_batch_id', $batchIds->all())
                        ->delete();
                    WmsJxEosDocument::query()
                        ->whereIn('import_batch_id', $batchIds->all())
                        ->delete();
                }

                WmsJxEosImportBatch::query()
                    ->whereIn('wms_jx_transmission_log_id', $logIds->all())
                    ->delete();
                WmsJxTransmissionLog::query()
                    ->whereIn('id', $logIds->all())
                    ->delete();
            }
        }

        parent::tearDown();
    }

    public function test_import_uses_received_price_unit_quantity_for_amount(): void
    {
        $this->app->instance(IncomingReceivedQuantityNormalizer::class, new class extends IncomingReceivedQuantityNormalizer
        {
            public function normalize(int $caseQuantity, int $pieceQuantity, int $packQuantity, ?string $janCode): int
            {
                if ($janCode === '9999998123456') {
                    return 72;
                }

                return $packQuantity > 0
                    ? ($caseQuantity * $packQuantity) + $pieceQuantity
                    : $pieceQuantity;
            }
        });

        $log = $this->createReceiveLog();

        app(JxEosImportService::class)->importFromContent(
            $log,
            implode('', [
                $this->aRecord(recordCount: 5, slipCount: 1),
                $this->bRecord('91461015496'),
                $this->dRecord(lineNo: 1, janCode: '9999998123457', itemCode: '979178', packQuantity: 24, caseQuantity: 2, pieceQuantity: 0, unitPriceRaw: 230400),
                $this->dRecord(lineNo: 2, janCode: '9999998123456', itemCode: '979177', packQuantity: 4, caseQuantity: 0, pieceQuantity: 12, unitPriceRaw: 77400),
            ]),
            'local',
            'local:jx/eos-import-amount-test.dat',
        );

        $lines = WmsJxEosLine::query()
            ->where('wms_jx_transmission_log_id', $log->id)
            ->orderBy('line_number')
            ->get();

        $this->assertCount(2, $lines);

        $this->assertSame(48, $lines[0]->total_quantity);
        $this->assertEquals('2304.00', $lines[0]->unit_price);
        $this->assertEquals('4608.00', $lines[0]->amount);

        $this->assertSame(72, $lines[1]->total_quantity);
        $this->assertEquals('774.00', $lines[1]->unit_price);
        $this->assertEquals('9288.00', $lines[1]->amount);
    }

    private function createReceiveLog(): WmsJxTransmissionLog
    {
        $this->messageId = 'test-jx-eos-import-'.str_replace('.', '', (string) microtime(true));

        return WmsJxTransmissionLog::create([
            'direction' => WmsJxTransmissionLog::DIRECTION_RECEIVE,
            'environment' => WmsJxTransmissionLog::ENV_TEST,
            'operation_type' => WmsJxTransmissionLog::OPERATION_GET,
            'message_id' => $this->messageId,
            'document_type' => '90',
            'format_type' => 'JX',
            'status' => WmsJxTransmissionLog::STATUS_SUCCESS,
            'data_size' => 512,
            'file_path' => 'local:jx/eos-import-amount-test.dat',
            'http_code' => 200,
            'transmitted_at' => now(),
        ]);
    }

    private function aRecord(int $recordCount, int $slipCount): string
    {
        return $this->record([
            [0, 1, 'A'],
            [1, 2, '02'],
            [3, 8, '20260720'],
            [11, 6, '090000'],
            [17, 8, 'SEND0001'],
            [25, 8, 'RECV0001'],
            [33, 6, str_pad((string) $recordCount, 6, '0', STR_PAD_LEFT)],
            [39, 6, str_pad((string) $slipCount, 6, '0', STR_PAD_LEFT)],
            [45, 15, 'TEST'],
        ]);
    }

    private function bRecord(string $slipNumber): string
    {
        return $this->record([
            [0, 1, 'B'],
            [1, 2, '02'],
            [3, 11, $slipNumber],
            [14, 4, '0001'],
            [18, 3, '001'],
            [21, 2, '02'],
            [23, 6, '260719'],
            [29, 6, '260720'],
            [35, 3, '001'],
            [38, 4, '1106'],
            [42, 15, 'TESTSHOP'],
            [57, 10, 'TEST'],
            [92, 1, '0'],
            [93, 11, '00000000001'],
            [104, 1, '0'],
        ]);
    }

    private function dRecord(
        int $lineNo,
        string $janCode,
        string $itemCode,
        int $packQuantity,
        int $caseQuantity,
        int $pieceQuantity,
        int $unitPriceRaw,
    ): string {
        return $this->record([
            [0, 1, 'D'],
            [1, 2, '02'],
            [3, 2, str_pad((string) $lineNo, 2, '0', STR_PAD_LEFT)],
            [5, 64, 'TEST ITEM'],
            [69, 13, $janCode],
            [82, 6, $itemCode],
            [88, 6, str_pad((string) $packQuantity, 6, '0', STR_PAD_LEFT)],
            [94, 7, str_pad((string) $caseQuantity, 7, '0', STR_PAD_LEFT)],
            [101, 7, str_pad((string) $pieceQuantity, 7, '0', STR_PAD_LEFT)],
            [108, 10, str_pad((string) $unitPriceRaw, 10, '0', STR_PAD_LEFT)],
        ]);
    }

    private function record(array $fields): string
    {
        $record = str_repeat(' ', 128);

        foreach ($fields as [$offset, $length, $value]) {
            $record = substr_replace($record, substr(str_pad($value, $length), 0, $length), $offset, $length);
        }

        return $record;
    }
}
