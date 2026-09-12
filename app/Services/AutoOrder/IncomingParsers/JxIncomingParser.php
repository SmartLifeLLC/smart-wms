<?php

namespace App\Services\AutoOrder\IncomingParsers;

use App\Contracts\IncomingFormatParserInterface;
use App\Models\WmsIncomingReceivedDetail;
use App\Models\WmsIncomingReceivedFile;
use App\Models\WmsIncomingReceivedSlip;
use App\Services\AutoOrder\IncomingReceivedQuantityNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * JX納品伝票パーサー
 *
 * 128バイト固定長・Shift_JISデータをパースして3層テーブルに保存
 */
class JxIncomingParser implements IncomingFormatParserInterface
{
    private const RECORD_LENGTH = 128;

    private const ENCODING = 'SJIS-win';

    public function parse(string $content, string $filename, ?int $contractorId = null): WmsIncomingReceivedFile
    {
        return $this->parseWithMetadata($content, $filename, $contractorId);
    }

    public function parseWithMetadata(
        string $content,
        string $filename,
        ?int $contractorId = null,
        array $metadata = []
    ): WmsIncomingReceivedFile {
        return DB::connection('sakemaru')->transaction(function () use ($content, $filename, $contractorId, $metadata) {
            // 改行を除去（FINET ヘッダー後の改行対応）
            $content = str_replace(["\r\n", "\r", "\n"], '', $content);

            // FINETラッパー判定・除去
            $hasFinet = $this->hasFiNetHeader($content);
            $finetData = [];

            if ($hasFinet) {
                $finetData = $this->parseFinetHeader($content);
                // 先頭128バイト（FINETヘッダー）と末尾128バイト（FINETフッター）を除去
                $content = substr($content, self::RECORD_LENGTH, -self::RECORD_LENGTH);
            }

            // 128バイトずつレコード分割
            $records = str_split($content, self::RECORD_LENGTH);

            // Aレコードを探してファイルレコード作成
            $fileRecord = $this->createFileRecord($records, $filename, $contractorId, $hasFinet, $finetData, $metadata);

            // B/Dレコードをパースして保存
            $this->parseSlipsAndDetails($records, $fileRecord);

            return $fileRecord->fresh();
        });
    }

    /**
     * FINETヘッダー有無判定
     */
    private function hasFiNetHeader(string $content): bool
    {
        return strlen($content) >= self::RECORD_LENGTH && $content[0] === '1';
    }

    /**
     * FINETヘッダーパース
     */
    private function parseFinetHeader(string $content): array
    {
        $header = substr($content, 0, self::RECORD_LENGTH);

        return [
            'sender_code' => $this->trimField($header, 66, 12),      // 提供企業CD [67-78]
            'sender_name' => $this->convertToUtf8(
                $this->trimField($header, 90, 15)                     // 提供企業名 [91-105]
            ),
            'record_count' => (int) $this->trimField($header, 115, 6), // 送信データ件数 [116-121]
        ];
    }

