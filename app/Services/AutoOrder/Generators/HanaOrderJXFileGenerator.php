<?php

namespace App\Services\AutoOrder\Generators;

use App\Contracts\OrderFileGeneratorInterface;
use App\Enums\EPurchasePriceType;
use App\Enums\QuantityType;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderIncomingSchedule;
use App\Models\WmsOrderJxSetting;
use App\Services\AutoOrder\LegacyEosSlipNumberService;
use App\Services\JX\JxDataWrapper;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ハナ様向け発注ファイル生成クラス
 *
 * 発注伝送仕様に基づいた固定長ファイルを生成する。
 * - Aレコード: ファイルヘッダ（1ファイルに1件）
 * - Bレコード: 伝票ヘッダ（発注先×倉庫単位）
 * - Dレコード: 伝票明細（Bレコード配下）
 *
 * 各レコードは128バイト固定長、改行なしで連結、末尾にCRLF。
 */
class HanaOrderJXFileGenerator implements OrderFileGeneratorInterface
{
    /**
     * JX送信対象発注先コード
     */
    private const JX_CONTRACTOR_CODES = [1106, 1017, 1202, 1330];

    /**
     * 送信先集約マッピング（発注先コード => 発注データ集約先コード）
     */
    private const TRANSMISSION_MAPPING = [
        1021 => 1106,  // カナカン酒類福井 → カナカン食品
        1029 => 1106,  // カナカンフローズン → カナカン食品
        1068 => 1106,  // カナカン酒類金沢 → カナカン食品
        1126 => 1106,  // カナカン日配 → カナカン食品
        1127 => 1106,  // カナカン菓子 → カナカン食品
    ];

    /**
     * 送信元コード（リカーワールド ハナ）
     */
    private const SENDER_CODE = '01451019';

    /**
     * 社名
     */
    private const COMPANY_NAME = 'ﾘｶｰﾜｰﾙﾄﾞ ﾊﾅ';

    private const ENCODING = 'SJIS-win';

    private const LINE_ENDING = "\r\n";

    private const FILE_EXTENSION = 'dat';

    private const RECORD_LENGTH = 128;

    /**
     * データなし時にAレコードを含めるか
     */
    protected bool $addZeroRecord = true;

    public function __construct(
        private readonly ?LegacyEosSlipNumberService $legacySlipNumberService = null
    ) {}

    /**
     * 発注先コード => 発注先ID のキャッシュ
     */
    private array $contractorIdCache = [];

    /**
     * 商品ID => JANコード のキャッシュ
     */
    private array $janCodeCache = [];

    /**
     * 発注先ID => WmsContractorSetting のキャッシュ
     */
    private array $contractorSettingCache = [];

    /**
     * 商品ID => 現在仕入単価 のキャッシュ
     */
    private array $costPriceCache = [];

    /**
     * 商品ID => 現在仕入バラ単価 のキャッシュ
     */
    private array $purchaseUnitPriceCache = [];

    /**
     * 商品ID => 発注荷姿入数 のキャッシュ（パック発注商品のみ）
     */
    private array $orderingUnitQtyCache = [];

    /**
     * 商品ID:発注コード => 発注コード荷姿情報 のキャッシュ
     */
    private array $orderingCodeInfoCache = [];

    /**
     * 発注ファイルを生成
     */
    public function generate(Collection $orderCandidates): array
    {
        $startTime = microtime(true);
        Log::info('[HanaOrderFileGenerator] generate開始', ['candidate_count' => $orderCandidates->count()]);

        if ($orderCandidates->isEmpty()) {
            Log::info('[HanaOrderFileGenerator] generate完了', [
                'file_count' => 0,
                'total_ms' => round((microtime(true) - $startTime) * 1000),
            ]);

            return [];
        }

        $results = [];

        // 商品IDを取得して仕入単価をプリロード
        $preloadStart = microtime(true);
        $itemIds = $orderCandidates->pluck('item_id')->unique()->toArray();
        $this->preloadCostPrices($itemIds);
        Log::info('[HanaOrderFileGenerator] プリロード完了', [
            'item_count' => count($itemIds),
            'elapsed_ms' => round((microtime(true) - $preloadStart) * 1000),
        ]);

        // 送信先別にグルーピング
        $groupStart = microtime(true);
        $grouped = $this->groupByTransmissionContractor($orderCandidates);
        Log::info('[HanaOrderFileGenerator] グルーピング完了', [
            'group_count' => $grouped->count(),
            'elapsed_ms' => round((microtime(true) - $groupStart) * 1000),
        ]);

        foreach ($grouped as $transmissionContractorCode => $candidates) {
            $fileStart = microtime(true);
            $candidates = $this->filterCandidatesWithOrderingCode($candidates);

            if ($candidates->isEmpty()) {
                Log::warning('[HanaOrderFileGenerator] 発注コード未設定のためファイル生成をスキップ', [
                    'contractor_code' => $transmissionContractorCode,
                ]);

                continue;
            }

            $jxSetting = $this->getJxSettingByContractorCode($transmissionContractorCode);

            $contentStart = microtime(true);
            $slipAssignments = [];
            $content = $this->generateFileContent($transmissionContractorCode, $candidates, $jxSetting, $slipAssignments);
            $contentElapsed = round((microtime(true) - $contentStart) * 1000);

            $filename = $this->generateFilename($transmissionContractorCode);

            $results[] = [
                'contractor_id' => $this->getContractorIdByCode($transmissionContractorCode),
                'contractor_code' => $transmissionContractorCode,
                'jx_setting_id' => $jxSetting?->id,
                'content' => $content,
                'filename' => $filename,
                'encoding' => self::ENCODING,
                'record_count' => $this->countRecords($candidates),
                'order_count' => $candidates->count(),
                'slip_assignments' => $slipAssignments,
            ];

            Log::info('[HanaOrderFileGenerator] ファイル生成完了', [
                'contractor_code' => $transmissionContractorCode,
                'candidate_count' => $candidates->count(),
                'content_ms' => $contentElapsed,
                'total_ms' => round((microtime(true) - $fileStart) * 1000),
            ]);
        }

        Log::info('[HanaOrderFileGenerator] generate完了', [
            'file_count' => count($results),
            'total_ms' => round((microtime(true) - $startTime) * 1000),
        ]);

        return $results;
    }

