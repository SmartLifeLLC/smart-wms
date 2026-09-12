<?php

namespace App\Services\LotAdjustment;

use App\Models\WmsLotAdjustmentLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ロット調節の実行オーケストレーション。
 *
 * - A（相殺）/ B（§3.3 再ACTIVE化）/ C（real_stocks 合わせ）を real_stock 単位のトランザクションで適用。
 *   C は単一 ACTIVE LOT のときのみ自動（SYNC_APPLIED）、複数/0個は SYNC_MANUAL（検出のみ）。
 * - D（STLA repoint）を候補単位のトランザクションで適用。
 * - 複数棚番・空棚番（MultiShelfDetector）は検出のみ・自動統一しない。
 * - 各 real_stock で棚番ガード（floor_id/location_id 不変）をアサートし、違反時はその単位のみロールバック。
 * - DRY_RUN はトランザクションをロールバックして「適用された場合の変更」を返す。
 * - 実行結果は wms_lot_adjustment_logs に1レコードとして記録する。
 */
class LotAdjustmentRunner
{
    private string $conn = 'sakemaru';

    public function __construct(
        private ?LotPlusMinusOffsetService $offsetService = null,
        private ?LotResidualReactivationService $reactivationService = null,
        private ?StlaReferenceRepairService $stlaService = null,
        private ?LotParentSyncService $syncService = null,
        private ?MultiShelfDetector $multiShelfDetector = null,
        private ?RsleReuseRiskDetector $rsleReuseRiskDetector = null,
    ) {
        $this->offsetService ??= new LotPlusMinusOffsetService;
        $this->reactivationService ??= new LotResidualReactivationService;
        $this->stlaService ??= new StlaReferenceRepairService;
        $this->syncService ??= new LotParentSyncService;
        $this->multiShelfDetector ??= new MultiShelfDetector;
        $this->rsleReuseRiskDetector ??= new RsleReuseRiskDetector;
    }

    /**
     * 波動生成の前段で呼ぶ安全実行。
     * 何が起きても例外を投げない（波動生成に一切影響させない）。失敗はログに残す。
     */
    public function runForWaveGeneration(int $warehouseId): ?array
    {
        try {
            return $this->run($warehouseId, true, 'wave_generation');
        } catch (\Throwable $e) {
            $this->logFailure($warehouseId, $e);

            return null;
        }
    }

    /**
     * @param  bool  $apply  true=適用(APPLIED) / false=プレビュー(DRY_RUN)
     * @param  string  $trigger  実行契機（manual / wave_generation）
     * @return array{run_uuid:string, mode:string, summary:array, affected_count:int, details:array, log_id:int}
     */
    public function run(int $warehouseId, bool $apply, string $trigger = 'manual'): array
    {
        $runUuid = (string) Str::uuid();
        $details = [];

        // A + B（real_stock 単位）
        foreach ($this->targetRealStockIds($warehouseId) as $rsId) {
            $details = array_merge($details, $this->processRealStock((int) $rsId, $apply));
        }

        // D（STLA repoint・候補単位）
        foreach ($this->stlaService->detectCandidates($warehouseId) as $cand) {
            if ($cand->eligible) {
                $details[] = $this->processRepoint($cand, $apply);
            } else {
                $details[] = [
                    'type' => 'SKIP',
                    'real_stock_id' => $cand->real_stock_id,
                    'lot_id' => null,
                    'stla_id' => $cand->stla_id,
                    'old_lot_id' => $cand->old_lot_id,
                    'new_lot_id' => null,
                    'reason' => 'STLA_'.$cand->skip_reason,
                ];
            }
        }

        // 複数棚番・空棚番（検出のみ・自動統一はしない）
        $details = array_merge($details, $this->multiShelfDetector->detectForWarehouse($warehouseId));

        // RSLE 再利用リスク（検出のみ・APPLYでもRSLEをCANCELしない）
        $details = array_merge($details, $this->rsleReuseRiskDetector->detectForWarehouse($warehouseId));

        $summary = $this->summarize($details);
        $affected = count(array_filter(
            $details,
            fn ($d) => in_array($d['type'] ?? null, ['OFFSET', 'REACTIVATE', 'ZERO_RESIDUAL', 'SYNC_APPLIED', 'REPOINT'], true)
        ));

        $log = WmsLotAdjustmentLog::record($apply ? 'APPLIED' : 'DRY_RUN', [
            'run_uuid' => $runUuid,
            'warehouse_id' => $warehouseId,
            'scope' => ['warehouse_id' => $warehouseId, 'trigger' => $trigger],
            'summary' => $summary,
            // DRY_RUN でも「適用されるはずの件数」を履歴に残す（mode で区別）。
            'affected_count' => $affected,
            'details' => $details,
        ]);

        return [
            'run_uuid' => $runUuid,
            'mode' => $apply ? 'APPLIED' : 'DRY_RUN',
            'summary' => $summary,
            'affected_count' => $affected,
            'details' => $details,
            'log_id' => (int) $log->id,
        ];
    }

