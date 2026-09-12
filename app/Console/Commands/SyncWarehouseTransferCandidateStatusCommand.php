<?php

namespace App\Console\Commands;

use App\Services\WarehouseTransfer\WarehouseTransferStatusSyncService;
use Illuminate\Console\Command;

/**
 * 倉庫移動候補の queue 処理結果を同期する
 */
class SyncWarehouseTransferCandidateStatusCommand extends Command
{
    protected $signature = 'wms:sync-warehouse-transfer-candidates';

    protected $description = '倉庫移動候補（HANDY）の stock_transfer_queue 処理結果を候補ステータスへ反映する';

    public function handle(WarehouseTransferStatusSyncService $service): int
    {
        $updated = $service->syncAll();

        $this->info("Synced warehouse transfer candidates: {$updated}");

        return self::SUCCESS;
    }
}
