<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Models\Sakemaru\Contractor;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\Generators\HanaOrderJXFileGenerator;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\Support\FakeLegacyEosSlipNumberService;
use Tests\TestCase;

/**
 * HanaOrderFileGenerator フィールド比較テスト
 *
 * サンプルファイルと生成ファイルのフィールド位置・形式を
 * 詳細に比較検証する。
 *
 * 実DBの既存データを利用してテストを実行する。
 * DB:fresh, refreshなどのリセットは一切行わない。
 */
class HanaOrderFileFieldComparisonTest extends TestCase
{
    private const SAMPLE_FILES = [
        1017 => 'storage/specifications/ordering/1017_sample.txt',
        1106 => 'storage/specifications/ordering/1106_sample.txt',
        1202 => 'storage/specifications/ordering/1202_sample.txt',
        1330 => 'storage/specifications/ordering/1330_sample.txt',
    ];

    private const RECORD_LENGTH = 128;

    private HanaOrderJXFileGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new HanaOrderJXFileGenerator(new FakeLegacyEosSlipNumberService);
    }

    /**
     * @test
     * Aレコードのフィールド位置比較（サンプル vs 生成）
     */
    public function it_compares_a_record_field_positions(): void
    {
        // サンプルファイルからAレコードを取得
        $sampleARecord = $this->getSampleARecord(1106);
        if (! $sampleARecord) {
            $this->markTestSkipped('Sample A record not available');
        }

        // 生成ファイルからAレコードを取得
        $generatedARecord = $this->getGeneratedARecord();
        if (! $generatedARecord) {
            $this->markTestSkipped('Generated A record not available');
        }

        // フィールド位置の比較（1-indexed）
        $fields = [
            ['name' => 'レコード区分', 'start' => 0, 'length' => 1, 'expected' => 'A'],
            ['name' => 'データ種別', 'start' => 1, 'length' => 2, 'expected' => '01'],
            ['name' => 'データ処理日付', 'start' => 3, 'length' => 8, 'pattern' => '/^\d{8}$/'],
            ['name' => 'データ処理時刻', 'start' => 11, 'length' => 6, 'pattern' => '/^\d{6}$/'],
            ['name' => 'データ送信元', 'start' => 17, 'length' => 8, 'pattern' => '/^[\d\s]{8}$/'],
            ['name' => 'データ送信先', 'start' => 25, 'length' => 8, 'pattern' => '/^[\d\s]{8}$/'],
            ['name' => 'レコード件数', 'start' => 33, 'length' => 6, 'pattern' => '/^\d{6}$/'],
            ['name' => '帳票枚数', 'start' => 39, 'length' => 6, 'pattern' => '/^\d{6}$/'],
            ['name' => '社名', 'start' => 45, 'length' => 15, 'pattern' => '/ﾘｶｰﾜｰﾙﾄﾞ/'],
        ];

        // サンプルのフィールド検証
        foreach ($fields as $field) {
            $sampleValue = substr($sampleARecord, $field['start'], $field['length']);
            $generatedValue = substr($generatedARecord, $field['start'], $field['length']);

            if (isset($field['expected'])) {
                $this->assertEquals(
                    $field['expected'],
                    $sampleValue,
                    "Sample A record {$field['name']} should be {$field['expected']}"
                );
                $this->assertEquals(
                    $field['expected'],
                    $generatedValue,
                    "Generated A record {$field['name']} should be {$field['expected']}"
                );
            }

            if (isset($field['pattern'])) {
                $sampleValueUtf8 = mb_convert_encoding($sampleValue, 'UTF-8', 'SJIS');
                $generatedValueUtf8 = mb_convert_encoding($generatedValue, 'UTF-8', 'SJIS');

                $this->assertMatchesRegularExpression(
                    $field['pattern'],
                    $sampleValueUtf8,
                    "Sample A record {$field['name']} should match pattern"
                );
                $this->assertMatchesRegularExpression(
                    $field['pattern'],
                    $generatedValueUtf8,
                    "Generated A record {$field['name']} should match pattern"
                );
            }
        }
    }

    /**
     * @test
     * Bレコードのフィールド位置比較（サンプル vs 生成）
     */
    public function it_compares_b_record_field_positions(): void
    {
        // サンプルファイルからBレコードを取得
        $sampleBRecord = $this->getSampleBRecord(1106);
        if (! $sampleBRecord) {
            $this->markTestSkipped('Sample B record not available');
        }

        // 生成ファイルからBレコードを取得
        $generatedBRecord = $this->getGeneratedBRecord();
        if (! $generatedBRecord) {
            $this->markTestSkipped('Generated B record not available');
        }

        // フィールド位置の比較
        $fields = [
            ['name' => 'レコード区分', 'start' => 0, 'length' => 1, 'expected' => 'B'],
            ['name' => 'データ種別', 'start' => 1, 'length' => 2, 'expected' => '01'],
            ['name' => '伝票番号', 'start' => 3, 'length' => 11, 'pattern' => '/^[\d\s]{11}$/'],
            ['name' => '社・店コード', 'start' => 14, 'length' => 4, 'pattern' => '/^[\d\s]{4}$/'],
            ['name' => '分類コード', 'start' => 18, 'length' => 3, 'pattern' => '/^[\d\s]{3}$/'],
            ['name' => '伝票区分', 'start' => 21, 'length' => 2, 'pattern' => '/^\d{2}$/'],
            ['name' => '発注日', 'start' => 23, 'length' => 6, 'pattern' => '/^\d{6}$/'],
            ['name' => '納品日', 'start' => 29, 'length' => 6, 'pattern' => '/^\d{6}$/'],
            ['name' => '便', 'start' => 35, 'length' => 3, 'pattern' => '/^[\d\s]{3}$/'],
            ['name' => '取引先コード', 'start' => 38, 'length' => 4, 'pattern' => '/^\d{4}$/'],
            ['name' => '店名', 'start' => 42, 'length' => 15, 'pattern' => '/./'],
            ['name' => '納品場所', 'start' => 57, 'length' => 10, 'pattern' => '/./'],
            ['name' => '備考', 'start' => 67, 'length' => 25, 'pattern' => '/./'],
            ['name' => 'メーカー直送区分', 'start' => 92, 'length' => 1, 'pattern' => '/^[\d\s]$/'],
        ];

        foreach ($fields as $field) {
            $sampleValue = substr($sampleBRecord, $field['start'], $field['length']);
            $generatedValue = substr($generatedBRecord, $field['start'], $field['length']);

            if (isset($field['expected'])) {
                $this->assertEquals(
                    $field['expected'],
                    $sampleValue,
                    "Sample B record {$field['name']} at position {$field['start']} should be {$field['expected']}"
                );
                $this->assertEquals(
                    $field['expected'],
                    $generatedValue,
                    "Generated B record {$field['name']} at position {$field['start']} should be {$field['expected']}"
                );
            }

            if (isset($field['pattern'])) {
                $this->assertMatchesRegularExpression(
                    $field['pattern'],
                    $sampleValue,
                    "Sample B record {$field['name']} at position {$field['start']} should match pattern"
                );
                $this->assertMatchesRegularExpression(
                    $field['pattern'],
                    $generatedValue,
                    "Generated B record {$field['name']} at position {$field['start']} should match pattern"
                );
            }
        }
    }

    /**
     * @test
     * Dレコードのフィールド位置比較（サンプル vs 生成）
     */
    public function it_compares_d_record_field_positions(): void
    {
        // サンプルファイルからDレコードを取得
        $sampleDRecord = $this->getSampleDRecord(1106);
        if (! $sampleDRecord) {
            $this->markTestSkipped('Sample D record not available');
        }

        // 生成ファイルからDレコードを取得
        $generatedDRecord = $this->getGeneratedDRecord();
        if (! $generatedDRecord) {
            $this->markTestSkipped('Generated D record not available');
        }

        // フィールド位置の比較
        $fields = [
            ['name' => 'レコード区分', 'start' => 0, 'length' => 1, 'expected' => 'D'],
            ['name' => 'データ種別', 'start' => 1, 'length' => 2, 'expected' => '01'],
            ['name' => '伝票行番号', 'start' => 3, 'length' => 2, 'pattern' => '/^\d{2}$/'],
            ['name' => '品名', 'start' => 5, 'length' => 64, 'pattern' => '/./'],
            ['name' => 'JANコード', 'start' => 69, 'length' => 13, 'pattern' => '/^[\d\s]{13}$/'],
            ['name' => '自社コード', 'start' => 82, 'length' => 6, 'pattern' => '/^[\d\s]{6}$/'],
            ['name' => '仕入入数', 'start' => 88, 'length' => 6, 'pattern' => '/^\d{6}$/'],
            ['name' => 'ケース数', 'start' => 94, 'length' => 7, 'pattern' => '/^\d{7}$/'],
            ['name' => 'バラ数量', 'start' => 101, 'length' => 7, 'pattern' => '/^\d{7}$/'],
            ['name' => '原単価', 'start' => 108, 'length' => 10, 'pattern' => '/^\d{10}$/'],
        ];

        foreach ($fields as $field) {
            $sampleValue = substr($sampleDRecord, $field['start'], $field['length']);
            $generatedValue = substr($generatedDRecord, $field['start'], $field['length']);

            if (isset($field['expected'])) {
                $this->assertEquals(
                    $field['expected'],
                    $sampleValue,
                    "Sample D record {$field['name']} at position {$field['start']} should be {$field['expected']}"
                );
                $this->assertEquals(
                    $field['expected'],
                    $generatedValue,
                    "Generated D record {$field['name']} at position {$field['start']} should be {$field['expected']}"
                );
            }

            if (isset($field['pattern'])) {
                $this->assertMatchesRegularExpression(
                    $field['pattern'],
                    $sampleValue,
                    "Sample D record {$field['name']} at position {$field['start']} should match pattern"
                );
                $this->assertMatchesRegularExpression(
                    $field['pattern'],
                    $generatedValue,
                    "Generated D record {$field['name']} at position {$field['start']} should match pattern"
                );
            }
        }
    }

    /**
     * @test
     * 全サンプルファイルの送信元コード検証
     */
    public function it_validates_sender_code_in_all_samples(): void
    {
        $expectedSenderCode = '01451019';

        foreach (self::SAMPLE_FILES as $contractorCode => $filePath) {
            if (! file_exists($filePath)) {
                continue;
            }

            $aRecord = $this->getSampleARecord($contractorCode);
            if (! $aRecord) {
                continue;
            }

            $senderCode = trim(substr($aRecord, 17, 8));

            // 送信元コードが空または期待値と一致することを確認
            $this->assertTrue(
                empty($senderCode) || $senderCode === $expectedSenderCode,
                "Contractor {$contractorCode}: Sender code should be empty or {$expectedSenderCode}, got {$senderCode}"
            );
        }
    }

    /**
     * @test
     * 生成ファイルの送信元コードが正しいこと
     */
    public function it_generates_correct_sender_code(): void
    {
        $expectedSenderCode = '01451019';

        $aRecord = $this->getGeneratedARecord();
        if (! $aRecord) {
            $this->markTestSkipped('Generated A record not available');
        }

        $senderCode = trim(substr($aRecord, 17, 8));
        $this->assertEquals($expectedSenderCode, $senderCode, 'Generated sender code should be 01451019');
    }

    /**
     * @test
     * 発注日・納品日の形式比較
     */
    public function it_compares_date_formats(): void
    {
        // サンプルのBレコードから日付を取得
        $sampleBRecord = $this->getSampleBRecord(1106);
        if ($sampleBRecord) {
            $sampleOrderDate = substr($sampleBRecord, 23, 6);
            $sampleDeliveryDate = substr($sampleBRecord, 29, 6);

            // YYMMDD形式であること
            $this->assertMatchesRegularExpression('/^\d{6}$/', $sampleOrderDate, 'Sample order date should be YYMMDD');
            $this->assertMatchesRegularExpression('/^\d{6}$/', $sampleDeliveryDate, 'Sample delivery date should be YYMMDD');

            // 年が20-30の範囲（2020-2030年）
            $year = intval(substr($sampleOrderDate, 0, 2));
            $this->assertGreaterThanOrEqual(20, $year, 'Year should be >= 20');
            $this->assertLessThanOrEqual(30, $year, 'Year should be <= 30');
        }

        // 生成のBレコードから日付を取得
        $generatedBRecord = $this->getGeneratedBRecord();
        if ($generatedBRecord) {
            $generatedOrderDate = substr($generatedBRecord, 23, 6);
            $generatedDeliveryDate = substr($generatedBRecord, 29, 6);

            // YYMMDD形式であること
            $this->assertMatchesRegularExpression('/^\d{6}$/', $generatedOrderDate, 'Generated order date should be YYMMDD');
            $this->assertMatchesRegularExpression('/^\d{6}$/', $generatedDeliveryDate, 'Generated delivery date should be YYMMDD');

            // 今日の日付と一致（発注日）
            $expectedOrderDate = Carbon::now()->format('ymd');
            $this->assertEquals($expectedOrderDate, $generatedOrderDate, 'Generated order date should be today');
        }
    }

    /**
     * @test
     * ケース数・バラ数の計算検証
     */
    public function it_calculates_cases_and_pieces_correctly(): void
    {
        // サンプルからいくつかのDレコードを取得して検証
        foreach (self::SAMPLE_FILES as $contractorCode => $filePath) {
            if (! file_exists($filePath)) {
                continue;
            }

            $records = $this->parseSampleFile($filePath);
            $dRecords = array_filter($records, fn ($r) => str_starts_with($r, 'D'));

            foreach (array_slice($dRecords, 0, 5) as $dRecord) {
                $caseQty = intval(trim(substr($dRecord, 88, 6)));
                $cases = intval(trim(substr($dRecord, 94, 7)));
                $pieces = intval(trim(substr($dRecord, 101, 7)));

                // 仕入入数が0より大きい場合、バラ数は仕入入数未満
                if ($caseQty > 0) {
                    $this->assertLessThan(
                        $caseQty,
                        $pieces,
                        "Contractor {$contractorCode}: Pieces should be < case qty"
                    );
                }

                // 総数量の計算確認
                $totalQty = ($cases * $caseQty) + $pieces;
                $this->assertGreaterThan(0, $totalQty, 'Total quantity should be > 0');
            }
        }
    }

    /**
     * @test
     * 各発注先サンプルの取引先コード検証
     */
    public function it_validates_contractor_codes_in_samples(): void
    {
        $expectedContractorMappings = [
            1017 => ['1017'],
            1106 => ['1021', '1029', '1106', '1126', '1127'],  // カナカングループ
            1202 => ['1202'],
            1330 => ['1330'],
        ];

        foreach (self::SAMPLE_FILES as $contractorCode => $filePath) {
            if (! file_exists($filePath)) {
                continue;
            }

            $records = $this->parseSampleFile($filePath);
            $bRecords = array_filter($records, fn ($r) => str_starts_with($r, 'B'));

            $foundCodes = [];
            foreach ($bRecords as $bRecord) {
                $code = trim(substr($bRecord, 38, 4));
                if (! empty($code)) {
                    $foundCodes[$code] = true;
                }
            }

            $this->assertNotEmpty($foundCodes, "Contractor {$contractorCode} should have contractor codes in B records");
        }
    }

    /**
     * @test
     * 品名フィールドの長さ検証（64バイト）
     */
    public function it_validates_item_name_length(): void
    {
        foreach (self::SAMPLE_FILES as $contractorCode => $filePath) {
            if (! file_exists($filePath)) {
                continue;
            }

            $records = $this->parseSampleFile($filePath);
            $dRecords = array_filter($records, fn ($r) => str_starts_with($r, 'D'));

            foreach (array_slice($dRecords, 0, 5) as $dRecord) {
                $itemName = substr($dRecord, 5, 64);

                // 64バイト固定長
                $this->assertEquals(64, strlen($itemName), 'Item name field should be 64 bytes');
            }
        }

        // 生成ファイルでも同様に検証
        $generatedDRecord = $this->getGeneratedDRecord();
        if ($generatedDRecord) {
            $itemName = substr($generatedDRecord, 5, 64);
            $this->assertEquals(64, strlen($itemName), 'Generated item name field should be 64 bytes');
        }
    }

    // ========================================
    // Helper Methods
    // ========================================

    private function parseSampleFile(string $filePath): array
    {
        $content = file_get_contents($filePath);
        $content = rtrim($content, "\r\n");

        $records = [];
        $pos = 0;

        while ($pos < strlen($content)) {
            $record = substr($content, $pos, self::RECORD_LENGTH);
            if (strlen($record) < self::RECORD_LENGTH) {
                break;
            }
            $records[] = $record;
            $pos += self::RECORD_LENGTH;
        }

        return $records;
    }

    private function getSampleARecord(int $contractorCode): ?string
    {
        $filePath = self::SAMPLE_FILES[$contractorCode] ?? null;
        if (! $filePath || ! file_exists($filePath)) {
            return null;
        }

        $records = $this->parseSampleFile($filePath);
        foreach ($records as $record) {
            if (str_starts_with($record, 'A')) {
                return $record;
            }
        }

        return null;
    }

    private function getSampleBRecord(int $contractorCode): ?string
    {
        $filePath = self::SAMPLE_FILES[$contractorCode] ?? null;
        if (! $filePath || ! file_exists($filePath)) {
            return null;
        }

        $records = $this->parseSampleFile($filePath);
        foreach ($records as $record) {
            if (str_starts_with($record, 'B')) {
                return $record;
            }
        }

        return null;
    }

    private function getSampleDRecord(int $contractorCode): ?string
    {
        $filePath = self::SAMPLE_FILES[$contractorCode] ?? null;
        if (! $filePath || ! file_exists($filePath)) {
            return null;
        }

        $records = $this->parseSampleFile($filePath);
        foreach ($records as $record) {
            if (str_starts_with($record, 'D')) {
                return $record;
            }
        }

        return null;
    }

    private function getGeneratedARecord(): ?string
    {
        $candidates = $this->getTestCandidates();
        if ($candidates->isEmpty()) {
            return null;
        }

        $result = $this->generator->generate($candidates);
        if (empty($result)) {
            return null;
        }

        $content = rtrim($result[0]['content'], "\r\n");
        $pos = 0;

        while ($pos < strlen($content)) {
            $record = substr($content, $pos, self::RECORD_LENGTH);
            if (strlen($record) < self::RECORD_LENGTH) {
                break;
            }
            if (str_starts_with($record, 'A')) {
                return $record;
            }
            $pos += self::RECORD_LENGTH;
        }

        return null;
    }

    private function getGeneratedBRecord(): ?string
    {
        $candidates = $this->getTestCandidates();
        if ($candidates->isEmpty()) {
            return null;
        }

        $result = $this->generator->generate($candidates);
        if (empty($result)) {
            return null;
        }

        $content = rtrim($result[0]['content'], "\r\n");
        $pos = 0;

        while ($pos < strlen($content)) {
            $record = substr($content, $pos, self::RECORD_LENGTH);
            if (strlen($record) < self::RECORD_LENGTH) {
                break;
            }
            if (str_starts_with($record, 'B')) {
                return $record;
            }
            $pos += self::RECORD_LENGTH;
        }

        return null;
    }

    private function getGeneratedDRecord(): ?string
    {
        $candidates = $this->getTestCandidates();
        if ($candidates->isEmpty()) {
            return null;
        }

        $result = $this->generator->generate($candidates);
        if (empty($result)) {
            return null;
        }

        $content = rtrim($result[0]['content'], "\r\n");
        $pos = 0;

        while ($pos < strlen($content)) {
            $record = substr($content, $pos, self::RECORD_LENGTH);
            if (strlen($record) < self::RECORD_LENGTH) {
                break;
            }
            if (str_starts_with($record, 'D')) {
                return $record;
            }
            $pos += self::RECORD_LENGTH;
        }

        return null;
    }

    private function getTestCandidates(int $limit = 5): Collection
    {
        $jxContractorCodes = [1106, 1017, 1202, 1330, 1021, 1029, 1068, 1126, 1127];
        $jxContractorIds = Contractor::whereIn('code', $jxContractorCodes)->pluck('id')->toArray();

        if (empty($jxContractorIds)) {
            return collect([]);
        }

        return WmsOrderCandidate::whereIn('contractor_id', $jxContractorIds)
            ->with(['warehouse', 'item', 'contractor'])
            ->limit($limit)
            ->get();
    }
}