    /**
     * 1 real_stock に A+B を適用。棚番ガード違反時はロールバックして LOCATION_ABORTED を返す。
     */
    private function processRealStock(int $realStockId, bool $apply): array
    {
        $conn = DB::connection($this->conn);
        $conn->beginTransaction();
        try {
            $snapshot = $this->locationSnapshot($realStockId);

            $changes = array_merge(
                $this->offsetService->applyForRealStock($realStockId),
                $this->reactivationService->applyForRealStock($realStockId),
                // C: ACTIVE 合計を real_stocks へ合わせる（単一 ACTIVE LOT のときのみ自動）
                $this->syncService->applyForRealStock($realStockId),
            );

            $violation = $this->locationGuardViolation($realStockId, $snapshot);
            if ($violation !== null) {
                $conn->rollBack();

                return [[
                    'type' => 'LOCATION_ABORTED',
                    'real_stock_id' => $realStockId,
                    'lot_id' => null,
                    'reason' => $violation,
                ]];
            }

            if ($apply) {
                $conn->commit();
            } else {
                $conn->rollBack();
            }

            return $changes;
        } catch (\Throwable $e) {
            $conn->rollBack();

            return [[
                'type' => 'SKIP',
                'real_stock_id' => $realStockId,
                'lot_id' => null,
                'reason' => 'ERROR: '.$e->getMessage(),
            ]];
        }
    }

    /**
     * 1 STLA repoint を適用（トランザクション単位）。
     */
    private function processRepoint(object $candidate, bool $apply): array
    {
        $conn = DB::connection($this->conn);
        $conn->beginTransaction();
        try {
            $change = $this->stlaService->applyRepoint($candidate);
            // 実際に repoint された場合のみ commit。SKIP（競合・WMS行）や 0 件更新は commit しない。
            if ($apply && ($change['type'] ?? '') === 'REPOINT') {
                $conn->commit();
            } else {
                $conn->rollBack();
            }

            return $change;
        } catch (\Throwable $e) {
            $conn->rollBack();

            return [
                'type' => 'SKIP',
                'real_stock_id' => $candidate->real_stock_id,
                'stla_id' => $candidate->stla_id,
                'reason' => 'REPOINT_ERROR: '.$e->getMessage(),
            ];
        }
    }

    /**
     * A（相殺）と B（非ACTIVE残数）の対象 real_stock を抽出。
     *
     * @return array<int, int>
     */
    private function targetRealStockIds(int $warehouseId): array
    {
        $offset = DB::connection($this->conn)
            ->table('real_stock_lots as l')
            ->join('real_stocks as rs', 'rs.id', '=', 'l.real_stock_id')
            ->where('rs.warehouse_id', $warehouseId)
            ->where('l.status', 'ACTIVE')
            ->groupBy('l.real_stock_id')
            ->havingRaw('SUM(l.current_quantity > 0) > 0 AND SUM(l.current_quantity < 0) > 0')
            ->pluck('l.real_stock_id');

        $residual = DB::connection($this->conn)
            ->table('real_stock_lots as l')
            ->join('real_stocks as rs', 'rs.id', '=', 'l.real_stock_id')
            ->where('rs.warehouse_id', $warehouseId)
            ->where('l.status', '!=', 'ACTIVE')
            ->where(function ($q) {
                $q->where('l.current_quantity', '<>', 0)
                    ->orWhere('l.reserved_quantity', '<>', 0);
            })
            ->distinct()
            ->pluck('l.real_stock_id');

        // C: 親 real_stocks と ACTIVE 合計が不一致の real_stock も対象に含める
        $mismatch = DB::connection($this->conn)
            ->table('real_stocks as rs')
            ->leftJoin('real_stock_lots as l', function ($j) {
                $j->on('l.real_stock_id', '=', 'rs.id')->where('l.status', '=', 'ACTIVE');
            })
            ->where('rs.warehouse_id', $warehouseId)
            ->groupBy('rs.id', 'rs.current_quantity', 'rs.reserved_quantity')
            ->havingRaw('rs.current_quantity <> COALESCE(SUM(l.current_quantity),0) OR rs.reserved_quantity <> COALESCE(SUM(l.reserved_quantity),0)')
            ->pluck('rs.id');

        return $offset->merge($residual)->merge($mismatch)->unique()->map(fn ($v) => (int) $v)->values()->all();
    }

