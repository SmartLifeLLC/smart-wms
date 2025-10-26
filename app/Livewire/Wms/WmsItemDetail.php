<?php

namespace App\Livewire\Wms;

use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\RealStock;
use Livewire\Attributes\On;
use Livewire\Component;

class WmsItemDetail extends Component
{
    public ?int $itemId = null;
    public ?Item $item = null;
    public array $stockInfo = [];

    #[On('item-selected')]
    public function loadItem(int $itemId): void
    {
        $this->itemId = $itemId;
        $this->item = Item::with(['itemCategory', 'supplier', 'warehouse'])->find($itemId);

        if ($this->item) {
            $this->loadStockInfo();
        }
    }

    private function loadStockInfo(): void
    {
        if (!$this->item) {
            return;
        }

        // Get real stock information with WMS fields
        $stocks = RealStock::where('item_id', $this->item->id)
            ->with(['warehouse', 'location'])
            ->fefoFifo() // Apply FEFO/FIFO ordering
            ->get();

        $this->stockInfo = [
            'total_qty' => $stocks->sum('current_quantity'),
            'reserved_qty' => $stocks->sum('wms_reserved_qty'),
            'picking_qty' => $stocks->sum('wms_picking_qty'),
            'available_qty' => $stocks->sum(function ($stock) {
                return $stock->current_quantity - $stock->wms_reserved_qty - $stock->wms_picking_qty;
            }),
            'locations' => $stocks->map(function ($stock) {
                return [
                    'warehouse_name' => $stock->warehouse?->name,
                    'location_name' => $stock->location?->name,
                    'lot_no' => $stock->lot_no,
                    'expiration_date' => $stock->expiration_date?->format('Y-m-d'),
                    'current_qty' => $stock->current_quantity,
                    'reserved_qty' => $stock->wms_reserved_qty,
                    'picking_qty' => $stock->wms_picking_qty,
                    'available_qty' => $stock->current_quantity - $stock->wms_reserved_qty - $stock->wms_picking_qty,
                ];
            })->toArray(),
        ];
    }

    public function clear(): void
    {
        $this->itemId = null;
        $this->item = null;
        $this->stockInfo = [];
    }

    public function render()
    {
        return view('livewire.wms.wms-item-detail');
    }
}
