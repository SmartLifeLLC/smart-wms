<x-filament-panels::page>
    <div class="h-[calc(100vh-8rem)] flex flex-col gap-4">
        {{-- Filter Section --}}
        <div class="flex-shrink-0">
            @livewire('wms.outbound-search-filters')
        </div>

        {{-- Two-pane layout --}}
        <div class="flex-1 grid grid-cols-12 gap-4 min-h-0">
            {{-- Left: Search Results --}}
            <div class="col-span-7 overflow-hidden">
                @livewire('wms.wms-search-results')
            </div>

            {{-- Right: Item Detail --}}
            <div class="col-span-5 overflow-hidden">
                @livewire('wms.wms-item-detail')
            </div>
        </div>
    </div>
</x-filament-panels::page>