    /**
     * 発注データ集約先コードでグルーピング
     */
    private function groupByTransmissionContractor(Collection $candidates): Collection
    {
        // 全ての発注先IDを取得してWmsContractorSettingをプリロード
        $contractorIds = $candidates->pluck('contractor_id')->unique()->toArray();
        $this->preloadContractorSettings($contractorIds);

        return $candidates->groupBy(function ($candidate) {
            $contractorCode = $candidate->contractor?->code;

            // キャッシュから設定を取得
            $setting = $this->contractorSettingCache[$candidate->contractor_id] ?? null;
            if ($setting?->transmission_contractor_id) {
                $transmissionContractor = $setting->transmissionContractor;
                if ($transmissionContractor) {
                    return $transmissionContractor->code;
                }
            }

            // マッピングに存在すれば送信先コードを返す
            if (isset(self::TRANSMISSION_MAPPING[$contractorCode])) {
                return self::TRANSMISSION_MAPPING[$contractorCode];
            }

            return $contractorCode;
        });
    }

    /**
     * WmsContractorSettingをプリロード
     */
    private function preloadContractorSettings(array $contractorIds): void
    {
        $settings = WmsContractorSetting::whereIn('contractor_id', $contractorIds)
            ->with('transmissionContractor')
            ->get();

        foreach ($settings as $setting) {
            $this->contractorSettingCache[$setting->contractor_id] = $setting;
        }

        Log::info('[HanaOrderFileGenerator] ContractorSettings プリロード完了', [
            'requested' => count($contractorIds),
            'loaded' => count($settings),
        ]);
    }

    /**
     * 1つのBレコードに含められる最大D行数
     */
    private const MAX_D_RECORDS_PER_B = 6;

    /**
     * B/Dレコードのグルーピングキーを生成
     *
     * 発注先×倉庫に加えて入荷予定日（納品日）を含める。
     * 入荷予定日をキーに含めないと、同一発注先×倉庫でも入荷予定日が異なる候補が
     * 1つのBレコードにまとまり、Bレコードの納品日が先頭候補の日付で全件統一されてしまう。
     * （Bレコードの納品日は generateBRecord() で先頭候補の expected_arrival_date を採用するため）
     */
    private function buildRecordGroupKey($candidate): string
    {
        $arrivalDate = $candidate->expected_arrival_date?->format('Y-m-d') ?? 'no-date';

        return "{$candidate->contractor_id}_{$candidate->warehouse_id}_{$arrivalDate}";
    }

    /**
     * ファイル内容を生成
     *
     * 仕様: レコード間に改行なし、ファイル末尾にのみCRLF
     * 1つのBレコードには最大6行のDレコードまで。超える場合は新しいBレコードを作成。
     */
    private function generateFileContent(
        int $transmissionContractorCode,
        Collection $candidates,
        ?WmsOrderJxSetting $jxSetting = null,
        ?array &$slipAssignments = null
    ): string {
        $records = [];
        $slipAssignments = [];

        // 発注先×倉庫×入荷予定日でグルーピングしてB/Dレコードを生成
        // 入荷予定日をキーに含めないと、同一発注先×倉庫で入荷予定日が異なる候補が
        // 1つのBレコードにまとまり、納品日が先頭候補の日付で全件統一されてしまう。
        $groupedByContractorWarehouse = $candidates->groupBy(function ($candidate) {
            return $this->buildRecordGroupKey($candidate);
        });

        // 6行制限を考慮してBレコード数とDレコード数を計算
        $bCount = 0;
        $dCount = $candidates->count();
        foreach ($groupedByContractorWarehouse as $groupCandidates) {
            // 各グループを6件ずつに分割した場合のBレコード数
            $bCount += (int) ceil($groupCandidates->count() / self::MAX_D_RECORDS_PER_B);
        }

        $totalRecordCount = 1 + $bCount + $dCount; // レコード件数（A + B + D）
        $records[] = $this->generateARecord($transmissionContractorCode, $totalRecordCount, $bCount, $jxSetting);

        $bRecordSeq = 1;
        $usedSlipNumbers = [];
        foreach ($groupedByContractorWarehouse as $groupKey => $groupCandidates) {
            // 6件ずつに分割
            $chunks = $groupCandidates->chunk(self::MAX_D_RECORDS_PER_B);

            foreach ($chunks as $chunk) {
                $firstCandidate = $chunk->first();

                $legacySlip = $this->resolveBRecordSlipNumber($chunk, $usedSlipNumbers);
                $slipNumber = $legacySlip['slip_number'];
                $slipAssignments[] = [
                    'slip_number' => $slipNumber,
                    'store_code' => $legacySlip['store_code'],
                    'year_code' => $legacySlip['year_code'],
                    'sequence_no' => $legacySlip['sequence_no'],
                    'b_record_sequence' => $bRecordSeq,
                    'order_candidate_ids' => $chunk
                        ->pluck('id')
                        ->filter()
                        ->map(fn ($id) => (int) $id)
                        ->values()
                        ->all(),
                ];

                // Bレコード（伝票ヘッダ）
                $records[] = $this->generateBRecord($firstCandidate, $bRecordSeq, $slipNumber);

                // Dレコード（伝票明細）- 各Bレコード内で1から開始
                $dRecordSeq = 1;
                foreach ($chunk as $candidate) {
                    $records[] = $this->generateDRecord($candidate, $dRecordSeq);
                    $dRecordSeq++;
                }

                $bRecordSeq++;
            }
        }

        // レコードを連結（改行なし）
        $content = implode('', $records);

        // JXラッパー（開始行"1" + 終了行"8"）を追加（UTF-8のまま）
        if ($jxSetting) {
            $wrapper = new JxDataWrapper($jxSetting);
            $content = $wrapper->wrap($content);
        }

        // 最後にShift_JISに変換
        return mb_convert_encoding($content, self::ENCODING, 'UTF-8');
    }

