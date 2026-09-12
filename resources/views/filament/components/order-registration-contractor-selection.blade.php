@php
    $lw = $lw ?? (isset($getLivewire) ? $getLivewire() : null);
    $dataProperty = $contractorsProperty ?? 'contractorsData';
    $selectedProperty = $selectedProperty ?? 'selectedContractorIds';
    $fallbackMethod = $fallbackMethod ?? 'getContractorsForWarehouse';
    $notice = $notice ?? null;
    $grouped = (bool) ($grouped ?? false);
    $compactListHeight = (bool) ($compactListHeight ?? false);
    $externalOrderLayout = ($layout ?? null) === 'externalOrderGeneration';
    $data = $lw->{$dataProperty} ?? [];

    // contractorsData が空の場合、直接取得を試みる
    if ($lw && empty($data) && method_exists($lw, $fallbackMethod)) {
        $data = $lw->{$fallbackMethod}();
        $lw->{$dataProperty} = $data;
        $lw->{$selectedProperty} = collect($data)->pluck('id')->values()->toArray();
    }

    $contractorIds = collect($data)->pluck('id')->values()->toArray();
    $selectedIds = $lw->{$selectedProperty} ?? $contractorIds;

    if ($grouped) {
        $primaryDataProperty = $primaryContractorsProperty ?? 'jxContractorsData';
        $secondaryDataProperty = $secondaryContractorsProperty ?? 'salesBasedOtherContractorsData';
        $primaryFallbackMethod = $primaryFallbackMethod ?? 'getJxContractorsForAutoOrderGeneration';
        $secondaryFallbackMethod = $secondaryFallbackMethod ?? 'getOtherContractorsForSalesBasedGeneration';
        $primaryLabel = $primaryLabel ?? '現在選択中の仕入先';
        $secondaryLabel = $secondaryLabel ?? 'その他の仕入先';

        $primaryData = $lw->{$primaryDataProperty} ?? [];
        if ($lw && empty($primaryData) && method_exists($lw, $primaryFallbackMethod)) {
            $primaryData = $lw->{$primaryFallbackMethod}();
            $lw->{$primaryDataProperty} = $primaryData;
        }

        $secondaryData = $lw->{$secondaryDataProperty} ?? [];
        if ($lw && empty($secondaryData) && method_exists($lw, $secondaryFallbackMethod)) {
            $secondaryData = $lw->{$secondaryFallbackMethod}();
            $lw->{$secondaryDataProperty} = $secondaryData;
        }

        $defaultIds = collect($primaryData)
            ->merge($secondaryData)
            ->pluck('id')
            ->values()
            ->toArray();
        $selectedIds = $lw->{$selectedProperty} ?? $defaultIds;
    }
@endphp