    /**
     * ファイルレコード作成（Aレコードから情報取得）
     */
    private function createFileRecord(
        array $records,
        string $filename,
        ?int $contractorId,
        bool $hasFinet,
        array $finetData,
        array $metadata = []
    ): WmsIncomingReceivedFile {
        // Aレコードを探す
        $aRecord = null;
        foreach ($records as $record) {
            if (strlen($record) >= 1 && $record[0] === 'A') {
                $aRecord = $record;
                break;
            }
        }

        $fileData = [
            'contractor_id' => $contractorId,
            'filename' => $filename,
            'format_type' => 'JX',
            'status' => 'PENDING',
            'has_finet_wrapper' => $hasFinet,
        ];

        if ($aRecord) {
            // A record layout は送信・受信共通（送信レイアウトと同一）
            // 1:rec_type(1) 2-3:data_type(2) 4-11:processing_date(8) 12-17:processing_time(6)
            // 18-25:sender_code(8) 26-33:receiver_code(8) 34-39:record_count(6)
            // 40-45:slip_count(6) 46-60:company_name(15) 61-128:filler(68)
            $fileData['a_data_type'] = $this->trimField($aRecord, 1, 2);           // [2-3]
            $fileData['a_send_receive_type'] = null;
            $fileData['a_created_date'] = $this->trimField($aRecord, 3, 8);        // [4-11] YYYYMMDD
            $fileData['a_created_time'] = $this->trimField($aRecord, 11, 6);       // [12-17]
            $fileData['a_record_count'] = (int) $this->trimField($aRecord, 33, 6); // [34-39]
            $fileData['a_slip_count'] = (int) $this->trimField($aRecord, 39, 6);   // [40-45]
            $fileData['a_company_name'] = $this->convertToUtf8(
                $this->trimField($aRecord, 45, 15)                                 // [46-60]
            );
        }

        if ($hasFinet) {
            $fileData['finet_sender_code'] = $finetData['sender_code'] ?? null;
            $fileData['finet_sender_name'] = $finetData['sender_name'] ?? null;
            $fileData['finet_record_count'] = $finetData['record_count'] ?? null;
        }

        return WmsIncomingReceivedFile::create(array_merge(
            $fileData,
            WmsIncomingReceivedFile::onlyExistingColumns($metadata)
        ));
    }

    /**
     * B/Dレコードをパース＆DB保存
     */
    private function parseSlipsAndDetails(array $records, WmsIncomingReceivedFile $fileRecord): void
    {
        $currentSlip = null;
        $slipCount = 0;
        $detailCount = 0;

        foreach ($records as $record) {
            if (strlen($record) < 1) {
                continue;
            }

            $type = $record[0];

            if ($type === 'B') {
                $currentSlip = $this->parseBRecord($record, $fileRecord);
                $slipCount++;
            } elseif ($type === 'D' && $currentSlip) {
                $this->parseDRecord($record, $currentSlip, $fileRecord);
                $detailCount++;
            }
            // Aレコードはスキップ（既にファイルレコードで処理済み）
        }

        // ファイルレコードの集計値を更新
        $fileRecord->update([
            'parsed_slip_count' => $slipCount,
            'parsed_detail_count' => $detailCount,
        ]);

        Log::info('[JxIncomingParser] パース完了', [
            'file_id' => $fileRecord->id,
            'slip_count' => $slipCount,
            'detail_count' => $detailCount,
        ]);
    }

    /**
     * Bレコードパース → wms_incoming_received_slips
     */
    private function parseBRecord(string $record, WmsIncomingReceivedFile $fileRecord): WmsIncomingReceivedSlip
    {
        // 伝票番号[4-14] 11桁数字のみ（DB保存フォーマットと同一）
        $rawSlipNumber = $this->trimField($record, 3, 11);
        $slipNumber = $this->formatSlipNumber($rawSlipNumber);

        return WmsIncomingReceivedSlip::create([
            'received_file_id' => $fileRecord->id,
            'slip_number' => $slipNumber,
            'match_status' => 'UNMATCHED',
            'b_data_type' => $this->trimField($record, 1, 2),         // [2-3]
            'b_shop_code' => $this->trimField($record, 14, 4),        // [15-18]
            'b_category_code' => $this->trimField($record, 18, 3),    // [19-21]
            'b_slip_type' => $this->trimField($record, 21, 2),        // [22-23]
            'b_order_date' => $this->trimField($record, 23, 6),       // [24-29]
            'b_delivery_date' => $this->trimField($record, 29, 6),    // [30-35]
            'b_delivery_route' => $this->trimField($record, 35, 3),   // [36-38]
            'b_contractor_code' => $this->trimField($record, 38, 4),  // [39-42]
            'b_shop_name' => $this->convertToUtf8(
                $this->trimField($record, 42, 15)                      // [43-57]
            ),
            'b_delivery_place' => $this->convertToUtf8(
                $this->trimField($record, 57, 10)                      // [58-67]
            ),
            'b_note' => $this->convertToUtf8(
                $this->trimField($record, 67, 25)                      // [68-92]
            ),
            'b_direct_type' => $this->trimField($record, 92, 2),      // [93-94]
        ]);
    }