    /**
     * Aレコード（ファイルヘッダー）を生成 - 128バイト
     *
     * 仕様:
     * 1: レコード区分 X(01) - "A"
     * 2-3: データ種別 9(02) - "01"
     * 4-11: データ処理日付 9(08) - YYYYMMDD
     * 12-17: データ処理時刻 9(06) - HHMMSS
     * 18-25: データ送信元 9(08) - JX設定のsender_station_code（0埋め8桁）
     * 26-33: データ送信先 9(08) - JX設定のreceiver_station_code（0埋め8桁）
     * 34-39: レコード件数 9(06) - ファイル内の全行数（A+B+D合計）
     * 40-45: 帳票枚数 9(06) - Bレコード（B01）の出現回数
     * 46-60: 社名 X(15)
     * 61-128: FILLER X(68)
     *
     * @param  int  $contractorCode  発注先コード（フォールバック用）
     * @param  int  $totalRecordCount  レコード件数（A+B+D合計）
     * @param  int  $slipCount  伝票枚数（Bレコード数）
     * @param  WmsOrderJxSetting|null  $jxSetting  JX設定
     */
    private function generateARecord(int $contractorCode, int $totalRecordCount, int $slipCount, ?WmsOrderJxSetting $jxSetting = null): string
    {
        $now = Carbon::now();

        // JX設定からステーションコードを取得（なければ従来のフォールバック）
        $senderStationCode = $jxSetting?->sender_station_code ?? self::SENDER_CODE;
        $receiverStationCode = $jxSetting?->receiver_station_code ?? (string) $contractorCode;

        $record = '';
        $record .= 'A';                                              // 1: レコード区分
        $record .= '01';                                             // 2-3: データ種別
        $record .= $now->format('Ymd');                              // 4-11: データ処理日付
        $record .= $now->format('His');                              // 12-17: データ処理時刻
        $record .= str_pad($senderStationCode, 8, '0', STR_PAD_LEFT);    // 18-25: データ送信元（0埋め8桁）
        $record .= str_pad($receiverStationCode, 8, '0', STR_PAD_LEFT);  // 26-33: データ送信先（0埋め8桁）
        $record .= $this->padNumber($totalRecordCount, 6);           // 34-39: レコード件数（A+B+D合計）
        $record .= $this->padNumber($slipCount, 6);                  // 40-45: 帳票枚数（Bレコード数）
        $record .= $this->padToByteLength(self::COMPANY_NAME, 15);   // 46-60: 社名
        $record .= str_pad('', 68);                                  // 61-128: FILLER

        return $this->ensureRecordLength($record);
    }

    /**
     * Bレコード（伝票ヘッダー）を生成 - 128バイト
     *
     * 仕様:
     * 1: レコード区分 X(01) - "B"
     * 2-3: データ種別 9(02) - "01"
     * 4-14: 伝票番号 X(11) - 旧EOS形式（店舗CD2桁 + 年度コード2桁 + 10固定 + 連番5桁）
     * 15-18: 社・店コード X(04) - 入庫倉庫コード（0埋め4桁）
     * 19-21: 分類コード X(03) - "999" 固定
     * 22-23: 伝票区分 X(02) - "01" 固定
     * 24-29: 発注日 9(06) - YYMMDD
     * 30-35: 納品日 9(06) - YYMMDD（入庫予定日）
     * 36-38: 便 X(03) - 空白
     * 39-42: 取引先コード X(04) - 仕入先コード
     * 43-57: 店名 X(15) - 発注倉庫名（半角カナ、右寄せ）
     * 58-67: 納品場所 X(10) - 入庫倉庫名（半角カナ、右寄せ）
     * 68-92: G（備考） X(25) - 空白
     * 93-94: 直送区分 X(02) - "00" 固定
     * 95-128: FILLER X(34)
     */
    private function generateBRecord($candidate, int $seq, ?string $slipNumber = null): string
    {
        $warehouse = $candidate->warehouse;
        $contractor = $candidate->contractor;
        $orderDate = Carbon::now();
        $deliveryDate = $candidate->expected_arrival_date ?? $orderDate->copy()->addDays(2);

        // 伝票番号: Bレコード単位で解決した11桁数字を使用
        if ($slipNumber) {
            if (! preg_match('/^\d{11}$/', $slipNumber)) {
                throw new \RuntimeException("JX伝票番号は11桁数字である必要があります: {$slipNumber}");
            }
        } else {
            $slipNumber = WmsOrderIncomingSchedule::formatSlipNumber($orderDate->toDateString(), $seq);
        }

        // 入庫倉庫コード（0埋め4桁）
        $warehouseCode = str_pad((string) ($warehouse?->code ?? ''), 4, '0', STR_PAD_LEFT);

        $record = '';
        $record .= 'B';                                              // 1: レコード区分
        $record .= '01';                                             // 2-3: データ種別
        $record .= $slipNumber;                                      // 4-14: 伝票番号（11桁）
        $record .= $warehouseCode;                                   // 15-18: 社・店コード（入庫倉庫コード）
        $record .= '999';                                            // 19-21: 分類コード（固定）
        $record .= '01';                                             // 22-23: 伝票区分
        $record .= $orderDate->format('ymd');                        // 24-29: 発注日 (YYMMDD)
        $record .= $deliveryDate->format('ymd');                     // 30-35: 納品日 (YYMMDD)
        $record .= str_pad('', 3);                                   // 36-38: 便（空白）
        $record .= str_pad(substr($contractor?->code ?? '', 0, 4), 4); // 39-42: 取引先コード
        $record .= $this->padToByteLengthRight($warehouse?->kana_name ?? '', 15); // 43-57: 店名（半角カナ、右寄せ）
        $record .= $this->padToByteLengthRight($warehouse?->kana_name ?? '', 10); // 58-67: 納品場所（半角カナ、右寄せ）
        $record .= str_pad('', 25);                                  // 68-92: G（備考）
        $record .= '00';                                             // 93-94: 直送区分（00固定）
        $record .= str_pad('', 34);                                  // 95-128: FILLER

        return $this->ensureRecordLength($record);
    }

