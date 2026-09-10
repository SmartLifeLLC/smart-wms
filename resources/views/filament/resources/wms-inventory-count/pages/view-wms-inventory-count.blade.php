<x-filament-panels::page class="overflow-hidden">
    @php
        $record = $this->record;
        $rows = $this->rows();
        $totalCount = $this->totalCount();
        $allCount = $this->countForTab('all');
        $diffCount = $this->countForTab('diff');
        $pdfDiffCount = $this->countForTab('pdf_diff');
        $matchedCount = $this->countForTab('matched');
        $unmanagedCount = $this->countForTab('unmanaged');
        $pageFirst = $rows->firstItem() ?? 0;
        $pageLast = $rows->lastItem() ?? 0;
        $activeRound = $this->activeCountRound;
        $progressRound = min(max((int) ($record->current_count_round ?: 1), 1), 3);
        $floorOptions = $this->floorOptions();
        $locationOptions = $this->locationOptions();
        $isEditable = in_array($record->status, [
            \App\Models\WmsInventoryCount::STATUS_DRAFT,
            \App\Models\WmsInventoryCount::STATUS_COUNTING,
            \App\Models\WmsInventoryCount::STATUS_CHECKED,
        ]);
        $displayStatus = $record->display_status;
        $filterInputClass = 'h-8 w-full rounded-md border border-slate-300 bg-slate-50 px-2 text-xs text-slate-900 shadow-inner outline-none transition placeholder:text-slate-400 focus:border-sky-500 focus:bg-white focus:ring-1 focus:ring-sky-500';
        $filterSelectClass = 'h-8 w-full rounded-md border border-slate-300 bg-slate-50 px-2 text-xs text-slate-900 shadow-inner outline-none transition focus:border-sky-500 focus:bg-white focus:ring-1 focus:ring-sky-500';
        $countInputClass = 'w-20 h-7 rounded border border-slate-300 bg-white px-1 text-right text-xs tabular-nums font-bold outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500 disabled:bg-slate-100 disabled:text-slate-500 disabled:cursor-not-allowed';
        $statusColors = [
            'draft' => 'bg-slate-200 text-slate-700',
            'counting' => 'bg-sky-100 text-sky-700',
            'current_stock_saved' => 'bg-emerald-100 text-emerald-700',
            'checked' => 'bg-amber-100 text-amber-700',
            'confirmed' => 'bg-green-100 text-green-700',
            'cancelled' => 'bg-red-100 text-red-700',
        ];
    @endphp

    <div x-data="{
        filtersOpen: true,
        detailsOpen: false,
        locationPickerOpen: false,
        activeTab: @entangle('listTab'),
        activeRound: @entangle('activeCountRound'),
        filters: { locationText: '' },
        selectedLocations: @entangle('selectedLocationFilters'),
        changes: {},
        normalize(value) {
            return String(value ?? '').replace(/[Ａ-Ｚａ-ｚ０-９]/g, c => String.fromCharCode(c.charCodeAt(0) - 0xFEE0)).toLowerCase();
        },
        includes(value, keyword) {
            keyword = this.normalize(keyword).trim();
            return keyword === '' || this.normalize(value).includes(keyword);
        },
        rowVisible(row) {
            if (this.activeTab === 'diff' && !((row.originalEndDiff !== null && row.originalEndDiff !== 0) || row.originalUncounted)) return false;
            if (this.activeTab === 'matched' && !(row.originalEndDiff !== null && row.originalEndDiff === 0 && !row.originalUncounted)) return false;
            if (this.activeTab === 'unmanaged' && !row.unmanagedStock) return false;
            if (!this.includes(row.location, this.filters.locationText)) return false;
            if (this.selectedLocations.length && !this.selectedLocations.includes(row.location)) return false;
            return true;
        },
        toggleLocation(location) {
            if (this.selectedLocations.includes(location)) {
                this.selectedLocations = this.selectedLocations.filter(v => v !== location);
            } else {
                this.selectedLocations = [...this.selectedLocations, location];
            }
        },
        clearFilters() {
            this.filters = { locationText: '' };
            this.selectedLocations = [];
            this.$wire.clearFilters();
        },
        setChange(id, field, value, origFirst, origSecond, origFinal, first, second, final_) {
            let changed = (first !== origFirst || second !== origSecond || final_ !== origFinal);
            if (changed) {
                this.changes[id] = { first: this.parseQuantity(first), second: this.parseQuantity(second), final: this.parseQuantity(final_) };
            } else {
                delete this.changes[id];
            }
        },
        parseQuantity(value) {
            value = String(value ?? '');
            if (value === '' || value === '-') return null;
            let number = parseInt(value, 10);
            return Number.isNaN(number) ? null : number;
        },
        get changeCount() { return Object.keys(this.changes).length; },
        focusItemCodeFilter() {
            this.filtersOpen = true;
            this.$nextTick(() => {
                setTimeout(() => {
                    const input = this.$refs.itemCodeFilter;
                    if (!input) return;

                    input.focus({ preventScroll: true });
                    input.select();
                }, 0);
            });
        },
        save() {
            if (!this.changeCount) return;
            this.$wire.saveInlineChanges(this.changes).then(() => {
                this.changes = {};
                this.focusItemCodeFilter();
            });
        },
        guardPagination(direction) {
            if (this.changeCount > 0) {
                alert('先に反映を実施してください');
                return;
            }

            if (direction === 'previous') {
                this.$wire.previousItemPage();
                return;
            }

            this.$wire.nextItemPage();
        }
    }" @count-update="setChange($event.detail.id, $event.detail.field, $event.detail.value, $event.detail.origFirst, $event.detail.origSecond, $event.detail.origFinal, $event.detail.first, $event.detail.second, $event.detail.final)"
    class="flex h-[calc(100vh-72px)] min-h-0 flex-col gap-2">
        {{-- Header bar --}}
        <div class="relative z-20 shrink-0 overflow-visible rounded-lg border border-slate-300 bg-slate-100 shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-200 bg-slate-800 px-3 py-2 text-white">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="truncate text-xs text-slate-300">
                        {{ $record->count_no }}
                        / {{ $record->warehouse_name }}
                        / {{ $record->count_date?->format('Y/m/d') }}
                    </span>
                    <span class="rounded-full px-2 py-0.5 text-[11px] font-bold {{ $statusColors[$displayStatus] ?? 'bg-slate-200 text-slate-700' }}">
                        {{ $record->status_label }}
                    </span>
                    @if ($record->handy_reception)
                        <span class="rounded-full bg-green-100 px-2 py-0.5 text-[11px] font-bold text-green-700">
                            HANDY受付中
                        </span>
                    @endif
                    @if ($record->snapshot_taken_at)
                        <span class="rounded-full bg-slate-200 px-2 py-0.5 text-[11px] font-bold text-slate-700">
                            在庫取得(開始) {{ $record->snapshot_taken_at->format('m/d H:i') }}
                        </span>
                    @endif
                    @if ($record->ending_stock_taken_at)
                        <span class="rounded-full bg-purple-100 px-2 py-0.5 text-[11px] font-bold text-purple-700">
                            在庫取得(終了) {{ $record->ending_stock_taken_at->format('m/d H:i') }}
                        </span>
                    @endif
                    @if ($record->stock_movement_from_at)
                        <span class="rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-bold text-blue-700">
                            実施 {{ $record->stock_movement_from_at->format('m/d H:i') }}
                        </span>
                    @endif
                    <span class="text-xs text-slate-400">
                        全{{ number_format($allCount) }}件
                        / 差異{{ number_format($diffCount) }}件
                        / 差分PDF順{{ number_format($pdfDiffCount) }}件
                        / 差異なし{{ number_format($matchedCount) }}件
                        / 在庫管理対象外{{ number_format($unmanagedCount) }}件
                        / 表示{{ number_format($pageFirst) }}-{{ number_format($pageLast) }}件
                    </span>
                </div>
                <button type="button"
                    class="inline-flex items-center gap-1 rounded-md border border-slate-500 px-2 py-1 text-xs font-semibold text-slate-100 hover:bg-slate-700"
                    @click="filtersOpen = ! filtersOpen">
                    <x-filament::icon icon="heroicon-m-magnifying-glass" class="h-4 w-4" />
                    <span>検索条件</span>
                    <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4 transition" x-bind:class="{ 'rotate-180': filtersOpen }" />
                </button>
            </div>

            {{-- Filter form --}}
            <div x-show="filtersOpen" x-collapse x-cloak class="bg-slate-100 p-2">
                <div class="grid grid-cols-2 items-end gap-2 md:grid-cols-6 xl:grid-cols-12">
                    <label class="space-y-1 md:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">フロア</span>
                        <select wire:model.live="floorFilter" class="{{ $filterSelectClass }}">
                            <option value="">すべて</option>
                            @foreach ($floorOptions as $floor)
                                <option value="{{ $floor }}">{{ $floor }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="space-y-1 md:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">エリア</span>
                        <input type="text" wire:model.live.debounce.300ms="areaFilter" placeholder="エリア検索" class="{{ $filterInputClass }}">
                    </label>
                    <label class="space-y-1 md:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">商品CD</span>
                        <input type="text" x-ref="itemCodeFilter" wire:model.live.debounce.300ms="itemCodeFilter" placeholder="商品CD検索" class="{{ $filterInputClass }}">
                    </label>
                    <div class="relative space-y-1 md:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">ロケーション</span>
                        <button type="button" @click="locationPickerOpen = ! locationPickerOpen" class="{{ $filterInputClass }} flex items-center justify-between text-left">
                            <span x-text="selectedLocations.length ? selectedLocations.length + '件選択' : 'ロケーション選択'"></span>
                            <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4" />
                        </button>
                        <div x-show="locationPickerOpen" x-cloak @click.outside="locationPickerOpen = false" class="absolute z-50 mt-1 w-[32rem] max-w-[calc(100vw-2rem)] rounded-lg border border-slate-200 bg-white p-2 shadow-xl">
                            <input type="text" x-model="filters.locationText" placeholder="ロケーション検索..." class="{{ $filterInputClass }} mb-2">
                            <div class="grid max-h-64 grid-cols-2 gap-1 overflow-auto rounded-md border border-slate-200 p-1">
                                @foreach ($locationOptions as $location)
                                    <label x-show="includes(@js($location), filters.locationText)" class="flex min-w-0 items-center gap-2 rounded-md border border-slate-200 px-2 py-1 text-xs hover:bg-slate-50" :class="selectedLocations.includes(@js($location)) ? 'border-sky-500 bg-sky-50 text-sky-800' : ''">
                                        <input type="checkbox" class="rounded border-slate-300" value="{{ $location }}" wire:model.live="selectedLocationFilters">
                                        <span class="truncate font-mono">{{ $location }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <div class="mt-2 flex items-center justify-between text-xs">
                                <button type="button" class="text-slate-600 hover:text-slate-900" @click="$wire.set('selectedLocationFilters', [])">選択解除</button>
                                <button type="button" class="rounded bg-slate-800 px-3 py-1 font-bold text-white" @click="locationPickerOpen = false">閉じる</button>
                            </div>
                        </div>
                    </div>
                    <label class="space-y-1 md:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">商品名</span>
                        <input type="text" wire:model.live.debounce.300ms="itemNameFilter" placeholder="商品名検索" class="{{ $filterInputClass }}">
                    </label>
                    <div class="flex items-end justify-end gap-2 md:col-span-2">
                        <button type="button" @click="clearFilters()" class="h-8 rounded-md border border-slate-300 px-3 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                            クリア
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Tab bar + table --}}
        <div class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 bg-green-700 px-3 pt-2 text-white">
                <div class="flex flex-wrap items-end justify-between gap-2">
                    <div class="flex items-end gap-1">
                    <button type="button"
                        wire:click="setListTab('all')"
                        @click="activeTab = 'all'"
                        class="relative inline-flex h-10 items-center gap-2 rounded-t-md border px-3 text-xs font-bold transition"
                        :class="activeTab === 'all' ? 'border-slate-200 border-b-white bg-white text-green-800 shadow-sm' : 'border-green-700 bg-green-800 text-white/85 hover:bg-green-900 hover:text-white'">
                        <span x-show="activeTab === 'all'" class="absolute inset-x-2 top-0 h-0.5 rounded-full bg-green-600"></span>
                        <span>全件</span>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-black tabular-nums" :class="activeTab === 'all' ? 'bg-green-100 text-green-800' : 'bg-white/15 text-white ring-1 ring-white/25'">
                            {{ number_format($allCount) }}
                        </span>
                    </button>
                    <button type="button"
                        wire:click="setListTab('diff')"
                        @click="activeTab = 'diff'"
                        class="relative inline-flex h-10 items-center gap-2 rounded-t-md border px-3 text-xs font-bold transition"
                        :class="activeTab === 'diff' ? 'border-slate-200 border-b-white bg-white text-red-700 shadow-sm' : 'border-green-700 bg-green-800 text-white/85 hover:bg-green-900 hover:text-white'">
                        <span x-show="activeTab === 'diff'" class="absolute inset-x-2 top-0 h-0.5 rounded-full bg-red-600"></span>
                        <span>差異あり</span>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-black tabular-nums" :class="activeTab === 'diff' ? 'bg-red-100 text-red-700' : 'bg-white/15 text-white ring-1 ring-white/25'">
                            {{ number_format($diffCount) }}
                        </span>
                    </button>
                    <button type="button"
                        wire:click="setListTab('pdf_diff')"
                        @click="activeTab = 'pdf_diff'"
                        class="relative inline-flex h-10 items-center gap-2 rounded-t-md border px-3 text-xs font-bold transition"
                        :class="activeTab === 'pdf_diff' ? 'border-slate-200 border-b-white bg-white text-purple-700 shadow-sm' : 'border-green-700 bg-green-800 text-white/85 hover:bg-green-900 hover:text-white'">
                        <span x-show="activeTab === 'pdf_diff'" class="absolute inset-x-2 top-0 h-0.5 rounded-full bg-purple-600"></span>
                        <span>差分PDF順</span>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-black tabular-nums" :class="activeTab === 'pdf_diff' ? 'bg-purple-100 text-purple-700' : 'bg-white/15 text-white ring-1 ring-white/25'">
                            {{ number_format($pdfDiffCount) }}
                        </span>
                    </button>
                    <button type="button"
                        wire:click="setListTab('matched')"
                        @click="activeTab = 'matched'"
                        class="relative inline-flex h-10 items-center gap-2 rounded-t-md border px-3 text-xs font-bold transition"
                        :class="activeTab === 'matched' ? 'border-slate-200 border-b-white bg-white text-sky-700 shadow-sm' : 'border-green-700 bg-green-800 text-white/85 hover:bg-green-900 hover:text-white'">
                        <span x-show="activeTab === 'matched'" class="absolute inset-x-2 top-0 h-0.5 rounded-full bg-sky-500"></span>
                        <span>差異なし</span>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-black tabular-nums" :class="activeTab === 'matched' ? 'bg-sky-100 text-sky-700' : 'bg-white/15 text-white ring-1 ring-white/25'">
                            {{ number_format($matchedCount) }}
                        </span>
                    </button>
                    <button type="button"
                        wire:click="setListTab('unmanaged')"
                        @click="activeTab = 'unmanaged'"
                        class="relative inline-flex h-10 items-center gap-2 rounded-t-md border px-3 text-xs font-bold transition"
                        :class="activeTab === 'unmanaged' ? 'border-slate-200 border-b-white bg-white text-amber-700 shadow-sm' : 'border-green-700 bg-green-800 text-white/85 hover:bg-green-900 hover:text-white'">
                        <span x-show="activeTab === 'unmanaged'" class="absolute inset-x-2 top-0 h-0.5 rounded-full bg-amber-500"></span>
                        <span>在庫管理対象外</span>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-black tabular-nums" :class="activeTab === 'unmanaged' ? 'bg-amber-100 text-amber-700' : 'bg-white/15 text-white ring-1 ring-white/25'">
                            {{ number_format($unmanagedCount) }}
                        </span>
                    </button>
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-2 pb-2">
                    <div class="flex items-center gap-1 rounded-md bg-green-900/30 p-1 text-xs font-bold">
                        <span class="px-2 text-white/80">入力中</span>
                        @foreach ([1 => '1回目', 2 => '2回目', 3 => '3回目'] as $round => $label)
                            <button type="button"
                                wire:click="setActiveCountRound({{ $round }})"
                                x-bind:disabled="changeCount > 0"
                                class="h-7 rounded px-2 disabled:cursor-not-allowed disabled:opacity-50 {{ $activeRound === $round ? 'bg-white text-green-800' : 'text-white hover:bg-green-800' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                    <div class="rounded-full bg-green-900/40 px-3 py-1 text-sm font-black text-white tabular-nums">
                        {{ number_format($pageFirst) }}-{{ number_format($pageLast) }} / {{ number_format($rows->total()) }}件
                    </div>
                    <div class="flex items-center gap-1 text-xs font-bold">
                        <button type="button"
                            @click="guardPagination('previous')"
                            x-bind:class="{ 'cursor-not-allowed opacity-40': changeCount > 0 }"
                            @disabled($rows->onFirstPage())
                            class="h-8 rounded-md border border-green-300 px-2 text-white disabled:cursor-not-allowed disabled:opacity-40 hover:bg-green-800">
                            前へ
                        </button>
                        <span class="px-2 tabular-nums">{{ $rows->currentPage() }} / {{ $rows->lastPage() }}</span>
                        <button type="button"
                            @click="guardPagination('next')"
                            x-bind:class="{ 'cursor-not-allowed opacity-40': changeCount > 0 }"
                            @disabled(! $rows->hasMorePages())
                            class="h-8 rounded-md border border-green-300 px-2 text-white disabled:cursor-not-allowed disabled:opacity-40 hover:bg-green-800">
                            次へ
                        </button>
                    </div>
                    @if ($isEditable)
                        <button type="button" @click="save()" x-show="changeCount > 0" x-cloak
                            class="inline-flex items-center gap-2 rounded-md bg-red-600 px-4 py-1.5 text-sm font-bold text-white shadow-sm hover:bg-red-700">
                            <x-filament::icon icon="heroicon-m-arrow-up-tray" class="h-4 w-4" />
                            <span>反映</span>
                            <span class="rounded-full bg-white/20 px-2 py-0.5 text-xs font-black" x-text="changeCount + '件'"></span>
                        </button>
                    @endif
                    </div>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-2 pb-2">
                    {{ $this->getAction('addSingleItem') }}
                    {{ $this->getAction('toggleHandyReception') }}
                    @if ($record->status === \App\Models\WmsInventoryCount::STATUS_DRAFT)
                        {{ $this->getAction('startCounting') }}
                    @endif
                    @if (in_array($record->status, [
                        \App\Models\WmsInventoryCount::STATUS_DRAFT,
                        \App\Models\WmsInventoryCount::STATUS_COUNTING,
                        \App\Models\WmsInventoryCount::STATUS_CHECKED,
                    ], true))
                        @php
                            $isCountingStarted = $record->status !== \App\Models\WmsInventoryCount::STATUS_DRAFT;
                        @endphp
                        <button type="button"
                            wire:click="calculateActiveRoundDifferences"
                            @click="activeTab = 'diff'"
                            x-bind:disabled="changeCount > 0 || {{ $isCountingStarted ? 'false' : 'true' }}"
                            class="inline-flex items-center gap-2 rounded-md bg-amber-500 px-3 py-1.5 text-sm font-bold text-white shadow-sm hover:bg-amber-600 disabled:cursor-not-allowed disabled:opacity-50">
                            <x-filament::icon icon="heroicon-m-calculator" class="h-4 w-4" />
                            <span>差異再計算</span>
                        </button>
                        <div class="flex items-center gap-1 rounded-md bg-green-900/30 p-1">
                            @foreach ([1 => '1回目', 2 => '2回目', 3 => '3回目'] as $round => $label)
                                @php
                                    $roundConfirmed = $this->isRoundConfirmed($round);
                                    $roundAvailable = $round <= $progressRound;
                                @endphp
                                <button type="button"
                                    wire:click="confirmRound({{ $round }})"
                                    x-bind:disabled="changeCount > 0 || {{ (! $isCountingStarted || $roundConfirmed || ! $roundAvailable) ? 'true' : 'false' }}"
                                    class="h-8 rounded-md px-3 text-xs font-bold shadow-sm disabled:cursor-not-allowed disabled:opacity-50 {{ $roundConfirmed ? 'bg-slate-300 text-slate-600' : ($roundAvailable ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-white/20 text-white') }}">
                                    {{ $roundConfirmed ? "{$label}確定済" : "{$label}確定" }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                    {{ $this->getAction('downloadInstructionSheet') }}
                    {{ $this->getAction('downloadDiffListPdf') }}
                    @if ($record->status !== \App\Models\WmsInventoryCount::STATUS_DRAFT)
                        {{ $this->getAction('downloadUncountedListPdf') }}
                        {{ $this->getAction('downloadDifferenceWorkbook') }}
                    @endif
                    @if ($record->status === \App\Models\WmsInventoryCount::STATUS_CHECKED)
                        {{ $this->getAction('reopenFinalRound') }}
                        {{ $this->getAction('confirm') }}
                    @endif
                    <button type="button"
                        @click="detailsOpen = ! detailsOpen"
                        class="inline-flex items-center gap-2 rounded-md border border-green-300 px-3 py-1.5 text-sm font-bold text-white shadow-sm hover:bg-green-800"
                        :class="detailsOpen ? 'bg-green-900' : 'bg-green-800/60'">
                        <x-filament::icon icon="heroicon-m-ellipsis-horizontal-circle" class="h-4 w-4" />
                        <span>詳細</span>
                        <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4 transition" x-bind:class="{ 'rotate-180': detailsOpen }" />
                    </button>
                    <div wire:loading class="text-xs">読込中...</div>
                </div>
                <div x-show="detailsOpen" x-collapse x-cloak class="border-t border-green-600/60 bg-green-800/50 px-3 py-2">
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        {{ $this->getAction('viewLogs') }}
                        {{ $this->getAction('downloadInstructionPdf') }}
                        {{ $this->getAction('saveCurrentStock') }}
                        {{ $this->getAction('resumeCurrentStockSavedForCounting') }}
                        {{ $this->getAction('refreshCurrentStock') }}
                        {{ $this->getAction('refreshDailySnapshotStock') }}
                        {{ $this->getAction('refreshSecondRoundConfirmedDifferences') }}
                        {{ $this->getAction('calculatePostCountMovements') }}
                        {{ $this->getAction('restoreCancelledForCounting') }}
                        {{ $this->getAction('downloadEnteredListWorkbook') }}
                        @if (! in_array($record->status, [
                            \App\Models\WmsInventoryCount::STATUS_CONFIRMED,
                            \App\Models\WmsInventoryCount::STATUS_CANCELLED,
                        ], true))
                            {{ $this->getAction('cancel') }}
                        @endif
                    </div>
                </div>
            </div>

            {{-- Table --}}
            <div class="min-h-0 flex-1 overflow-auto">
                @if ($rows->count() === 0)
                    <div class="p-8 text-center text-sm text-slate-500">条件に一致する明細はありません。</div>
                @else
                    <table class="w-max min-w-full border-collapse text-xs">
                        <thead class="sticky top-0 z-10 bg-slate-100 text-slate-700">
                            <tr>
                                <th class="border border-slate-300 px-2 py-2 text-left">フロア</th>
                                <th class="border border-slate-300 px-2 py-2 text-left">エリア</th>
                                <th class="border border-slate-300 px-2 py-2 text-left">ロケーション</th>
                                <th class="border border-slate-300 px-2 py-2 text-left">
                                    <button type="button" wire:click="sortBy('item_code')" class="inline-flex items-center gap-1 font-bold hover:text-sky-700">
                                        <span>商品CD</span>
                                        <span class="text-[10px]">{{ $this->sortIndicator('item_code') }}</span>
                                    </button>
                                </th>
                                <th class="border border-slate-300 px-2 py-2 text-left">
                                    <button type="button" wire:click="sortBy('item_name')" class="inline-flex items-center gap-1 font-bold hover:text-sky-700">
                                        <span>商品名</span>
                                        <span class="text-[10px]">{{ $this->sortIndicator('item_name') }}</span>
                                    </button>
                                </th>
                                <th class="border border-slate-300 px-2 py-2 text-right">
                                    <button type="button" wire:click="sortBy('ending_system_quantity')" class="inline-flex items-center gap-1 font-bold hover:text-sky-700">
                                        <span>理論在庫</span>
                                        <span class="text-[10px]">{{ $this->sortIndicator('ending_system_quantity') }}</span>
                                    </button>
                                </th>
                                <th class="border border-slate-300 px-2 py-2 text-right">受払合計</th>
                                <th class="border border-slate-300 px-2 py-2 text-right">1回目</th>
                                <th class="border border-slate-300 px-2 py-2 text-right">1回目終了差分</th>
                                <th class="border border-slate-300 px-2 py-2 text-left">1回目入力者</th>
                                <th class="border border-slate-300 px-2 py-2 text-right">2回目</th>
                                <th class="border border-slate-300 px-2 py-2 text-right">2回目終了差分</th>
                                <th class="border border-slate-300 px-2 py-2 text-left">2回目入力者</th>
                                <th class="border border-slate-300 px-2 py-2 text-right">3回目</th>
                                <th class="border border-slate-300 px-2 py-2 text-right">3回目終了差分</th>
                                <th class="border border-slate-300 px-2 py-2 text-left">3回目入力者</th>
                                <th class="border border-slate-300 px-2 py-2 text-right">
                                    <button type="button" wire:click="sortBy('ending_difference_quantity')" class="inline-flex items-center gap-1 font-bold hover:text-sky-700">
                                        <span>終了差異数量</span>
                                        <span class="text-[10px]">{{ $this->sortIndicator('ending_difference_quantity') }}</span>
                                    </button>
                                </th>
                                <th class="border border-slate-300 px-2 py-2 text-right">終了差異金額</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows->items() as $row)
                                @php
                                    $initFirst = $row->first_count_quantity !== null ? (string) (int) $row->first_count_quantity : '';
                                    $initSecond = $row->second_count_quantity !== null ? (string) (int) $row->second_count_quantity : '';
                                    $initFinal = $row->final_count_quantity !== null ? (string) (int) $row->final_count_quantity : '';
                                    $movementQty = $row->post_count_movement_quantity;
                                    $pdfSystemQty = $this->listTab === 'pdf_diff' ? $row->getAttribute('pdf_system_quantity') : null;
                                    $endingSystemQty = $pdfSystemQty ?? $row->ending_system_quantity ?? $row->system_quantity;
                                    $firstConfirmedDiff = $row->confirmedRoundDifference(1);
                                    $secondConfirmedDiff = $row->confirmedRoundDifference(2);
                                    $finalConfirmedDiff = $row->confirmedRoundDifference(3);
                                    $firstConfirmedAmount = $row->getAttribute('first_count_confirmed_difference_amount');
                                    $secondConfirmedAmount = $row->getAttribute('second_count_confirmed_difference_amount');
                                    $finalConfirmedAmount = $row->getAttribute('final_count_confirmed_difference_amount');
                                @endphp
                                <tr wire:key="ic-row-{{ $row->id }}-r{{ $activeRound }}-u{{ $row->updated_at?->timestamp ?? 0 }}"
                                    x-data="{
                                        floor: @js($row->floor_name ?: ''),
                                        area: @js($row->location_code1 ?: ''),
                                        location: @js($row->location_no ?: ''),
                                        itemCode: @js($row->item_code ?: ''),
                                        itemName: @js($row->item_name ?: ''),
                                        unmanagedStock: @js($this->isUnmanagedStockItemForDisplay($row)),
                                        uncountedTarget: @js($this->isUncountedTargetItemForDisplay($row)),
                                        first: @js($initFirst), second: @js($initSecond), final_: @js($initFinal),
                                        origFirst: @js($initFirst), origSecond: @js($initSecond), origFinal: @js($initFinal),
                                        firstConfirmed: @js($this->isRoundConfirmed(1)), secondConfirmed: @js($this->isRoundConfirmed(2)), finalConfirmed: @js($this->isRoundConfirmed(3)),
                                        firstConfirmedDiff: @js($firstConfirmedDiff !== null ? (int) $firstConfirmedDiff : null),
                                        secondConfirmedDiff: @js($secondConfirmedDiff !== null ? (int) $secondConfirmedDiff : null),
                                        finalConfirmedDiff: @js($finalConfirmedDiff !== null ? (int) $finalConfirmedDiff : null),
                                        firstConfirmedAmount: @js($firstConfirmedAmount !== null ? (int) $firstConfirmedAmount : null),
                                        secondConfirmedAmount: @js($secondConfirmedAmount !== null ? (int) $secondConfirmedAmount : null),
                                        finalConfirmedAmount: @js($finalConfirmedAmount !== null ? (int) $finalConfirmedAmount : null),
                                        endingSystem: @js($endingSystemQty !== null ? (int) $endingSystemQty : null), cost: {{ (float) $row->cost_price }},
                                        toInt(v) {
                                            v = String(v ?? '');
                                            if (v === '' || v === '-') return null;
                                            let number = parseInt(v, 10);
                                            return Number.isNaN(number) ? null : number;
                                        },
                                        get counted() {
                                            if (this.activeRound == 3) return this.toInt(this.final_);
                                            if (this.activeRound == 2) return this.toInt(this.second) ?? this.toInt(this.first);
                                            return this.toInt(this.first);
                                        },
                                        get originalCounted() {
                                            return this.quantityForRound(this.activeRound, false);
                                        },
                                        physicalQuantityForRound(round, current) {
                                            if (round == 3) return this.toInt(current ? this.final_ : this.origFinal);
                                            if (round == 2) return this.toInt(current ? this.second : this.origSecond);
                                            return this.toInt(current ? this.first : this.origFirst);
                                        },
                                        quantityForRound(round, current) {
                                            let physical = this.physicalQuantityForRound(round, current);
                                            if (physical !== null) return physical;
                                            if (round == 2 && !this.uncountedTarget) return this.toInt(current ? this.first : this.origFirst);
                                            return null;
                                        },
                                        isUncountedForRound(round, current) {
                                            return this.uncountedTarget && this.physicalQuantityForRound(round, current) === null;
                                        },
                                        get originalUncounted() {
                                            return this.isUncountedForRound(this.activeRound, false);
                                        },
                                        diffFor(quantity, confirmed, confirmedDiff, uncounted) {
                                            if (confirmed && confirmedDiff !== null) return confirmedDiff;
                                            if (quantity === null && uncounted) quantity = 0;
                                            return quantity !== null && this.endingSystem !== null ? quantity-this.endingSystem : null;
                                        },
                                        get firstDiff() {
                                            return this.diffFor(this.quantityForRound(1, true), this.firstConfirmed, this.firstConfirmedDiff, this.isUncountedForRound(1, true));
                                        },
                                        get secondDiff() {
                                            return this.diffFor(this.quantityForRound(2, true), this.secondConfirmed, this.secondConfirmedDiff, this.isUncountedForRound(2, true));
                                        },
                                        get finalDiff() {
                                            return this.diffFor(this.quantityForRound(3, true), this.finalConfirmed, this.finalConfirmedDiff, this.isUncountedForRound(3, true));
                                        },
                                        get endDiff() {
                                            if (this.activeRound == 3) return this.finalDiff;
                                            if (this.activeRound == 2) return this.secondDiff;
                                            return this.firstDiff;
                                        },
                                        get originalEndDiff() {
                                            if (this.activeRound == 3) return this.diffFor(this.quantityForRound(3, false), this.finalConfirmed, this.finalConfirmedDiff, this.isUncountedForRound(3, false));
                                            if (this.activeRound == 2) return this.diffFor(this.quantityForRound(2, false), this.secondConfirmed, this.secondConfirmedDiff, this.isUncountedForRound(2, false));
                                            return this.diffFor(this.quantityForRound(1, false), this.firstConfirmed, this.firstConfirmedDiff, this.isUncountedForRound(1, false));
                                        },
                                        get endDiffAmt() {
                                            if (this.activeRound == 3 && this.finalConfirmed && this.finalConfirmedAmount !== null) return this.finalConfirmedAmount;
                                            if (this.activeRound == 2 && this.secondConfirmed && this.secondConfirmedAmount !== null) return this.secondConfirmedAmount;
                                            if (this.activeRound == 1 && this.firstConfirmed && this.firstConfirmedAmount !== null) return this.firstConfirmedAmount;
                                            return this.endDiff!==null ? Math.round(this.endDiff*this.cost) : null;
                                        },
                                        get changed() { return this.first!==this.origFirst||this.second!==this.origSecond||this.final_!==this.origFinal; },
                                        clean(v) {
                                            v = String(v ?? '')
                                                .replace(/[０-９]/g,c=>String.fromCharCode(c.charCodeAt(0)-0xFEE0))
                                                .replace(/[−－ー―]/g,'-')
                                                .replace(/[^0-9-]/g,'');
                                            let negative = v.includes('-');
                                            v = v.replace(/-/g,'');
                                            return (negative ? '-' : '') + v;
                                        },
                                        notify() { $dispatch('count-update',{id:{{ $row->id }},origFirst:this.origFirst,origSecond:this.origSecond,origFinal:this.origFinal,first:this.first,second:this.second,final:this.final_}); }
                                    }"
                                    x-show="rowVisible($data)"
                                    :class="changed ? 'bg-amber-50' : ($el.rowIndex % 2 === 0 ? 'bg-white' : 'bg-slate-50')"
                                    class="hover:bg-sky-50">
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1">{{ $row->floor_name ?: '-' }}</td>
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1">{{ $row->location_code1 ?: '-' }}</td>
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1 font-mono">{{ $row->location_no ?: '-' }}</td>
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1 font-mono">{{ $row->item_code ?: '-' }}</td>
                                    <td class="min-w-[240px] border border-slate-300 px-2 py-1">{{ $row->item_name ?: '-' }}</td>
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right font-bold tabular-nums {{ $endingSystemQty !== null && (int) $endingSystemQty !== (int) $row->system_quantity ? 'text-purple-700' : 'text-slate-700' }}">
                                        {{ $endingSystemQty !== null ? number_format((int) $endingSystemQty) : '-' }}
                                    </td>
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right font-bold tabular-nums {{ $movementQty !== null && (int) $movementQty > 0 ? 'text-green-700' : ($movementQty !== null && (int) $movementQty < 0 ? 'text-red-700' : 'text-slate-500') }}">
                                        {{ $movementQty !== null ? number_format((int) $movementQty) : '-' }}
                                    </td>
                                    @if ($isEditable)
                                        <td class="whitespace-nowrap border border-slate-300 px-1 py-0.5" @click.stop>
                                            <input type="text" inputmode="numeric"
                                                :value="first"
                                                @input="first=clean($event.target.value); $event.target.value=first; notify()"
                                                @keydown="if(['e','E','+','.'].includes($event.key)) $event.preventDefault()"
                                                @disabled($activeRound !== 1 || $this->isRoundConfirmed(1))
                                                class="{{ $countInputClass }}" placeholder="-">
                                        </td>
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right font-bold tabular-nums"
                                            :class="{ 'text-green-700': firstDiff > 0, 'text-red-700': firstDiff < 0 }"
                                            x-text="firstDiff !== null ? new Intl.NumberFormat().format(firstDiff) : '-'"></td>
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-slate-600">{{ $row->first_count_actor_name ?: '-' }}</td>
                                        <td class="whitespace-nowrap border border-slate-300 px-1 py-0.5" @click.stop>
                                            <input type="text" inputmode="numeric"
                                                :value="second"
                                                @input="second=clean($event.target.value); $event.target.value=second; notify()"
                                                @keydown="if(['e','E','+','.'].includes($event.key)) $event.preventDefault()"
                                                @disabled($activeRound !== 2 || $this->isRoundConfirmed(2))
                                                class="{{ $countInputClass }}" placeholder="-">
                                        </td>
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right font-bold tabular-nums"
                                            :class="{ 'text-green-700': secondDiff > 0, 'text-red-700': secondDiff < 0 }"
                                            x-text="secondDiff !== null ? new Intl.NumberFormat().format(secondDiff) : '-'"></td>
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-slate-600">{{ $row->second_count_actor_name ?: '-' }}</td>
                                        <td class="whitespace-nowrap border border-slate-300 px-1 py-0.5" @click.stop>
                                            <input type="text" inputmode="numeric"
                                                :value="final_"
                                                @input="final_=clean($event.target.value); $event.target.value=final_; notify()"
                                                @keydown="if(['e','E','+','.'].includes($event.key)) $event.preventDefault()"
                                                @disabled($activeRound !== 3 || $this->isRoundConfirmed(3))
                                                class="{{ $countInputClass }}" placeholder="-">
                                        </td>
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right font-bold tabular-nums"
                                            :class="{ 'text-green-700': finalDiff > 0, 'text-red-700': finalDiff < 0 }"
                                            x-text="finalDiff !== null ? new Intl.NumberFormat().format(finalDiff) : '-'"></td>
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-slate-600">{{ $row->final_count_actor_name ?: '-' }}</td>
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right font-bold tabular-nums"
                                            :class="{ 'text-green-700': endDiff > 0, 'text-red-700': endDiff < 0 }"
                                            x-text="endDiff !== null ? new Intl.NumberFormat().format(endDiff) : '-'"></td>
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right tabular-nums"
                                            :class="{ 'text-green-700': endDiffAmt > 0, 'text-red-700': endDiffAmt < 0 }"
                                            x-text="endDiffAmt !== null ? '¥' + new Intl.NumberFormat().format(endDiffAmt) : '-'"></td>
                                    @else
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right tabular-nums">{{ $initFirst !== '' ? number_format((int) $initFirst) : '-' }}</td>
                                        @php
                                            $firstDiff = $this->roundDifferenceForDisplay($row, 1);
                                            $secondDiff = $this->roundDifferenceForDisplay($row, 2);
                                            $finalDiff = $this->roundDifferenceForDisplay($row, 3);
                                        @endphp
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right font-bold tabular-nums {{ $firstDiff !== null && $firstDiff > 0 ? 'text-green-700' : ($firstDiff !== null && $firstDiff < 0 ? 'text-red-700' : '') }}">{{ $firstDiff !== null ? number_format((int) $firstDiff) : '-' }}</td>
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-slate-600">{{ $row->first_count_actor_name ?: '-' }}</td>
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right tabular-nums">{{ $initSecond !== '' ? number_format((int) $initSecond) : '-' }}</td>
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right font-bold tabular-nums {{ $secondDiff !== null && $secondDiff > 0 ? 'text-green-700' : ($secondDiff !== null && $secondDiff < 0 ? 'text-red-700' : '') }}">{{ $secondDiff !== null ? number_format((int) $secondDiff) : '-' }}</td>
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-slate-600">{{ $row->second_count_actor_name ?: '-' }}</td>
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right font-bold tabular-nums">{{ $initFinal !== '' ? number_format((int) $initFinal) : '-' }}</td>
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right font-bold tabular-nums {{ $finalDiff !== null && $finalDiff > 0 ? 'text-green-700' : ($finalDiff !== null && $finalDiff < 0 ? 'text-red-700' : '') }}">{{ $finalDiff !== null ? number_format((int) $finalDiff) : '-' }}</td>
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-slate-600">{{ $row->final_count_actor_name ?: '-' }}</td>
                                        @php
                                            $diffQty = $this->roundDifferenceForDisplay($row, $activeRound);
                                            $confirmedAmount = match ($activeRound) {
                                                1 => $row->getAttribute('first_count_confirmed_difference_amount'),
                                                2 => $row->getAttribute('second_count_confirmed_difference_amount'),
                                                3 => $row->getAttribute('final_count_confirmed_difference_amount'),
                                            };
                                            $diffAmount = $this->isRoundConfirmed($activeRound) && $confirmedAmount !== null
                                                ? (float) $confirmedAmount
                                                : ($diffQty !== null ? (float) $diffQty * (float) $row->cost_price : null);
                                        @endphp
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right font-bold tabular-nums {{ $diffQty !== null && $diffQty > 0 ? 'text-green-700' : ($diffQty !== null && $diffQty < 0 ? 'text-red-700' : '') }}">{{ $diffQty !== null ? number_format((int) $diffQty) : '-' }}</td>
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right tabular-nums {{ $diffAmount !== null && $diffAmount > 0 ? 'text-green-700' : ($diffAmount !== null && $diffAmount < 0 ? 'text-red-700' : '') }}">{{ $diffAmount !== null ? '¥' . number_format((int) $diffAmount) : '-' }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                @endif
            </div>
            <div class="flex shrink-0 items-center justify-between border-t border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                <div class="tabular-nums">
                    {{ number_format($pageFirst) }}-{{ number_format($pageLast) }} / {{ number_format($rows->total()) }}件
                    <span class="ml-2 font-bold text-green-800">入力中: {{ $this->activeRoundLabel() }}</span>
                    <span x-show="changeCount > 0" x-cloak class="ml-2 font-bold text-red-700">未反映の入力があります。反映後にページ移動できます。</span>
                </div>
                <div class="flex items-center gap-1 font-bold">
                    <button type="button"
                        @click="guardPagination('previous')"
                        x-bind:class="{ 'cursor-not-allowed opacity-40': changeCount > 0 }"
                        @disabled($rows->onFirstPage())
                        class="h-8 rounded-md border border-slate-300 bg-white px-3 disabled:cursor-not-allowed disabled:opacity-40 hover:bg-slate-100">
                        前へ
                    </button>
                    <span class="px-2 tabular-nums">{{ $rows->currentPage() }} / {{ $rows->lastPage() }}</span>
                    <button type="button"
                        @click="guardPagination('next')"
                        x-bind:class="{ 'cursor-not-allowed opacity-40': changeCount > 0 }"
                        @disabled(! $rows->hasMorePages())
                        class="h-8 rounded-md border border-slate-300 bg-white px-3 disabled:cursor-not-allowed disabled:opacity-40 hover:bg-slate-100">
                        次へ
                    </button>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