@if ($grouped && $externalOrderLayout)
<div x-data="{
    searchQuery: '',
    primaryContractors: @js($primaryData),
    secondaryContractors: @js($secondaryData),
    selectedIds: $wire.$entangle(@js($selectedProperty)).live,

    init() {
        if (!Array.isArray(this.selectedIds)) {
            this.selectedIds = [];
        }
    },

    get allContractors() {
        return [...this.primaryContractors, ...this.secondaryContractors];
    },

    get filteredPrimaryContractors() {
        return this.filterContractors(this.primaryContractors);
    },

    get filteredSecondaryContractors() {
        return this.filterContractors(this.secondaryContractors);
    },

    get selectedCount() {
        return this.selectedIds.length;
    },

    get totalCount() {
        return this.allContractors.length;
    },

    filterContractors(contractors) {
        if (!this.searchQuery) return contractors;
        const q = this.searchQuery.normalize('NFKC').toLowerCase();
        return contractors.filter(c =>
            String(c.code).normalize('NFKC').toLowerCase().includes(q) ||
            c.name.normalize('NFKC').toLowerCase().includes(q)
        );
    },

    isSelected(id) {
        return this.selectedIds.map(Number).includes(Number(id));
    },

    toggle(id) {
        const normalizedId = Number(id);
        if (this.isSelected(normalizedId)) {
            this.selectedIds = this.selectedIds.filter(selectedId => Number(selectedId) !== normalizedId);
        } else {
            this.selectedIds = [...this.selectedIds, normalizedId];
        }
    },

    isGroupAllSelected(contractors) {
        return contractors.length > 0 && contractors.every(c => this.isSelected(c.id));
    },

    selectGroup(contractors) {
        const ids = contractors.map(c => Number(c.id));
        this.selectedIds = [...new Set([...this.selectedIds, ...ids])];
    },

    deselectGroup(contractors) {
        const ids = contractors.map(c => Number(c.id));
        this.selectedIds = this.selectedIds.filter(id => !ids.includes(Number(id)));
    },
}" class="flex flex-col gap-3 overflow-hidden">
    <div class="flex items-center gap-4 rounded-md border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex shrink-0 items-center gap-2 rounded-full border border-amber-200 bg-amber-50 px-3 py-1.5 text-sm font-bold text-amber-600 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
            <x-heroicon-m-check-circle class="h-4 w-4" />
            <span x-text="`${selectedCount}/${totalCount}件 選択中`"></span>
        </div>
        <div class="relative flex-1">
            <input type="text"
                   x-model="searchQuery"
                   placeholder="仕入先CD・名前で検索..."
                   class="w-full rounded-md border border-gray-300 bg-gray-50 py-2 pl-9 pr-3 text-sm shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                <x-heroicon-m-magnifying-glass class="h-4 w-4 text-gray-400" />
            </div>
        </div>
    </div>

    <div class="grid h-[24rem] min-h-0 grid-cols-2 gap-3">
        <div class="flex min-w-0 flex-col overflow-hidden rounded-md border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="border-b border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="truncate text-sm font-bold text-gray-800 dark:text-gray-100">{{ $primaryLabel }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400" x-text="`${filteredPrimaryContractors.length}件`"></div>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <button type="button"
                                @click="selectGroup(filteredPrimaryContractors)"
                                class="rounded border border-gray-300 bg-gray-100 px-2 py-1 text-xs text-gray-700 transition hover:bg-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                            全選択
                        </button>
                        <button type="button"
                                @click="deselectGroup(filteredPrimaryContractors)"
                                class="rounded border border-gray-300 bg-gray-100 px-2 py-1 text-xs text-gray-700 transition hover:bg-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                            解除
                        </button>
                    </div>
                </div>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto bg-amber-50/30 dark:bg-slate-900">
                <template x-if="filteredPrimaryContractors.length === 0">
                    <div class="p-4 text-center text-sm text-gray-500 dark:text-gray-400">該当する仕入先がありません</div>
                </template>
                <div class="grid grid-cols-1">
                    <template x-for="(contractor, index) in filteredPrimaryContractors" :key="`primary-${contractor.id}`">
                        <label class="flex cursor-pointer items-center gap-2 border-b border-gray-100 px-3 py-2 text-sm transition hover:bg-amber-100/50 dark:border-gray-800 dark:hover:bg-slate-800"
                               :class="index % 2 === 0 ? 'bg-amber-50/50 dark:bg-slate-900' : 'bg-white dark:bg-slate-950'">
                            <input type="checkbox"
                                   :checked="isSelected(contractor.id)"
                                   @change="toggle(contractor.id)"
                                   class="h-4 w-4 shrink-0 rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500 dark:border-gray-500 dark:bg-gray-700">
                            <span class="shrink-0 font-mono text-gray-500 dark:text-gray-400" x-text="contractor.code"></span>
                            <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-gray-100" x-text="contractor.name"></span>
                            <span x-show="contractor.transmission_type === 'JX_FINET'"
                                  class="shrink-0 rounded bg-green-100 px-1.5 py-0.5 text-[10px] font-bold text-green-600 dark:bg-green-900/30 dark:text-green-300">
                                JX
                            </span>
                            <span x-show="contractor.transmission_parent_code"
                                  class="shrink-0 rounded bg-orange-100 px-1.5 py-0.5 text-[10px] font-bold text-orange-600 dark:bg-orange-900/30 dark:text-orange-300">
                                集約元
                            </span>
                        </label>
                    </template>
                </div>
            </div>
        </div>

        <div class="flex min-w-0 flex-col overflow-hidden rounded-md border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="border-b border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="truncate text-sm font-bold text-gray-800 dark:text-gray-100">{{ $secondaryLabel }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400" x-text="`${filteredSecondaryContractors.length}件`"></div>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <button type="button"
                                @click="selectGroup(filteredSecondaryContractors)"
                                class="rounded border border-gray-300 bg-gray-100 px-2 py-1 text-xs text-gray-700 transition hover:bg-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                            全選択
                        </button>
                        <button type="button"
                                @click="deselectGroup(filteredSecondaryContractors)"
                                class="rounded border border-gray-300 bg-gray-100 px-2 py-1 text-xs text-gray-700 transition hover:bg-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                            解除
                        </button>
                    </div>
                </div>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto bg-amber-50/30 dark:bg-slate-900">
                <template x-if="filteredSecondaryContractors.length === 0">
                    <div class="p-4 text-center text-sm text-gray-500 dark:text-gray-400">該当する仕入先がありません</div>
                </template>
                <div class="grid grid-cols-1">
                    <template x-for="(contractor, index) in filteredSecondaryContractors" :key="`secondary-${contractor.id}`">
                        <label class="flex cursor-pointer items-center gap-2 border-b border-gray-100 px-3 py-2 text-sm transition hover:bg-amber-100/50 dark:border-gray-800 dark:hover:bg-slate-800"
                               :class="index % 2 === 0 ? 'bg-amber-50/50 dark:bg-slate-900' : 'bg-white dark:bg-slate-950'">
                            <input type="checkbox"
                                   :checked="isSelected(contractor.id)"
                                   @change="toggle(contractor.id)"
                                   class="h-4 w-4 shrink-0 rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500 dark:border-gray-500 dark:bg-gray-700">
                            <span class="shrink-0 font-mono text-gray-500 dark:text-gray-400" x-text="contractor.code"></span>
                            <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-gray-100" x-text="contractor.name"></span>
                            <span x-show="contractor.generation_time"
                                  class="shrink-0 font-mono text-xs text-gray-400 dark:text-gray-500"
                                  x-text="contractor.generation_time"></span>
                        </label>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <div x-show="selectedCount === 0" class="text-sm text-danger-600 dark:text-danger-400">
        ※ 最低1つの仕入先を選択してください
    </div>
</div>
@elseif ($grouped)
<div x-data="{
    searchQuery: '',
    primaryContractors: @js($primaryData),
    secondaryContractors: @js($secondaryData),
    selectedIds: @js($selectedIds),

    get allContractors() {
        return [...this.primaryContractors, ...this.secondaryContractors];
    },

    get filteredPrimaryContractors() {
        return this.filterContractors(this.primaryContractors);
    },

    get filteredSecondaryContractors() {
        return this.filterContractors(this.secondaryContractors);
    },

    get selectedCount() {
        return this.selectedIds.length;
    },

    get totalCount() {
        return this.allContractors.length;
    },

    filterContractors(contractors) {
        if (!this.searchQuery) return contractors;
        const q = this.searchQuery.normalize('NFKC').toLowerCase();
        return contractors.filter(c =>
            String(c.code).normalize('NFKC').toLowerCase().includes(q) ||
            c.name.normalize('NFKC').toLowerCase().includes(q)
        );
    },

    isSelected(id) {
        return this.selectedIds.includes(id);
    },

    toggle(id) {
        const idx = this.selectedIds.indexOf(id);
        if (idx > -1) {
            this.selectedIds.splice(idx, 1);
        } else {
            this.selectedIds.push(id);
        }
    },

    isGroupAllSelected(contractors) {
        return contractors.length > 0 && contractors.every(c => this.selectedIds.includes(c.id));
    },

    selectGroup(contractors) {
        const ids = contractors.map(c => c.id);
        this.selectedIds = [...new Set([...this.selectedIds, ...ids])];
    },

    deselectGroup(contractors) {
        const ids = contractors.map(c => c.id);
        this.selectedIds = this.selectedIds.filter(id => !ids.includes(id));
    },
}" x-effect="$wire.set(@js($selectedProperty), selectedIds, false)" class="space-y-3 overflow-x-hidden">
    @if ($notice)
        <div class="flex items-center gap-2 rounded-lg border border-yellow-200 bg-yellow-50 px-3 py-2 text-xs text-yellow-800 dark:border-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-300">
            <x-heroicon-m-exclamation-triangle class="h-4 w-4 flex-shrink-0" />
            <span>{{ $notice }}</span>
        </div>
    @endif

    <div class="flex flex-col gap-3 rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 sm:flex-row sm:items-center sm:justify-between dark:border-gray-600 dark:bg-gray-800">
        <div class="flex items-center gap-2">
            <x-heroicon-m-check-circle class="h-5 w-5 text-primary-500" />
            <span class="text-xs text-gray-700 dark:text-gray-300" x-text="`${selectedCount}/${totalCount}件 選択中`"></span>
        </div>
        <div class="relative w-full sm:w-72">
            <input type="text"
                   x-model="searchQuery"
                   placeholder="仕入先CD・名前で検索..."
                   class="w-full rounded-lg border-gray-300 py-1.5 pl-8 text-xs shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2.5">
                <x-heroicon-m-magnifying-glass class="h-4 w-4 text-gray-400" />
            </div>
        </div>
    </div>

    <div class="grid min-w-0 gap-4 lg:grid-cols-2">
        <div class="min-w-0 space-y-2 lg:border-r lg:border-gray-200 lg:pr-4 dark:lg:border-gray-700">
            <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <div class="truncate text-xs font-semibold text-gray-800 dark:text-gray-100">{{ $primaryLabel }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400" x-text="`${filteredPrimaryContractors.length}件`"></div>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <button type="button"
                            @click="selectGroup(filteredPrimaryContractors)"
                            class="rounded-md bg-gray-100 px-2 py-1 text-[11px] text-gray-700 transition hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                        全選択
                    </button>
                    <button type="button"
                            @click="deselectGroup(filteredPrimaryContractors)"
                            class="rounded-md bg-gray-100 px-2 py-1 text-[11px] text-gray-700 transition hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                        解除
                    </button>
                </div>
            </div>

            <div @class([
                'overflow-x-auto overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-600',
                'h-[7.5rem]' => $compactListHeight,
                'h-[28rem]' => ! $compactListHeight,
            ])>
                <template x-if="filteredPrimaryContractors.length === 0">
                    <div class="p-4 text-center text-sm text-gray-500 dark:text-gray-400">該当する仕入先がありません</div>
                </template>
                <div class="grid grid-cols-1">
                    <template x-for="(contractor, index) in filteredPrimaryContractors" :key="`primary-${contractor.id}`">
                        <label @class([
                            'flex cursor-pointer items-center gap-2 border-b transition hover:bg-gray-100 dark:border-gray-600 dark:hover:bg-gray-700/50',
                            'px-2 py-1' => $compactListHeight,
                            'px-3 py-2' => ! $compactListHeight,
                        ])
                               :class="{
                                   'bg-primary-50 dark:bg-primary-900/20': isSelected(contractor.id),
                                   'bg-gray-50 dark:bg-gray-800/50': !isSelected(contractor.id) && index % 2 === 1,
                               }">
                            <input type="checkbox"
                                   :checked="isSelected(contractor.id)"
                                   @change="toggle(contractor.id)"
                                   class="shrink-0 rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-500 dark:bg-gray-700">
                            <div class="min-w-0 flex-1">
                                <div class="flex min-w-0 items-center gap-1.5">
                                    <span class="shrink-0 font-mono text-[11px] font-semibold text-gray-500 dark:text-gray-400" x-text="contractor.code"></span>
                                    <span class="truncate text-xs text-gray-900 dark:text-gray-100" x-text="contractor.name"></span>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                <span x-show="contractor.transmission_type === 'JX_FINET'"
                                      class="inline-flex items-center rounded bg-green-100 px-1 py-0.5 text-[10px] font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                    JX
                                </span>
                                <span x-show="contractor.transmission_parent_code"
                                      class="inline-flex items-center rounded bg-amber-100 px-1 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                    集約元
                                </span>
                            </div>
                        </label>
                    </template>
                </div>
            </div>
        </div>

        <div class="min-w-0 space-y-2 overflow-x-auto pb-1">
            <div class="flex min-w-[24rem] items-center justify-between gap-3">
                <div class="min-w-0">
                    <div class="truncate text-xs font-semibold text-gray-800 dark:text-gray-100">{{ $secondaryLabel }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400" x-text="`${filteredSecondaryContractors.length}件`"></div>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <button type="button"
                            @click="selectGroup(filteredSecondaryContractors)"
                            class="rounded-md bg-gray-100 px-2 py-1 text-[11px] text-gray-700 transition hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                        全選択
                    </button>
                    <button type="button"
                            @click="deselectGroup(filteredSecondaryContractors)"
                            class="rounded-md bg-gray-100 px-2 py-1 text-[11px] text-gray-700 transition hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                        解除
                    </button>
                </div>
            </div>

            <div @class([
                'min-w-[24rem] overflow-x-auto overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-600',
                'h-[7.5rem]' => $compactListHeight,
                'h-[28rem]' => ! $compactListHeight,
            ])>
                <template x-if="filteredSecondaryContractors.length === 0">
                    <div class="p-4 text-center text-sm text-gray-500 dark:text-gray-400">該当する仕入先がありません</div>
                </template>
                <div class="grid grid-cols-1">
                    <template x-for="(contractor, index) in filteredSecondaryContractors" :key="`secondary-${contractor.id}`">
                        <label @class([
                            'flex cursor-pointer items-center gap-2 border-b transition hover:bg-gray-100 dark:border-gray-600 dark:hover:bg-gray-700/50',
                            'px-2 py-1' => $compactListHeight,
                            'px-3 py-2' => ! $compactListHeight,
                        ])
                               :class="{
                                   'bg-primary-50 dark:bg-primary-900/20': isSelected(contractor.id),
                                   'bg-gray-50 dark:bg-gray-800/50': !isSelected(contractor.id) && index % 2 === 1,
                               }">
                            <input type="checkbox"
                                   :checked="isSelected(contractor.id)"
                                   @change="toggle(contractor.id)"
                                   class="shrink-0 rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-500 dark:bg-gray-700">
                            <div class="min-w-0 flex-1">
                                <div class="flex min-w-0 items-center gap-1.5">
                                    <span class="shrink-0 font-mono text-[11px] font-semibold text-gray-500 dark:text-gray-400" x-text="contractor.code"></span>
                                    <span class="truncate text-xs text-gray-900 dark:text-gray-100" x-text="contractor.name"></span>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                <span x-show="contractor.transmission_type === 'INTERNAL'"
                                      class="inline-flex items-center rounded bg-blue-100 px-1 py-0.5 text-[10px] font-medium text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">
                                    移動
                                </span>
                                <span x-show="contractor.generation_time"
                                      class="font-mono text-[10px] text-gray-400 dark:text-gray-500"
                                      x-text="contractor.generation_time"></span>
                            </div>
                        </label>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <div x-show="selectedCount === 0" class="text-sm text-danger-600 dark:text-danger-400">
        ※ 最低1つの仕入先を選択してください
    </div>
</div>
@else
<div x-data="{
    searchQuery: '',
    allContractors: @js($data),
    selectedIds: @js($selectedIds),
    expanded: false,

    get filteredContractors() {
        if (!this.searchQuery) return this.allContractors;
        const q = this.searchQuery.toLowerCase();
        return this.allContractors.filter(c =>
            String(c.code).toLowerCase().includes(q) || c.name.toLowerCase().includes(q)
        );
    },

    get selectedCount() {
        return this.selectedIds.length;
    },

    get isAllSelected() {
        return this.allContractors.length > 0 &&
            this.selectedIds.length === this.allContractors.length;
    },

    get allVisibleSelected() {
        return this.filteredContractors.length > 0 &&
            this.filteredContractors.every(c => this.selectedIds.includes(c.id));
    },

    isSelected(id) {
        return this.selectedIds.includes(id);
    },

    toggle(id) {
        const idx = this.selectedIds.indexOf(id);
        if (idx > -1) {
            this.selectedIds.splice(idx, 1);
        } else {
            this.selectedIds.push(id);
        }
    },

    toggleAllVisible() {
        if (this.allVisibleSelected) {
            const visibleIds = this.filteredContractors.map(c => c.id);
            this.selectedIds = this.selectedIds.filter(id => !visibleIds.includes(id));
        } else {
            const visibleIds = this.filteredContractors.map(c => c.id);
            this.selectedIds = [...new Set([...this.selectedIds, ...visibleIds])];
        }
    },

    selectAll() {
        this.selectedIds = this.allContractors.map(c => c.id);
    },

    deselectAll() {
        this.selectedIds = [];
    }
    }" x-effect="$wire.set(@js($selectedProperty), selectedIds, false)" class="space-y-3">
    @if ($notice)
        <div class="flex items-center gap-2 rounded-lg border border-yellow-200 bg-yellow-50 px-3 py-2 text-xs text-yellow-800 dark:border-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-300">
            <x-heroicon-m-exclamation-triangle class="h-4 w-4 flex-shrink-0" />
            <span>{{ $notice }}</span>
        </div>
    @endif

    {{-- ヘッダー: 選択状況 + 展開ボタン --}}
    <div class="flex items-center justify-between rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 px-4 py-2.5">
        <div class="flex items-center gap-2">
            <template x-if="isAllSelected">
                <x-heroicon-m-check-circle class="w-5 h-5 text-success-500" />
            </template>
            <template x-if="!isAllSelected && selectedCount > 0">
                <x-heroicon-m-minus-circle class="w-5 h-5 text-warning-500" />
            </template>
            <template x-if="selectedCount === 0">
                <x-heroicon-m-x-circle class="w-5 h-5 text-danger-500" />
            </template>
            <span class="text-sm text-gray-700 dark:text-gray-300">
                <span x-show="isAllSelected">すべての仕入先が選択されています</span>
                <span x-show="!isAllSelected" x-text="`${selectedCount}/${allContractors.length}件 選択中`"></span>
            </span>
        </div>
        <button type="button"
                @click="expanded = !expanded"
                class="text-xs px-3 py-1.5 rounded-md bg-white hover:bg-gray-100 text-gray-700 border border-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 dark:text-gray-300 dark:border-gray-500 transition flex items-center gap-1">
            <span x-text="expanded ? '閉じる' : '選択を変更'"></span>
            <svg class="w-3.5 h-3.5 transition-transform" :class="expanded && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>
    </div>

    {{-- 展開時: 検索 + チェックボックスリスト --}}
    <div x-show="expanded" x-collapse class="space-y-3">
        <div class="flex items-center gap-3">
            <div class="relative flex-1">
                <input type="text"
                       x-model="searchQuery"
                       placeholder="仕入先コード・名前で検索..."
                       class="w-full rounded-lg border-gray-300 text-sm pl-9 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                    <x-heroicon-m-magnifying-glass class="w-4 h-4 text-gray-400" />
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <button type="button"
                    @click="selectAll()"
                    class="text-xs px-3 py-1.5 rounded-md bg-gray-100 hover:bg-gray-200 text-gray-700 dark:bg-gray-700 dark:hover:bg-gray-600 dark:text-gray-300 transition">
                すべて選択
            </button>
            <button type="button"
                    @click="deselectAll()"
                    class="text-xs px-3 py-1.5 rounded-md bg-gray-100 hover:bg-gray-200 text-gray-700 dark:bg-gray-700 dark:hover:bg-gray-600 dark:text-gray-300 transition">
                すべて解除
            </button>
            <button type="button"
                    @click="toggleAllVisible()"
                    class="text-xs px-3 py-1.5 rounded-md bg-gray-100 hover:bg-gray-200 text-gray-700 dark:bg-gray-700 dark:hover:bg-gray-600 dark:text-gray-300 transition">
                <span x-text="allVisibleSelected ? '表示中を解除' : '表示中を選択'"></span>
            </button>
        </div>

        <div class="border rounded-lg dark:border-gray-600 max-h-96 overflow-y-auto">
            <template x-if="filteredContractors.length === 0">
                <div class="p-4 text-center text-sm text-gray-500 dark:text-gray-400">
                    <span x-show="searchQuery">該当する仕入先がありません</span>
                    <span x-show="!searchQuery">仕入先が登録されていません</span>
                </div>
            </template>

            <div class="grid grid-cols-2">
                <template x-for="(contractor, index) in filteredContractors" :key="contractor.id">
                    <label class="flex items-center gap-2 px-3 py-2 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700/50 border-b border-r dark:border-gray-600 transition"
                           :class="{
                               'bg-primary-50 dark:bg-primary-900/20': isSelected(contractor.id),
                               'bg-gray-50 dark:bg-gray-800/50': !isSelected(contractor.id) && index % 4 >= 2,
                           }">
                        <input type="checkbox"
                               :checked="isSelected(contractor.id)"
                               @change="toggle(contractor.id)"
                               class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-500 dark:bg-gray-700 flex-shrink-0">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-1.5">
                                <span class="text-xs font-mono font-semibold text-gray-500 dark:text-gray-400" x-text="contractor.code"></span>
                                <span class="text-sm text-gray-900 dark:text-gray-100 truncate" x-text="contractor.name"></span>
                            </div>
                        </div>
                        <div class="flex items-center gap-1 flex-shrink-0">
                            <span x-show="contractor.transmission_type === 'JX_FINET'"
                                  class="inline-flex items-center px-1 py-0.5 rounded text-[10px] font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                JX
                            </span>
                            <span x-show="contractor.transmission_parent_code"
                                  class="inline-flex items-center px-1 py-0.5 rounded text-[10px] font-medium bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                集約元
                            </span>
                            <span x-show="contractor.transmission_type === 'INTERNAL'"
                                  class="inline-flex items-center px-1 py-0.5 rounded text-[10px] font-medium bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">
                                移動
                            </span>
                            <span x-show="contractor.generation_time"
                                  class="text-[10px] text-gray-400 dark:text-gray-500 font-mono"
                                  x-text="contractor.generation_time"></span>
                        </div>
                    </label>
                </template>
            </div>
        </div>

        <div x-show="selectedCount === 0" class="text-sm text-danger-600 dark:text-danger-400">
            ※ 最低1つの仕入先を選択してください
        </div>
    </div>
</div>
@endif