    /**
     * @return array{slip_number: string, store_code: string, year_code: int, sequence_no: int}
     */
    private function resolveBRecordSlipNumber(Collection $chunk, array &$usedSlipNumbers): array
    {
        $firstCandidate = $chunk->first();
        $existingSlipNumber = $this->existingLegacySlipNumber($firstCandidate);
        $service = $this->legacySlipNumberService();

        if ($existingSlipNumber && ! in_array($existingSlipNumber, $usedSlipNumbers, true)) {
            $usedSlipNumbers[] = $existingSlipNumber;

            return [
                'slip_number' => $existingSlipNumber,
                'store_code' => substr($existingSlipNumber, 0, 2),
                'year_code' => (int) substr($existingSlipNumber, 2, 2),
                'sequence_no' => (int) substr($existingSlipNumber, 6, 5),
            ];
        }

        if ($existingSlipNumber) {
            Log::warning('JX slip number replaced', [
                'candidate_id' => $firstCandidate->id ?? null,
                'slip_number' => $existingSlipNumber,
            ]);
        }

        do {
            $legacySlip = $service->allocateForWarehouse($firstCandidate->warehouse, Carbon::now());
            $newSlipNumber = $legacySlip['slip_number'];
        } while (in_array($newSlipNumber, $usedSlipNumbers, true));

        $usedSlipNumbers[] = $newSlipNumber;

        return $legacySlip;
    }

    private function existingLegacySlipNumber($candidate): ?string
    {
        if (! $candidate?->id) {
            return null;
        }

        $slipNumber = WmsOrderIncomingSchedule::where('order_candidate_id', $candidate->id)
            ->whereNotNull('slip_number')
            ->value('slip_number');

        return $this->legacySlipNumberService()->isLegacySlipNumber($slipNumber) ? $slipNumber : null;
    }

    private function legacySlipNumberService(): LegacyEosSlipNumberService
    {
        return $this->legacySlipNumberService ?? app(LegacyEosSlipNumberService::class);
    }

    /**
     * Dレコード（伝票明細）を生成 - 128バイト
     *
     * 仕様:
     * 1: レコード区分 X(01) - "D"
     * 2-3: データ種別 9(02) - "01"
     * 4-5: 伝票行番号 9(02) - Bレコード内の行番号（1-6）
     * 6-69: 品名 X(64) - name_main、62バイト+2バイト空白、半角カナ変換なし
     * 70-82: JANコード X(13)
     * 83-88: 自社コード X(06)
     * 89-94: 仕入入数 9(06) - 通常はケース入数。6缶などの発注CDは1ケース内の発注単位数
     * 95-101: ケース数 9(07) - quantity_type=CASE の場合のみ数量設定
     * 102-108: バラ数量 9(07) - quantity_type=PIECE の場合のみ数量設定
     * 109-118: 原単価 9(10) - 小数点2桁（160.00→000016000）
     * 119-128: FILLER X(10)
     */
    private function generateDRecord($candidate, int $seq): string
    {
        $item = $candidate->item;
        $capacityCase = (int) ($item?->capacity_case ?? 1);
        $totalQty = (int) $candidate->order_quantity;

        // 発注コード: 候補に保存されているordering_codeを優先、空欄/全ゼロなら動的取得（後方互換性）
        $orderingCode = $this->resolveOrderingCode($candidate);

        // 発注コード数量区分を取得
        $orderingUnitQty = $this->getOrderingUnitQuantity($item?->id, $orderingCode, $capacityCase);
        $displayCapacity = $orderingUnitQty !== null
            ? $this->resolveDisplayCapacity($capacityCase, $orderingUnitQty)
            : $capacityCase;

        if ($orderingUnitQty !== null) {
            if ($candidate->quantity_type === QuantityType::CASE || $candidate->quantity_type?->value === 'CASE') {
                $caseQty = $totalQty;
                $pieceQty = 0;
            } else {
                $caseQty = $this->convertPieceQuantityToOrderingCaseQuantity($totalQty, $capacityCase, $orderingUnitQty);
                $pieceQty = 0;
            }
        } elseif ($candidate->quantity_type === QuantityType::CASE || $candidate->quantity_type?->value === 'CASE') {
            $caseQty = $totalQty;
            $pieceQty = 0;
        } else {
            $caseQty = 0;
            $pieceQty = $totalQty;
        }

        // 原単価を取得
        // 発注コード数量区分がある場合も、先方は原単価を仕入入数で割るためケース原価を送る。
        // ただし仕入入数1の単品商品はケース原価が未設定のため、バラ原価を送る。
        $priceQuantityType = $capacityCase <= 1
            ? QuantityType::PIECE
            : ($orderingUnitQty !== null ? QuantityType::CASE : $candidate->quantity_type);
        $costPrice = $this->getCurrentCostPrice(
            $item?->id,
            $priceQuantityType
        );
        $priceFormatted = (int) round($costPrice * 100);

        $record = '';
        $record .= 'D';                                              // 1: レコード区分
        $record .= '01';                                             // 2-3: データ種別
        $record .= $this->padNumber($seq, 2);                        // 4-5: 伝票行番号
        $record .= $this->padProductName($item?->name_main ?? '', 62).'  '; // 6-69: 品名（62バイト+2空白）
        $record .= str_pad($orderingCode, 13);                       // 70-82: 発注コード
        $record .= str_pad(substr($item?->code ?? '', 0, 6), 6);     // 83-88: 自社コード
        $record .= $this->padNumber($displayCapacity, 6);            // 89-94: 仕入入数
        $record .= $this->padNumber($caseQty, 7);                    // 95-101: ケース数
        $record .= $this->padNumber($pieceQty, 7);                   // 102-108: バラ数量
        $record .= $this->padNumber($priceFormatted, 10);            // 109-118: 原単価
        $record .= str_pad('', 10);                                  // 119-128: FILLER

        return $this->ensureRecordLength($record);
    }

