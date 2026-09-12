<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * ロット調節ツールの実行ログ（1回の実行＝1レコード）。
 *
 * 在庫データ（real_stocks / real_stock_lots / stla）は変更しない。
 * 実行前後の状態を details(JSON) に記録し、画面で履歴を確認できるようにする。
 */
class WmsLotAdjustmentLog extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'wms_lot_adjustment_logs';

    public $timestamps = false; // created_at only

    protected $fillable = [
        'run_uuid',
        'mode',
        'user_id',
        'warehouse_id',
        'scope',
        'summary',
        'affected_count',
        'details',
        'note',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'scope' => 'array',
        'summary' => 'array',
        'details' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Sakemaru\Warehouse::class, 'warehouse_id');
    }

    /**
     * ロット調節の実行ログを記録する。
     *
     * @param  string  $mode  'DRY_RUN' | 'APPLIED'
     * @param  array  $data  warehouse_id, scope, summary, affected_count, details, note 等
     */
    /** 種別(type)の日本語ラベル */
    public static function typeLabel(?string $type): string
    {
        return match ($type) {
            'OFFSET' => '相殺',
            'REACTIVATE' => '再ACTIVE化',
            'ZERO_RESIDUAL' => '残数0化',
            'SYNC_APPLIED' => '在庫数合わせ',
            'SYNC_MANUAL' => '在庫数合わせ（要手動）',
            'REPOINT' => 'STLA修正',
            'MULTI_SHELF' => '複数棚番（検出のみ）',
            'BLANK_LOCATION' => '空棚番（検出のみ）',
            'RSLE_REUSE_RISK' => 'RSLE再利用リスク（検出のみ）',
            'RSLE_REUSE_WMS_EXISTS' => 'RSLE再利用・WMS行あり（検出のみ）',
            'SKIP' => 'スキップ',
            'LOCATION_ABORTED' => '棚番保護で中止',
            default => (string) $type,
        };
    }

    /** 種別(type)のバッジ色クラス */
    public static function typeBadgeClass(?string $type): string
    {
        return match ($type) {
            'OFFSET' => 'bg-blue-100 text-blue-700',
            'REACTIVATE' => 'bg-green-100 text-green-700',
            'ZERO_RESIDUAL' => 'bg-teal-100 text-teal-700',
            'SYNC_APPLIED' => 'bg-cyan-100 text-cyan-700',
            'SYNC_MANUAL' => 'bg-amber-100 text-amber-700',
            'REPOINT' => 'bg-indigo-100 text-indigo-700',
            'MULTI_SHELF' => 'bg-orange-100 text-orange-700',
            'BLANK_LOCATION' => 'bg-pink-100 text-pink-700',
            'RSLE_REUSE_RISK' => 'bg-amber-100 text-amber-700',
            'RSLE_REUSE_WMS_EXISTS' => 'bg-red-100 text-red-700',
            'LOCATION_ABORTED' => 'bg-red-100 text-red-700',
            default => 'bg-gray-100 text-gray-600',
        };
    }

    /** 理由(reason)コードの日本語ラベル（末尾の「: 詳細」は保持） */
    public static function reasonLabel(?string $reason): string
    {
        if ($reason === null || $reason === '') {
            return '';
        }

        $detail = '';
        $code = $reason;
        if (str_contains($reason, ': ')) {
            [$code, $detail] = explode(': ', $reason, 2);
        }

        $map = [
            'REACTIVATE_SAME_LOT' => '同一LOTを再ACTIVE化',
            'ZERO_ABNORMAL_RESIDUAL' => '余剰の異常残を0/0に',
            'MANUAL_REQUIRED' => '要手動判断',
            'NEGATIVE_LOT_REPOINT' => '負/枯渇LOT参照を正LOTへ付替',
            'SAME_SHELF_POSITIVE_LOT_MERGE_REPOINT' => '同一棚番の正LOTを合算してSTLA付替',
            'STLA_NO_POSITIVE_LOT' => 'STLA：正LOT無し',
            'STLA_AMBIGUOUS_POSITIVE_LOT' => 'STLA：正LOT候補が複数',
            'STLA_DELIVERED_OR_CONFIRMED' => 'STLA：出荷確定済み',
            'STLA_PICKING_STARTED' => 'STLA：ピッキング着手済み',
            'STLA_WMS_ROWS_EXIST' => 'STLA：WMS行が存在',
            'STLA_WMS_ROWS_EXIST_AT_APPLY' => 'STLA：実行直前にWMS行が発生',
            'STLA_SKIP_CONCURRENTLY_CHANGED' => 'STLA：競合変更により0件更新',
            'ALIGN_SINGLE_ACTIVE_LOT_TO_PARENT' => '単一ACTIVE LOTを親在庫に合わせた',
            'SYNC_NO_ACTIVE_LOT' => 'ACTIVE LOT無し（新規補正が必要）',
            'SYNC_MULTIPLE_ACTIVE_LOTS' => 'ACTIVE LOTが複数（対象が一意でない）',
            'MULTI_SHELF_MANUAL_REQUIRED' => '複数棚番（要手動統一）',
            'RSLE_REUSE_RISK_NO_WMS_ROWS' => 'WMS行なし（波動前にRSLE要確認・CANCELは別操作）',
            'RSLE_REUSE_RISK_WMS_ROWS_EXIST' => 'WMS行あり（自動CANCEL禁止・WMS状態調査が必要）',
            'ERROR' => 'エラー',
            'REPOINT_ERROR' => 'STLA更新エラー',
        ];

        $jp = $map[$code] ?? $code;

        return $detail !== '' ? $jp.'：'.$detail : $jp;
    }

    public static function record(string $mode, array $data = []): self
    {
        $user = auth()->user();

        $logData = array_merge([
            'run_uuid' => $data['run_uuid'] ?? (string) Str::uuid(),
            'mode' => $mode,
            'user_id' => $user?->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ], $data);

        return self::create($logData);
    }
}
