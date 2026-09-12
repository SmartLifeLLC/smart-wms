<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Models\Sakemaru\Contractor;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\Generators\HanaOrderJXFileGenerator;
use Illuminate\Support\Collection;
use Tests\Support\FakeLegacyEosSlipNumberService;
use Tests\TestCase;

/**
 * HanaOrderFileGenerator サンプルファイル検証テスト
 *
 * サンプルファイルのフォーマットを解析し、
 * 生成されるファイルが同じフォーマットに準拠することを確認する。
 *
 * 実DBの既存データを利用してテストを実行する。
 * DB:fresh, refreshなどのリセットは一切行わない。
 */
class HanaOrderFileSampleValidationTest extends TestCase
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
     * 1017サンプルファイルのフォーマット検証
     */
    public function it_validates_1017_sample_file_format(): void
    {
        $this->validateSampleFileFormat(1017);
    }

    /**
     * @test
     * 1106サンプルファイルのフォーマット検証
     */
    public function it_validates_1106_sample_file_format(): void
    {
        $this->validateSampleFileFormat(1106);
    }

    /**
     * @test
     * 1202サンプルファイルのフォーマット検証
     */
    public function it_validates_1202_sample_file_format(): void
    {
        $this->validateSampleFileFormat(1202);
    }

    /**
     * @test
     * 1330サンプルファイルのフォーマット検証
     */
    public function it_validates_1330_sample_file_format(): void
    {
        $this->validateSampleFileFormat(1330);
    }

    /**
     * @test
     * 全サンプルファイルのAレコード構造検証
     */
    public function it_validates_a_record_structure_in_all_samples(): void
    {
        foreach (self::SAMPLE_FILES as $contractorCode => $filePath) {
            if (! file_exists($filePath)) {
                continue;
            }

            $records = $this->parseSampleFile($filePath);
            $aRecords = array_filter($records, fn ($r) => str_starts_with($r, 'A'));

            $this->assertCount(1, $aRecords, "Contractor {$contractorCode} should have exactly 1 A record");

            $aRecord = reset($aRecords);
            $this->validateARecordStructure($aRecord, $contractorCode);
        }
    }

    /**
     * @test
     * 全サンプルファイルのBレコード構造検証
     */
    public function it_validates_b_record_structure_in_all_samples(): void
    {
        foreach (self::SAMPLE_FILES as $contractorCode => $filePath) {
            if (! file_exists($filePath)) {
                continue;
            }

            $records = $this->parseSampleFile($filePath);
            $bRecords = array_filter($records, fn ($r) => str_starts_with($r, 'B'));

            $this->assertNotEmpty($bRecords, "Contractor {$contractorCode} should have B records");

            foreach ($bRecords as $bRecord) {
                $this->validateBRecordStructure($bRecord, $contractorCode);
            }
        }
    }

    /**
     * @test
     * 全サンプルファイルのDレコード構造検証
     */
    public function it_validates_d_record_structure_in_all_samples(): void
    {
        foreach (self::SAMPLE_FILES as $contractorCode => $filePath) {
            if (! file_exists($filePath)) {
                continue;
            }

            $records = $this->parseSampleFile($filePath);
            $dRecords = array_filter($records, fn ($r) => str_starts_with($r, 'D'));

            $this->assertNotEmpty($dRecords, "Contractor {$contractorCode} should have D records");

            foreach ($dRecords as $dRecord) {
                $this->validateDRecordStructure($dRecord, $contractorCode);
            }
        }
    }

    /**
     * @test
     * サンプルファイルからJANコードを抽出し検証
     */
    public function it_extracts_valid_jan_codes_from_samples(): void
    {
        $allJanCodes = [];

        foreach (self::SAMPLE_FILES as $contractorCode => $filePath) {
            if (! file_exists($filePath)) {
                continue;
            }

            $records = $this->parseSampleFile($filePath);
            $dRecords = array_filter($records, fn ($r) => str_starts_with($r, 'D'));

            foreach ($dRecords as $dRecord) {
                $janCode = trim(substr($dRecord, 69, 13));
                if (! empty($janCode) && $janCode !== '0000000000000') {
                    $allJanCodes[] = [
                        'contractor' => $contractorCode,
                        'jan_code' => $janCode,
                    ];

                    // JANコードの形式検証（13桁または8桁の数字）
                    $this->assertMatchesRegularExpression(
                        '/^\d{8,13}$/',
                        $janCode,
                        "JAN code should be 8-13 digits: {$janCode}"
                    );
                }
            }
        }

        $this->assertNotEmpty($allJanCodes, 'Should extract JAN codes from sample files');
    }

    /**
     * @test
     * サンプルファイルからケース数・バラ数量を抽出し検証
     */
    public function it_extracts_valid_quantities_from_samples(): void
    {
        foreach (self::SAMPLE_FILES as $contractorCode => $filePath) {
            if (! file_exists($filePath)) {
                continue;
            }

            $records = $this->parseSampleFile($filePath);
            $dRecords = array_filter($records, fn ($r) => str_starts_with($r, 'D'));

            foreach ($dRecords as $dRecord) {
                $caseQty = intval(trim(substr($dRecord, 88, 6)));
                $cases = intval(trim(substr($dRecord, 94, 7)));
                $pieces = intval(trim(substr($dRecord, 101, 7)));

                // 仕入入数は正の整数
                $this->assertGreaterThanOrEqual(0, $caseQty, 'Case qty should be >= 0');

                // ケース数・バラ数量は0以上
                $this->assertGreaterThanOrEqual(0, $cases, 'Cases should be >= 0');
                $this->assertGreaterThanOrEqual(0, $pieces, 'Pieces should be >= 0');

                // 少なくとも一方は発注がある
                $this->assertTrue(
                    $cases > 0 || $pieces > 0,
                    "Either cases or pieces should be > 0 in contractor {$contractorCode}"
                );
            }
        }
    }

    /**
     * @test
     * 生成ファイルのAレコードがサンプルと同じ構造
     */
    public function it_generates_a_record_matching_sample_structure(): void
    {
        $candidates = $this->getTestCandidates();
        if ($candidates->isEmpty()) {
            $this->markTestSkipped('No test data available');
        }

        $result = $this->generator->generate($candidates);
        if (empty($result)) {
            $this->markTestSkipped('No result generated');
        }

        $generatedRecords = $this->parseGeneratedContent($result[0]['content']);
        $aRecords = array_filter($generatedRecords, fn ($r) => str_starts_with($r, 'A'));

        $this->assertCount(1, $aRecords, 'Should have exactly 1 A record');

        $aRecord = reset($aRecords);
        $this->assertEquals(self::RECORD_LENGTH, strlen($aRecord), 'A record should be 128 bytes');

        // Aレコード構造検証
        $this->assertEquals('A', substr($aRecord, 0, 1), 'Position 1: Record type');
        $this->assertEquals('01', substr($aRecord, 1, 2), 'Position 2-3: Data type');
        $this->assertMatchesRegularExpression('/^\d{8}$/', substr($aRecord, 3, 8), 'Position 4-11: Date');
        $this->assertMatchesRegularExpression('/^\d{6}$/', substr($aRecord, 11, 6), 'Position 12-17: Time');
    }

    /**
     * @test
     * 生成ファイルのBレコードがサンプルと同じ構造
     */
    public function it_generates_b_record_matching_sample_structure(): void
    {
        $candidates = $this->getTestCandidates();
        if ($candidates->isEmpty()) {
            $this->markTestSkipped('No test data available');
        }

        $result = $this->generator->generate($candidates);
        if (empty($result)) {
            $this->markTestSkipped('No result generated');
        }

        $generatedRecords = $this->parseGeneratedContent($result[0]['content']);
        $bRecords = array_filter($generatedRecords, fn ($r) => str_starts_with($r, 'B'));

        $this->assertNotEmpty($bRecords, 'Should have B records');

        foreach ($bRecords as $bRecord) {
            $this->assertEquals(self::RECORD_LENGTH, strlen($bRecord), 'B record should be 128 bytes');

            // Bレコード構造検証
            $this->assertEquals('B', substr($bRecord, 0, 1), 'Position 1: Record type');
            $this->assertEquals('01', substr($bRecord, 1, 2), 'Position 2-3: Data type');
            $this->assertEquals('01', substr($bRecord, 21, 2), 'Position 22-23: Slip type');
            $this->assertMatchesRegularExpression('/^\d{6}$/', substr($bRecord, 23, 6), 'Position 24-29: Order date');
            $this->assertMatchesRegularExpression('/^\d{6}$/', substr($bRecord, 29, 6), 'Position 30-35: Delivery date');
        }
    }

    /**
     * @test
     * 生成ファイルのDレコードがサンプルと同じ構造
     */
    public function it_generates_d_record_matching_sample_structure(): void
    {
        $candidates = $this->getTestCandidates();
        if ($candidates->isEmpty()) {
            $this->markTestSkipped('No test data available');
        }

        $result = $this->generator->generate($candidates);
        if (empty($result)) {
            $this->markTestSkipped('No result generated');
        }

        $generatedRecords = $this->parseGeneratedContent($result[0]['content']);
        $dRecords = array_filter($generatedRecords, fn ($r) => str_starts_with($r, 'D'));

        $this->assertNotEmpty($dRecords, 'Should have D records');

        foreach ($dRecords as $dRecord) {
            $this->assertEquals(self::RECORD_LENGTH, strlen($dRecord), 'D record should be 128 bytes');

            // Dレコード構造検証
            $this->assertEquals('D', substr($dRecord, 0, 1), 'Position 1: Record type');
            $this->assertEquals('01', substr($dRecord, 1, 2), 'Position 2-3: Data type');
            $this->assertMatchesRegularExpression('/^\d{2}$/', substr($dRecord, 3, 2), 'Position 4-5: Line number');

            // 数量フィールドの検証
            $caseQty = substr($dRecord, 88, 6);
            $cases = substr($dRecord, 94, 7);
            $pieces = substr($dRecord, 101, 7);

            $this->assertMatchesRegularExpression('/^\d{6}$/', $caseQty, 'Position 89-94: Case qty');
            $this->assertMatchesRegularExpression('/^\d{7}$/', $cases, 'Position 95-101: Cases');
            $this->assertMatchesRegularExpression('/^\d{7}$/', $pieces, 'Position 102-108: Pieces');
        }
    }

    /**
     * @test
     * レコード順序の検証（A→B→D→B→D...）
     */
    public function it_validates_record_order_matches_sample(): void
    {
        foreach (self::SAMPLE_FILES as $contractorCode => $filePath) {
            if (! file_exists($filePath)) {
                continue;
            }

            $records = $this->parseSampleFile($filePath);

            // 最初はAレコード
            $this->assertEquals('A', substr($records[0], 0, 1), 'First record should be A');

            // Aの後はB
            $this->assertEquals('B', substr($records[1], 0, 1), 'Second record should be B');

            // レコード順序のパターン検証
            $currentSection = 'A';
            for ($i = 1; $i < count($records); $i++) {
                $type = substr($records[$i], 0, 1);

                if ($currentSection === 'A') {
                    $this->assertEquals('B', $type, 'After A should come B');
                    $currentSection = 'B';
                } elseif ($currentSection === 'B') {
                    $this->assertContains($type, ['D', 'B'], 'After B should come D or another B');
                    $currentSection = $type;
                } elseif ($currentSection === 'D') {
                    $this->assertContains($type, ['D', 'B'], 'After D should come D or B');
                    $currentSection = $type;
                }
            }
        }
    }

    /**
     * @test
     * 生成ファイルのエンコーディングがSJIS
     */
    public function it_generates_file_with_sjis_encoding(): void
    {
        $candidates = $this->getTestCandidates();
        if ($candidates->isEmpty()) {
            $this->markTestSkipped('No test data available');
        }

        $result = $this->generator->generate($candidates);
        if (empty($result)) {
            $this->markTestSkipped('No result generated');
        }

        $content = $result[0]['content'];

        // SJISでエンコードされていることを確認
        $detected = mb_detect_encoding($content, ['SJIS', 'SJIS-win', 'UTF-8'], true);
        $this->assertContains($detected, ['SJIS', 'SJIS-win'], 'Content should be SJIS encoded');

        // エンコーディング属性の確認
        $this->assertEquals('SJIS', $result[0]['encoding']);
    }

    /**
     * @test
     * サンプルファイルのレコード数とAレコードの件数フィールドが一致
     */
    public function it_validates_record_count_in_a_record(): void
    {
        foreach (self::SAMPLE_FILES as $contractorCode => $filePath) {
            if (! file_exists($filePath)) {
                continue;
            }

            $records = $this->parseSampleFile($filePath);
            $aRecord = $records[0];

            $recordCount = intval(trim(substr($aRecord, 33, 6)));
            $documentCount = intval(trim(substr($aRecord, 39, 6)));

            // レコード件数 = 全レコード数
            $this->assertEquals(
                count($records),
                $recordCount,
                "Contractor {$contractorCode}: Record count in A record should match actual records"
            );
        }
    }

    /**
     * @test
     * 送信先集約の検証（カナカングループは1106に集約）
     */
    public function it_validates_kanakan_group_consolidation(): void
    {
        // カナカングループの発注先
        $kanakanCodes = [1021, 1029, 1068, 1126, 1127];
        $kanakanContractorIds = Contractor::whereIn('code', $kanakanCodes)->pluck('id')->toArray();

        if (empty($kanakanContractorIds)) {
            $this->markTestSkipped('Kanakan contractors not found');
        }

        $candidates = WmsOrderCandidate::whereIn('contractor_id', $kanakanContractorIds)
            ->with(['warehouse', 'item', 'contractor'])
            ->limit(5)
            ->get();

        if ($candidates->isEmpty()) {
            $this->markTestSkipped('No Kanakan candidates available');
        }

        $result = $this->generator->generate($candidates);

        // 1ファイルに集約されているはず
        $this->assertCount(1, $result, 'Kanakan group should produce 1 file');
        $this->assertEquals(1106, $result[0]['contractor_code'], 'Should be consolidated to 1106');
    }

    /**
     * @test
     * 各発注先別にファイル生成
     */
    public function it_generates_separate_files_for_each_contractor(): void
    {
        // 異なる発注先の候補を取得
        $contractor1202 = Contractor::where('code', 1202)->first();
        $contractor1330 = Contractor::where('code', 1330)->first();

        if (! $contractor1202 || ! $contractor1330) {
            $this->markTestSkipped('Contractors not found');
        }

        $candidates1202 = WmsOrderCandidate::where('contractor_id', $contractor1202->id)
            ->with(['warehouse', 'item', 'contractor'])
            ->limit(3)
            ->get();

        $candidates1330 = WmsOrderCandidate::where('contractor_id', $contractor1330->id)
            ->with(['warehouse', 'item', 'contractor'])
            ->limit(3)
            ->get();

        if ($candidates1202->isEmpty() && $candidates1330->isEmpty()) {
            $this->markTestSkipped('No candidates available');
        }

        $combined = $candidates1202->merge($candidates1330);

        if ($combined->groupBy('contractor_id')->count() < 2) {
            $this->markTestSkipped('Not enough different contractors');
        }

        $result = $this->generator->generate($combined);

        // 発注先ごとに別ファイル
        $this->assertGreaterThanOrEqual(2, count($result), 'Should generate files for each contractor');

        $contractorCodes = collect($result)->pluck('contractor_code')->unique()->toArray();
        $this->assertContains(1202, $contractorCodes);
        $this->assertContains(1330, $contractorCodes);
    }

    // ========================================
    // Helper Methods
    // ========================================

    /**
     * サンプルファイルのフォーマットを検証
     */
    private function validateSampleFileFormat(int $contractorCode): void
    {
        $filePath = self::SAMPLE_FILES[$contractorCode] ?? null;

        if (! $filePath || ! file_exists($filePath)) {
            $this->markTestSkipped("Sample file for {$contractorCode} not found");
        }

        $records = $this->parseSampleFile($filePath);

        // レコードが存在すること
        $this->assertNotEmpty($records, "Should have records in {$contractorCode} sample");

        // 全レコードが128バイト
        foreach ($records as $i => $record) {
            $this->assertEquals(
                self::RECORD_LENGTH,
                strlen($record),
                "Record {$i} in {$contractorCode} should be 128 bytes, got ".strlen($record)
            );
        }

        // レコードタイプがA, B, Dのいずれか
        foreach ($records as $record) {
            $type = substr($record, 0, 1);
            $this->assertContains($type, ['A', 'B', 'D'], "Invalid record type: {$type}");
        }

        // Aレコードは1件
        $aCount = count(array_filter($records, fn ($r) => str_starts_with($r, 'A')));
        $this->assertEquals(1, $aCount, 'Should have exactly 1 A record');
    }

    /**
     * Aレコードの構造を検証
     */
    private function validateARecordStructure(string $record, int $contractorCode): void
    {
        $this->assertEquals('A', substr($record, 0, 1), 'Position 1: Record type');
        $this->assertEquals('01', substr($record, 1, 2), 'Position 2-3: Data type');

        // 日付形式（YYYYMMDD）
        $date = substr($record, 3, 8);
        $this->assertMatchesRegularExpression('/^20\d{6}$/', $date, 'Position 4-11: Invalid date format');

        // 時刻形式（HHMMSS）
        $time = substr($record, 11, 6);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $time, 'Position 12-17: Invalid time format');

        // レコード件数（6桁数字）
        $recordCount = substr($record, 33, 6);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $recordCount, 'Position 34-39: Invalid record count');

        // 社名が含まれている
        $companyName = mb_convert_encoding(substr($record, 45, 15), 'UTF-8', 'SJIS');
        $this->assertStringContainsString('ﾘｶｰﾜｰﾙﾄﾞ', $companyName, 'Position 46-60: Company name');
    }

    /**
     * Bレコードの構造を検証
     */
    private function validateBRecordStructure(string $record, int $contractorCode): void
    {
        $this->assertEquals('B', substr($record, 0, 1), 'Position 1: Record type');
        $this->assertEquals('01', substr($record, 1, 2), 'Position 2-3: Data type');

        // 伝票区分
        $slipType = substr($record, 21, 2);
        $this->assertMatchesRegularExpression('/^\d{2}$/', $slipType, 'Position 22-23: Slip type');

        // 発注日（YYMMDD）
        $orderDate = substr($record, 23, 6);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $orderDate, 'Position 24-29: Order date');

        // 納品日（YYMMDD）
        $deliveryDate = substr($record, 29, 6);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $deliveryDate, 'Position 30-35: Delivery date');
    }

    /**
     * Dレコードの構造を検証
     */
    private function validateDRecordStructure(string $record, int $contractorCode): void
    {
        $this->assertEquals('D', substr($record, 0, 1), 'Position 1: Record type');
        $this->assertEquals('01', substr($record, 1, 2), 'Position 2-3: Data type');

        // 行番号（2桁）
        $lineNo = substr($record, 3, 2);
        $this->assertMatchesRegularExpression('/^\d{2}$/', $lineNo, 'Position 4-5: Line number');

        // 仕入入数（6桁）
        $caseQty = substr($record, 88, 6);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $caseQty, 'Position 89-94: Case qty');

        // ケース数（7桁）
        $cases = substr($record, 94, 7);
        $this->assertMatchesRegularExpression('/^\d{7}$/', $cases, 'Position 95-101: Cases');

        // バラ数量（7桁）
        $pieces = substr($record, 101, 7);
        $this->assertMatchesRegularExpression('/^\d{7}$/', $pieces, 'Position 102-108: Pieces');

        // 原単価（10桁）
        $price = substr($record, 108, 10);
        $this->assertMatchesRegularExpression('/^\d{10}$/', $price, 'Position 109-118: Price');
    }

    /**
     * サンプルファイルを128バイト単位でパース
     */
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

    /**
     * 生成コンテンツを128バイト単位でパース
     */
    private function parseGeneratedContent(string $sjisContent): array
    {
        $content = rtrim($sjisContent, "\r\n");

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

    /**
     * テスト用の発注候補を取得
     */
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
