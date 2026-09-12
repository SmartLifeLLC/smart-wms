<?php

namespace App\Services\Print;

use App\Models\PrintRequestQueue;
use App\Models\WmsPickingTask;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PrintRequestService
{
    private const RECENT_COMPLETED_DUPLICATE_WINDOW_SECONDS = 5;

    /**
     * 配送コース別に伝票印刷依頼を作成
     *
     * @param  int  $deliveryCourseId  配送コースID
     * @param  string  $shipmentDate  納品日
     * @param  int  $warehouseId  倉庫ID
     * @param  int|null  $waveId  ウェーブID (Optional)
     * @return array ['success' => bool, 'message' => string, 'queue_id' => int|null]
     */
    public function createPrintRequest(
        int $deliveryCourseId,
        string $shipmentDate,
        int $warehouseId,
        ?int $waveId = null,
        ?int $overridePrinterDriverId = null,
        bool $useDefaultPrinter = false,
    ): array {
        try {
            if ($overridePrinterDriverId !== null) {
                // モーダルで明示的にプリンターが選択された場合
                $printerDriverId = $overridePrinterDriverId;
            } elseif ($useDefaultPrinter) {
                // 配送コース別プリンター設定を取得
                $printerSetting = DB::connection('sakemaru')
                    ->table('client_printer_course_settings')
                    ->where('warehouse_id', $warehouseId)
                    ->where('delivery_course_id', $deliveryCourseId)
                    ->where('is_active', true)
                    ->first();

                // プリンタードライバーIDを取得（未設定の場合はnull = PDF生成のみ）
                $printerDriverId = $printerSetting?->printer_driver_id;
            } else {
                // モーダルで「なし（PDFのみ生成）」が明示的に選択された場合
                $printerDriverId = null;
            }
            $hasPrinter = $printerDriverId !== null;

            // 対象のピッキングタスクを取得
            $query = WmsPickingTask::where('delivery_course_id', $deliveryCourseId)
                ->where('shipment_date', $shipmentDate)
                ->where('warehouse_id', $warehouseId);

            if ($waveId) {
                $query->where('wave_id', $waveId);
            }

            $tasks = $query->with(['pickingItemResults'])->get();

            if ($tasks->isEmpty()) {
                return [
                    'success' => false,
                    'message' => '対象のピッキングタスクが見つかりません。',
                    'queue_id' => null,
                ];
            }

            $taskIds = $tasks->pluck('id')->all();
            [$earningIds, $stockTransferIds] = $this->collectPrintTargetIds($taskIds);

            // 売上も倉庫移動もない場合はエラー
            if (empty($earningIds) && empty($stockTransferIds)) {
                return [
                    'success' => true,
                    'message' => '印刷対象の売上・倉庫移動が見つかりません。',
                    'queue_id' => null,
                    'earning_count' => 0,
                    'stock_transfer_count' => 0,
                    'has_printer' => false,
                    'no_print_targets' => true,
                ];
            }

            // client_idを取得（売上または倉庫移動から）
            $clientId = $this->resolveClientId($earningIds, $stockTransferIds);

            if (! $clientId) {
                return [
                    'success' => false,
                    'message' => 'クライアントIDが取得できません。',
                    'queue_id' => null,
                ];
            }

            // トランザクション内で処理
            return DB::connection('sakemaru')->transaction(function () use (
                $clientId,
                $earningIds,
                $stockTransferIds,
                $warehouseId,
                $printerDriverId,
                $hasPrinter,
                $deliveryCourseId,
                $taskIds
            ) {
                WmsPickingTask::whereIn('id', $taskIds)
                    ->lockForUpdate()
                    ->pluck('id');

                $existingQueue = $this->findDuplicatePrintRequestQueue(
                    $earningIds,
                    $stockTransferIds,
                    $warehouseId
                );

                if ($existingQueue) {
                    if ($existingQueue->status === PrintRequestQueue::STATUS_PENDING) {
                        $existingQueue->update([
                            'print_type' => $hasPrinter
                                ? PrintRequestQueue::PRINT_TYPE_CLIENT_SLIP_PRINTER
                                : PrintRequestQueue::PRINT_TYPE_CLIENT_SLIP,
                            'printer_driver_id' => $printerDriverId,
                        ]);
                    }

                    return [
                        'success' => true,
                        'message' => '既に印刷依頼が作成されています。',
                        'queue_id' => $existingQueue->id,
                        'earning_count' => count($earningIds),
                        'stock_transfer_count' => count($stockTransferIds),
                        'has_printer' => $hasPrinter,
                        'already_queued' => true,
                    ];
                }

                // print_request_queueにレコードを作成
                // printer_driver_idがある場合はプリンター印刷、なければPDF生成のみ
                $queue = PrintRequestQueue::create([
                    'client_id' => $clientId,
                    'earning_ids' => $earningIds,
                    'stock_transfer_ids' => $stockTransferIds,
                    'print_type' => $hasPrinter
                        ? PrintRequestQueue::PRINT_TYPE_CLIENT_SLIP_PRINTER
                        : PrintRequestQueue::PRINT_TYPE_CLIENT_SLIP,
                    'group_by_delivery_course' => true,
                    'warehouse_id' => $warehouseId,
                    'printer_driver_id' => $printerDriverId,
                    'status' => PrintRequestQueue::STATUS_PENDING,
                    'requested_by' => Auth::id(),
                ]);

                // 売上の picking_status を SHIPPED に更新
                if (! empty($earningIds)) {
                    DB::connection('sakemaru')
                        ->table('earnings')
                        ->whereIn('id', $earningIds)
                        ->update([
                            'picking_status' => 'SHIPPED',
                            'updated_at' => now(),
                        ]);
                }

                // 倉庫移動の picking_status を SHIPPED に更新
                if (! empty($stockTransferIds)) {
                    DB::connection('sakemaru')
                        ->table('stock_transfers')
                        ->whereIn('id', $stockTransferIds)
                        ->update([
                            'picking_status' => 'SHIPPED',
                            'updated_at' => now(),
                        ]);
                }

                Log::info('Print request created and picking_status updated to SHIPPED', [
                    'queue_id' => $queue->id,
                    'delivery_course_id' => $deliveryCourseId,
                    'earning_ids' => $earningIds,
                    'stock_transfer_ids' => $stockTransferIds,
                    'printer_driver_id' => $printerDriverId,
                ]);

                return [
                    'success' => true,
                    'message' => $hasPrinter ? '印刷依頼を作成しました。' : 'PDF生成依頼を作成しました（プリンター未設定）。',
                    'queue_id' => $queue->id,
                    'earning_count' => count($earningIds),
                    'stock_transfer_count' => count($stockTransferIds),
                    'has_printer' => $hasPrinter,
                ];
            });
        } catch (\Exception $e) {
            Log::error('Failed to create print request', [
                'delivery_course_id' => $deliveryCourseId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '印刷依頼の作成に失敗しました: '.$e->getMessage(),
                'queue_id' => null,
            ];
        }
    }

    /**
     * 同一対象の未処理キュー、または二重押下相当の連続完了キューを探す。
     */
    private function findDuplicatePrintRequestQueue(array $earningIds, array $stockTransferIds, int $warehouseId): ?PrintRequestQueue
    {
        $duplicateWindowStart = now()->subSeconds(self::RECENT_COMPLETED_DUPLICATE_WINDOW_SECONDS);

        $queues = PrintRequestQueue::query()
            ->where('warehouse_id', $warehouseId)
            ->where('group_by_delivery_course', true)
            ->where(function ($query) use ($duplicateWindowStart) {
                $query->whereIn('status', [
                    PrintRequestQueue::STATUS_PENDING,
                    PrintRequestQueue::STATUS_PROCESSING,
                ])
                    ->orWhere(function ($query) use ($duplicateWindowStart) {
                        $query->where('status', PrintRequestQueue::STATUS_COMPLETED)
                            ->where(function ($query) use ($duplicateWindowStart) {
                                $query->where('processed_at', '>=', $duplicateWindowStart)
                                    ->orWhere(function ($query) use ($duplicateWindowStart) {
                                        $query->whereNull('processed_at')
                                            ->where('created_at', '>=', $duplicateWindowStart);
                                    });
                            });
                    });
            })
            ->lockForUpdate()
            ->get();

        foreach ($queues as $queue) {
            if (
                $this->sameIdSet($queue->earning_ids ?? [], $earningIds)
                && $this->sameIdSet($queue->stock_transfer_ids ?? [], $stockTransferIds)
            ) {
                return $queue;
            }
        }

        return null;
    }

    private function sameIdSet(array $left, array $right): bool
    {
        $left = array_values(array_unique(array_map('intval', $left)));
        $right = array_values(array_unique(array_map('intval', $right)));
        sort($left);
        sort($right);

        return $left === $right;
    }

    /**
     * client_id を取得（売上または倉庫移動から）
     */
    private function resolveClientId(array $earningIds, array $stockTransferIds): ?int
    {
        // 売上がある場合は売上から取得
        if (! empty($earningIds)) {
            return DB::connection('sakemaru')
                ->table('earnings')
                ->where('id', $earningIds[0])
                ->value('client_id');
        }

        // 倉庫移動がある場合は倉庫移動から取得
        if (! empty($stockTransferIds)) {
            return DB::connection('sakemaru')
                ->table('stock_transfers')
                ->where('id', $stockTransferIds[0])
                ->value('client_id');
        }

        return null;
    }

    /**
     * 配送コース別ピッキングリストと同じ棚順で、帳票生成対象の伝票IDを収集する。
     *
     * @return array{0: array<int>, 1: array<int>}
     */
    private function collectPrintTargetIds(array $taskIds): array
    {
        $rows = DB::connection('sakemaru')
            ->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->join('items as i', 'pir.item_id', '=', 'i.id')
            ->leftJoin('earnings as e', 'pir.earning_id', '=', 'e.id')
            ->leftJoin('trades as et', 'e.trade_id', '=', 'et.id')
            ->leftJoin('stock_transfers as st', 'pir.stock_transfer_id', '=', 'st.id')
            ->leftJoin('trades as stt', 'st.trade_id', '=', 'stt.id')
            ->leftJoin('trade_items as ti', 'pir.trade_item_id', '=', 'ti.id')
            ->leftJoin('locations as l', 'pir.location_id', '=', 'l.id')
            ->whereIn('pir.picking_task_id', $taskIds)
            ->where('pir.ordered_qty', '>', 0)
            ->where(function ($query) {
                $query->where(function ($query) {
                    $query->whereNotNull('pir.earning_id')
                        ->where('e.is_active', true)
                        ->where('et.is_active', true);
                })
                    ->orWhere(function ($query) {
                        $query->whereNotNull('pir.stock_transfer_id')
                            ->where('st.is_active', true)
                            ->where('stt.is_active', true);
                    });
            })
            ->where('ti.is_active', true)
            ->select([
                'pir.earning_id',
                'pir.stock_transfer_id',
            ])
            ->orderByRaw('COALESCE(l.floor_id, 999999)')
            ->orderByRaw("COALESCE(l.code1, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code2, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code3, 'ZZZ')")
            ->orderBy('i.code')
            ->orderByRaw('COALESCE(e.id, st.id)')
            ->get();

        $earningIds = [];
        $stockTransferIds = [];

        foreach ($rows as $row) {
            if ($row->earning_id && ! in_array((int) $row->earning_id, $earningIds, true)) {
                $earningIds[] = (int) $row->earning_id;
            }
            if ($row->stock_transfer_id && ! in_array((int) $row->stock_transfer_id, $stockTransferIds, true)) {
                $stockTransferIds[] = (int) $row->stock_transfer_id;
            }
        }

        return [$earningIds, $stockTransferIds];
    }
}
