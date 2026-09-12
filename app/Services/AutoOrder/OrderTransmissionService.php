<?php

namespace App\Services\AutoOrder;

use App\Contracts\OrderFileGeneratorInterface;
use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\JobProcessName;
use App\Enums\AutoOrder\OrderChannel;
use App\Enums\AutoOrder\OrderDataFileChannel;
use App\Enums\AutoOrder\OrderDataFileStatus;
use App\Enums\AutoOrder\TransmissionDocumentStatus;
use App\Enums\AutoOrder\TransmissionDocumentType;
use App\Enums\AutoOrder\TransmissionType;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\User as SakemaruUser;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderDataFile;
use App\Models\WmsOrderIncomingSchedule;
use App\Models\WmsOrderJxDocument;
use App\Models\WmsOrderJxSetting;
use App\Models\WmsOrderSlipNumberAssignment;
use App\Models\WmsOrderTransmissionLog;
use App\Services\AutoOrder\Generators\HanaOrderJXFileGenerator;
use App\Services\JX\JxClient;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 発注送信サービス
 */
class OrderTransmissionService
{
    public function __construct(
        private readonly OrderValidationService $validationService
    ) {}

    /**
     * 確定済み発注候補を送信
     *
     * @throws \RuntimeException バリデーションエラー時
     */
    public function transmitConfirmedOrders(string $batchCode): WmsAutoOrderJobControl
    {
        if (WmsAutoOrderJobControl::hasRunningJob(JobProcessName::ORDER_TRANSMISSION)) {
            throw new \RuntimeException('Order transmission job is already running');
        }

        // 送信前バリデーション: 全候補がCONFIRMED状態であることを確認
        $validation = $this->validationService->validateBatchForTransmission($batchCode);
        if (! $validation['valid']) {
            throw new \RuntimeException($validation['message']);
        }

        $job = WmsAutoOrderJobControl::startJob(
            processName: JobProcessName::ORDER_TRANSMISSION,
            createdBy: auth()->id(),
        );
        $job->update(['batch_code' => $batchCode]);

        try {
            // 確定済みの発注候補をグループ化（APPROVED → CONFIRMED に変更）
            $candidateGroups = WmsOrderCandidate::where('batch_code', $batchCode)
                ->where('status', CandidateStatus::CONFIRMED)
                ->whereNull('transmitted_at')
                ->with(['warehouse', 'item', 'contractor'])
                ->get()
                ->groupBy(fn ($c) => "{$c->warehouse_id}_{$c->contractor_id}");

            if ($candidateGroups->isEmpty()) {
                Log::info('No confirmed candidates to transmit', ['batch_code' => $batchCode]);
                $job->markAsSuccess(0);

                return $job;
            }

            $processedCount = 0;
            $totalGroups = $candidateGroups->count();

            foreach ($candidateGroups as $groupKey => $candidates) {
                [$warehouseId, $contractorId] = explode('_', $groupKey);

                $document = $this->createJxDocument(
                    $batchCode,
                    (int) $warehouseId,
                    (int) $contractorId,
                    $candidates
                );

                // 発注先のWMS送信設定を取得
                $setting = WmsContractorSetting::where('contractor_id', $contractorId)->first();
                $transmissionType = $setting?->transmission_type ?? TransmissionType::MANUAL_CSV;

                // 送信実行
                $success = $this->executeTransmission($document, $transmissionType);

                if ($success) {
                    // 候補のステータス更新
                    $candidates->each(function ($candidate) use ($document) {
                        $candidate->update([
                            'status' => CandidateStatus::EXECUTED,
                            'transmission_status' => 'TRANSMITTED',
                            'transmitted_at' => now(),
                            'wms_order_jx_document_id' => $document->id,
                        ]);
                    });
                    $processedCount += $candidates->count();
                }

                $job->updateProgress($processedCount, $totalGroups);
            }

            $job->markAsSuccess($processedCount);

            Log::info('Order transmission completed', [
                'batch_code' => $batchCode,
                'processed_count' => $processedCount,
            ]);

        } catch (\Exception $e) {
            $job->markAsFailed($e->getMessage());
            Log::error('Order transmission failed', [
                'batch_code' => $batchCode,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $job;
    }

    /**
     * JXドキュメントを作成
     */
    private function createJxDocument(
        string $batchCode,
        int $warehouseId,
        int $contractorId,
        $candidates
    ): WmsOrderJxDocument {
        return DB::transaction(function () use ($batchCode, $warehouseId, $contractorId, $candidates) {
            $quantityResolver = app(OrderOutputQuantityResolver::class);
            $totalQuantity = $quantityResolver->sumOutputOrderQuantity($candidates);

            return WmsOrderJxDocument::create([
                'batch_code' => $batchCode,
                'warehouse_id' => $warehouseId,
                'contractor_id' => $contractorId,
                'document_type' => TransmissionDocumentType::PURCHASE,
                'status' => TransmissionDocumentStatus::PENDING,
                'total_items' => $candidates->count(),
                'total_quantity' => $totalQuantity,
                'jx_request_data' => $this->buildJxRequestData($candidates, $quantityResolver),
                ...$this->createdByAttributes(),
            ]);
        });
    }

    /**
     * JX送信データを構築
     */
    private function buildJxRequestData($candidates, OrderOutputQuantityResolver $quantityResolver): array
    {
        $items = [];

        foreach ($candidates as $candidate) {
            $outputQuantity = $quantityResolver->resolve($candidate);

            $items[] = [
                'item_code' => $outputQuantity['ordering_code'] ?? $candidate->item?->jan_code ?? $candidate->item_id,
                'item_name' => $candidate->item?->item_name ?? '',
                'quantity' => $outputQuantity['order_quantity'],
                'unit' => $outputQuantity['quantity_type'],
                'expected_arrival_date' => $candidate->expected_arrival_date?->format('Y-m-d'),
            ];
        }

        return [
            'document_date' => now()->format('Y-m-d'),
            'items' => $items,
        ];
    }

    /**
     * 送信を実行
     */
    private function executeTransmission(WmsOrderJxDocument $document, TransmissionType $type): bool
    {
        try {
            if ($type === TransmissionType::JX_FINET) {
                return $this->transmitViaJxFinet($document);
            } else {
                return $this->transmitViaFtp($document);
            }
        } catch (\Exception $e) {
            $this->logTransmission($document, $type, 'TRANSMIT', 'FAILED', null, $e->getMessage());
            $document->update([
                'status' => TransmissionDocumentStatus::ERROR,
                'error_message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * JX-FINET経由で送信
     */
    private function transmitViaJxFinet(WmsOrderJxDocument $document): bool
    {
        // JX-FINET設定を取得
        $jxSetting = WmsOrderJxSetting::where('warehouse_id', $document->warehouse_id)
            ->where('is_enabled', true)
            ->first();

        if (! $jxSetting) {
            throw new \RuntimeException("JX-FINET settings not found for warehouse {$document->warehouse_id}");
        }

        // TODO: 実際のJX-FINET APIコールを実装
        // 現在はモック実装
        Log::info('Simulating JX-FINET transmission', [
            'document_id' => $document->id,
            'endpoint' => $jxSetting->endpoint_url,
        ]);

        // モック: 成功として処理
        $mockDocumentNo = 'JX'.date('Ymd').str_pad($document->id, 6, '0', STR_PAD_LEFT);

        $document->update([
            'jx_document_no' => $mockDocumentNo,
            'status' => TransmissionDocumentStatus::TRANSMITTED,
            'transmitted_at' => now(),
            'transmitted_by' => auth()->id(),
            ...$this->transmittedByAttributes(),
            'jx_response_data' => [
                'document_no' => $mockDocumentNo,
                'status' => 'ACCEPTED',
                'timestamp' => now()->toIso8601String(),
            ],
        ]);

        $this->logTransmission(
            $document,
            TransmissionType::JX_FINET,
            'TRANSMIT',
            'SUCCESS',
            $document->jx_response_data
        );

        return true;
    }

    /**
     * FTP経由で送信
     */
    private function transmitViaFtp(WmsOrderJxDocument $document): bool
    {
        // TODO: 実際のFTP送信を実装
        Log::info('Simulating FTP transmission', [
            'document_id' => $document->id,
        ]);

        // モック: 成功として処理
        $document->update([
            'status' => TransmissionDocumentStatus::TRANSMITTED,
            'transmitted_at' => now(),
            'transmitted_by' => auth()->id(),
            ...$this->transmittedByAttributes(),
        ]);
        $this->markSlipAssignmentsTransmitted([$document->id]);

        $this->logTransmission(
            $document,
            TransmissionType::FTP,
            'TRANSMIT',
            'SUCCESS'
        );

        return true;
    }

    /**
     * 送信ログを記録
     */
    private function logTransmission(
        WmsOrderJxDocument $document,
        TransmissionType $type,
        string $action,
        string $status,
        ?array $responseData = null,
        ?string $errorMessage = null
    ): void {
        WmsOrderTransmissionLog::create([
            'batch_code' => $document->batch_code,
            'wms_order_jx_document_id' => $document->id,
            'transmission_type' => $type,
            'action' => $action,
            'status' => $status,
            'request_data' => $document->jx_request_data,
            'response_data' => $responseData,
            'error_message' => $errorMessage,
            'executed_by' => auth()->id(),
        ]);
    }

    /**
     * ドキュメントの送信ステータスを確認
     */
    public function checkTransmissionStatus(WmsOrderJxDocument $document): array
    {
        if ($document->status !== TransmissionDocumentStatus::TRANSMITTED) {
            return [
                'confirmed' => false,
                'message' => 'Document not yet transmitted',
            ];
        }

        // TODO: 実際のステータス確認APIコールを実装
        // 現在はモック実装

        return [
            'confirmed' => true,
            'message' => 'Order confirmed by supplier',
        ];
    }

    /**
     * 確定済み発注候補を purchase_create_queue にバッチ登録
     *
     * 仕様書: storage/specifications/inbound/purchase-create-queue-batching.md
     *
     * グルーピング基準:
     * - warehouse_code (倉庫コード)
     * - supplier_code (仕入先コード)
     * - delivered_date (入荷日 = expected_arrival_date)
     *
     * @param  string  $batchCode  バッチコード
     * @return array ['success' => bool, 'queue_count' => int, 'candidate_count' => int, 'errors' => array]
     */
    public function transmitToPurchaseQueue(string $batchCode): array
    {
        // 送信前バリデーション: 全候補がCONFIRMED状態であることを確認
        $validation = $this->validationService->validateBatchForTransmission($batchCode);
        if (! $validation['valid']) {
            throw new \RuntimeException($validation['message']);
        }

        // 確定済みの発注候補を取得
        $candidates = WmsOrderCandidate::where('batch_code', $batchCode)
            ->where('status', CandidateStatus::CONFIRMED)
            ->whereNull('transmitted_at')
            ->with(['warehouse', 'item', 'contractor'])
            ->get();

        if ($candidates->isEmpty()) {
            Log::info('No confirmed candidates to transmit', ['batch_code' => $batchCode]);

            return [
                'success' => true,
                'queue_count' => 0,
                'candidate_count' => 0,
                'errors' => [],
            ];
        }

        // グルーピング: 倉庫 + 仕入先 + 入荷予定日
        $grouped = $candidates->groupBy(function ($candidate) {
            $warehouseCode = $candidate->warehouse?->code ?? 'UNKNOWN';
            $contractorCode = $candidate->contractor?->code ?? '';
            $deliveredDate = $candidate->expected_arrival_date?->format('Y-m-d') ?? now()->format('Y-m-d');

            return "{$warehouseCode}_{$contractorCode}_{$deliveredDate}";
        });

        $queueCount = 0;
        $candidateCount = 0;
        $errors = [];

        foreach ($grouped as $groupKey => $groupCandidates) {
            try {
                // 100件以下で分割（仕様推奨）
                $chunks = $groupCandidates->chunk(100);

                foreach ($chunks as $chunk) {
                    $queueId = $this->createPurchaseQueueRecord($chunk);

                    // 候補のステータスをEXECUTEDに更新
                    foreach ($chunk as $candidate) {
                        $candidate->update([
                            'status' => CandidateStatus::EXECUTED,
                            'transmitted_at' => now(),
                        ]);
                    }

                    $queueCount++;
                    $candidateCount += $chunk->count();

                    Log::info('Purchase queue created', [
                        'batch_code' => $batchCode,
                        'group_key' => $groupKey,
                        'queue_id' => $queueId,
                        'candidate_count' => $chunk->count(),
                    ]);
                }
            } catch (\Exception $e) {
                $errors[] = [
                    'group_key' => $groupKey,
                    'error' => $e->getMessage(),
                ];
                Log::error('Failed to create purchase queue', [
                    'batch_code' => $batchCode,
                    'group_key' => $groupKey,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'success' => empty($errors),
            'queue_count' => $queueCount,
            'candidate_count' => $candidateCount,
            'errors' => $errors,
        ];
    }

    /**
     * purchase_create_queue にレコードを作成
     *
     * @param  Collection  $candidates  同一グループの発注候補
     */
    private function createPurchaseQueueRecord(Collection $candidates): int
    {
        $first = $candidates->first();

        // マスタ情報を取得
        $warehouse = $first->warehouse;
        $contractor = $first->contractor;
        $deliveredDate = $first->expected_arrival_date?->format('Y-m-d') ?? now()->format('Y-m-d');

        // 明細を構築
        $details = $candidates->map(function ($candidate) {
            return [
                'item_code' => $candidate->item?->code ?? '',
                'quantity' => $candidate->order_quantity,
                'quantity_type' => $candidate->quantity_type?->value ?? 'PIECE',
            ];
        })->toArray();

        // 仕入データを構築
        $purchaseData = [
            'process_date' => $deliveredDate,
            'delivered_date' => $deliveredDate,
            'account_date' => $deliveredDate,
            'supplier_code' => $contractor?->code ?? '',
            'warehouse_code' => $warehouse?->code ?? '',
            'note' => $this->buildBatchPurchaseNote($first),
            'details' => $details,
        ];

        // キューに挿入
        $queueId = DB::connection('sakemaru')->table('purchase_create_queue')->insertGetId([
            'request_uuid' => Str::uuid()->toString(),
            'delivered_date' => $deliveredDate,
            'items' => json_encode($purchaseData, JSON_UNESCAPED_UNICODE),
            'status' => 'BEFORE',
            'retry_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $queueId;
    }

    /**
     * バッチ送信用の仕入れ伝票備考を構築
     */
    private function buildBatchPurchaseNote(WmsOrderCandidate $candidate): string
    {
        $parts = [];
        $parts[] = '自動発注';
        $parts[] = "バッチ:{$candidate->batch_code}";

        return implode(' / ', $parts);
    }

    // ========================================
    // JX送信用ファイル生成・送信機能
    // ========================================

    /**
     * 確定済み発注候補から発注送信ファイルを生成（JX対象のみ）
     *
     * @param  string  $batchCode  バッチコード
     * @return array{success: bool, files: array, total_orders: int, errors: array}
     */
    public function generateOrderFiles(
        string $batchCode,
        ?int $warehouseId = null,
        bool $generateEmptyFiles = true
    ): array {
        $generator = $this->getOrderFileGenerator();

        if (! $generator) {
            return [
                'success' => false,
                'files' => [],
                'total_orders' => 0,
                'errors' => ['発注ファイル生成クラスが設定されていません'],
            ];
        }

        $targetContractorIds = $this->jxOrderTargetContractorIds($generator);

        if (empty($targetContractorIds)) {
            return [
                'success' => true,
                'files' => [],
                'total_orders' => 0,
                'errors' => [],
                'message' => 'JX送信対象の発注先がありません',
            ];
        }

        // CONFIRMED状態（確定済み）でファイル未生成のJX対象発注候補を取得
        $query = WmsOrderCandidate::where('batch_code', $batchCode)
            ->where('status', CandidateStatus::CONFIRMED)
            ->whereNull('wms_order_jx_document_id')
            ->whereIn('contractor_id', $targetContractorIds)
            ->where(fn (Builder $query): Builder => $this->applyJxEligibleOrderChannel($query))
            ->with(['warehouse', 'item', 'contractor']);

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        $candidates = $query->get();

        // 候補が空でも空ファイル生成のために処理を続行する
        return $this->doGenerateOrderFiles(
            $batchCode,
            $candidates,
            TransmissionDocumentStatus::PENDING,
            true,
            $generateEmptyFiles
        );
    }

    /**
     * 仕入先単位で未送信の確定済み発注候補をまとめてファイル生成（バッチ横断）
     *
     * @param  array  $contractorIds  対象仕入先ID（親＋子）
     * @return array{success: bool, files: array, total_orders: int, errors: array, batch_codes: array}
     */
    public function generateOrderFilesForContractor(array $contractorIds): array
    {
        $generator = $this->getOrderFileGenerator();

        if (! $generator) {
            return [
                'success' => false,
                'files' => [],
                'total_orders' => 0,
                'errors' => ['発注ファイル生成クラスが設定されていません'],
                'batch_codes' => [],
            ];
        }

        $targetContractorIds = $this->jxOrderTargetContractorIds($generator);

        // 対象仕入先かつJX対象に絞る
        $scopedContractorIds = array_intersect($contractorIds, $targetContractorIds);

        if (empty($scopedContractorIds)) {
            return [
                'success' => true,
                'files' => [],
                'total_orders' => 0,
                'errors' => [],
                'batch_codes' => [],
                'message' => 'JX送信対象の発注先がありません',
            ];
        }

        // CONFIRMED状態でファイル未生成の候補を全バッチから取得（日付制限なし）
        $candidates = WmsOrderCandidate::where('status', CandidateStatus::CONFIRMED)
            ->whereNull('wms_order_jx_document_id')
            ->whereIn('contractor_id', $scopedContractorIds)
            ->where(fn (Builder $query): Builder => $this->applyJxEligibleOrderChannel($query))
            ->with(['warehouse', 'item', 'contractor'])
            ->get();

        $batchCodes = $candidates->pluck('batch_code')->unique()->values()->toArray();

        // 代表batch_codeを使用（ファイルパス・ドキュメント記録用）
        $representativeBatchCode = $batchCodes[0] ?? now()->format('YmdHis');

        $result = $this->doGenerateOrderFiles($representativeBatchCode, $candidates, TransmissionDocumentStatus::PENDING, true);
        $result['batch_codes'] = $batchCodes;

        return $result;
    }

    /**
     * 送信済みJX伝票に紐づく確定済み発注候補から、修正再送用ファイルを生成する。
     *
     * 既存の発注候補と元の送信済みJX伝票の紐づきは監査用に残すため、ここでは再紐付けしない。
     *
     * @return array{success: bool, files: array, total_orders: int, errors: array}
     */
    public function generateCorrectionResendFiles(int $contractorId, string $transmittedDate): array
    {
        $candidates = $this->getCorrectionResendCandidates($contractorId, $transmittedDate);

        if ($candidates->isEmpty()) {
            return [
                'success' => false,
                'files' => [],
                'total_orders' => 0,
                'errors' => ['指定条件に一致する送信済み確定発注がありません'],
            ];
        }

        return $this->doGenerateOrderFiles(
            $this->makeCorrectionBatchCode(),
            $candidates,
            TransmissionDocumentStatus::PENDING,
            false,
            false
        );
    }

    /**
     * 送信前確認用CSVを生成する。
     *
     * CSVのJX項目は、実際のJX生成クラスが作った固定長DATを解析して出力する。
     *
     * @return array{filename: string, content: string, candidate_count: int}
     */
    public function buildCorrectionResendPreviewCsv(int $contractorId, string $transmittedDate): array
    {
        $candidates = $this->getCorrectionResendCandidates($contractorId, $transmittedDate);

        if ($candidates->isEmpty()) {
            throw new \RuntimeException('指定条件に一致する送信済み確定発注がありません');
        }

        $generator = $this->getOrderFileGenerator();
        if (! $generator) {
            throw new \RuntimeException('発注ファイル生成クラスが設定されていません');
        }

        $contractor = Contractor::find($contractorId);
        $files = $generator->generate($candidates);

        $rows = [[
            'ファイル名',
            '発注先ID',
            '発注先CD',
            '発注先名',
            '送信日',
            '発注CD',
            '商品CD',
            '商品名',
            '仕入入数',
            'JXケース数',
            'JXバラ数',
            'JX原単価',
        ]];

        foreach ($files as $file) {
            foreach ($this->extractDRecordsFromJxContent($file['content']) as $record) {
                $rows[] = [
                    $file['filename'],
                    $file['contractor_id'],
                    $file['contractor_code'] ?? $contractor?->code ?? '',
                    $contractor?->name ?? '',
                    $transmittedDate,
                    ltrim(trim(substr($record, 69, 13)), '0') ?: '0',
                    trim(substr($record, 82, 6)),
                    trim(mb_convert_encoding(substr($record, 5, 64), 'UTF-8', 'SJIS-win')),
                    (int) substr($record, 88, 6),
                    (int) substr($record, 94, 7),
                    (int) substr($record, 101, 7),
                    number_format(((int) substr($record, 108, 10)) / 100, 2, '.', ''),
                ];
            }
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        $contractorCode = $contractor?->code ?? $contractorId;

        return [
            'filename' => "correction_resend_{$transmittedDate}_{$contractorCode}.csv",
            'content' => $content,
            'candidate_count' => $candidates->count(),
        ];
    }

    /**
     * 修正再送対象の発注候補を取得する。
     */
    public function getCorrectionResendCandidates(int $contractorId, string $transmittedDate): Collection
    {
        $contractorIds = $this->getCorrectionResendSourceContractorIds($contractorId);

        return WmsOrderCandidate::query()
            ->where('status', CandidateStatus::CONFIRMED)
            ->whereIn('contractor_id', $contractorIds)
            ->whereNotNull('wms_order_jx_document_id')
            ->whereExists(function ($query) use ($transmittedDate) {
                $query->selectRaw('1')
                    ->from('wms_order_jx_documents as jx')
                    ->whereColumn('jx.id', 'wms_order_candidates.wms_order_jx_document_id')
                    ->where('jx.status', TransmissionDocumentStatus::TRANSMITTED->value)
                    ->whereDate('jx.transmitted_at', $transmittedDate);
            })
            ->with(['warehouse', 'item', 'contractor'])
            ->orderBy('warehouse_id')
            ->orderBy('contractor_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * Generator インスタンスを取得（UIからのマッピング参照用）
     */
    public function getGenerator(): ?OrderFileGeneratorInterface
    {
        return $this->getOrderFileGenerator();
    }

    private function applyJxEligibleOrderChannel(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->whereNull('order_channel')
                ->orWhere('order_channel', OrderChannel::EOS->value);
        });
    }

    /**
     * 確定済み候補からファイル生成→JX送信（JX送信ボタン用）
     */
    public function generateAndTransmitForContractor(int $transmissionContractorId): array
    {
        $generator = $this->getOrderFileGenerator();
        if (! $generator) {
            return ['success' => false, 'transmitted' => [], 'errors' => [['error' => 'Generator未設定']]];
        }

        $mapping = $generator->getTransmissionContractorMapping();

        // この送信先にマッピングされるソース仕入先IDを収集
        $sourceContractorIds = [$transmissionContractorId];
        foreach ($mapping as $sourceId => $targetId) {
            if ((int) $targetId === $transmissionContractorId) {
                $sourceContractorIds[] = (int) $sourceId;
            }
        }

        $candidates = WmsOrderCandidate::where('status', CandidateStatus::CONFIRMED)
            ->whereIn('contractor_id', $sourceContractorIds)
            ->where(fn (Builder $query): Builder => $this->applyJxEligibleOrderChannel($query))
            ->with(['item', 'contractor', 'warehouse'])
            ->get();

        if ($candidates->isEmpty()) {
            return ['success' => true, 'transmitted' => [], 'errors' => [], 'message' => '送信対象の候補がありません'];
        }

        if ($zeroQuantityError = $this->zeroQuantityJxTransmissionError($candidates)) {
            return ['success' => false, 'transmitted' => [], 'errors' => [$zeroQuantityError]];
        }

        $files = $generator->generate($candidates);
        if (empty($files)) {
            return ['success' => true, 'transmitted' => [], 'errors' => []];
        }

        $file = $files[0];
        $content = $file['content'];

        // JX設定を取得
        $jxSetting = WmsOrderJxSetting::findByContractorId($transmissionContractorId);
        if (! $jxSetting) {
            return ['success' => false, 'transmitted' => [], 'errors' => [['error' => "JX設定が見つかりません (contractor_id={$transmissionContractorId})"]]];
        }

        // S3保存
        $now = now();
        $contractorCode = $file['contractor_code'] ?? $transmissionContractorId;
        $filename = "{$contractorCode}_order_{$now->format('YmdHis')}.dat";
        $s3Path = "jx-orders/{$now->format('Y-m-d')}/{$filename}";
        Storage::disk('s3')->put($s3Path, $content);

        // CSV保存
        $csvPath = $this->generateAndSaveCsvFile(
            $candidates->first()->batch_code ?? 'manual',
            $file,
            $candidates,
            TransmissionDocumentStatus::TRANSMITTED
        );

        // バックアップ保存（送信前）
        $backupPath = "jx-backup/{$now->format('Y-m-d')}/{$contractorCode}_order_{$now->format('YmdHis')}.dat";
        Storage::disk('s3')->put($backupPath, $content);

        // JX送信
        $client = new JxClient($jxSetting);
        $result = $client->putDocumentWithWrapper(
            $content,
            $jxSetting->send_document_type ?? '91',
            'SecondGenEDI'
        );

        if ($result->succeeded()) {
            // ドキュメント記録
            $document = WmsOrderJxDocument::create([
                'batch_code' => $candidates->first()->batch_code ?? 'manual_'.$now->format('YmdHis'),
                'contractor_id' => $transmissionContractorId,
                'warehouse_id' => $candidates->first()->warehouse_id,
                'order_date' => $now->toDateString(),
                'expected_arrival_date' => $candidates->first()->expected_arrival_date,
                'document_type' => TransmissionDocumentType::PURCHASE ?? 'PURCHASE',
                'status' => TransmissionDocumentStatus::TRANSMITTED,
                'file_path' => $s3Path,
                'csv_path' => $csvPath,
                'record_count' => $file['record_count'] ?? 0,
                'order_count' => $file['order_count'] ?? $candidates->count(),
                'encoding' => $file['encoding'] ?? 'SJIS',
                'file_size' => strlen($content),
                'wms_order_jx_setting_id' => $jxSetting->id,
                'jx_message_id' => $result->messageId,
                'transmitted_at' => $now,
                'transmitted_by' => auth()->id(),
                ...$this->transmittedByAttributes(),
                ...$this->createdByAttributes(),
                'jx_response_data' => [
                    'message_id' => $result->messageId,
                    'timestamp' => $now->toIso8601String(),
                ],
            ]);

            // 候補を送信済みに更新 & ドキュメント紐付け
            $candidates->each(fn ($c) => $c->update([
                'status' => CandidateStatus::EXECUTED,
                'transmitted_at' => $now,
                'wms_order_jx_document_id' => $document->id,
            ]));
            $this->persistSlipAssignments(
                $document,
                $file['slip_assignments'] ?? [],
                WmsOrderSlipNumberAssignment::STATUS_TRANSMITTED
            );

            // 既存PENDINGドキュメントがあれば割当を取消してから削除
            WmsOrderJxDocument::where('status', TransmissionDocumentStatus::PENDING)
                ->whereIn('contractor_id', $sourceContractorIds)
                ->get()
                ->each(function (WmsOrderJxDocument $pendingDocument): void {
                    $this->cancelSlipAssignmentsForDocument($pendingDocument->id);
                    $pendingDocument->delete();
                });

            Log::info('JX送信完了（生成＆送信）', [
                'contractor_id' => $transmissionContractorId,
                'document_id' => $document->id,
                'order_count' => $candidates->count(),
                'message_id' => $result->messageId,
            ]);

            return [
                'success' => true,
                'transmitted' => [['document_id' => $document->id, 'contractor_id' => $transmissionContractorId, 'message_id' => $result->messageId]],
                'errors' => [],
                'order_count' => $candidates->count(),
            ];
        }

        Log::error('JX送信失敗（生成＆送信）', [
            'contractor_id' => $transmissionContractorId,
            'error' => $result->error,
        ]);

        return [
            'success' => false,
            'transmitted' => [],
            'errors' => [['error' => $result->error ?? '送信失敗']],
        ];
    }

    public function transmitPendingDocumentsForContractor(array $contractorIds): array
    {
        return $this->transmitPendingJxDocumentsForContractor($contractorIds);
    }

    public function transmitPendingJxDocumentsForContractor(
        array $contractorIds,
        ?CarbonInterface $orderDate = null,
        ?CarbonInterface $orderDateUntil = null
    ): array {
        $targetContractorIds = $this->expandTransmissionContractorIds($contractorIds);

        $documents = WmsOrderJxDocument::where('status', TransmissionDocumentStatus::PENDING)
            ->whereIn('contractor_id', $targetContractorIds)
            ->when($orderDate && $orderDateUntil, function ($query) use ($orderDate, $orderDateUntil): void {
                $query
                    ->whereDate('order_date', '>=', $orderDate->toDateString())
                    ->whereDate('order_date', '<=', $orderDateUntil->toDateString());
            })
            ->when($orderDate && ! $orderDateUntil, fn ($query) => $query->whereDate('order_date', $orderDate->toDateString()))
            ->orderBy('id')
            ->get();

        if ($documents->isEmpty()) {
            return [
                'success' => true,
                'transmitted' => [],
                'errors' => [],
                'message' => '送信対象のドキュメントがありません',
            ];
        }

        [$jxDocuments, $nonJxDocuments] = $documents
            ->partition(fn (WmsOrderJxDocument $document): bool => $this->isJxTransmissionTargetDocument($document));

        $result = $jxDocuments->isNotEmpty()
            ? $this->transmitExistingPendingDocuments($jxDocuments)
            : ['success' => true, 'transmitted' => [], 'errors' => [], 'order_count' => 0, 'skipped' => []];

        if ($nonJxDocuments->isNotEmpty()) {
            $result['skipped_non_jx'] = $nonJxDocuments
                ->map(fn (WmsOrderJxDocument $document): array => [
                    'document_id' => $document->id,
                    'contractor_id' => $document->contractor_id,
                ])
                ->values()
                ->all();
        }

        return $result;
    }

    public function transmitSelectedDocuments(Collection $documents): array
    {
        $pendingDocuments = $documents
            ->filter(fn (WmsOrderJxDocument $document): bool => $document->status === TransmissionDocumentStatus::PENDING)
            ->values();

        if ($pendingDocuments->isEmpty()) {
            return [
                'success' => false,
                'transmitted' => [],
                'errors' => [['error' => '選択データに送信待ちのJXデータがありません']],
            ];
        }

        [$jxDocuments, $nonJxDocuments] = $pendingDocuments
            ->partition(fn (WmsOrderJxDocument $document): bool => $this->isJxTransmissionTargetDocument($document));

        $jxResult = $jxDocuments->isNotEmpty()
            ? $this->transmitExistingPendingDocuments($jxDocuments)
            : ['success' => true, 'transmitted' => [], 'errors' => [], 'order_count' => 0];

        $completionResult = $nonJxDocuments->isNotEmpty()
            ? $this->completeNonJxPendingDocuments($nonJxDocuments)
            : ['success' => true, 'completed' => [], 'errors' => [], 'completed_order_count' => 0];

        return [
            'success' => empty($jxResult['errors']) && empty($completionResult['errors']),
            'transmitted' => $jxResult['transmitted'] ?? [],
            'completed' => $completionResult['completed'] ?? [],
            'skipped' => $jxResult['skipped'] ?? [],
            'errors' => array_merge($jxResult['errors'] ?? [], $completionResult['errors'] ?? []),
            'order_count' => (int) ($jxResult['order_count'] ?? 0),
            'completed_order_count' => (int) ($completionResult['completed_order_count'] ?? 0),
        ];
    }

    private function transmitExistingPendingDocuments(Collection $documents): array
    {
        $transmitted = [];
        $skipped = [];
        $errors = [];
        $orderCount = 0;

        foreach ($documents as $document) {
            $result = $this->transmitDocumentViaJx($document);

            if ($result['skipped'] ?? false) {
                $skipped[] = [
                    'document_id' => $document->id,
                    'contractor_id' => $document->contractor_id,
                    'reason' => $result['message'] ?? '他の処理で送信中または処理済みです',
                ];

                continue;
            }

            if ($result['success'] ?? false) {
                $transmitted[] = [
                    'document_id' => $document->id,
                    'contractor_id' => $document->contractor_id,
                    'message_id' => $result['message_id'] ?? null,
                ];
                $orderCount += (int) ($document->order_count ?? 0);

                continue;
            }

            $errors[] = [
                'document_id' => $document->id,
                'error' => $result['error'] ?? '送信失敗',
            ];
        }

        return [
            'success' => empty($errors),
            'transmitted' => $transmitted,
            'skipped' => $skipped,
            'errors' => $errors,
            'order_count' => $orderCount,
        ];
    }

    private function completeNonJxPendingDocuments(Collection $documents): array
    {
        $completed = [];
        $errors = [];
        $completedOrderCount = 0;

        foreach ($documents as $document) {
            try {
                $result = $this->completeNonJxPendingDocument($document);

                if ($result['success'] ?? false) {
                    $completed[] = [
                        'document_id' => $document->id,
                        'contractor_id' => $document->contractor_id,
                    ];
                    $completedOrderCount += (int) ($document->order_count ?? 0);

                    continue;
                }

                $errors[] = [
                    'document_id' => $document->id,
                    'error' => $result['error'] ?? '完了処理失敗',
                ];
            } catch (\Throwable $e) {
                $errors[] = [
                    'document_id' => $document->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'success' => empty($errors),
            'completed' => $completed,
            'errors' => $errors,
            'completed_order_count' => $completedOrderCount,
        ];
    }

    private function completeNonJxPendingDocument(WmsOrderJxDocument $document): array
    {
        return DB::connection('sakemaru')->transaction(function () use ($document): array {
            $lockedDocument = WmsOrderJxDocument::query()
                ->whereKey($document->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedDocument) {
                return ['success' => false, 'error' => 'ドキュメントが見つかりません'];
            }

            if ($lockedDocument->status !== TransmissionDocumentStatus::PENDING) {
                return ['success' => false, 'error' => '送信待ちのデータのみ完了処理できます'];
            }

            if ($this->isJxTransmissionTargetDocument($lockedDocument)) {
                return ['success' => false, 'error' => 'JX送信対象のデータは完了処理に回せません'];
            }

            $now = now();
            $errorMessage = 'JX送信対象外のため送信せず失敗完了';
            $completionData = [
                'completed_without_jx_transmission' => true,
                'failure_completed_without_jx_transmission' => true,
                'reason' => $errorMessage,
                'timestamp' => $now->toIso8601String(),
            ];

            $lockedDocument->update([
                'status' => TransmissionDocumentStatus::ERROR,
                'transmitted_at' => null,
                'transmitted_by' => null,
                'transmitted_by_name' => null,
                'error_message' => $errorMessage,
                'jx_response_data' => $completionData,
            ]);

            $updatedCandidates = WmsOrderCandidate::where('wms_order_jx_document_id', $lockedDocument->id)
                ->update([
                    'status' => CandidateStatus::EXECUTED,
                    'transmission_status' => 'FAILED',
                    'transmitted_at' => null,
                ]);

            $this->cancelSlipAssignmentsForDocument($lockedDocument->id);
            $this->logTransmission(
                $lockedDocument,
                $this->transmissionTypeForDocument($lockedDocument),
                'TRANSMIT',
                'FAILED',
                $completionData,
                $errorMessage
            );

            Log::info('Non-JX order document completed without transmission', [
                'document_id' => $lockedDocument->id,
                'contractor_id' => $lockedDocument->contractor_id,
                'candidate_count' => $updatedCandidates,
            ]);

            return ['success' => true];
        }, 3);
    }

    private function isJxTransmissionTargetDocument(WmsOrderJxDocument $document): bool
    {
        if ($document->wms_order_jx_setting_id) {
            return true;
        }

        if ($this->transmissionTypeForDocument($document) === TransmissionType::JX_FINET) {
            return true;
        }

        return $document->contractor_id
            ? WmsOrderJxSetting::findByContractorId((int) $document->contractor_id) !== null
            : false;
    }

    private function transmissionTypeForDocument(WmsOrderJxDocument $document): TransmissionType
    {
        $type = WmsContractorSetting::where('contractor_id', $document->contractor_id)
            ->value('transmission_type');

        if ($type instanceof TransmissionType) {
            return $type;
        }

        return TransmissionType::tryFrom((string) $type) ?? TransmissionType::MANUAL_CSV;
    }

    public function transmitPendingOrGenerateForContractor(int $contractorId): array
    {
        $generator = $this->getOrderFileGenerator();
        $mapping = $generator?->getTransmissionContractorMapping() ?? [];
        $contractorId = (int) ($mapping[$contractorId] ?? $contractorId);

        $targetContractorIds = $this->expandTransmissionContractorIds([$contractorId]);

        $hasPendingDocuments = WmsOrderJxDocument::where('status', TransmissionDocumentStatus::PENDING)
            ->whereIn('contractor_id', $targetContractorIds)
            ->exists();

        if ($hasPendingDocuments) {
            return $this->transmitPendingDocumentsForContractor([$contractorId]);
        }

        $hasConfirmedCandidates = WmsOrderCandidate::where('status', CandidateStatus::CONFIRMED)
            ->whereIn('contractor_id', $targetContractorIds)
            ->exists();

        if ($hasConfirmedCandidates) {
            return $this->generateAndTransmitForContractor($contractorId);
        }

        return $this->generateAndTransmitForContractor($contractorId);
    }

    public function candidatesForDocumentCsvRows(Collection $documents): Collection
    {
        $documentIds = $documents->pluck('id')->filter()->values();
        if ($documentIds->isEmpty()) {
            return collect();
        }

        $csvRows = $this->selectedDocumentCsvRows($documents);
        $candidates = WmsOrderCandidate::query()
            ->whereIn('wms_order_jx_document_id', $documentIds)
            ->where('status', CandidateStatus::CONFIRMED)
            ->whereNotNull('ordering_code')
            ->with(['warehouse', 'item', 'contractor'])
            ->orderBy('wms_order_jx_document_id')
            ->orderBy('id')
            ->get();

        return $this->filterCandidatesByCsvRows($candidates, $csvRows);
    }

    private function expandTransmissionContractorIds(array $contractorIds): array
    {
        $generator = $this->getOrderFileGenerator();
        $mapping = $generator?->getTransmissionContractorMapping() ?? [];
        $targetContractorIds = array_map('intval', $contractorIds);

        foreach ($mapping as $sourceId => $targetId) {
            if (in_array((int) $targetId, $targetContractorIds, true)) {
                $targetContractorIds[] = (int) $sourceId;
            }
        }

        return array_values(array_unique($targetContractorIds));
    }

    private function zeroQuantityJxTransmissionError(Collection $candidates): ?array
    {
        $resolver = app(OrderOutputQuantityResolver::class);

        $zeroCandidates = $candidates
            ->filter(function (WmsOrderCandidate $candidate) use ($resolver): bool {
                if ((int) $candidate->order_quantity <= 0) {
                    return true;
                }

                try {
                    $outputQuantity = $resolver->resolve($candidate);
                } catch (\Throwable) {
                    return false;
                }

                return (int) ($outputQuantity['order_quantity'] ?? 0) <= 0;
            })
            ->values();

        if ($zeroCandidates->isEmpty()) {
            return null;
        }

        $examples = $zeroCandidates
            ->take(5)
            ->map(fn (WmsOrderCandidate $candidate): string => '['.($candidate->item?->code ?? $candidate->item_code ?? $candidate->item_id).']'.($candidate->item?->name ?? '商品名なし'))
            ->implode('、');

        return [
            'error' => "発注数0のデータが {$zeroCandidates->count()}件 含まれているためJX送信できません。発注確定画面または確定承認待ち画面で発注数0に絞り込み、除外してください。対象例: {$examples}",
        ];
    }

    /**
     * @return array<string, int>
     */
    private function selectedDocumentCsvRows(Collection $documents): array
    {
        $keys = [];

        foreach ($documents as $document) {
            if (! $document->csv_path || ! Storage::disk('s3')->exists($document->csv_path)) {
                throw new \RuntimeException("選択データのCSVが見つかりません: {$document->batch_code}");
            }

            $stream = fopen('php://temp', 'r+');
            fwrite($stream, Storage::disk('s3')->get($document->csv_path));
            rewind($stream);

            $columnMap = null;
            while (($row = fgetcsv($stream)) !== false) {
                if ($columnMap === null) {
                    $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
                    $columnMap = array_flip($row);

                    continue;
                }

                if (count($row) < 10) {
                    continue;
                }

                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
                $key = $this->csvCandidateKey(
                    $row[$columnMap['発注先コード'] ?? 0] ?? '',
                    $row[$columnMap['倉庫コード'] ?? 2] ?? '',
                    $row[$columnMap['商品コード'] ?? 4] ?? '',
                    $row[$columnMap['JX発注CD'] ?? $columnMap['発注コード'] ?? 6] ?? '',
                    $row[$columnMap['発注数量'] ?? 7] ?? '',
                    $row[$columnMap['入荷予定日'] ?? 8] ?? '',
                );
                $keys[$key] = ($keys[$key] ?? 0) + 1;
            }

            fclose($stream);
        }

        if ($keys === []) {
            throw new \RuntimeException('選択データのCSVに明細行がありません');
        }

        return $keys;
    }

    private function filterCandidatesByCsvRows(Collection $candidates, array $csvRows): Collection
    {
        $resolver = app(OrderOutputQuantityResolver::class);
        $remaining = $csvRows;

        return $candidates
            ->filter(function (WmsOrderCandidate $candidate) use ($resolver, &$remaining): bool {
                $outputQuantity = $resolver->resolve($candidate);
                $key = $this->csvCandidateKey(
                    $candidate->contractor?->code ?? '',
                    $candidate->warehouse?->code ?? '',
                    $candidate->item?->code ?? '',
                    $outputQuantity['ordering_code'] ?? '',
                    $outputQuantity['order_quantity'],
                    $candidate->expected_arrival_date?->format('Y-m-d') ?? '',
                );

                if (($remaining[$key] ?? 0) <= 0) {
                    return false;
                }

                $remaining[$key]--;

                return true;
            })
            ->values();
    }

    private function csvCandidateKey(
        string|int|null $contractorCode,
        string|int|null $warehouseCode,
        string|int|null $itemCode,
        string|int|null $orderingCode,
        string|int|null $orderQuantity,
        ?string $expectedArrivalDate,
    ): string {
        return implode('|', [
            trim((string) $contractorCode),
            trim((string) $warehouseCode),
            trim((string) $itemCode),
            trim((string) $orderingCode),
            (string) (int) $orderQuantity,
            trim((string) $expectedArrivalDate),
        ]);
    }

    /**
     * 単一ドキュメントの送信
     */
    private function transmitSingleDocument(WmsOrderJxDocument $document): array
    {
        try {
            $result = $this->transmitDocumentViaJx($document);

            if ($result['success']) {
                return [
                    'success' => true,
                    'transmitted' => [[
                        'document_id' => $document->id,
                        'contractor_id' => $document->contractor_id,
                        'message_id' => $result['message_id'] ?? null,
                    ]],
                    'errors' => [],
                ];
            }

            return [
                'success' => false,
                'transmitted' => [],
                'errors' => [['document_id' => $document->id, 'error' => $result['error'] ?? '送信失敗']],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'transmitted' => [],
                'errors' => [['document_id' => $document->id, 'error' => $e->getMessage()]],
            ];
        }
    }

    /**
     * ドキュメントからJX設定を解決
     */
    private function resolveJxSetting(WmsOrderJxDocument $document): ?WmsOrderJxSetting
    {
        if ($document->wms_order_jx_setting_id) {
            $setting = WmsOrderJxSetting::find($document->wms_order_jx_setting_id);
            if ($setting) {
                return $setting;
            }
        }

        if ($document->contractor_id) {
            return WmsOrderJxSetting::findByContractorId($document->contractor_id);
        }

        return null;
    }

    /**
     * 発注送信ファイルを生成（共通処理）
     */
    private function doGenerateOrderFiles(
        string $batchCode,
        Collection $candidates,
        TransmissionDocumentStatus $status,
        bool $linkCandidates,
        bool $generateEmptyFiles = true
    ): array {
        return $this->doGenerateOrderFilesWithProgress(
            $batchCode,
            $candidates,
            $status,
            $linkCandidates,
            null,
            $generateEmptyFiles
        );
    }

    /**
     * 発注送信ファイルを生成（進捗コールバック付き）
     *
     * @param  string  $batchCode  バッチコード
     * @param  Collection  $candidates  発注候補
     * @param  TransmissionDocumentStatus  $status  ドキュメントステータス
     * @param  bool  $linkCandidates  候補とドキュメントを紐付けるか
     * @param  callable|null  $progressCallback  ファイル生成後に呼び出されるコールバック
     */
    private function doGenerateOrderFilesWithProgress(
        string $batchCode,
        Collection $candidates,
        TransmissionDocumentStatus $status,
        bool $linkCandidates,
        ?callable $progressCallback,
        bool $generateEmptyFiles = true
    ): array {
        $methodStart = microtime(true);
        Log::info('[doGenerateOrderFiles] 開始', [
            'batch_code' => $batchCode,
            'candidate_count' => $candidates->count(),
            'status' => $status->value,
            'has_progress_callback' => $progressCallback !== null,
        ]);

        $generator = $this->getOrderFileGenerator();
        $results = [];
        $errors = [];
        $totalOrders = 0;

        try {
            // Generatorでファイル生成
            $generatorStart = microtime(true);
            $files = $generator->generate($candidates);
            Log::info('[doGenerateOrderFiles] Generator完了', [
                'batch_code' => $batchCode,
                'file_count' => count($files),
                'elapsed_ms' => round((microtime(true) - $generatorStart) * 1000),
            ]);

            foreach ($files as $fileIndex => $file) {
                $fileStart = microtime(true);

                // S3に保存（.datファイル）
                $s3Start = microtime(true);
                $s3Path = $this->saveOrderFileToS3($batchCode, $file, $status);
                $s3Elapsed = round((microtime(true) - $s3Start) * 1000);

                // この送信先に属する候補からwarehouse_idを取得
                $mapping = $generator->getTransmissionContractorMapping();
                $fileCandidates = $candidates->filter(function ($c) use ($file, $mapping) {
                    $transmissionId = $mapping[$c->contractor_id] ?? $c->contractor_id;

                    return $transmissionId === $file['contractor_id'];
                });
                $generatedCandidateIds = $this->generatedCandidateIdsForFile($file);
                if ($generatedCandidateIds->isNotEmpty()) {
                    $fileCandidates = $fileCandidates
                        ->filter(fn ($candidate) => $generatedCandidateIds->contains((int) $candidate->id))
                        ->values();
                }

                $warehouseId = $fileCandidates->first()?->warehouse_id;

                // 入荷予定日を取得（同一グループは同じ日付のはず）
                $expectedArrivalDate = $fileCandidates->first()?->expected_arrival_date;

                // 確認用CSVファイルも生成・保存
                $csvStart = microtime(true);
                $csvPath = $this->generateAndSaveCsvFile($batchCode, $file, $fileCandidates, $status);
                $csvElapsed = round((microtime(true) - $csvStart) * 1000);

                // wms_order_jx_documentsに記録し、候補・伝票番号割当も同じ短いDBトランザクションで確定
                $dbStart = microtime(true);
                $document = DB::connection('sakemaru')->transaction(function () use (
                    $batchCode,
                    $file,
                    $s3Path,
                    $warehouseId,
                    $status,
                    $expectedArrivalDate,
                    $csvPath,
                    $linkCandidates,
                    $fileCandidates
                ): WmsOrderJxDocument {
                    $document = $this->createOrderDocument($batchCode, $file, $s3Path, $warehouseId, $status, $expectedArrivalDate, $csvPath);

                    // 発注候補とドキュメントを紐付け（確定済みの場合のみ）
                    if ($linkCandidates) {
                        $this->linkCandidatesToDocument($fileCandidates, $file['contractor_id'], $document);
                        $this->persistSlipAssignments($document, $file['slip_assignments'] ?? []);
                    }

                    return $document;
                }, 3);
                $dbElapsed = round((microtime(true) - $dbStart) * 1000);

                $fileResult = [
                    'contractor_id' => $file['contractor_id'],
                    'contractor_code' => $file['contractor_code'] ?? null,
                    'filename' => $file['filename'],
                    's3_path' => $s3Path,
                    'csv_path' => $csvPath,
                    'document_id' => $document->id,
                    'record_count' => $file['record_count'],
                    'order_count' => $file['order_count'],
                ];

                $results[] = $fileResult;
                $totalOrders += $file['order_count'];

                // 進捗コールバックを呼び出し
                if ($progressCallback !== null) {
                    $progressCallback($fileResult);
                }

                Log::info('[doGenerateOrderFiles] ファイル処理完了', [
                    'batch_code' => $batchCode,
                    'file_index' => $fileIndex + 1,
                    'contractor_id' => $file['contractor_id'],
                    'filename' => $file['filename'],
                    's3_ms' => $s3Elapsed,
                    'csv_ms' => $csvElapsed,
                    'db_ms' => $dbElapsed,
                    'total_ms' => round((microtime(true) - $fileStart) * 1000),
                ]);
            }

            // データなしJX設定に対する空ファイル生成
            if ($generateEmptyFiles) {
                $emptyFileResults = $this->generateEmptyFilesForMissingSettings(
                    $batchCode,
                    $files,
                    $status,
                    $progressCallback
                );
                foreach ($emptyFileResults as $emptyResult) {
                    $results[] = $emptyResult;
                }
            }
        } catch (\Exception $e) {
            $errors[] = $e->getMessage();
            Log::error('Order file generation failed', [
                'batch_code' => $batchCode,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'success' => empty($errors),
            'files' => $results,
            'total_orders' => $totalOrders,
            'errors' => $errors,
        ];
    }

    /**
     * 確認用CSVファイルを生成・保存
     *
     * S3にCSVファイルを保存し、wms_order_data_filesにも記録する
     */
    private function generateAndSaveCsvFile(
        string $batchCode,
        array $file,
        Collection $candidates,
        TransmissionDocumentStatus $status
    ): ?string {
        if ($candidates->isEmpty()) {
            return null;
        }

        // CSVヘッダー
        $headers = [
            '発注先コード',
            '発注先名',
            '倉庫コード',
            '倉庫名',
            '商品コード',
            '商品名',
            'JX発注CD',
            '発注数量',
            '入荷予定日',
            '発注日',
            'JXファイル名',
            'JX_1データシリアルNo',
            'JX_1データ種別',
            'JX_1作成日',
            'JX_1作成時刻',
            'JX_1ファイルNo',
            'JX_1処理日',
            'JX_1利用者企業CD',
            'JX_1送信元センターCD',
            'JX_1最終送信先CD',
            'JX_1直接送信先企業CD',
            'JX_1提供企業CD',
            'JX_1提供企業事業所CD',
            'JX_1提供企業名',
            'JX_1提供企業事業所名',
            'JX_1送信データ件数',
            'JX_1レコードサイズ',
            'JX_A処理日付',
            'JX_A処理時刻',
            'JX_A送信元',
            'JX_A送信先',
            'JX_Aレコード件数',
            'JX_A帳票枚数',
            'JX_B伝票番号',
            'JX_B社店CD',
            'JX_B分類CD',
            'JX_B伝票区分',
            'JX_B発注日',
            'JX_B納品日',
            'JX_B便',
            'JX_B取引先CD',
            'JX_B店名',
            'JX_B納品場所',
            'JX_B備考',
            'JX_B直送区分',
            'JX_D行番号',
            'JX_D品名',
            'JX仕入入数',
            'JXケース数',
            'JXバラ数',
            'JX総バラ数',
            'JX原単価',
        ];

        // CSVデータ
        $rows = [];
        $rows[] = $headers;
        $quantityResolver = app(OrderOutputQuantityResolver::class);
        $jxDetailRows = $this->extractJxDetailRowsFromContent($file['content']);

        foreach ($candidates->values() as $index => $candidate) {
            $outputQuantity = $quantityResolver->resolve($candidate);
            $jx = $jxDetailRows[$index] ?? [];
            $displayCapacity = max(1, (int) ($jx['d_purchase_lot'] ?? $outputQuantity['display_capacity'] ?? 1));
            $caseQuantity = (int) ($jx['d_case_quantity'] ?? $outputQuantity['case_quantity'] ?? 0);
            $pieceQuantity = (int) ($jx['d_piece_quantity'] ?? $outputQuantity['piece_quantity'] ?? 0);
            $itemCapacityCase = max(1, (int) ($candidate->item?->capacity_case ?? $displayCapacity));
            $totalPieceQuantity = ($outputQuantity['ordering_unit_quantity'] ?? null) !== null && $caseQuantity > 0
                ? ($caseQuantity * $itemCapacityCase) + ($pieceQuantity * (int) $outputQuantity['ordering_unit_quantity'])
                : ($caseQuantity * $displayCapacity) + $pieceQuantity;
            $jxUnitPrice = $jx['d_unit_price'] ?? null;

            $rows[] = [
                $candidate->contractor?->code ?? '',
                $candidate->contractor?->name ?? '',
                $candidate->warehouse?->code ?? '',
                $candidate->warehouse?->name ?? '',
                $candidate->item?->code ?? '',
                $candidate->item?->name ?? '',
                $outputQuantity['ordering_code'] ?? '',
                $outputQuantity['order_quantity'],
                $candidate->expected_arrival_date?->format('Y-m-d') ?? '',
                now()->format('Y-m-d'),
                $file['filename'] ?? '',
                $jx['wrapper_serial_number'] ?? '',
                $jx['wrapper_document_type'] ?? '',
                $jx['wrapper_created_date'] ?? '',
                $jx['wrapper_created_time'] ?? '',
                $jx['wrapper_file_number'] ?? '',
                $jx['wrapper_process_date'] ?? '',
                $jx['wrapper_receiver_trading_code'] ?? '',
                $jx['wrapper_sender_center_code'] ?? '',
                $jx['wrapper_final_receiver_code'] ?? '',
                $jx['wrapper_direct_receiver_company_code'] ?? '',
                $jx['wrapper_sender_trading_code'] ?? '',
                $jx['wrapper_sender_office_code'] ?? '',
                $jx['wrapper_sender_name'] ?? '',
                $jx['wrapper_sender_office_name'] ?? '',
                $jx['wrapper_record_count'] ?? '',
                $jx['wrapper_record_size'] ?? '',
                $jx['a_process_date'] ?? '',
                $jx['a_process_time'] ?? '',
                $jx['a_sender'] ?? '',
                $jx['a_receiver'] ?? '',
                $jx['a_record_count'] ?? '',
                $jx['a_slip_count'] ?? '',
                $jx['b_slip_number'] ?? '',
                $jx['b_store_code'] ?? '',
                $jx['b_category_code'] ?? '',
                $jx['b_slip_type'] ?? '',
                $jx['b_order_date'] ?? '',
                $jx['b_delivery_date'] ?? '',
                $jx['b_delivery_bin'] ?? '',
                $jx['b_contractor_code'] ?? '',
                $jx['b_store_name'] ?? '',
                $jx['b_delivery_place'] ?? '',
                $jx['b_note'] ?? '',
                $jx['b_direct_type'] ?? '',
                $jx['d_line_number'] ?? '',
                $jx['d_item_name'] ?? '',
                $displayCapacity,
                $caseQuantity,
                $pieceQuantity,
                $totalPieceQuantity,
                $jxUnitPrice === null ? '' : number_format((float) $jxUnitPrice, 2, '.', ''),
            ];
        }

        // CSV生成
        $stream = fopen('php://temp', 'r+');
        // BOM付きUTF-8
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }
        rewind($stream);
        $csvContent = stream_get_contents($stream);
        fclose($stream);

        // S3に保存
        $date = now()->format('Y-m-d');
        $contractorCode = $file['contractor_code'] ?? $file['contractor_id'];
        $warehouseCode = $candidates->first()?->warehouse?->code ?? 'UNKNOWN';
        $csvFilename = "{$batchCode}_{$warehouseCode}_{$contractorCode}_".now()->format('YmdHisv').'_'.Str::random(6).'.csv';
        $csvPath = "jx-orders/{$date}/{$csvFilename}";

        Storage::disk('s3')->put($csvPath, $csvContent);

        $firstCandidate = $candidates->first();
        $createdBy = $this->resolveOrderDataFileCreatedBy($batchCode, $candidates);

        WmsOrderDataFile::create([
            'batch_code' => $batchCode,
            'created_by' => $createdBy['id'],
            'created_by_name' => $createdBy['name'],
            'warehouse_id' => $firstCandidate?->warehouse_id,
            'contractor_id' => $file['contractor_id'],
            'candidate_ids' => $candidates->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'order_channel' => OrderDataFileChannel::JX_CONFIRMATION,
            'show_eos_stamp' => false,
            'order_date' => ClientSetting::freshSystemDateYMD('order_transmission:data_file'),
            'expected_arrival_date' => $firstCandidate?->expected_arrival_date,
            'file_path' => $csvPath,
            'file_size' => strlen($csvContent),
            'order_count' => $candidates->count(),
            'total_quantity' => $quantityResolver->sumOutputOrderQuantity($candidates),
            'is_mail_order' => (bool) WmsContractorSetting::where('contractor_id', $file['contractor_id'])
                ->whereNotNull('order_mail')
                ->where('order_mail', '!=', '')
                ->exists(),
            'is_test' => false,
            'status' => OrderDataFileStatus::GENERATED,
        ]);

        return $csvPath;
    }

    /**
     * @return array{id: int|null, name: string}
     */
    private function resolveOrderDataFileCreatedBy(string $batchCode, ?Collection $candidates = null): array
    {
        $authUser = auth()->user();
        if ($authUser && filled($authUser->name)) {
            return [
                'id' => (int) $authUser->getAuthIdentifier(),
                'name' => $authUser->name,
            ];
        }

        $candidateBatchCodes = $candidates?->pluck('batch_code')->filter()->unique()->values();
        if ($candidateBatchCodes?->isNotEmpty()) {
            $candidateCreatedBy = WmsAutoOrderJobControl::query()
                ->whereIn('batch_code', $candidateBatchCodes)
                ->whereNotNull('created_by')
                ->with('createdByUser:id,name')
                ->latest('id')
                ->first();

            if ($candidateCreatedBy?->createdByUser && filled($candidateCreatedBy->createdByUser->name)) {
                return [
                    'id' => (int) $candidateCreatedBy->created_by,
                    'name' => $candidateCreatedBy->createdByUser->name,
                ];
            }
        }

        $batchCreatedBy = WmsAutoOrderJobControl::query()
            ->where('batch_code', $batchCode)
            ->whereNotNull('created_by')
            ->with('createdByUser:id,name')
            ->latest('id')
            ->first();

        if ($batchCreatedBy?->createdByUser && filled($batchCreatedBy->createdByUser->name)) {
            return [
                'id' => (int) $batchCreatedBy->created_by,
                'name' => $batchCreatedBy->createdByUser->name,
            ];
        }

        $automatorId = SakemaruUser::resolveAutomatorId();
        if ($automatorId > 0) {
            return [
                'id' => $automatorId,
                'name' => SakemaruUser::withoutGlobalScopes()->whereKey($automatorId)->value('name') ?? 'システム',
            ];
        }

        return [
            'id' => null,
            'name' => 'システム',
        ];
    }

    private function resolveOrderDataFileCreatedByName(string $batchCode, ?Collection $candidates = null): string
    {
        return $this->resolveOrderDataFileCreatedBy($batchCode, $candidates)['name'];
    }

    /**
     * JXファイル生成者を伝票に固定保存する。
     *
     * Web操作はログインユーザー、バッチ/キュー実行は自動実行ユーザーとして残す。
     */
    private function createdByAttributes(): array
    {
        $authUser = auth()->user();
        if ($authUser) {
            return [
                'created_by' => (int) $authUser->getAuthIdentifier(),
                'created_by_name' => $authUser->name,
            ];
        }

        $userId = SakemaruUser::resolveAutomatorId();
        if ($userId > 0) {
            return [
                'created_by' => $userId,
                'created_by_name' => SakemaruUser::withoutGlobalScopes()->whereKey($userId)->value('name') ?? 'システム',
            ];
        }

        return [
            'created_by' => null,
            'created_by_name' => 'システム',
        ];
    }

    /**
     * JX送信者を伝票に固定保存する。
     */
    private function transmittedByAttributes(): array
    {
        $authUser = auth()->user();
        if ($authUser) {
            return [
                'transmitted_by' => (int) $authUser->getAuthIdentifier(),
                'transmitted_by_name' => $authUser->name,
            ];
        }

        $userId = SakemaruUser::resolveAutomatorId();
        if ($userId > 0) {
            return [
                'transmitted_by' => $userId,
                'transmitted_by_name' => SakemaruUser::withoutGlobalScopes()->whereKey($userId)->value('name') ?? 'システム',
            ];
        }

        return [
            'transmitted_by' => null,
            'transmitted_by_name' => 'システム',
        ];
    }

    /**
     * ドキュメントのダウンロードURLを取得
     */
    public function getDownloadUrl(WmsOrderJxDocument $document): ?string
    {
        if (! $document->file_path) {
            return null;
        }

        // 署名付きURL（1時間有効）
        return Storage::disk('s3')->temporaryUrl(
            $document->file_path,
            now()->addHour()
        );
    }

    /**
     * ドキュメントの内容を取得
     */
    public function getDocumentContent(WmsOrderJxDocument $document): ?string
    {
        if (! $document->file_path) {
            return null;
        }

        return Storage::disk('s3')->get($document->file_path);
    }

    /**
     * 発注ファイルをJX-FINETで送信
     *
     * @param  string  $batchCode  バッチコード
     * @return array{success: bool, transmitted: array, errors: array}
     */
    public function transmitOrderFilesViaJx(string $batchCode): array
    {
        // PENDING状態のドキュメントを取得
        $documents = WmsOrderJxDocument::where('batch_code', $batchCode)
            ->where('status', TransmissionDocumentStatus::PENDING)
            ->get();

        if ($documents->isEmpty()) {
            return [
                'success' => true,
                'transmitted' => [],
                'errors' => [],
                'message' => '送信対象のドキュメントがありません',
            ];
        }

        $transmitted = [];
        $errors = [];

        foreach ($documents as $document) {
            try {
                $result = $this->transmitDocumentViaJx($document);

                if ($result['success']) {
                    $transmitted[] = [
                        'document_id' => $document->id,
                        'contractor_id' => $document->contractor_id,
                        'message_id' => $result['message_id'] ?? null,
                    ];
                } else {
                    $errors[] = [
                        'document_id' => $document->id,
                        'error' => $result['error'] ?? '送信失敗',
                    ];
                }
            } catch (\Exception $e) {
                $errors[] = [
                    'document_id' => $document->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'success' => empty($errors),
            'transmitted' => $transmitted,
            'errors' => $errors,
        ];
    }

    /**
     * 指定されたドキュメントIDのJXファイルを送信
     *
     * @param  int  $documentId  ドキュメントID
     * @return array{success: bool, message_id?: string, error?: string}
     */
    public function transmitDocumentById(int $documentId): array
    {
        $document = WmsOrderJxDocument::find($documentId);

        if (! $document) {
            return ['success' => false, 'error' => 'ドキュメントが見つかりません'];
        }

        if ($document->status !== TransmissionDocumentStatus::PENDING) {
            return ['success' => false, 'error' => '送信可能なステータスではありません'];
        }

        return $this->transmitDocumentViaJx($document);
    }

    public function retransmitDocumentById(int $documentId): array
    {
        $document = WmsOrderJxDocument::find($documentId);

        if (! $document) {
            return ['success' => false, 'error' => 'ドキュメントが見つかりません'];
        }

        if ($document->status !== TransmissionDocumentStatus::TRANSMITTED) {
            return ['success' => false, 'error' => '再送信可能なステータスではありません'];
        }

        $candidates = WmsOrderCandidate::where('wms_order_jx_document_id', $document->id)
            ->with(['item', 'contractor', 'warehouse'])
            ->get();

        if ($candidates->isEmpty()) {
            return ['success' => false, 'error' => '再送信用の候補データが見つかりません'];
        }

        if ($zeroQuantityError = $this->zeroQuantityJxTransmissionError($candidates)) {
            return ['success' => false, 'error' => $zeroQuantityError['error']];
        }

        $generator = $this->getOrderFileGenerator();
        if (! $generator) {
            return ['success' => false, 'error' => 'Generator未設定'];
        }

        $files = $generator->generate($candidates);
        $file = collect($files)->firstWhere('contractor_id', $document->contractor_id) ?? ($files[0] ?? null);

        if (! $file) {
            return ['success' => false, 'error' => '再送信用JXファイルを生成できません'];
        }

        $jxSetting = $this->resolveJxSetting($document);
        if (! $jxSetting) {
            return ['success' => false, 'error' => 'JX設定が見つかりません'];
        }

        $now = now();
        $contractorCode = $file['contractor_code'] ?? $document->contractor?->code ?? $document->contractor_id;
        $filename = "{$contractorCode}_order_resend_{$now->format('YmdHis')}.dat";
        $s3Path = "jx-orders/{$now->format('Y-m-d')}/{$filename}";
        $content = $file['content'];
        Storage::disk('s3')->put($s3Path, $content);

        $client = new JxClient($jxSetting);
        $result = $client->putDocumentWithWrapper(
            $content,
            $jxSetting->send_document_type ?? '91',
            'SecondGenEDI'
        );

        if (! $result->succeeded()) {
            return ['success' => false, 'error' => $result->error ?? '送信失敗'];
        }

        $newDocument = WmsOrderJxDocument::create([
            'batch_code' => $this->makeCorrectionBatchCode(),
            'wms_order_jx_setting_id' => $jxSetting->id,
            'warehouse_id' => $document->warehouse_id,
            'contractor_id' => $document->contractor_id,
            'order_date' => $now->toDateString(),
            'expected_arrival_date' => $document->expected_arrival_date,
            'document_type' => TransmissionDocumentType::PURCHASE,
            'status' => TransmissionDocumentStatus::TRANSMITTED,
            'file_path' => $s3Path,
            'file_size' => strlen($content),
            'record_count' => $file['record_count'] ?? 0,
            'order_count' => $file['order_count'] ?? $candidates->count(),
            'encoding' => $file['encoding'] ?? 'SJIS',
            'jx_message_id' => $result->messageId,
            'transmitted_at' => $now,
            'transmitted_by' => auth()->id(),
            ...$this->transmittedByAttributes(),
            ...$this->createdByAttributes(),
            'jx_response_data' => [
                'message_id' => $result->messageId,
                'timestamp' => $now->toIso8601String(),
                'resend_of_document_id' => $document->id,
            ],
        ]);

        $this->saveBackupToS3($newDocument, $content);

        return [
            'success' => true,
            'message_id' => $result->messageId,
            'document_id' => $newDocument->id,
        ];
    }

    /**
     * 発注ファイル生成クラスを取得
     */
    private function getOrderFileGenerator(): ?OrderFileGeneratorInterface
    {
        return OrderServiceFactory::generator();
    }

    /**
     * JX発注データ生成対象の発注先IDを取得する。
     *
     * 代表発注先は「発注先設定がJX_FINET」かつ「有効なJX設定あり」に限定し、
     * その代表へ集約される子発注先だけを追加する。これにより手動バルク生成でも
     * FAX/メール/手動CSV対象の発注先がJXファイルへ混入しない。
     *
     * @return array<int>
     */
    private function jxOrderTargetContractorIds(?OrderFileGeneratorInterface $generator = null): array
    {
        $generator ??= $this->getOrderFileGenerator();

        $representativeIds = WmsContractorSetting::query()
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

        if (empty($representativeIds)) {
            return [];
        }

        $childIds = WmsContractorSetting::query()
            ->whereIn('transmission_contractor_id', $representativeIds)
            ->pluck('contractor_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        $mappedSourceIds = [];
        foreach ($generator?->getTransmissionContractorMapping() ?? [] as $sourceId => $targetId) {
            if (in_array((int) $targetId, $representativeIds, true)) {
                $mappedSourceIds[] = (int) $sourceId;
            }
        }

        return collect($representativeIds)
            ->merge($childIds)
            ->merge($mappedSourceIds)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 修正再送用の一時実行CDを生成する。
     *
     * wms_order_candidates.batch_code は17桁制限だが、再送ファイルは候補へ再紐付けしないため
     * wms_order_jx_documents / wms_order_data_files の20桁制限内で一意にする。
     */
    private function makeCorrectionBatchCode(): string
    {
        return 'R'.now()->format('YmdHis').str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
    }

    /**
     * 選択した発注候補IDからJXファイルを生成（送信しない）
     *
     * @param  array<int>  $candidateIds
     */
    public function generateJxFilesForCandidateIds(array $candidateIds): array
    {
        $candidateIds = collect($candidateIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $selectedCount = count($candidateIds);
        $baseQuery = WmsOrderCandidate::whereIn('id', $candidateIds);
        $existingCount = (clone $baseQuery)->count();
        $excludedMissing = max(0, $selectedCount - $existingCount);
        $excludedNotConfirmed = (clone $baseQuery)
            ->where('status', '!=', CandidateStatus::CONFIRMED)
            ->count();
        $excludedAlreadyGenerated = (clone $baseQuery)
            ->where('status', CandidateStatus::CONFIRMED)
            ->whereNotNull('wms_order_jx_document_id')
            ->count();
        $excludedFaxChannel = (clone $baseQuery)
            ->where('status', CandidateStatus::CONFIRMED)
            ->whereNull('wms_order_jx_document_id')
            ->where('order_channel', OrderChannel::FAX->value)
            ->count();
        $targetContractorIds = $this->jxOrderTargetContractorIds();
        $excludedNotJxTarget = (clone $baseQuery)
            ->where('status', CandidateStatus::CONFIRMED)
            ->whereNull('wms_order_jx_document_id')
            ->where(function ($query): void {
                $query
                    ->whereNull('order_channel')
                    ->orWhere('order_channel', OrderChannel::EOS->value);
            })
            ->when(
                ! empty($targetContractorIds),
                fn ($query) => $query->whereNotIn('contractor_id', $targetContractorIds),
                fn ($query) => $query
            )
            ->count();

        $targetCandidates = WmsOrderCandidate::whereIn('id', $candidateIds)
            ->where('status', CandidateStatus::CONFIRMED)
            ->whereNull('wms_order_jx_document_id')
            ->where(function ($query): void {
                $query
                    ->whereNull('order_channel')
                    ->orWhere('order_channel', OrderChannel::EOS->value);
            })
            ->when(
                ! empty($targetContractorIds),
                fn ($query) => $query->whereIn('contractor_id', $targetContractorIds),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->with(['item', 'contractor', 'warehouse'])
            ->get();

        [$candidates, $excludedMissingOrderingCode] = $this->filterCandidatesWithJxOrderingCode($targetCandidates);

        if ($candidates->isEmpty()) {
            $message = $excludedMissingOrderingCode > 0
                ? 'JX対象の発注候補にJX発注CD未設定の商品が含まれているため、生成対象がありません'
                : ($excludedFaxChannel > 0
                ? 'FAX発注として登録された候補が含まれているため、生成対象がありません'
                : ($excludedNotJxTarget > 0
                ? 'JX送信対象外の発注先が含まれているため、生成対象がありません'
                : 'JX未生成の確定済み発注候補がありません'));

            return [
                'success' => false,
                'files' => [],
                'total_orders' => 0,
                'selected_count' => $selectedCount,
                'eligible_count' => 0,
                'jx_target_count' => $targetCandidates->count(),
                'excluded_count' => $excludedMissing + $excludedNotConfirmed + $excludedAlreadyGenerated + $excludedFaxChannel + $excludedNotJxTarget + $excludedMissingOrderingCode,
                'excluded_missing' => $excludedMissing,
                'excluded_not_confirmed' => $excludedNotConfirmed,
                'excluded_already_generated' => $excludedAlreadyGenerated,
                'excluded_fax_channel' => $excludedFaxChannel,
                'excluded_not_jx_target' => $excludedNotJxTarget,
                'excluded_missing_ordering_code' => $excludedMissingOrderingCode,
                'skipped_count' => 0,
                'errors' => [$message],
            ];
        }

        $batchCode = 'J'.now()->format('YmdHis').str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);

        $result = $this->doGenerateOrderFiles(
            $batchCode,
            $candidates,
            TransmissionDocumentStatus::PENDING,
            true,
            false
        );

        $eligibleCount = $candidates->count();
        $totalOrders = (int) ($result['total_orders'] ?? 0);
        $files = $result['files'] ?? [];
        if ($totalOrders === 0 && empty($files)) {
            $result['success'] = false;
            $result['errors'] = [
                ...($result['errors'] ?? []),
                'JX対象の発注候補はありますが、JXファイルに出力できる明細がありません',
            ];
        }

        return [
            ...$result,
            'selected_count' => $selectedCount,
            'eligible_count' => $eligibleCount,
            'jx_target_count' => $targetCandidates->count(),
            'excluded_count' => $excludedMissing + $excludedNotConfirmed + $excludedAlreadyGenerated + $excludedFaxChannel + $excludedNotJxTarget + $excludedMissingOrderingCode,
            'excluded_missing' => $excludedMissing,
            'excluded_not_confirmed' => $excludedNotConfirmed,
            'excluded_already_generated' => $excludedAlreadyGenerated,
            'excluded_fax_channel' => $excludedFaxChannel,
            'excluded_not_jx_target' => $excludedNotJxTarget,
            'excluded_missing_ordering_code' => $excludedMissingOrderingCode,
            'skipped_count' => max(0, $eligibleCount - $totalOrders),
        ];
    }

    /**
     * JX固定長へ出力できる発注CDを持つ候補だけを残す。
     *
     * @return array{0: Collection<int, WmsOrderCandidate>, 1: int}
     */
    private function filterCandidatesWithJxOrderingCode(Collection $candidates): array
    {
        if ($candidates->isEmpty()) {
            return [$candidates, 0];
        }

        $resolver = app(OrderOutputQuantityResolver::class);
        $excludedCount = 0;
        $filtered = $candidates
            ->filter(function (WmsOrderCandidate $candidate) use ($resolver, &$excludedCount): bool {
                $orderingCode = $resolver->resolve($candidate)['ordering_code'] ?? null;
                if (filled($orderingCode)) {
                    return true;
                }

                $excludedCount++;

                return false;
            })
            ->values();

        return [$filtered, $excludedCount];
    }

    /**
     * 送信前JXドキュメントを生成取消し、発注候補をJX生成前に戻す。
     */
    public function cancelPendingJxDocumentAndRestoreCandidates(int $documentId): array
    {
        return DB::connection('sakemaru')->transaction(function () use ($documentId): array {
            $document = WmsOrderJxDocument::query()
                ->whereKey($documentId)
                ->lockForUpdate()
                ->first();

            if (! $document) {
                return ['success' => false, 'error' => 'ドキュメントが見つかりません'];
            }

            if ($document->status !== TransmissionDocumentStatus::PENDING) {
                return ['success' => false, 'error' => '送信待ちのJXデータのみ生成取消できます'];
            }

            $restoredCount = WmsOrderCandidate::where('wms_order_jx_document_id', $document->id)
                ->update(['wms_order_jx_document_id' => null]);

            $this->cancelSlipAssignmentsForDocument($document->id);

            $document->update([
                'status' => TransmissionDocumentStatus::CANCELLED,
                'error_message' => '送信前に生成取消されました',
            ]);

            return [
                'success' => true,
                'document_id' => $document->id,
                'restored_count' => $restoredCount,
            ];
        }, 3);
    }

    /**
     * 指定した送信先に集約される元仕入先IDを取得する。
     */
    private function getCorrectionResendSourceContractorIds(int $contractorId): array
    {
        $generator = $this->getOrderFileGenerator();
        $mapping = $generator?->getTransmissionContractorMapping() ?? [];

        $contractorIds = [$contractorId];
        foreach ($mapping as $sourceContractorId => $transmissionContractorId) {
            if ((int) $transmissionContractorId === $contractorId) {
                $contractorIds[] = (int) $sourceContractorId;
            }
        }

        return array_values(array_unique(array_merge(
            $contractorIds,
            WmsContractorSetting::getContractorIdsWithChildren($contractorId)
        )));
    }

    /**
     * JX固定長内容からDレコードだけを取り出す。
     *
     * 内容はShift_JISの128バイト固定長。JXラッパー、A/B/8レコードは除外する。
     *
     * @return array<int, string>
     */
    private function extractDRecordsFromJxContent(string $content): array
    {
        $content = str_replace(["\r\n", "\n", "\r"], '', $content);
        $records = [];

        for ($offset = 0, $length = strlen($content); $offset + 128 <= $length; $offset += 128) {
            $record = substr($content, $offset, 128);
            if ($record[0] === 'D') {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * JX固定長内容をCSV確認用の明細行に展開する。
     *
     * @return array<int, array<string, int|string|float>>
     */
    private function extractJxDetailRowsFromContent(string $content): array
    {
        $content = str_replace(["\r\n", "\n", "\r"], '', $content);

        $wrapper = [];
        $a = [];
        $currentB = [];
        $rows = [];

        for ($offset = 0, $length = strlen($content); $offset + 128 <= $length; $offset += 128) {
            $record = substr($content, $offset, 128);

            if ($record[0] === '1') {
                $wrapper = [
                    'wrapper_serial_number' => trim(substr($record, 1, 7)),
                    'wrapper_document_type' => trim(substr($record, 8, 2)),
                    'wrapper_created_date' => trim(substr($record, 10, 6)),
                    'wrapper_created_time' => trim(substr($record, 16, 6)),
                    'wrapper_file_number' => trim(substr($record, 22, 2)),
                    'wrapper_process_date' => trim(substr($record, 24, 6)),
                    'wrapper_receiver_trading_code' => trim(substr($record, 30, 12)),
                    'wrapper_sender_center_code' => trim(substr($record, 42, 6)),
                    'wrapper_final_receiver_code' => trim(substr($record, 50, 6)),
                    'wrapper_direct_receiver_company_code' => trim(substr($record, 58, 6)),
                    'wrapper_sender_trading_code' => trim(substr($record, 66, 12)),
                    'wrapper_sender_office_code' => trim(substr($record, 78, 12)),
                    'wrapper_sender_name' => $this->decodeJxText(substr($record, 90, 15)),
                    'wrapper_sender_office_name' => $this->decodeJxText(substr($record, 105, 10)),
                    'wrapper_record_count' => (int) substr($record, 115, 6),
                    'wrapper_record_size' => (int) substr($record, 121, 3),
                ];

                continue;
            }

            if ($record[0] === 'A') {
                $a = [
                    'a_process_date' => trim(substr($record, 3, 8)),
                    'a_process_time' => trim(substr($record, 11, 6)),
                    'a_sender' => trim(substr($record, 17, 8)),
                    'a_receiver' => trim(substr($record, 25, 8)),
                    'a_record_count' => (int) substr($record, 33, 6),
                    'a_slip_count' => (int) substr($record, 39, 6),
                ];

                continue;
            }

            if ($record[0] === 'B') {
                $currentB = [
                    'b_slip_number' => trim(substr($record, 3, 11)),
                    'b_store_code' => trim(substr($record, 14, 4)),
                    'b_category_code' => trim(substr($record, 18, 3)),
                    'b_slip_type' => trim(substr($record, 21, 2)),
                    'b_order_date' => trim(substr($record, 23, 6)),
                    'b_delivery_date' => trim(substr($record, 29, 6)),
                    'b_delivery_bin' => trim(substr($record, 35, 3)),
                    'b_contractor_code' => trim(substr($record, 38, 4)),
                    'b_store_name' => $this->decodeJxText(substr($record, 42, 15)),
                    'b_delivery_place' => $this->decodeJxText(substr($record, 57, 10)),
                    'b_note' => $this->decodeJxText(substr($record, 67, 25)),
                    'b_direct_type' => trim(substr($record, 92, 2)),
                ];

                continue;
            }

            if ($record[0] !== 'D') {
                continue;
            }

            $purchaseLot = (int) substr($record, 88, 6);
            $caseQuantity = (int) substr($record, 94, 7);
            $pieceQuantity = (int) substr($record, 101, 7);
            $unitPrice = ((int) substr($record, 108, 10)) / 100;

            $rows[] = array_merge($wrapper, $a, $currentB, [
                'd_line_number' => (int) substr($record, 3, 2),
                'd_item_name' => $this->decodeJxText(substr($record, 5, 64)),
                'd_ordering_code' => trim(substr($record, 69, 13)),
                'd_item_code' => trim(substr($record, 82, 6)),
                'd_purchase_lot' => $purchaseLot,
                'd_case_quantity' => $caseQuantity,
                'd_piece_quantity' => $pieceQuantity,
                'd_total_piece_quantity' => ($caseQuantity * max(1, $purchaseLot)) + $pieceQuantity,
                'd_unit_price' => $unitPrice,
            ]);
        }

        return $rows;
    }

    private function decodeJxText(string $value): string
    {
        return trim(mb_convert_encoding($value, 'UTF-8', 'SJIS-win'));
    }

    /**
     * 発注ファイルをS3に保存
     *
     * 注: AWS_BUCKET_PREFIXがfilesystems.phpで設定されているため、
     * ここでは追加のprefixを付けない
     */
    private function saveOrderFileToS3(string $batchCode, array $file, TransmissionDocumentStatus $status): string
    {
        $date = now()->format('Y-m-d');

        // ステータスに応じてフォルダを分ける
        $folder = match ($status) {
            TransmissionDocumentStatus::TEST => 'jx-orders-test',
            TransmissionDocumentStatus::DRAFT => 'jx-orders-draft',
            default => 'jx-orders',
        };

        $path = "{$folder}/{$date}/{$file['filename']}";
        if (Storage::disk('s3')->exists($path)) {
            $pathInfo = pathinfo($file['filename']);
            $baseName = $pathInfo['filename'];
            $extension = isset($pathInfo['extension']) ? '.'.$pathInfo['extension'] : '';
            $suffix = 2;

            do {
                $path = "{$folder}/{$date}/{$baseName}_{$suffix}{$extension}";
                $suffix++;
            } while (Storage::disk('s3')->exists($path));

            Log::warning('Order file S3 path collision avoided', [
                'batch_code' => $batchCode,
                'filename' => $file['filename'],
                'resolved_path' => $path,
                'status' => $status->value,
            ]);
        }

        Storage::disk('s3')->put($path, $file['content']);

        Log::info('Order file saved to S3', [
            'batch_code' => $batchCode,
            'path' => $path,
            'status' => $status->value,
        ]);

        return $path;
    }

    /**
     * 発注ドキュメントを作成
     */
    private function createOrderDocument(
        string $batchCode,
        array $file,
        string $s3Path,
        ?int $warehouseId = null,
        TransmissionDocumentStatus $status = TransmissionDocumentStatus::PENDING,
        $expectedArrivalDate = null,
        ?string $csvPath = null
    ): WmsOrderJxDocument {
        return WmsOrderJxDocument::create([
            'batch_code' => $batchCode,
            'wms_order_jx_setting_id' => $file['jx_setting_id'] ?? null,
            'warehouse_id' => $warehouseId,
            'contractor_id' => $file['contractor_id'],
            'order_date' => ClientSetting::freshSystemDateYMD('order_transmission:data_file'),
            'expected_arrival_date' => $expectedArrivalDate,
            'document_type' => TransmissionDocumentType::PURCHASE,
            'status' => $status,
            'file_path' => $s3Path,
            'csv_path' => $csvPath,
            'file_size' => strlen($file['content']),
            'record_count' => $file['record_count'],
            'order_count' => $file['order_count'],
            'encoding' => $file['encoding'],
            ...$this->createdByAttributes(),
        ]);
    }

    /**
     * ファイルに実際出力された発注候補IDを取得する。
     */
    private function generatedCandidateIdsForFile(array $file): Collection
    {
        return collect($file['slip_assignments'] ?? [])
            ->flatMap(fn (array $assignment): array => $assignment['order_candidate_ids'] ?? [])
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * 発注候補とドキュメントを紐付け
     */
    private function linkCandidatesToDocument(Collection $candidates, int $contractorId, WmsOrderJxDocument $document): void
    {
        // transmission_contractor_idを考慮して紐付け
        $generator = $this->getOrderFileGenerator();
        $mapping = $generator?->getTransmissionContractorMapping() ?? [];

        $candidates->each(function ($candidate) use ($contractorId, $document, $mapping) {
            // この候補が発注データ集約先と一致するか確認
            $candidateTransmissionId = $mapping[$candidate->contractor_id] ?? $candidate->contractor_id;

            if ($candidateTransmissionId === $contractorId) {
                $candidate->update([
                    'wms_order_jx_document_id' => $document->id,
                ]);
            }
        });
    }

    private function persistSlipAssignments(
        WmsOrderJxDocument $document,
        array $slipAssignments,
        string $status = WmsOrderSlipNumberAssignment::STATUS_ACTIVE
    ): void {
        if (empty($slipAssignments)) {
            return;
        }

        DB::connection('sakemaru')->transaction(function () use ($document, $slipAssignments, $status): void {
            foreach ($slipAssignments as $assignment) {
                $candidateIds = collect($assignment['order_candidate_ids'] ?? [])
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all();

                WmsOrderSlipNumberAssignment::query()->create([
                    'wms_order_jx_document_id' => $document->id,
                    'document_type' => LegacyEosSlipNumberService::DOCUMENT_TYPE_EOS_ORDER,
                    'slip_number' => $assignment['slip_number'],
                    'store_code' => $assignment['store_code'],
                    'year_code' => $assignment['year_code'],
                    'sequence_no' => $assignment['sequence_no'],
                    'b_record_sequence' => $assignment['b_record_sequence'] ?? null,
                    'status' => $status,
                    'order_candidate_ids' => $candidateIds,
                ]);

                if (empty($candidateIds)) {
                    continue;
                }

                WmsOrderIncomingSchedule::query()
                    ->whereIn('order_candidate_id', $candidateIds)
                    ->where('status', IncomingScheduleStatus::PENDING->value)
                    ->update(['slip_number' => $assignment['slip_number']]);
            }
        }, 3);
    }

    private function markSlipAssignmentsTransmitted(array $documentIds): void
    {
        $documentIds = array_values(array_unique(array_filter(array_map('intval', $documentIds))));
        if (empty($documentIds)) {
            return;
        }

        WmsOrderSlipNumberAssignment::query()
            ->whereIn('wms_order_jx_document_id', $documentIds)
            ->where('status', WmsOrderSlipNumberAssignment::STATUS_ACTIVE)
            ->update(['status' => WmsOrderSlipNumberAssignment::STATUS_TRANSMITTED]);
    }

    private function cancelSlipAssignmentsForDocument(int $documentId): void
    {
        $assignments = WmsOrderSlipNumberAssignment::query()
            ->where('wms_order_jx_document_id', $documentId)
            ->where('status', WmsOrderSlipNumberAssignment::STATUS_ACTIVE)
            ->get();

        foreach ($assignments as $assignment) {
            $candidateIds = collect($assignment->order_candidate_ids ?? [])
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            if (! empty($candidateIds)) {
                WmsOrderIncomingSchedule::query()
                    ->whereIn('order_candidate_id', $candidateIds)
                    ->where('slip_number', $assignment->slip_number)
                    ->where('status', IncomingScheduleStatus::PENDING->value)
                    ->update(['slip_number' => null]);
            }

            $assignment->update(['status' => WmsOrderSlipNumberAssignment::STATUS_CANCELLED]);
        }
    }

    /**
     * データなしJX設定に対して空ファイルを生成
     *
     * 生成済みファイルのJX設定IDを確認し、まだファイルが生成されていない
     * アクティブなJX設定に対して空ファイルを生成する。
     * HANA generator: Aレコード付き空ファイル
     * HANA2 generator: JXラッパーのみの完全空ファイル
     *
     * @param  string  $batchCode  バッチコード
     * @param  array  $generatedFiles  生成済みファイル情報
     * @param  TransmissionDocumentStatus  $status  ドキュメントステータス
     * @param  callable|null  $progressCallback  進捗コールバック
     * @return array 空ファイル生成結果
     */
    private function generateEmptyFilesForMissingSettings(
        string $batchCode,
        array $generatedFiles,
        TransmissionDocumentStatus $status,
        ?callable $progressCallback
    ): array {
        // 生成済みファイルのJX設定IDを収集
        $generatedSettingIds = collect($generatedFiles)
            ->pluck('jx_setting_id')
            ->filter()
            ->unique()
            ->toArray();

        // 全アクティブJX設定を取得
        $allActiveSettings = WmsOrderJxSetting::where('is_active', true)->get();

        $results = [];

        foreach ($allActiveSettings as $jxSetting) {
            // 既にファイルが生成されている設定はスキップ
            if (in_array($jxSetting->id, $generatedSettingIds)) {
                continue;
            }

            // JX設定にgeneratorが設定されていない場合はスキップ
            $generator = OrderServiceFactory::generatorForJxSetting($jxSetting);
            if (! ($generator instanceof HanaOrderJXFileGenerator)) {
                continue;
            }

            try {
                $file = $generator->generateEmptyFile($jxSetting);

                // S3に保存
                $s3Path = $this->saveOrderFileToS3($batchCode, $file, $status);

                // ドキュメント作成（CSV生成・候補紐付けなし）
                $document = $this->createOrderDocument($batchCode, $file, $s3Path, null, $status);

                $fileResult = [
                    'contractor_id' => $file['contractor_id'],
                    'contractor_code' => $file['contractor_code'] ?? null,
                    'filename' => $file['filename'],
                    's3_path' => $s3Path,
                    'csv_path' => null,
                    'document_id' => $document->id,
                    'record_count' => $file['record_count'],
                    'order_count' => 0,
                ];

                $results[] = $fileResult;

                if ($progressCallback !== null) {
                    $progressCallback($fileResult);
                }

                Log::info('[doGenerateOrderFiles] 空ファイル生成完了', [
                    'batch_code' => $batchCode,
                    'jx_setting_id' => $jxSetting->id,
                    'contractor_id' => $file['contractor_id'],
                    'filename' => $file['filename'],
                ]);
            } catch (\Exception $e) {
                Log::error('[doGenerateOrderFiles] 空ファイル生成失敗', [
                    'jx_setting_id' => $jxSetting->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * ドキュメントをJX-FINETで送信
     */
    private function transmitDocumentViaJx(WmsOrderJxDocument $document): array
    {
        $linkedCandidates = WmsOrderCandidate::where('wms_order_jx_document_id', $document->id)
            ->with(['item'])
            ->get();
        if ($zeroQuantityError = $this->zeroQuantityJxTransmissionError($linkedCandidates)) {
            return ['success' => false, 'error' => $zeroQuantityError['error']];
        }

        $document = $this->claimDocumentForJxTransmission($document);
        if (! $document) {
            return [
                'success' => true,
                'skipped' => true,
                'message' => '他の処理で送信中または処理済みです',
            ];
        }

        $jxSetting = $this->resolveJxSetting($document);

        if (! $jxSetting) {
            $document->update([
                'status' => TransmissionDocumentStatus::ERROR,
                'error_message' => 'JX設定が見つかりません',
            ]);

            return ['success' => false, 'error' => 'JX設定が見つかりません'];
        }

        // ファイル内容を取得
        $fileContent = Storage::disk('s3')->get($document->file_path);
        if (! $fileContent) {
            $document->update([
                'status' => TransmissionDocumentStatus::ERROR,
                'error_message' => 'ファイルが見つかりません',
            ]);

            return ['success' => false, 'error' => 'ファイルが見つかりません'];
        }

        // JX送信実行
        $client = new JxClient($jxSetting);
        try {
            $result = $client->putDocumentWithWrapper(
                $fileContent,
                $jxSetting->send_document_type ?? '91',
                'SecondGenEDI'
            );
        } catch (\Throwable $e) {
            $document->update([
                'status' => TransmissionDocumentStatus::ERROR,
                'error_message' => $e->getMessage(),
                'jx_response_data' => [
                    'timestamp' => now()->toIso8601String(),
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ],
            ]);

            Log::error('JX transmission exception', [
                'document_id' => $document->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }

        if ($result->succeeded()) {
            $document->update([
                'status' => TransmissionDocumentStatus::TRANSMITTED,
                'transmitted_at' => now(),
                'transmitted_by' => auth()->id(),
                ...$this->transmittedByAttributes(),
                'jx_message_id' => $result->messageId,
                'jx_response_data' => [
                    'message_id' => $result->messageId,
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);
            $this->markSlipAssignmentsTransmitted([$document->id]);

            Log::info('JX transmission succeeded', [
                'document_id' => $document->id,
                'message_id' => $result->messageId,
            ]);

            $linkedCandidates->each(fn (WmsOrderCandidate $candidate) => $candidate->update([
                'status' => CandidateStatus::EXECUTED,
                'transmitted_at' => now(),
            ]));

            try {
                $this->saveBackupToS3($document, $fileContent);
            } catch (\Throwable $e) {
                Log::warning('JX transmission backup failed after successful transmission', [
                    'document_id' => $document->id,
                    'message_id' => $result->messageId,
                    'error' => $e->getMessage(),
                ]);
            }

            return ['success' => true, 'message_id' => $result->messageId];
        } else {
            $document->update([
                'status' => TransmissionDocumentStatus::ERROR,
                'error_message' => $result->error,
                'jx_message_id' => $result->messageId,
                'jx_response_data' => [
                    'message_id' => $result->messageId,
                    'timestamp' => now()->toIso8601String(),
                    'error' => $result->error,
                ],
            ]);

            Log::error('JX transmission failed', [
                'document_id' => $document->id,
                'error' => $result->error,
            ]);

            return ['success' => false, 'error' => $result->error];
        }
    }

    private function claimDocumentForJxTransmission(WmsOrderJxDocument $document): ?WmsOrderJxDocument
    {
        $updated = WmsOrderJxDocument::query()
            ->whereKey($document->id)
            ->where('status', TransmissionDocumentStatus::PENDING)
            ->update([
                'status' => TransmissionDocumentStatus::TRANSMITTING,
                'error_message' => null,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            return null;
        }

        return WmsOrderJxDocument::query()->find($document->id);
    }

    /**
     * 送信済みファイルのバックアップをS3に保存
     *
     * 注: AWS_BUCKET_PREFIXがfilesystems.phpで設定されているため、
     * ここでは追加のprefixを付けない
     */
    private function saveBackupToS3(WmsOrderJxDocument $document, string $content): void
    {
        $date = now()->format('Y-m-d');
        $timestamp = now()->format('His');
        $filename = pathinfo($document->file_path, PATHINFO_FILENAME);
        $extension = pathinfo($document->file_path, PATHINFO_EXTENSION);

        $backupPath = "jx-backup/{$date}/{$filename}_{$timestamp}.{$extension}";

        Storage::disk('s3')->put($backupPath, $content);

        Log::info('Backup saved to S3', [
            'document_id' => $document->id,
            'path' => $backupPath,
        ]);
    }
}