    private function convertPieceQuantityToOrderingCaseQuantity(int $pieceQuantity, int $capacityCase, int $orderingUnitQty): int
    {
        $orderingPieceQuantity = (int) ceil(max(0, $pieceQuantity) / $orderingUnitQty);
        $displayCapacity = $this->resolveDisplayCapacity($capacityCase, $orderingUnitQty);

        return (int) ceil($orderingPieceQuantity / max(1, $displayCapacity));
    }

    private function resolveDisplayCapacity(int $capacityCase, int $orderingUnitQty): int
    {
        if ($orderingUnitQty > 1 && $orderingUnitQty < $capacityCase && $capacityCase % $orderingUnitQty === 0) {
            return (int) ($capacityCase / $orderingUnitQty);
        }

        return $orderingUnitQty;
    }

    /**
     * レコードが128バイトになるようにパディング/切り詰め
     */
    private function ensureRecordLength(string $record): string
    {
        $sjisRecord = mb_convert_encoding($record, self::ENCODING, 'UTF-8');
        $length = strlen($sjisRecord);

        if ($length < self::RECORD_LENGTH) {
            $sjisRecord .= str_repeat(' ', self::RECORD_LENGTH - $length);
        } elseif ($length > self::RECORD_LENGTH) {
            $sjisRecord = substr($sjisRecord, 0, self::RECORD_LENGTH);
        }

        return mb_convert_encoding($sjisRecord, 'UTF-8', self::ENCODING);
    }

    /**
     * ファイル名を生成
     */
    private function generateFilename(int $contractorCode): string
    {
        $timestamp = Carbon::now()->format('YmdHis');

        return "{$contractorCode}_order_{$timestamp}.".self::FILE_EXTENSION;
    }

    /**
     * レコード数をカウント
     *
     * JXラッパー(1,8) + Aレコード(1) + Bレコード(グループ数) + Dレコード(候補数)
     */
    private function countRecords(Collection $candidates): int
    {
        // 6件ずつ分割した場合のBレコード数を計算
        $grouped = $candidates->groupBy(function ($c) {
            return $this->buildRecordGroupKey($c);
        });

        $bCount = 0;
        foreach ($grouped as $groupCandidates) {
            $bCount += (int) ceil($groupCandidates->count() / self::MAX_D_RECORDS_PER_B);
        }

        // JXラッパー開始(1) + Aレコード(1) + Bレコード + Dレコード + JXラッパー終了(1)
        return 1 + 1 + $bCount + $candidates->count() + 1;
    }

    /**
     * 発注先コードからJX設定を取得
     */
    private function getJxSettingByContractorCode(int $contractorCode): ?WmsOrderJxSetting
    {
        $contractorId = $this->getContractorIdByCode($contractorCode);

        if (! $contractorId) {
            return null;
        }

        // wms_contractor_settingsからJX設定IDを取得
        $setting = WmsContractorSetting::where('contractor_id', $contractorId)->first();

        if ($setting?->wms_order_jx_setting_id) {
            $jxSetting = WmsOrderJxSetting::find($setting->wms_order_jx_setting_id);
            if ($jxSetting?->is_active) {
                return $jxSetting;
            }
        }

        return WmsOrderJxSetting::findByContractorId($contractorId);
    }

    /**
     * 発注先コードから発注先IDを取得
     */
    private function getContractorIdByCode(int $code): ?int
    {
        if (isset($this->contractorIdCache[$code])) {
            return $this->contractorIdCache[$code];
        }

        $contractor = \App\Models\Sakemaru\Contractor::where('code', $code)->first();
        $this->contractorIdCache[$code] = $contractor?->id;

        return $this->contractorIdCache[$code];
    }

    /**
     * 商品IDから発注コードを取得（フォールバック用）
     *
     * 通常は WmsOrderCandidate.ordering_code を使用する。
     * このメソッドは ordering_code が設定されていない既存データ用の後方互換性のため。
     *
     * item_search_information テーブルから取得する。
     * 優先順位:
     * 1. is_used_for_ordering=true のコード
     * 2. code_type='JAN' かつ quantity_type='PIECE'
     * 3. code_type='JAN' かつ quantity_type='CASE'
     * 4. code_type='OTHER' かつ quantity_type='PIECE'（数字のみ7桁以上）
     *
     * 取得したコードは13桁にゼロパディングして返す。
     */
    private function getJanCode(?int $itemId): string
    {
        if (! $itemId) {
            return '';
        }

        if (isset($this->janCodeCache[$itemId])) {
            return $this->janCodeCache[$itemId];
        }

        // まずis_used_for_ordering=trueのコードを検索
        $codeInfo = DB::connection('sakemaru')
            ->table('item_search_information')
            ->where('item_id', $itemId)
            ->where('is_used_for_ordering', true)
            ->where('is_active', true)
            ->whereRaw("search_string REGEXP '[1-9]'")
            ->first();

        // なければJANコードを検索（PIECE優先）
        if (! $codeInfo) {
            $codeInfo = DB::connection('sakemaru')
                ->table('item_search_information')
                ->where('item_id', $itemId)
                ->where('code_type', 'JAN')
                ->where('is_active', true)
                ->whereRaw("search_string REGEXP '[1-9]'")
                ->orderByRaw("CASE WHEN quantity_type = 'PIECE' THEN 0 ELSE 1 END")
                ->first();
        }

        // それでもなければOTHERコードを検索（数字のみ7桁以上）
        if (! $codeInfo) {
            $codeInfo = DB::connection('sakemaru')
                ->table('item_search_information')
                ->where('item_id', $itemId)
                ->where('code_type', 'OTHER')
                ->where('is_active', true)
                ->whereRaw("search_string REGEXP '[1-9]'")
                ->whereRaw("search_string REGEXP '^[0-9]{7,}$'")
                ->orderByRaw("CASE WHEN quantity_type = 'PIECE' THEN 0 ELSE 1 END")
                ->first();
        }

        $code = $this->normalizeOrderingCode($codeInfo->search_string ?? '') ?? '';

        $this->janCodeCache[$itemId] = $code;

        return $code;
    }

