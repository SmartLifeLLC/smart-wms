<?php

namespace App\Livewire;

use App\Models\Sakemaru\Warehouse;
use Livewire\Component;

class WarehouseSelector extends Component
{
    private const SELECTABLE_VIRTUAL_WAREHOUSE_CODES = [92];

    public ?int $selectedWarehouseId = null;

    public string $selectedWarehouseName = '倉庫未選択';

    public array $warehouses = [];

    public function mount(): void
    {
        $user = auth()->user();
        $this->selectedWarehouseId = $user?->getSelectedWarehouseId();

        $warehouseModels = Warehouse::where(function ($query) {
            $query->where('is_virtual', false)
                ->orWhereIn('code', self::SELECTABLE_VIRTUAL_WAREHOUSE_CODES);
        })
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $this->warehouses = $warehouseModels->map(fn ($w) => [
            'id' => $w->id,
            'code' => str_pad($w->code, 2, '0', STR_PAD_LEFT),
            'name' => $w->name,
        ])->toArray();

        if ($this->selectedWarehouseId) {
            $selected = $warehouseModels->firstWhere('id', $this->selectedWarehouseId);
            if ($selected) {
                $this->selectedWarehouseName = $selected->name;
            }
        }
    }

    public function selectWarehouse(int $warehouseId): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        $user->wms_selected_warehouse_id = $warehouseId;
        $user->save();

        $this->redirect(request()->header('Referer', '/admin'), navigate: true);
    }

    public function render()
    {
        return view('livewire.warehouse-selector');
    }
}