    /**
     * Dレコードパース → wms_incoming_received_details
     */
    private function parseDRecord(
        string $record,
        WmsIncomingReceivedSlip $slip,
        WmsIncomingReceivedFile $fileRecord
    ): WmsIncomingReceivedDetail {
        // 実データは送信レイアウトと同一（品名64バイト）
        // 1:rec_type(1) 2-3:data_type(2) 4-5:line_no(2) 6-69:product_name(64)
        // 70-82:jan_code(13) 83-88:item_code(6) 89-94:pack_qty(6)
        // 95-101:case_qty(7) 102-108:piece_qty(7) 109-118:unit_price(10) 119-128:filler(10)
        $caseQty = (int) $this->trimField($record, 94, 7);    // [95-101]
        $pieceQty = (int) $this->trimField($record, 101, 7);  // [102-108]
        $packQty = (int) $this->trimField($record, 88, 6);    // [89-94]

        $janCode = $this->trimField($record, 69, 13);         // [70-82]
        $itemCode = $this->trimField($record, 82, 6);         // [83-88]

        $totalQuantity = $this->calculateTotalQuantity($caseQty, $pieceQty, $packQty, $janCode);

        // 欠品: ケース0 かつ バラ0
        $isShortage = ($caseQty === 0 && $pieceQty === 0);

        $detail = WmsIncomingReceivedDetail::create([
            'received_slip_id' => $slip->id,
            'received_file_id' => $fileRecord->id,
            'd_data_type' => $this->trimField($record, 1, 2),          // [2-3]
            'd_line_number' => (int) $this->trimField($record, 3, 2),  // [4-5]
            'd_product_name' => $this->convertToUtf8(
                $this->trimField($record, 5, 64)                        // [6-69] 64バイト
            ),
            'd_jan_code' => $janCode,
            'd_item_code' => $itemCode,
            'd_pack_quantity' => $packQty,
            'd_case_quantity' => $caseQty,
            'd_piece_quantity' => $pieceQty,
            'd_unit_price' => (int) $this->trimField($record, 108, 10), // [109-118]
            'd_total_pieces' => null,
            'd_note' => null,
            'd_amount' => null,
            'total_quantity' => $totalQuantity,
            'is_shortage' => $isShortage,
        ]);

        // 伝票のdetail_countとshortage_countを更新
        $slip->increment('detail_count');
        if ($isShortage) {
            $slip->increment('shortage_count');
        }

        return $detail;
    }

    private function calculateTotalQuantity(int $caseQty, int $pieceQty, int $packQty, string $janCode): int
    {
        return app(IncomingReceivedQuantityNormalizer::class)
            ->normalize($caseQty, $pieceQty, $packQty, $janCode);
    }

    /**
     * SJIS文字列からフィールド切り出し（バイト位置、0-indexed）
     */
    private function trimField(string $record, int $offset, int $length): string
    {
        return trim(substr($record, $offset, $length));
    }

    /**
     * SJIS → UTF-8変換
     */
    private function convertToUtf8(string $sjisStr): string
    {
        if ($sjisStr === '') {
            return '';
        }

        $utf8 = mb_convert_encoding($sjisStr, 'UTF-8', self::ENCODING);

        return $utf8 !== false ? $utf8 : $sjisStr;
    }

    /**
     * 伝票番号を正規化
     *
     * JX Bレコードの11桁をそのまま使用（ハイフンなし、数字のみ）
     * 旧形式のハイフン付きデータが来た場合はハイフンを除去
     */
    private function formatSlipNumber(string $raw): string
    {
        return str_replace('-', '', $raw);
    }
}
