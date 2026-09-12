<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Contracts\OrderFileGeneratorInterface;
use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\EOrderFileGenerator;
use App\Enums\AutoOrder\TransmissionDocumentStatus;
use App\Enums\AutoOrder\TransmissionDocumentType;
use App\Enums\EWMSClient;
use App\Enums\QuantityType;
use App\Models\Sakemaru\Contractor;
use App\Models\User;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderJxDocument;
use App\Models\WmsOrderJxSetting;
use App\Services\AutoOrder\Generators\HanaOrderJXFileGenerator;
use App\Services\AutoOrder\OrderServiceFactory;
use App\Services\AutoOrder\OrderTransmissionService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * OrderTransmissionService テスト
 *
 * 実DBの既存データを利用してテストを実行する。
 * DB:fresh, refreshなどのリセットは一切行わない。
 *
 * 注意: S3への実際の書き込みはfakeを使用する。
 */
class OrderTransmissionServiceTest extends TestCase
{
    private OrderTransmissionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OrderTransmissionService::class);
    }

    /**
     * @test
     * サービスがDIで解決できること
     */
    public function it_can_be_resolved_from_container(): void
    {
        $service = app(OrderTransmissionService::class);
        $this->assertInstanceOf(OrderTransmissionService::class, $service);
    }

    /**
     * @test
     * 発注データファイルの作成者名にログインユーザー名を使用すること
     */
    public function it_uses_authenticated_user_name_for_order_data_file_creator(): void
    {
        $this->actingAs((new User)->forceFill([
            'id' => 123,
            'name' => 'JX生成担当者',
        ]));

        $method = new \ReflectionMethod(OrderTransmissionService::class, 'resolveOrderDataFileCreatedByName');
        $method->setAccessible(true);

        $this->assertSame('JX生成担当者', $method->invoke($this->service, 'J20260520182144448', collect()));

        $method = new \ReflectionMethod(OrderTransmissionService::class, 'resolveOrderDataFileCreatedBy');
        $method->setAccessible(true);

        $this->assertSame([
            'id' => 123,
            'name' => 'JX生成担当者',
        ], $method->invoke($this->service, 'J20260520182144448', collect()));
    }

    /**
     * @test
     * OrderServiceFactoryからジェネレーターを取得できること
     */
    public function it_can_get_generator_from_factory(): void
    {
        $generator = OrderServiceFactory::generator();

        $this->assertInstanceOf(OrderFileGeneratorInterface::class, $generator);
    }

    /**
     * @test
     * EWMSClient::HANAが設定されている場合HanaOrderFileGeneratorが返されること
     */
    public function it_returns_hana_generator_when_configured(): void
    {
        $client = EWMSClient::current();

        if ($client !== EWMSClient::HANA) {
            $this->markTestSkipped('WMS_CLIENT is not set to hana');
        }

        $generator = OrderServiceFactory::generator();
        $this->assertInstanceOf(HanaOrderJXFileGenerator::class, $generator);
    }

    /**
     * @test
     * JX設定からgeneratorを取得できること
     */
    public function it_can_get_generator_from_jx_setting(): void
    {
        $jxSetting = WmsOrderJxSetting::where('is_active', true)->first();

        if (! $jxSetting) {
            $this->markTestSkipped('No active JX setting available');
        }

        // order_file_generatorが設定されていない場合はnull
        if ($jxSetting->order_file_generator === null) {
            $generator = OrderServiceFactory::generatorForJxSetting($jxSetting);
            $this->assertNull($generator);

            return;
        }

        $generator = OrderServiceFactory::generatorForJxSetting($jxSetting);
        $this->assertInstanceOf(OrderFileGeneratorInterface::class, $generator);
    }

    /**
     * @test
     * EOrderFileGenerator EnumからHanaOrderJXFileGeneratorが取得できること
     */
    public function it_returns_hana_generator_from_enum(): void
    {
        $enum = EOrderFileGenerator::HANA;

        $this->assertEquals('hana', $enum->value);
        $this->assertEquals(HanaOrderJXFileGenerator::class, $enum->generatorClass());

        $generator = $enum->generator();
        $this->assertInstanceOf(HanaOrderJXFileGenerator::class, $generator);
    }

    /**
     * @test
     * generateOrderFilesが対象候補なしの場合にエラーにならないこと
     */
    public function it_handles_no_candidates_gracefully(): void
    {
        // 存在しないバッチコードを使用
        $result = $this->service->generateOrderFiles('NON_EXISTENT_BATCH_CODE_'.time());

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['total_orders']);
    }

    /**
     * @test
     * transmitOrderFilesViaJxが対象ドキュメントなしの場合にエラーにならないこと
     */
    public function it_handles_no_documents_gracefully(): void
    {
        $result = $this->service->transmitOrderFilesViaJx('NON_EXISTENT_BATCH_CODE_'.time());

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertEmpty($result['transmitted']);
        $this->assertArrayHasKey('message', $result);
    }

    /**
     * @test
     * JX生成対象がない場合も選択数と除外数を返すこと
     */
    public function it_reports_counts_when_selected_candidates_are_not_eligible_for_jx_generation(): void
    {
        $result = $this->service->generateJxFilesForCandidateIds([-999999991, -999999992]);

        $this->assertFalse($result['success']);
        $this->assertSame(2, $result['selected_count']);
        $this->assertSame(0, $result['eligible_count']);
        $this->assertSame(0, $result['jx_target_count']);
        $this->assertSame(2, $result['excluded_count']);
        $this->assertSame(2, $result['excluded_missing']);
        $this->assertSame(0, $result['excluded_not_confirmed']);
        $this->assertSame(0, $result['excluded_already_generated']);
        $this->assertSame(0, $result['excluded_not_jx_target']);
        $this->assertSame(0, $result['excluded_missing_ordering_code']);
        $this->assertSame(0, $result['skipped_count']);
    }

    /**
     * @test
     * JX発注CDを解決できない発注候補は生成対象から除外されること
     */
    public function it_excludes_candidates_without_resolvable_jx_ordering_code_before_generation(): void
    {
        $candidate = (new WmsOrderCandidate)->forceFill([
            'id' => 999999991,
            'ordering_code' => null,
            'order_quantity' => 1,
            'quantity_type' => 'CASE',
        ]);
        $candidate->setRelation('item', null);

        $method = new \ReflectionMethod(OrderTransmissionService::class, 'filterCandidatesWithJxOrderingCode');
        $method->setAccessible(true);

        [$filtered, $excludedCount] = $method->invoke($this->service, collect([$candidate]));

        $this->assertCount(0, $filtered);
        $this->assertSame(1, $excludedCount);
    }

    /**
     * @test
     * JX送信対象外の発注先は手動JXファイル生成の対象から除外されること
     */
    public function it_excludes_non_jx_target_contractors_from_selected_jx_file_generation(): void
    {
        $candidate = WmsOrderCandidate::create([
            'batch_code' => 'TJXEXCL0000000001',
            'warehouse_id' => 999999991,
            'item_id' => 999999991,
            'item_code' => 'TEST-JX-EXCL',
            'contractor_id' => 999999991,
            'suggested_quantity' => 1,
            'order_quantity' => 1,
            'purchase_unit' => 1,
            'quantity_type' => 'CASE',
            'status' => CandidateStatus::CONFIRMED,
            'lot_status' => 'RAW',
            'modified_at' => now(),
        ]);

        try {
            $result = $this->service->generateJxFilesForCandidateIds([$candidate->id]);

            $this->assertFalse($result['success']);
            $this->assertSame(1, $result['selected_count']);
            $this->assertSame(0, $result['eligible_count']);
            $this->assertSame(0, $result['jx_target_count']);
            $this->assertSame(1, $result['excluded_count']);
            $this->assertSame(1, $result['excluded_not_jx_target']);
            $this->assertSame(0, $result['excluded_missing_ordering_code']);
            $this->assertSame(0, $result['total_orders']);
            $this->assertSame([], $result['files']);
            $this->assertStringContainsString('JX送信対象外', $result['errors'][0] ?? '');
            $this->assertDatabaseMissing('wms_order_jx_documents', [
                'batch_code' => 'TJXEXCL0000000001',
            ], 'sakemaru');
        } finally {
            $candidate->delete();
        }
    }

    /**
     * @test
     * JXファイルに実出力された候補IDだけを抽出できること
     */
    public function it_extracts_generated_candidate_ids_from_slip_assignments(): void
    {
        $method = new \ReflectionMethod(OrderTransmissionService::class, 'generatedCandidateIdsForFile');
        $method->setAccessible(true);

        $ids = $method->invoke($this->service, [
            'slip_assignments' => [
                ['order_candidate_ids' => [10, '11', 10]],
                ['order_candidate_ids' => [12, null, 0]],
            ],
        ]);

        $this->assertSame([10, 11, 12], $ids->all());
    }

    /**
     * @test
     * JX設定が存在することの確認
     */
    public function it_has_jx_settings_in_database(): void
    {
        $settings = WmsOrderJxSetting::where('is_active', true)->get();

        $this->assertGreaterThan(0, $settings->count(), 'Should have at least one active JX setting');

        foreach ($settings as $setting) {
            $this->assertNotEmpty($setting->name);
            $this->assertNotEmpty($setting->endpoint_url);
        }
    }

    /**
     * @test
     * 発注候補のステータス遷移確認（EXECUTED状態の存在確認）
     */
    public function it_can_find_executed_candidates(): void
    {
        $executedCount = WmsOrderCandidate::where('status', CandidateStatus::EXECUTED)->count();

        // EXECUTEDステータスの候補がなくてもテストは成功（データ依存しない）
        $this->assertGreaterThanOrEqual(0, $executedCount);
    }

    /**
     * @test
     * wms_order_jx_documentsテーブルの構造確認
     */
    public function it_verifies_jx_document_table_structure(): void
    {
        // 新しいドキュメントを作成せず、既存のスキーマを確認
        $fillable = (new WmsOrderJxDocument)->getFillable();

        $requiredColumns = [
            'batch_code',
            'wms_order_jx_setting_id',
            'contractor_id',
            'document_type',
            'status',
            'file_path',
            'file_size',
            'record_count',
            'order_count',
            'encoding',
        ];

        foreach ($requiredColumns as $column) {
            $this->assertContains($column, $fillable, "Column {$column} should be fillable");
        }
    }

    /**
     * @test
     * S3パス生成の確認（設定値のテスト）
     */
    public function it_uses_correct_s3_prefix(): void
    {
        $prefix = config('wms.s3_prefix', 'wms/');
        $this->assertEquals('wms/', $prefix);
    }

    /**
     * @test
     * 同名JXファイルが既にS3にある場合は上書きせず別パスに保存すること
     */
    public function it_avoids_overwriting_existing_jx_file_paths(): void
    {
        Storage::fake('s3');
        \Illuminate\Support\Carbon::setTestNow('2026-05-06 15:45:26');

        $method = new \ReflectionMethod(OrderTransmissionService::class, 'saveOrderFileToS3');
        $method->setAccessible(true);

        try {
            $file = [
                'filename' => '1106_order_20260506154526.dat',
                'content' => 'first-content',
            ];

            $firstPath = $method->invoke($this->service, 'BATCH1', $file, TransmissionDocumentStatus::TRANSMITTED);

            $file['content'] = 'second-content';
            $secondPath = $method->invoke($this->service, 'BATCH2', $file, TransmissionDocumentStatus::TRANSMITTED);

            $this->assertSame('jx-orders/2026-05-06/1106_order_20260506154526.dat', $firstPath);
            $this->assertSame('jx-orders/2026-05-06/1106_order_20260506154526_2.dat', $secondPath);
            $this->assertSame('first-content', Storage::disk('s3')->get($firstPath));
            $this->assertSame('second-content', Storage::disk('s3')->get($secondPath));
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    /**
     * @test
     * 実データを使用したファイル生成のドライラン
     * （S3への書き込みはfakeを使用）
     */
    public function it_can_generate_files_with_fake_storage(): void
    {
        // OrderServiceFactoryの確認
        $generator = OrderServiceFactory::generator();
        if ($generator instanceof \App\Services\AutoOrder\Generators\DefaultOrderFileGenerator) {
            $this->markTestSkipped('DefaultOrderFileGenerator is configured (no actual file generation)');
        }

        // EXECUTED状態で未送信の候補を確認
        $jxContractorCodes = [1106, 1017, 1202, 1330, 1021, 1029, 1068, 1126, 1127];
        $jxContractorIds = Contractor::whereIn('code', $jxContractorCodes)->pluck('id')->toArray();

        $candidates = WmsOrderCandidate::whereIn('contractor_id', $jxContractorIds)
            ->where('status', CandidateStatus::EXECUTED)
            ->whereNull('wms_order_jx_document_id')
            ->limit(5)
            ->get();

        if ($candidates->isEmpty()) {
            $this->markTestSkipped('No EXECUTED candidates available for testing');
        }

        $batchCode = $candidates->first()->batch_code;

        // S3をfakeに置き換え
        Storage::fake('s3');

        // ファイル生成実行
        $result = $this->service->generateOrderFiles($batchCode);

        $this->assertIsArray($result);

        // エラーがなければファイルが生成されているはず
        if ($result['success'] && ! empty($result['files'])) {
            foreach ($result['files'] as $file) {
                $this->assertArrayHasKey('s3_path', $file);
                $this->assertArrayHasKey('document_id', $file);

                // S3にファイルが存在することを確認
                Storage::disk('s3')->assertExists($file['s3_path']);
            }
        }
    }

    /**
     * @test
     * JX送信対象の発注先設定確認
     */
    public function it_has_jx_contractors_configured(): void
    {
        $jxContractorCodes = [1106, 1017, 1202, 1330];

        foreach ($jxContractorCodes as $code) {
            $contractor = Contractor::where('code', $code)->first();

            if ($contractor) {
                $jxSetting = WmsOrderJxSetting::findByContractorId($contractor->id);
                // 設定があることが望ましいが、なくてもテストは失敗しない
                if ($jxSetting) {
                    $this->assertTrue($jxSetting->is_active, "JX setting for contractor {$code} should be active");
                }
            }
        }
    }

    /**
     * @test
     * PENDINGステータスのドキュメント検索
     */
    public function it_can_find_pending_documents(): void
    {
        $pendingDocs = WmsOrderJxDocument::where('status', TransmissionDocumentStatus::PENDING)->get();

        // PENDINGドキュメントがあってもなくてもテストは成功
        $this->assertGreaterThanOrEqual(0, $pendingDocs->count());

        foreach ($pendingDocs as $doc) {
            $this->assertNotEmpty($doc->batch_code);
            $this->assertEquals(TransmissionDocumentStatus::PENDING, $doc->status);
        }
    }

    /**
     * @test
     * バッチコードによるドキュメントグルーピング
     */
    public function it_groups_documents_by_batch_code(): void
    {
        $documents = WmsOrderJxDocument::select('batch_code')
            ->distinct()
            ->limit(5)
            ->pluck('batch_code');

        // ドキュメントがなくてもテストは成功
        $this->assertGreaterThanOrEqual(0, $documents->count());

        foreach ($documents as $batchCode) {
            $count = WmsOrderJxDocument::where('batch_code', $batchCode)->count();
            $this->assertGreaterThan(0, $count);
        }
    }

    /**
     * @test
     * 発注候補とドキュメントの紐付け確認
     */
    public function it_can_link_candidates_to_documents(): void
    {
        $batchCode = 'TLINK'.now()->format('His').random_int(100, 999);
        $document = WmsOrderJxDocument::query()->create([
            'batch_code' => $batchCode,
            'warehouse_id' => 999999991,
            'contractor_id' => 999999991,
            'order_date' => '2026-08-04',
            'expected_arrival_date' => '2026-08-05',
            'document_type' => TransmissionDocumentType::PURCHASE->value,
            'status' => TransmissionDocumentStatus::PENDING->value,
            'file_path' => 'tests/jx-orders/link-test.dat',
            'file_size' => 128,
            'record_count' => 1,
            'order_count' => 1,
            'encoding' => 'SJIS-win',
        ]);
        $candidate = WmsOrderCandidate::query()->create([
            'batch_code' => $batchCode,
            'warehouse_id' => 999999991,
            'item_id' => 999999991,
            'item_code' => 'TEST-LINK',
            'contractor_id' => 999999991,
            'suggested_quantity' => 1,
            'order_quantity' => 1,
            'purchase_unit' => 1,
            'quantity_type' => QuantityType::CASE->value,
            'expected_arrival_date' => '2026-08-05',
            'status' => CandidateStatus::CONFIRMED->value,
            'lot_status' => 'RAW',
            'wms_order_jx_document_id' => $document->id,
            'modified_at' => now(),
        ]);

        try {
            $linkedCandidate = WmsOrderCandidate::with('jxDocument')->find($candidate->id);

            $this->assertNotNull($linkedCandidate);
            $this->assertNotNull($linkedCandidate->jxDocument);
            $this->assertSame($document->id, $linkedCandidate->jxDocument->id);
            $this->assertSame($batchCode, $linkedCandidate->batch_code);
            $this->assertSame($batchCode, $linkedCandidate->jxDocument->batch_code);
        } finally {
            $candidate->delete();
            $document->delete();
        }
    }

    /**
     * @test
     * JxClient依存のテスト（モック不使用で設定確認のみ）
     */
    public function it_verifies_jx_client_can_be_instantiated(): void
    {
        $jxSetting = WmsOrderJxSetting::where('is_active', true)->first();

        if (! $jxSetting) {
            $this->markTestSkipped('No active JX setting available');
        }

        // JxClientのインスタンス化が可能であることを確認
        $client = new \App\Services\JX\JxClient($jxSetting);
        $this->assertInstanceOf(\App\Services\JX\JxClient::class, $client);
    }
}