    /**
     * 発注コードが取得できる候補だけを残す。
     */
    private function filterCandidatesWithOrderingCode(Collection $candidates): Collection
    {
        return $candidates
            ->filter(function ($candidate) {
                $orderingCode = $this->resolveOrderingCode($candidate);

                if ($orderingCode !== null) {
                    return true;
                }

                $item = $candidate->item;
                Log::warning('[HanaOrderFileGenerator] 発注コード未設定の明細をJX生成からスキップ', [
                    'candidate_id' => $candidate->id,
                    'item_id' => $item?->id,
                    'item_code' => $item?->code,
                    'item_name' => $item?->name_main,
                    'contractor_id' => $candidate->contractor_id,
                    'warehouse_id' => $candidate->warehouse_id,
                    'ordering_code' => $candidate->ordering_code,
                ]);

                return false;
            })
            ->values();
    }

    /**
     * 発注コード数量区分の荷姿入数を取得。通常ケース発注はnull。
     */
    private function getOrderingUnitQuantity(?int $itemId, ?string $orderingCode = null, ?int $capacityCase = null): ?int
    {
        if (! $itemId) {
            return null;
        }

        $cacheKey = $itemId.':'.($orderingCode ?? '');
        if (array_key_exists($cacheKey, $this->orderingUnitQtyCache)) {
            return $this->orderingUnitQtyCache[$cacheKey];
        }

        if (array_key_exists($cacheKey, $this->orderingCodeInfoCache)) {
            $row = $this->orderingCodeInfoCache[$cacheKey];
        } else {
            $query = DB::connection('sakemaru')
                ->table('item_search_information as isi')
                ->join('item_quantity_information as iqi', 'iqi.id', '=', 'isi.item_quantity_information_id')
                ->where('isi.item_id', $itemId)
                ->where('isi.is_active', true)
                ->where('iqi.quantity', '>', 1);

            if ($orderingCode) {
                $query->whereRaw('LPAD(isi.search_string, 13, "0") = ?', [$orderingCode]);
            } else {
                $query->where('isi.is_used_for_ordering', true);
            }

            $row = $query->select('iqi.quantity')->first();
            $this->orderingCodeInfoCache[$cacheKey] = $row;
        }

        $qty = $row ? (int) $row->quantity : null;

        // capacity_caseと同じ場合は通常ケース発注なのでnull。
        if ($qty !== null && $qty > 1) {
            $caseCapacity = $capacityCase ?? (int) (DB::connection('sakemaru')
                ->table('items')->where('id', $itemId)->value('capacity_case') ?? 0);
            if ($qty === $caseCapacity) {
                $qty = null;
            }
        } else {
            $qty = null;
        }

        $this->orderingUnitQtyCache[$cacheKey] = $qty;

        return $qty;
    }

    /**
     * 候補からJX発注コードを解決する。
     */
    private function resolveOrderingCode($candidate): ?string
    {
        $candidateCode = $this->normalizeOrderingCode($candidate->ordering_code);

        if ($candidateCode) {
            return $candidateCode;
        }

        return $this->normalizeOrderingCode($this->getJanCode($candidate->item?->id));
    }

    /**
     * JX発注コードとして使える13桁コードに正規化する。
     *
     * 空欄と全ゼロは「未設定」として扱う。
     */
    private function normalizeOrderingCode(?string $code): ?string
    {
        $code = trim((string) $code);

        if ($code === '' || preg_match('/^0+$/', $code) === 1) {
            return null;
        }

        return str_pad($code, 13, '0', STR_PAD_LEFT);
    }

    /**
     * 全角カナを半角カナに変換
     */
    private function toHalfWidthKana(string $str): string
    {
        return mb_convert_kana($str, 'askh', 'UTF-8');
    }

    /**
     * 固定長フィールド用のパディング（Shift_JISバイト長基準）
     *
     * UTF-8文字列を受け取り、Shift_JISでのバイト長が指定長になるようにパディングした
     * UTF-8文字列を返す。最終的にファイル全体がSJISに変換されるため。
     *
     * @param  string  $str  元の文字列（UTF-8）
     * @param  int  $length  目標バイト長（SJIS換算）
     * @param  string  $pad  パディング文字
     * @param  int  $padType  STR_PAD_RIGHT or STR_PAD_LEFT
     */
    private function padToByteLength(string $str, int $length, string $pad = ' ', int $padType = STR_PAD_RIGHT): string
    {
        // 半角カナに変換
        $str = $this->toHalfWidthKana($str);

        // 文字単位で処理し、SJISバイト長が目標を超えないようにする
        $result = '';
        $currentByteLength = 0;

        for ($i = 0; $i < mb_strlen($str, 'UTF-8'); $i++) {
            $char = mb_substr($str, $i, 1, 'UTF-8');
            $charSjis = mb_convert_encoding($char, self::ENCODING, 'UTF-8');
            $charByteLength = strlen($charSjis);

            if ($currentByteLength + $charByteLength > $length) {
                break;
            }

            $result .= $char;
            $currentByteLength += $charByteLength;
        }

        // パディングを追加
        $padLength = $length - $currentByteLength;
        $padding = str_repeat($pad, $padLength);

        if ($padType === STR_PAD_LEFT) {
            return $padding.$result;
        }

        return $result.$padding;
    }

