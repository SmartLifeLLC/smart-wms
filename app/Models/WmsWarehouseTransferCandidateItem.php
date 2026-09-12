<?php

namespace App\Models;

use App\Models\Sakemaru\Item;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 倉庫移動候補 明細（同一商品・在庫区分で集約済み）
 */
class WmsWarehouseTransferCandidateItem extends WmsModel
{
    protected $table = 'wms_warehouse_transfer_candidate_items';

    protected $fillable = [
        'candidate_id',
        'item_id',
        'item_code',
        'item_name',
        'barcode',
        'real_stock_id',
        'location_id',
        'location_no',
        'stock_allocation_code',
        'case_quantity',
        'piece_quantity',
        'package_quantity',
        'transfer_quantity',
        'available_quantity_at_sync',
        'available_quantity_at_confirm',
        'scanned_code',
        'source_line_count',
        'line_note',
        'sort_order',
    ];

    protected $casts = [
        'case_quantity' => 'decimal:3',
        'piece_quantity' => 'decimal:3',
        'package_quantity' => 'integer',
        'transfer_quantity' => 'decimal:3',
        'available_quantity_at_sync' => 'decimal:3',
        'available_quantity_at_confirm' => 'decimal:3',
        'source_line_count' => 'integer',
        'sort_order' => 'integer',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(WmsWarehouseTransferCandidate::class, 'candidate_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(WmsWarehouseTransferCandidateItemSource::class, 'candidate_item_id');
    }

    /**
     * 総バラ数 = ケース数 × 入数 + バラ数
     */
    public static function calculateTransferQuantity(float $caseQuantity, int $packageQuantity, float $pieceQuantity): float
    {
        return ($caseQuantity * max($packageQuantity, 1)) + $pieceQuantity;
    }

    /**
     * 総バラ数からケース/バラ表示を再計算
     *
     * @return array{case_quantity: float, piece_quantity: float}
     */
    public static function splitTransferQuantity(float $transferQuantity, int $packageQuantity): array
    {
        $packageQuantity = max($packageQuantity, 1);
        $caseQuantity = $packageQuantity > 1 ? floor($transferQuantity / $packageQuantity) : 0.0;

        return [
            'case_quantity' => (float) $caseQuantity,
            'piece_quantity' => (float) ($transferQuantity - ($caseQuantity * $packageQuantity)),
        ];
    }
}
