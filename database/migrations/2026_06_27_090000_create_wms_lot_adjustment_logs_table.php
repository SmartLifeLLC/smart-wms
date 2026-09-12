<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->create('wms_lot_adjustment_logs', function (Blueprint $table) {
            $table->id();

            // 実行の識別（1回の実行＝1 run_uuid。dry-run と適用を区別）
            $table->uuid('run_uuid')->comment('実行ラン識別ID');
            $table->enum('mode', ['DRY_RUN', 'APPLIED'])->default('APPLIED')->comment('DRY_RUN=プレビュー / APPLIED=適用');

            // 実行者・対象スコープ
            $table->unsignedBigInteger('user_id')->nullable()->comment('実行ユーザーID');
            $table->unsignedBigInteger('warehouse_id')->nullable()->comment('対象倉庫ID');
            $table->json('scope')->nullable()->comment('実行スコープ（倉庫/商品フィルタ等）');

            // 集計サマリ
            // { offset, reactivate, zero_residual, repoint, sync_applied, sync_manual,
            //   multi_shelf, blank_location, reserved_reuse_risk, reserved_reuse_wms_exists,
            //   skipped, location_aborted }
            $table->json('summary')->nullable()->comment('処理種別ごとの件数サマリ');
            $table->integer('affected_count')->nullable()->comment('実際に変更したレコード数（APPLIED時）');

            // 変更明細（before/after）
            // 各要素: type[OFFSET/REACTIVATE/ZERO_RESIDUAL/SYNC_APPLIED/SYNC_MANUAL/REPOINT/
            //               MULTI_SHELF/BLANK_LOCATION/RSLE_REUSE_RISK/RSLE_REUSE_WMS_EXISTS/
            //               SKIP/LOCATION_ABORTED],
            //         real_stock_id, lot_id, status_before/after,
            //         current_before/after, reserved_before/after,
            //         location_id, stla_id, old_lot_id, new_lot_id, reason
            $table->json('details')->nullable()->comment('変更/検出明細（before/after）');

            // 補足・クライアント情報
            $table->text('note')->nullable()->comment('補足・理由');
            $table->string('ip_address', 45)->nullable()->comment('IPアドレス');
            $table->text('user_agent')->nullable()->comment('ユーザーエージェント');

            // タイムスタンプ
            $table->timestamp('created_at')->useCurrent()->comment('作成日時');

            // インデックス
            $table->index('run_uuid');
            $table->index('user_id');
            $table->index('warehouse_id');
            $table->index('mode');
            $table->index('created_at');
            $table->index(['warehouse_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_lot_adjustment_logs');
    }
};