    /**
     * 数値を固定長でゼロパディング
     */
    private function padNumber($value, int $length): string
    {
        return str_pad((string) ($value ?? 0), $length, '0', STR_PAD_LEFT);
    }

    /**
     * 固定長フィールド用の右寄せパディング（半角カナ変換付き、Shift_JISバイト長基準）
     *
     * UTF-8文字列を受け取り、Shift_JISでのバイト長が指定長になるように
     * 左側に空白を追加してUTF-8文字列を返す。
     *
     * @param  string  $str  元の文字列（UTF-8）
     * @param  int  $length  目標バイト長（SJIS換算）
     */
    private function padToByteLengthRight(string $str, int $length): string
    {
        // 半角カナに変換
        $str = $this->toHalfWidthKana($str);

        // 文字単位で処理し、SJISバイト長が目標を超えないようにする
        $result = '';
        $currentByteLength = 0;

        for ($i = 0; $i < mb_strlen($str, 'UTF-8'); $i++) {
            $char = mb_substr($str, $i, 1, 'UTF-8');
            $charSjis = mb_convert_encoding($char, self::ENCODING, 'UTF-8');
            $charByteLength = strlen($charSjis);

            if ($currentByteLength + $charByteLength > $length) {
                break;
            }

            $result .= $char;
            $currentByteLength += $charByteLength;
        }

        // 左側に空白パディングを追加（右寄せ）
        $padLength = $length - $currentByteLength;

        return str_repeat(' ', $padLength).$result;
    }

    /**
     * 品名を固定バイト長にパディング（半角カナ変換なし）
     *
     * UTF-8文字列を受け取り、Shift_JISでのバイト長が指定長になるようにパディング。
     * マルチバイト文字が途中で切れないように注意。
     *
     * @param  string  $str  元の文字列（UTF-8）
     * @param  int  $length  目標バイト長（SJIS換算）
     */
    private function padProductName(string $str, int $length): string
    {
        // 文字単位で処理し、SJISバイト長が目標を超えないようにする
        $result = '';
        $currentByteLength = 0;

        for ($i = 0; $i < mb_strlen($str, 'UTF-8'); $i++) {
            $char = mb_substr($str, $i, 1, 'UTF-8');
            $charSjis = mb_convert_encoding($char, self::ENCODING, 'UTF-8');
            $charByteLength = strlen($charSjis);

            if ($currentByteLength + $charByteLength > $length) {
                break;
            }

            $result .= $char;
            $currentByteLength += $charByteLength;
        }

        // 残りを空白でパディング
        $padLength = $length - $currentByteLength;

        return $result.str_repeat(' ', $padLength);
    }

    /**
     * 商品の現在有効な仕入単価を取得
     *
     * item_pricesテーブルからstart_dateが現在日以前で最も新しいレコードを取得。
     * quantity_type=PIECE の場合は cost_unit_price、それ以外は cost_case_price を返す。
     *
     * @param  int|null  $itemId  商品ID
     * @param  QuantityType|string|null  $quantityType  数量タイプ
     * @return float 仕入単価
     */
    private function getCurrentCostPrice(
        ?int $itemId,
        QuantityType|string|null $quantityType = null
    ): float {
        if (! $itemId) {
            return 0.0;
        }

        // キャッシュから取得
        if (isset($this->costPriceCache[$itemId])) {
            $price = $this->costPriceCache[$itemId];
        } else {
            $price = DB::connection('sakemaru')
                ->table('item_prices as ip')
                ->join('items as i', 'i.id', '=', 'ip.item_id')
                ->where('ip.item_id', $itemId)
                ->where('ip.is_active', true)
                ->where('ip.start_date', '<=', now()->toDateString())
                ->orderBy('ip.start_date', 'desc')
                ->select([
                    'i.purchase_price_type',
                    'ip.producer_unit_price',
                    'ip.cost_unit_price',
                    'ip.cost_case_price',
                    'ip.wholesale_unit_price',
                ])
                ->first();

            $this->costPriceCache[$itemId] = $price;
        }

        if (! $price) {
            return 0.0;
        }

        // quantity_typeでバラ単価/ケース単価を判定
        $isPiece = $quantityType === QuantityType::PIECE
            || (is_string($quantityType) && $quantityType === 'PIECE')
            || ($quantityType instanceof QuantityType && $quantityType->value === 'PIECE');

        if ($isPiece) {
            return (float) ($price->cost_unit_price ?? 0);
        }

        return (float) ($price->cost_case_price ?? 0);
    }