    /**
     * real_stock 配下全LOTの棚番スナップショット。lot_id => "floor|location"
     *
     * @return array<int, string>
     */
    private function locationSnapshot(int $realStockId): array
    {
        return DB::connection($this->conn)
            ->table('real_stock_lots')
            ->where('real_stock_id', $realStockId)
            ->pluck(DB::raw("CONCAT(COALESCE(floor_id,'null'),'|',COALESCE(location_id,'null'))"), 'id')
            ->map(fn ($v) => (string) $v)
            ->all();
    }

    /**
     * 棚番ガード: 既存LOTの floor_id/location_id が変わっていないか検査。
     * 違反があれば理由文字列、なければ null。
     */
    private function locationGuardViolation(int $realStockId, array $snapshot): ?string
    {
        $after = $this->locationSnapshot($realStockId);

        foreach ($snapshot as $lotId => $before) {
            $now = $after[$lotId] ?? null;
            if ($now !== null && $now !== $before) {
                return "LOT {$lotId} の棚番が変化 ({$before} -> {$now})";
            }
        }

        return null;
    }

    /**
     * 波動生成前のロット調節が例外で失敗したとき、ロット調節履歴に失敗を記録する。
     * ログ記録自体の失敗も握りつぶす（波動生成に絶対に影響させない）。
     */
    private function logFailure(int $warehouseId, \Throwable $e): void
    {
        try {
            WmsLotAdjustmentLog::record('APPLIED', [
                'warehouse_id' => $warehouseId,
                'scope' => ['warehouse_id' => $warehouseId, 'trigger' => 'wave_generation'],
                'summary' => ['error' => 1],
                'affected_count' => 0,
                'details' => [[
                    'type' => 'ERROR',
                    'real_stock_id' => null,
                    'lot_id' => null,
                    'reason' => 'WAVE_PRE_ADJUSTMENT_ERROR: '.$e->getMessage(),
                ]],
                'note' => '波動生成前のロット調節でエラーが発生しました（波動生成は継続）。'
                    .$e::class.': '.$e->getMessage(),
            ]);
        } catch (\Throwable $ignore) {
            // ログ失敗も無視（波動生成を止めない）
        }
    }

    private function summarize(array $details): array
    {
        $count = fn (string $type) => count(array_filter($details, fn ($d) => ($d['type'] ?? null) === $type));

        return [
            'offset' => $count('OFFSET'),
            'reactivate' => $count('REACTIVATE'),
            'zero_residual' => $count('ZERO_RESIDUAL'),
            'repoint' => $count('REPOINT'),
            'sync_applied' => $count('SYNC_APPLIED'),
            'sync_manual' => $count('SYNC_MANUAL'),
            'multi_shelf' => $count('MULTI_SHELF'),
            'blank_location' => $count('BLANK_LOCATION'),
            'reserved_reuse_risk' => $count('RSLE_REUSE_RISK'),
            'reserved_reuse_wms_exists' => $count('RSLE_REUSE_WMS_EXISTS'),
            'skipped' => $count('SKIP'),
            'location_aborted' => $count('LOCATION_ABORTED'),
        ];
    }
}
