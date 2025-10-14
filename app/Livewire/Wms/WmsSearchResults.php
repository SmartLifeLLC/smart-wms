<?php

namespace App\Livewire\Wms;

use App\Models\Sakemaru\Earning;
use App\Models\WmsStockAvailable;
use Livewire\Attributes\On;
use Livewire\Component;

class WmsSearchResults extends Component
{
    public array $filters = [];
    public int $perPage = 50;
    public int $currentPage = 1;
    public bool $hasMorePages = true;
    public array $loadedItems = [];
    public int|null $selectedItemId = null;
    public ?string $sortBy = null;
    public string $sortDir = 'asc';

    #[On('search-updated')]
    public function updateSearch(array $filters): void
    {
        $this->filters = $filters;
        $this->currentPage = 1;
        $this->hasMorePages = true;
        $this->loadedItems = [];
        $this->loadItems();
    }

    public function loadMore(): void
    {
        $this->currentPage++;
        $this->loadItems();
    }

    public function mount(): void
    {
        $this->loadItems();
    }

    public function selectItem($itemId = null): void
    {
        if ($itemId) {
            $this->dispatch('item-selected', itemId: (int)$itemId);
            $this->selectedItemId = $itemId;
        }
    }

    public function sort(string $by): void
    {
        $allowed = [
            'item_code', 'item_name',
            'delivered_date', 'total_amount',
            'available_qty'
        ];

        if (!in_array($by, $allowed, true)) return;

        if ($this->sortBy === $by) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $by;
            $this->sortDir = 'asc';
        }

        $this->currentPage = 1;
        $this->hasMorePages = true;
        $this->loadedItems = [];
        $this->loadItems();
    }

    private function buildFilteredQuery(): \Illuminate\Database\Eloquent\Builder
    {
        // Use WmsStockAvailable view for available stock information
        $query = WmsStockAvailable::query()->with(['item', 'warehouse']);

        // Apply filters
        if (!empty($this->filters['item_name'])) {
            $query->whereHas('item', function ($q) {
                $q->where('name', 'like', '%' . $this->filters['item_name'] . '%');
            });
        }

        if (!empty($this->filters['item_code'])) {
            $query->whereHas('item', function ($q) {
                $q->where('code', 'like', '%' . $this->filters['item_code'] . '%');
            });
        }

        if (!empty($this->filters['jancode'])) {
            $query->whereHas('item', function ($q) {
                $q->where('jancode', 'like', '%' . $this->filters['jancode'] . '%');
            });
        }

        if (!empty($this->filters['warehouse_id'])) {
            $query->where('warehouse_id', $this->filters['warehouse_id']);
        }

        // Apply sorting
        if ($this->sortBy) {
            $query->orderBy($this->sortBy, $this->sortDir);
        } else {
            // Default sorting by FEFO/FIFO
            $query->fefoFifo();
        }

        return $query;
    }

    private function loadItems(): void
    {
        $query = $this->buildFilteredQuery();

        $offset = ($this->currentPage - 1) * $this->perPage;
        $items = $query->skip($offset)->take($this->perPage)->get();

        $this->loadedItems = $this->currentPage === 1
            ? $items->toArray()
            : array_merge($this->loadedItems, $items->toArray());

        $this->hasMorePages = $items->count() === $this->perPage;
    }

    public function getTotalCount(): int
    {
        return $this->buildFilteredQuery()->count();
    }

    public function render()
    {
        return view('livewire.wms.wms-search-results', [
            'results' => collect($this->loadedItems)->map(function ($item) {
                return (object)$item;
            }),
            'totalCount' => $this->getTotalCount(),
        ]);
    }
}