    private function getCurrentPurchaseUnitPrice(?int $itemId): ?float
    {
        if (! $itemId) {
            return null;
        }

        if (array_key_exists($itemId, $this->purchaseUnitPriceCache)) {
            return $this->purchaseUnitPriceCache[$itemId];
        }

        if (isset($this->costPriceCache[$itemId])) {
            $price = $this->costPriceCache[$itemId];
            $purchaseUnitPrice = match ($price->purchase_price_type ?? null) {
                EPurchasePriceType::PRODUCER->value => $price->producer_unit_price ?? null,
                EPurchasePriceType::COST->value => $price->cost_unit_price ?? null,
                EPurchasePriceType::WHOLESALE->value => $price->wholesale_unit_price ?? null,
                default => $price->purchase_unit_price ?? $price->cost_unit_price ?? null,
            };

            $this->purchaseUnitPriceCache[$itemId] = $purchaseUnitPrice !== null ? (float) $purchaseUnitPrice : null;

            return $this->purchaseUnitPriceCache[$itemId];
        }

        $price = DB::connection('sakemaru')
            ->table('item_prices as ip')
            ->join('items as i', 'i.id', '=', 'ip.item_id')
            ->where('ip.item_id', $itemId)
            ->where('ip.is_active', true)
            ->where('ip.start_date', '<=', now()->toDateString())
            ->orderBy('ip.start_date', 'desc')
            ->select([
                'i.purchase_price_type',
                'ip.producer_unit_price',
                'ip.cost_unit_price',
                'ip.wholesale_unit_price',
            ])
            ->first();

        $purchaseUnitPrice = null;
        if ($price) {
            $purchaseUnitPrice = match ($price->purchase_price_type) {
                EPurchasePriceType::PRODUCER->value => $price->producer_unit_price,
                EPurchasePriceType::COST->value => $price->cost_unit_price,
                EPurchasePriceType::WHOLESALE->value => $price->wholesale_unit_price,
                default => null,
            };
        }

        $this->purchaseUnitPriceCache[$itemId] = $purchaseUnitPrice !== null ? (float) $purchaseUnitPrice : null;

        return $this->purchaseUnitPriceCache[$itemId];
    }

    /**
     * 商品の仕入単価をプリロード
     */
    public function preloadCostPrices(array $itemIds): void
    {
        if (empty($itemIds)) {
            return;
        }

        $today = now()->toDateString();

        // サブクエリで各商品の最新start_dateを取得
        $latestDates = DB::connection('sakemaru')
            ->table('item_prices')
            ->select('item_id', DB::raw('MAX(start_date) as max_start_date'))
            ->whereIn('item_id', $itemIds)
            ->where('is_active', true)
            ->where('start_date', '<=', $today)
            ->groupBy('item_id');

        $prices = DB::connection('sakemaru')
            ->table('item_prices as ip')
            ->joinSub($latestDates, 'latest', function ($join) {
                $join->on('ip.item_id', '=', 'latest.item_id')
                    ->on('ip.start_date', '=', 'latest.max_start_date');
            })
            ->where('ip.is_active', true)
            ->get();

        foreach ($prices as $price) {
            $this->costPriceCache[$price->item_id] = $price;
        }

        Log::info('[HanaOrderFileGenerator] CostPrices プリロード完了', [
            'requested' => count($itemIds),
            'loaded' => count($prices),
        ]);
    }

    /**
     * データなし時の空ファイルを生成
     *
     * $addZeroRecord=true: Aレコード付き空ファイル（JXラッパー(1) + Aレコード(1) + JXラッパー(8) = 3レコード）
     * $addZeroRecord=false: JXラッパーのみ（JXラッパー(1) + JXラッパー(8) = 2レコード）
     *
     * @param  WmsOrderJxSetting  $jxSetting  JX設定
     * @return array ファイル情報（generate()と同じフォーマット）
     */
    public function generateEmptyFile(WmsOrderJxSetting $jxSetting): array
    {
        $contractorId = $jxSetting->contractor_id;
        $contractorCode = $jxSetting->contractor?->code
            ?? DB::connection('sakemaru')
                ->table('contractors')
                ->where('id', $contractorId)
                ->value('code');

        // addZeroRecord=true: Aレコード付き、addZeroRecord=false: レコードなし（JXラッパーのみ）
        $innerContent = $this->addZeroRecord
            ? $this->generateARecord((int) $contractorCode, 1, 0, $jxSetting)
            : '';

        // JXラッパーで包む（UTF-8のまま）
        $wrapper = new JxDataWrapper($jxSetting);
        $content = $wrapper->wrap($innerContent);

        // Shift_JISに変換
        $sjisContent = mb_convert_encoding($content, self::ENCODING, 'UTF-8');

        $filename = $this->generateFilename((int) $contractorCode);
        $recordCount = $this->addZeroRecord ? 3 : 2; // wrapper(1) + A?(1) + wrapper(8)

        return [
            'contractor_id' => $contractorId,
            'contractor_code' => $contractorCode,
            'jx_setting_id' => $jxSetting->id,
            'content' => $sjisContent,
            'filename' => $filename,
            'encoding' => self::ENCODING,
            'record_count' => $recordCount,
            'order_count' => 0,
        ];
    }

    // ========================================
    // Interface実装
    // ========================================

    public function getJxTransmissionContractorIds(): array
    {
        $ids = [];
        foreach (self::JX_CONTRACTOR_CODES as $code) {
            $id = $this->getContractorIdByCode($code);
            if ($id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function getTransmissionContractorMapping(): array
    {
        $mapping = [];

        WmsContractorSetting::query()
            ->whereNotNull('transmission_contractor_id')
            ->get(['contractor_id', 'transmission_contractor_id'])
            ->each(function (WmsContractorSetting $setting) use (&$mapping): void {
                $mapping[(int) $setting->contractor_id] = (int) $setting->transmission_contractor_id;
            });

        foreach (self::TRANSMISSION_MAPPING as $fromCode => $toCode) {
            $fromId = $this->getContractorIdByCode($fromCode);
            $toId = $this->getContractorIdByCode($toCode);
            if ($fromId && $toId) {
                $mapping[$fromId] ??= $toId;
            }
        }

        return $mapping;
    }

    public function getEncoding(): string
    {
        return self::ENCODING;
    }

    public function getLineEnding(): string
    {
        return self::LINE_ENDING;
    }

    public function getFileExtension(): string
    {
        return self::FILE_EXTENSION;
    }
}
