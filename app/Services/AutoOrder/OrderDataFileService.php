<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\OrderDataFileChannel;
use App\Enums\AutoOrder\OrderDataFileStatus;
use App\Enums\EVolumeUnit;
use App\Enums\QuantityType;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\User as SakemaruUser;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderDataFile;
use App\Models\WmsOrderIncomingSchedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 発注データファイルサービス（共通CSVダウンロード用）
 *
 * 倉庫別×発注先別にCSVファイルを生成・管理
 */
class OrderDataFileService
{
    /**
     * 確定済み発注候補からCSVファイルを生成
     *
     * @param  string  $batchCode  バッチコード
     * @param  bool  $splitByWarehouse  納品先（倉庫）別にファイルを分割するか
     * @return array{success: bool, files: array, total_files: int, errors: array}
     */
    public function generateCsvFiles(string $batchCode, bool $splitByWarehouse = true, ?int $warehouseId = null, ?string $communicationNotes = null): array
    {
        $shouldSplitByWarehouse = $splitByWarehouse || $warehouseId !== null;
        $communicationNotes = $this->normalizeCommunicationNotes($communicationNotes);

        // CONFIRMED状態の発注候補を取得
        $query = WmsOrderCandidate::where('batch_code', $batchCode)
            ->where('status', CandidateStatus::CONFIRMED)
            ->with(['warehouse', 'item', 'contractor']);

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        $candidates = $query->get();

        if ($candidates->isEmpty()) {
            return [
                'success' => true,
                'files' => [],
                'total_files' => 0,
                'errors' => [],
                'message' => '生成対象の発注候補がありません',
            ];
        }

        // グルーピング: FAX/MAIL/CSVのヘッダー日付が混在しないよう、入荷予定日単位でも分割する
        $grouped = $this->groupCandidatesForDataFiles($candidates, $shouldSplitByWarehouse);

        $results = [];
        $errors = [];

        foreach ($grouped as $groupKey => $groupCandidates) {
            try {
                $result = $this->generateCsvFile($batchCode, $groupCandidates, $shouldSplitByWarehouse, $communicationNotes);
                $results[] = $result;

                Log::info('Order data CSV file generated', [
                    'batch_code' => $batchCode,
                    'warehouse_id' => $shouldSplitByWarehouse ? $groupCandidates->first()->warehouse_id : null,
                    'contractor_id' => $groupCandidates->first()->contractor_id,
                    'expected_arrival_date' => $groupCandidates->first()->expected_arrival_date?->format('Y-m-d'),
                    'order_count' => $groupCandidates->count(),
                    'split_by_warehouse' => $shouldSplitByWarehouse,
                    'requested_warehouse_id' => $warehouseId,
                ]);
            } catch (\Throwable $e) {
                $errors[] = [
                    'group' => $groupKey,
                    'error' => $e->getMessage(),
                ];
                Log::error('Order data CSV file generation failed', [
                    'batch_code' => $batchCode,
                    'group' => $groupKey,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'success' => empty($errors),
            'files' => $results,
            'total_files' => count($results),
            'errors' => $errors,
        ];
    }

    /**
     * 選択された確定済み発注候補からFAX/MAIL/CSV用の発注データを生成する。
     *
     * @param  array<int>  $candidateIds
     * @return array{success: bool, files: array, total_files: int, errors: array}
     */
    public function generateCsvFilesForCandidates(array $candidateIds, bool $splitByWarehouse = true, ?string $communicationNotes = null): array
    {
        $communicationNotes = $this->normalizeCommunicationNotes($communicationNotes);

        $candidates = WmsOrderCandidate::whereIn('id', $candidateIds)
            ->where('status', CandidateStatus::CONFIRMED)
            ->with(['warehouse', 'item', 'contractor', 'supplier.partner'])
            ->get();

        if ($candidates->isEmpty()) {
            return [
                'success' => true,
                'files' => [],
                'total_files' => 0,
                'errors' => [],
                'message' => '生成対象の確定済み発注候補がありません',
            ];
        }

        $results = [];
        $errors = [];

        foreach ($candidates->groupBy('batch_code') as $batchCode => $batchCandidates) {
            $grouped = $this->groupCandidatesForDataFiles($batchCandidates, $splitByWarehouse);

            foreach ($grouped as $groupKey => $groupCandidates) {
                try {
                    $results[] = $this->generateCsvFile((string) $batchCode, $groupCandidates, $splitByWarehouse, $communicationNotes);
                } catch (\Throwable $e) {
                    $errors[] = [
                        'group' => $groupKey,
                        'error' => $e->getMessage(),
                    ];
                    Log::error('Order data CSV file generation failed', [
                        'batch_code' => $batchCode,
                        'group' => $groupKey,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return [
            'success' => empty($errors),
            'files' => $results,
            'total_files' => count($results),
            'errors' => $errors,
        ];
    }

    /**
     * 選択された確定済み発注候補からFAX PDFのみを生成する。
     * CSVは作成せず、wms_order_data_files.file_path はNULLにする。
     *
     * @param  array<int>  $candidateIds
     * @return array{success: bool, files: array, total_files: int, errors: array}
     */
    public function generateFaxPdfFilesForCandidates(
        array $candidateIds,
        OrderDataFileChannel $channel,
        bool $splitByWarehouse = true,
        ?string $communicationNotes = null
    ): array {
        $communicationNotes = $this->normalizeCommunicationNotes($communicationNotes);

        $candidates = WmsOrderCandidate::whereIn('id', $candidateIds)
            ->where('status', CandidateStatus::CONFIRMED)
            ->with(['warehouse', 'item', 'contractor', 'supplier.partner'])
            ->get();

        if ($candidates->isEmpty()) {
            return [
                'success' => true,
                'files' => [],
                'total_files' => 0,
                'errors' => [],
                'message' => '生成対象の確定済み発注候補がありません',
            ];
        }

        $results = [];
        $errors = [];

        foreach ($candidates->groupBy('batch_code') as $batchCode => $batchCandidates) {
            $grouped = $this->groupCandidatesForDataFiles($batchCandidates, $splitByWarehouse);

            foreach ($grouped as $groupKey => $groupCandidates) {
                try {
                    $results[] = $this->generateFaxPdfFile((string) $batchCode, $groupCandidates, $channel, $splitByWarehouse, $communicationNotes);
                } catch (\Throwable $e) {
                    $errors[] = [
                        'group' => $groupKey,
                        'error' => $e->getMessage(),
                    ];
                    Log::error('Order FAX PDF file generation failed', [
                        'batch_code' => $batchCode,
                        'group' => $groupKey,
                        'channel' => $channel->value,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return [
            'success' => empty($errors),
            'files' => $results,
            'total_files' => count($results),
            'errors' => $errors,
        ];
    }

    /**
     * 1つのFAX PDF用データファイルレコードを生成する。
     */
    private function generateFaxPdfFile(
        string $batchCode,
        Collection $candidates,
        OrderDataFileChannel $channel,
        bool $splitByWarehouse = true,
        ?string $communicationNotes = null
    ): array {
        $firstCandidate = $candidates->first();
        $contractorId = $firstCandidate->contractor_id;
        $contractor = $firstCandidate->contractor;
        $supplierId = $firstCandidate->supplier_id;
        $supplierName = $firstCandidate->supplier?->partner?->name;
        $warehouseId = $splitByWarehouse ? $firstCandidate->warehouse_id : null;
        $warehouse = $splitByWarehouse ? $firstCandidate->warehouse : null;
        $expectedArrivalDate = $firstCandidate->expected_arrival_date;
        $quantityResolver = app(OrderOutputQuantityResolver::class);
        $totalQuantity = $quantityResolver->sumOutputOrderQuantity($candidates);
        $totalPieceQuantity = $this->sumTotalPieceQuantity($candidates);
        $createdBy = $this->resolveCreatedBy($batchCode, $candidates);
        $candidateIds = $candidates->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        $dataFile = WmsOrderDataFile::create([
            'batch_code' => $batchCode,
            'created_by' => $createdBy['id'],
            'created_by_name' => $createdBy['name'],
            'warehouse_id' => $warehouseId,
            'contractor_id' => $contractorId,
            'candidate_ids' => $candidateIds,
            'order_channel' => $channel,
            'show_eos_stamp' => $channel === OrderDataFileChannel::EOS,
            'order_date' => ClientSetting::freshSystemDateYMD('order_data_file:fax_pdf'),
            'expected_arrival_date' => $expectedArrivalDate,
            'file_path' => '',
            'file_size' => 0,
            'order_count' => $candidates->count(),
            'total_quantity' => $totalQuantity,
            'is_mail_order' => (bool) WmsContractorSetting::where('contractor_id', $contractorId)
                ->whereNotNull('order_mail')
                ->where('order_mail', '!=', '')
                ->exists(),
            'is_test' => false,
            'status' => OrderDataFileStatus::GENERATED,
        ]);

        $faxError = null;
        try {
            app(PurchaseOrderPdfService::class)->generateAndStoreFromCandidates($candidates, $dataFile, $communicationNotes);
            $dataFile->refresh();
        } catch (\Throwable $e) {
            $faxError = $e->getMessage();
            Log::error('Order FAX PDF generation failed', [
                'batch_code' => $batchCode,
                'warehouse_id' => $warehouseId,
                'contractor_id' => $contractorId,
                'candidate_ids' => $candidateIds,
                'channel' => $channel->value,
                'error' => $faxError,
            ]);
        }

        return [
            'id' => $dataFile->id,
            'warehouse_id' => $warehouseId,
            'warehouse_name' => $splitByWarehouse ? $warehouse?->name : '全倉庫',
            'contractor_id' => $contractorId,
            'contractor_name' => $contractor?->name,
            'supplier_id' => $supplierId,
            'supplier_name' => $supplierName,
            'expected_arrival_date' => $expectedArrivalDate?->format('Y-m-d'),
            'order_channel' => $channel->value,
            'file_path' => '',
            'fax_file_path' => $dataFile->fax_file_path,
            'fax_error' => $faxError,
            'order_count' => $candidates->count(),
            'total_quantity' => $totalQuantity,
            'total_piece_quantity' => $totalPieceQuantity,
            'candidate_ids' => $candidateIds,
        ];
    }

    /**
     * 1つのCSVファイルを生成
     */
    private function generateCsvFile(string $batchCode, Collection $candidates, bool $splitByWarehouse = true, ?string $communicationNotes = null): array
    {
        $firstCandidate = $candidates->first();
        $contractorId = $firstCandidate->contractor_id;
        $contractor = $firstCandidate->contractor;

        // 納品先別分割の場合は単一倉庫、まとめる場合はNULL
        $warehouseId = $splitByWarehouse ? $firstCandidate->warehouse_id : null;
        $warehouse = $splitByWarehouse ? $firstCandidate->warehouse : null;

        // 入荷予定日（グループ化済みのため同一日付）
        $expectedArrivalDate = $firstCandidate->expected_arrival_date;

        $quantityResolver = app(OrderOutputQuantityResolver::class);

        // CSV生成
        $csvContent = $this->buildCsvContent($candidates, $quantityResolver);

        // S3に保存
        $date = now()->format('Y-m-d');
        $contractorCode = $contractor?->code ?? $contractorId;
        if ($splitByWarehouse) {
            $warehouseCode = $warehouse?->code ?? $warehouseId;
            $filename = "{$batchCode}_{$warehouseCode}_{$contractorCode}_".now()->format('YmdHisv').'_'.Str::random(6).'.csv';
        } else {
            $filename = "{$batchCode}_{$contractorCode}_".now()->format('YmdHisv').'_'.Str::random(6).'.csv';
        }
        $filePath = "order-data-files/{$date}/{$filename}";

        Storage::disk('s3')->put($filePath, $csvContent);

        // 合計数量を計算
        $totalQuantity = $quantityResolver->sumOutputOrderQuantity($candidates);
        $totalPieceQuantity = $this->sumTotalPieceQuantity($candidates);

        // DBに記録
        $createdBy = $this->resolveCreatedBy($batchCode, $candidates);
        $candidateIds = $candidates->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        // 同一候補を含む既存の未使用ファイルを置き換え（重複防止）＋新規レコード作成
        $supersededS3Paths = [];
        $dataFile = DB::connection('sakemaru')->transaction(function () use (
            $batchCode,
            $createdBy,
            $warehouseId,
            $contractorId,
            $candidateIds,
            $expectedArrivalDate,
            $filePath,
            $csvContent,
            $candidates,
            $totalQuantity,
            &$supersededS3Paths
        ) {
            $supersededS3Paths = $this->supersedeExistingDataFiles($batchCode, $warehouseId, $contractorId, $candidateIds);

            return WmsOrderDataFile::create([
                'batch_code' => $batchCode,
                'created_by' => $createdBy['id'],
                'created_by_name' => $createdBy['name'],
                'warehouse_id' => $warehouseId,
                'contractor_id' => $contractorId,
                'candidate_ids' => $candidateIds,
                'order_channel' => OrderDataFileChannel::FAX,
                'show_eos_stamp' => false,
                'order_date' => ClientSetting::freshSystemDateYMD('order_data_file:create'),
                'expected_arrival_date' => $expectedArrivalDate,
                'file_path' => $filePath,
                'file_size' => strlen($csvContent),
                'order_count' => $candidates->count(),
                'total_quantity' => $totalQuantity,
                'is_test' => false,
                'status' => OrderDataFileStatus::GENERATED,
            ]);
        });

        // 置き換えた旧ファイルのS3オブジェクトを削除（DBコミット後）
        $this->deleteS3Files($supersededS3Paths);

        $faxError = null;
        if ($warehouseId !== null) {
            try {
                app(PurchaseOrderPdfService::class)->generateAndStoreFromCandidates($candidates, $dataFile, $communicationNotes);
                $dataFile->refresh();
            } catch (\Throwable $e) {
                $faxError = $e->getMessage();
                Log::error('Order data FAX PDF generation failed', [
                    'batch_code' => $batchCode,
                    'warehouse_id' => $warehouseId,
                    'contractor_id' => $contractorId,
                    'candidate_ids' => $candidates->pluck('id')->all(),
                    'error' => $faxError,
                ]);
            }
        }

        return [
            'id' => $dataFile->id,
            'warehouse_id' => $warehouseId,
            'warehouse_name' => $splitByWarehouse ? $warehouse?->name : '全倉庫',
            'contractor_id' => $contractorId,
            'contractor_name' => $contractor?->name,
            'expected_arrival_date' => $expectedArrivalDate?->format('Y-m-d'),
            'order_channel' => OrderDataFileChannel::FAX->value,
            'file_path' => $filePath,
            'fax_file_path' => $dataFile->fax_file_path,
            'fax_error' => $faxError,
            'order_count' => $candidates->count(),
            'total_quantity' => $totalQuantity,
            'total_piece_quantity' => $totalPieceQuantity,
            'candidate_ids' => $candidateIds,
        ];
    }

    /**
     * 同一候補を含む既存の「未使用」発注データファイルを削除して置き換える（重複防止）。
     *
     * - 対象: 同一バッチ・未ダウンロード（CSV/FAX）・未メール送信のレコードのみ
     * - CSVダウンロード済み / FAXダウンロード済み / メール送信済みのファイルは履歴として残す
     *   → データ修正後の再作成（新規レコード追加）は引き続き可能
     * - FAX/MAIL/CSV生成由来（order-data-files/）のみ対象。
     *   JXフロー由来の確認用CSV（jx-orders/...）は wms_order_jx_documents.csv_path が
     *   同一S3オブジェクトを参照しているため絶対に削除しない
     *
     * @param  array<int>  $candidateIds  これから生成するファイルに含まれる候補ID
     * @return array<string> 削除対象のS3パス（DBコミット後に削除する）
     */
    private function supersedeExistingDataFiles(string $batchCode, ?int $warehouseId, ?int $contractorId, array $candidateIds): array
    {
        $existing = WmsOrderDataFile::where('is_test', false)
            ->where('batch_code', $batchCode)
            ->where('status', OrderDataFileStatus::GENERATED)
            ->whereNull('csv_downloaded_at')
            ->whereNull('fax_downloaded_at')
            ->whereNull('mail_sent_at')
            ->where('file_path', 'like', 'order-data-files/%')
            ->lockForUpdate()
            ->get()
            ->filter(function (WmsOrderDataFile $file) use ($warehouseId, $contractorId, $candidateIds): bool {
                $fileCandidateIds = is_array($file->candidate_ids)
                    ? array_map('intval', $file->candidate_ids)
                    : [];

                if ($fileCandidateIds !== []) {
                    // 候補が1件でも重なれば同一データの再生成とみなす
                    return array_intersect($fileCandidateIds, $candidateIds) !== [];
                }

                // candidate_ids未記録の旧レコードは倉庫×発注先で判定
                return (int) ($file->warehouse_id ?? 0) === (int) ($warehouseId ?? 0)
                    && (int) ($file->contractor_id ?? 0) === (int) ($contractorId ?? 0);
            });

        if ($existing->isEmpty()) {
            return [];
        }

        $s3Paths = $existing
            ->flatMap(fn (WmsOrderDataFile $file): array => [$file->file_path, $file->fax_file_path])
            ->filter()
            ->values()
            ->all();

        WmsOrderDataFile::whereIn('id', $existing->pluck('id'))->delete();

        Log::info('Superseded duplicate order data files', [
            'batch_code' => $batchCode,
            'warehouse_id' => $warehouseId,
            'contractor_id' => $contractorId,
            'superseded_ids' => $existing->pluck('id')->all(),
        ]);

        return $s3Paths;
    }

    /**
     * S3上の旧ファイルを削除（失敗してもログのみで処理は継続）
     *
     * @param  array<string>  $paths
     */
    private function deleteS3Files(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                Storage::disk('s3')->delete($path);
            } catch (\Throwable $e) {
                Log::warning('Failed to delete superseded order data file from S3', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function groupCandidatesForDataFiles(Collection $candidates, bool $splitByWarehouse): Collection
    {
        return $candidates->groupBy(fn (WmsOrderCandidate $candidate): string => $this->dataFileGroupKey($candidate, $splitByWarehouse));
    }

    private function dataFileGroupKey(WmsOrderCandidate $candidate, bool $splitByWarehouse): string
    {
        $supplierId = $candidate->supplier_id ?? 'no-supplier';
        $arrivalDate = $candidate->expected_arrival_date?->format('Y-m-d') ?? 'no-date';

        return $splitByWarehouse
            ? "{$candidate->warehouse_id}_{$candidate->contractor_id}_{$supplierId}_{$arrivalDate}"
            : "{$candidate->contractor_id}_{$supplierId}_{$arrivalDate}";
    }

    private function sumTotalPieceQuantity(Collection $candidates): int
    {
        return (int) $candidates->sum(function (WmsOrderCandidate $candidate): int {
            $quantity = max(0, (int) $candidate->order_quantity);
            $quantityType = $candidate->quantity_type instanceof QuantityType
                ? $candidate->quantity_type
                : QuantityType::tryFrom((string) $candidate->quantity_type);

            if ($quantityType === QuantityType::CASE) {
                return $quantity * max(1, (int) ($candidate->item?->capacity_case ?? 1));
            }

            return $quantity;
        });
    }

    /**
     * @return array{id: int|null, name: string}
     */
    private function resolveCreatedBy(string $batchCode, ?Collection $candidates = null): array
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

    private function resolveCreatedByName(string $batchCode, ?Collection $candidates = null): string
    {
        return $this->resolveCreatedBy($batchCode, $candidates)['name'];
    }

    /**
     * CSV内容を生成
     *
     * 伝票ヘッダー情報と明細情報が1行に表示される形式
     * （ヘッダーは明細分重複表示される）
     */
    private function buildCsvContent(Collection $candidates, OrderOutputQuantityResolver $quantityResolver): string
    {
        // 候補IDから入荷予定レコードを取得（伝票番号・単価情報用）
        $candidateIds = $candidates->pluck('id')->toArray();
        $incomingSchedules = WmsOrderIncomingSchedule::whereIn('order_candidate_id', $candidateIds)
            ->get()
            ->keyBy('order_candidate_id');
        $slipNumberMap = $incomingSchedules->pluck('slip_number', 'order_candidate_id')->toArray();

        $headers = [
            // 伝票ヘッダー
            '伝票番号',
            '明細行',
            '発注日',
            '入荷予定日',
            '倉庫コード',
            '倉庫名',
            '発注先コード',
            '発注先名',
            // 明細
            '商品コード',
            '商品名',
            '規格',
            '容量',
            '発注コード',
            '発注単位',
            '発注数量',
            '単位',
            '単価',
        ];

        $rows = [];
        $rows[] = $headers;

        // 伝票番号順でソート
        $sortedCandidates = $candidates->sortBy(function ($candidate) use ($slipNumberMap) {
            return $slipNumberMap[$candidate->id] ?? '';
        });

        // 明細行番号をトラック（伝票ごと）
        $lineNumbers = [];

        foreach ($sortedCandidates as $candidate) {
            $slipNumber = $slipNumberMap[$candidate->id] ?? '';

            // 伝票内の明細行番号
            if (! isset($lineNumbers[$slipNumber])) {
                $lineNumbers[$slipNumber] = 0;
            }
            $lineNumbers[$slipNumber]++;

            $outputQuantity = $quantityResolver->resolve($candidate);

            // 単価（ケース/バラに合わせて）
            $schedule = $incomingSchedules[$candidate->id] ?? null;
            $unitPrice = $quantityResolver->resolveUnitPrice($candidate, $schedule);

            $rows[] = [
                // 伝票ヘッダー（明細分重複）
                $slipNumber,
                $lineNumbers[$slipNumber],
                now()->format('Y-m-d'),
                $candidate->expected_arrival_date?->format('Y-m-d') ?? '',
                $candidate->warehouse?->code ?? '',
                $candidate->warehouse?->name ?? '',
                $candidate->contractor?->code ?? '',
                $candidate->contractor?->name ?? '',
                // 明細
                $candidate->item?->code ?? '',
                $candidate->item?->name ?? '',
                $candidate->item?->packaging ?? '',
                $this->formatVolume($candidate->item),
                $outputQuantity['ordering_code'] ?? '',
                $outputQuantity['display_capacity'] ?? '',
                $outputQuantity['order_quantity'],
                $outputQuantity['unit_label'],
                $unitPrice,
            ];
        }

        // BOM付きUTF-8 CSV生成
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content;
    }

    private function normalizeCommunicationNotes(?string $communicationNotes): ?string
    {
        $notes = trim(str_replace(["\r\n", "\r"], "\n", (string) $communicationNotes));

        return $notes === '' ? null : mb_substr($notes, 0, 500);
    }

    private function formatVolume($item): string
    {
        if (! $item || ! $item->volume || ! $item->volume_unit) {
            return '';
        }

        $unit = EVolumeUnit::tryFrom($item->volume_unit);

        return $unit ? $item->volume.$unit->name() : '';
    }

    /**
     * ダウンロードURLを取得
     */
    public function getDownloadUrl(WmsOrderDataFile $dataFile): ?string
    {
        if (! $dataFile->file_path) {
            return null;
        }

        return Storage::disk('s3')->temporaryUrl(
            $dataFile->file_path,
            now()->addHour()
        );
    }
}
