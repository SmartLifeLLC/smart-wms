<div x-data="{
    filters: {
        itemCode: '',
        janCode: '',
        itemName: '',
        contractorId: '',
        category1Id: '',
        category2Id: '',
        category3Id: '',
        lastShippedFrom: '',
        lastShippedTo: '',
    },
    results: [],
    quantities: {},
    pinnedItems: {},
    totalCount: 0,
    currentPage: 1,
    lastPage: 1,
    perPage: 25,
    loading: false,
    searched: false,
    categories2: [],
    categories3: [],
    contractorSearch: '',
    contractorOptions: [],
    contractorDropdownOpen: false,
    contractorLoading: false,
    contractorComposing: false,
    contractorJustComposed: false,
    hoveredItemName: null,
    itemNameTooltipX: 0,
    itemNameTooltipY: 0,

    updateItemNameTooltipPosition(event) {
        const padding = 16;
        this.itemNameTooltipX = Math.min(event.clientX + 14, window.innerWidth - 620 - padding);
        this.itemNameTooltipY = Math.min(event.clientY + 14, window.innerHeight - 160 - padding);
    },

    showItemNameTooltip(event, name) {
        this.hoveredItemName = name || null;
        this.updateItemNameTooltipPosition(event);
    },

    isTransferOrderItem(item) {
        return String(item.contractor_code) === '9012';
    },

    normalizeIntegerInput(value) {
        const normalized = String(value || '')
            .replace(/[０-９]/g, char => String.fromCharCode(char.charCodeAt(0) - 0xFEE0))
            .replace(/\D/g, '');

        return normalized;
    },

    setQty(item, field, event) {
        const qty = this.getQty(item.id);

        if (this.isTransferOrderItem(item)) {
            qty[field] = null;
            event.target.value = '';
            return;
        }

        const normalized = this.normalizeIntegerInput(event.target.value);
        event.target.value = normalized;
        qty[field] = normalized === '' ? null : parseInt(normalized, 10);
        this.onQtyChange();
    },

    formatVolume(item) {
        const packaging = String(item.packaging || '').trim().replace(/[×✕ｘＸ]/g, 'x').replace(/\s*x\s*/ig, 'x');
        const capacityCase = parseInt(item.capacity_case || 0);
        if (!packaging) return '-';

        let volume = packaging.split(/x/i)[0].trim();
        if (capacityCase > 0) {
            volume = volume.replace(new RegExp(`\\s+${capacityCase}$`), '').trim();
        }

        if (/^\d+(\.\d+)?$/.test(volume)) {
            if (Number(volume) <= 0) {
                return '-';
            }

            return `${volume}ml`;
        }

        return volume || '-';
    },

    async search(page = 1) {
        const warehouseId = $wire.get('mountedActions.0.data.warehouse_id');
        if (!warehouseId) {
            alert('発注倉庫を選択してください');
            return;
        }
        this.loading = true;
        this.currentPage = page;
        this.updatePinnedItems();
        try {
            const result = await $wire.searchItemsForModal(
                parseInt(warehouseId),
                this.filters.itemCode || null,
                this.filters.janCode || null,
                this.filters.itemName || null,
                this.filters.contractorId ? parseInt(this.filters.contractorId) : null,
                this.filters.category1Id ? parseInt(this.filters.category1Id) : null,
                this.filters.category2Id ? parseInt(this.filters.category2Id) : null,
                this.filters.category3Id ? parseInt(this.filters.category3Id) : null,
                this.filters.lastShippedFrom || null,
                this.filters.lastShippedTo || null,
                page,
                this.perPage
            );
            this.totalCount = result.total;
            this.currentPage = result.current_page;
            this.lastPage = result.last_page;
            this.searched = true;

            const newIds = new Set(result.data.map(r => String(r.id)));
            const pinned = Object.values(this.pinnedItems).filter(p => !newIds.has(String(p.id)));
            this.results = [...pinned, ...result.data];

            this.results.forEach(item => {
                const key = String(item.id);
                if (!(key in this.quantities)) {
                    this.quantities[key] = {
                        caseQty: item.pending_case_qty || null,
                        pieceQty: item.pending_piece_qty || null,
                    };
                }
            });
        } finally {
            this.loading = false;
        }
    },

    updatePinnedItems() {
        this.results.forEach(item => {
            const key = String(item.id);
            const qty = this.quantities[key];
            if (this.isTransferOrderItem(item)) {
                delete this.pinnedItems[key];
                return;
            }
            if (qty && ((qty.caseQty > 0) || (qty.pieceQty > 0))) {
                this.pinnedItems[key] = { ...item };
            } else {
                delete this.pinnedItems[key];
            }
        });
    },

    resetFilters() {
        this.filters = { itemCode: '', janCode: '', itemName: '', contractorId: '', category1Id: '', category2Id: '', category3Id: '', lastShippedFrom: '', lastShippedTo: '' };
        this.categories2 = [];
        this.categories3 = [];
        this.contractorSearch = '';
        this.contractorOptions = [];
        this.contractorDropdownOpen = false;
        this.contractorComposing = false;
        this.contractorJustComposed = false;
        if (this.$refs.contractorSearchInput) {
            this.$refs.contractorSearchInput.value = '';
        }
        this.results = [];
        this.pinnedItems = {};
        this.searched = false;
        this.totalCount = 0;
    },

    async searchContractors() {
        const query = this.contractorSearch.trim().normalize('NFKC');
        this.filters.contractorId = '';
        this.contractorDropdownOpen = true;

        if (!query || (query.length < 2 && !/^\d+$/.test(query))) {
            this.contractorOptions = [];
            return;
        }

        this.contractorLoading = true;
        try {
            this.contractorOptions = await $wire.searchContractorsForOrderCreate(query);
        } finally {
            this.contractorLoading = false;
        }
    },

    finishContractorComposition() {
        this.contractorComposing = false;
        this.contractorJustComposed = true;

        setTimeout(() => {
            this.contractorJustComposed = false;
        }, 100);
    },

    handleContractorEnter(event) {
        if (this.contractorComposing || this.contractorJustComposed || event.isComposing || event.keyCode === 229) {
            return;
        }

        event.preventDefault();

        if (this.contractorOptions.length === 1) {
            this.selectContractor(this.contractorOptions[0]);
            return;
        }

        this.search(1);
    },

    selectContractor(contractor) {
        const label = contractor.label || `[${contractor.code}]${contractor.name}`;
        this.filters.contractorId = contractor.id;
        this.contractorSearch = label;
        if (this.$refs.contractorSearchInput) {
            this.$refs.contractorSearchInput.value = label;
        }
        this.contractorOptions = [];
        this.contractorDropdownOpen = false;
    },

    selectContractorById(contractorId) {
        const contractor = this.contractorOptions.find((option) => String(option.id) === String(contractorId));
        if (!contractor) {
            this.clearContractor();
            return;
        }

        this.selectContractor(contractor);
    },

    clearContractor() {
        this.filters.contractorId = '';
        this.contractorSearch = '';
        if (this.$refs.contractorSearchInput) {
            this.$refs.contractorSearchInput.value = '';
        }
        this.contractorOptions = [];
        this.contractorDropdownOpen = false;
    },

    async loadCategories2() {
        this.filters.category2Id = '';
        this.filters.category3Id = '';
        this.categories3 = [];
        if (this.filters.category1Id) {
            this.categories2 = await $wire.getSubCategories(parseInt(this.filters.category1Id));
        } else {
            this.categories2 = [];
        }
    },

    async loadCategories3() {
        this.filters.category3Id = '';
        if (this.filters.category2Id) {
            this.categories3 = await $wire.getSubCategories(parseInt(this.filters.category2Id));
        } else {
            this.categories3 = [];
        }
    },

    cleanDateInput(value) {
        return String(value || '')
            .replace(/[０-９]/g, char => String.fromCharCode(char.charCodeAt(0) - 0xFEE0))
            .replace(/[^0-9\-\/]/g, '');
    },

    formatSmartDate(field) {
        const original = this.filters[field] || '';
        const input = this.cleanDateInput(original).trim();
        this.filters[field] = input;

        if (!input) {
            return;
        }

        const fullDateMatch = input.match(/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/);
        if (fullDateMatch) {
            this.applySmartDate(field, parseInt(fullDateMatch[1], 10), parseInt(fullDateMatch[2], 10), parseInt(fullDateMatch[3], 10), original);
            return;
        }

        const digits = input.replace(/\D/g, '');
        const now = new Date();
        let year = now.getFullYear();
        let month = now.getMonth() + 1;
        let day = now.getDate();

        if (digits.length === 1 || digits.length === 2) {
            day = parseInt(digits, 10);
        } else if (digits.length === 3) {
            month = parseInt(digits.substring(0, 1), 10);
            day = parseInt(digits.substring(1, 3), 10);
        } else if (digits.length === 4) {
            month = parseInt(digits.substring(0, 2), 10);
            day = parseInt(digits.substring(2, 4), 10);
        } else if (digits.length === 6) {
            year = 2000 + parseInt(digits.substring(0, 2), 10);
            month = parseInt(digits.substring(2, 4), 10);
            day = parseInt(digits.substring(4, 6), 10);
        } else if (digits.length === 8) {
            year = parseInt(digits.substring(0, 4), 10);
            month = parseInt(digits.substring(4, 6), 10);
            day = parseInt(digits.substring(6, 8), 10);
        } else {
            this.filters[field] = '';
            return;
        }

        this.applySmartDate(field, year, month, day, original);
    },

    applySmartDate(field, year, month, day, fallback = '') {
        const parsed = new Date(year, month - 1, day);
        if (parsed.getFullYear() !== year || parsed.getMonth() !== month - 1 || parsed.getDate() !== day) {
            this.filters[field] = fallback;
            return;
        }

        this.filters[field] = [
            parsed.getFullYear(),
            String(parsed.getMonth() + 1).padStart(2, '0'),
            String(parsed.getDate()).padStart(2, '0'),
        ].join('-');
    },

    openNativeDatePicker(refName, field) {
        const picker = this.$refs[refName];
        if (!picker) return;
        picker.value = this.filters[field] || '';
        if (typeof picker.showPicker === 'function') {
            picker.showPicker();
            return;
        }
        picker.click();
    },

    onQtyChange() {
        this.syncToWire();
    },

    syncToWire() {
        const items = [];
        for (const [itemId, qty] of Object.entries(this.quantities)) {
            const item = this.results.find(r => String(r.id) === itemId);
            if (!item) continue;
            if (this.isTransferOrderItem(item)) continue;
            if ((qty.caseQty > 0) || (qty.pieceQty > 0)) {
                if (qty.caseQty > 0) {
                    items.push({
                        item_id: parseInt(itemId),
                        item_code: item.code,
                        search_code: item.search_code || '',
                        ordering_code: item.ordering_code || '',
                        capacity_case: item.capacity_case || 1,
                        quantity_type: 'CASE',
                        case_qty: qty.caseQty,
                        piece_qty: 0,
                        order_quantity: qty.caseQty,
                    });
                }
                if (qty.pieceQty > 0) {
                    items.push({
                        item_id: parseInt(itemId),
                        item_code: item.code,
                        search_code: item.search_code || '',
                        ordering_code: item.ordering_code || '',
                        capacity_case: item.capacity_case || 1,
                        quantity_type: 'PIECE',
                        case_qty: 0,
                        piece_qty: qty.pieceQty,
                        order_quantity: qty.pieceQty,
                    });
                }
            }
        }
        $wire.set('orderCandidateItems', items);
    },

    get validCount() {
        let count = 0;
        for (const [itemId, qty] of Object.entries(this.quantities)) {
            const item = this.results.find(r => String(r.id) === itemId);
            if (item && this.isTransferOrderItem(item)) continue;
            if ((qty.caseQty > 0) || (qty.pieceQty > 0)) count++;
        }
        return count;
    },

    getQty(itemId) {
        const key = String(itemId);
        if (!(key in this.quantities)) {
            this.quantities[key] = { caseQty: null, pieceQty: null };
        }
        return this.quantities[key];
    }
}" x-init="$wire.set('orderCandidateItems', [])" class="space-y-3">
    <div
        x-cloak
        x-show="hoveredItemName"
        x-transition.opacity.duration.100ms
        class="pointer-events-none fixed z-[9999] max-w-[620px] whitespace-normal rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold leading-6 text-slate-900 shadow-xl ring-1 ring-black/5 dark:border-slate-600 dark:bg-slate-900 dark:text-white"
        x-bind:style="`left: ${Math.max(16, itemNameTooltipX)}px; top: ${Math.max(16, itemNameTooltipY)}px;`"
        x-text="hoveredItemName"
    ></div>

    {{-- 検索フィルタ --}}
    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 space-y-2">
        <div class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">商品検索フィルタ</div>
        <div class="grid grid-cols-5 gap-2">
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">商品CD</label>
                <input type="text" x-model="filters.itemCode" @keydown.enter.prevent="search(1)"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded px-2 py-1 text-xs bg-white dark:bg-gray-900 text-gray-900 dark:text-white" />
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">JANコード</label>
                <input type="text" x-model="filters.janCode" @keydown.enter.prevent="search(1)"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded px-2 py-1 text-xs bg-white dark:bg-gray-900 text-gray-900 dark:text-white" />
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">商品名</label>
                <input type="text" x-model="filters.itemName" @keydown.enter.prevent="search(1)"
                    placeholder="2文字以上"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded px-2 py-1 text-xs bg-white dark:bg-gray-900 text-gray-900 dark:text-white" />
            </div>
            <div class="relative z-[80]">
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">発注先</label>
                <div class="relative">
                    <input type="text"
                        x-ref="contractorSearchInput"
                        x-model="contractorSearch"
                        @focus="contractorDropdownOpen = true"
                        @input.debounce.250ms="searchContractors()"
                        @compositionstart="contractorComposing = true"
                        @compositionend="finishContractorComposition()"
                        @keydown.enter="handleContractorEnter($event)"
                        @keydown.escape.prevent="contractorDropdownOpen = false"
                        placeholder="CD・名前で検索して選択"
                        class="w-full border border-gray-300 dark:border-gray-600 rounded px-2 py-1 pr-7 text-xs bg-white dark:bg-gray-900 text-gray-900 dark:text-white" />
                    <button type="button"
                        x-show="contractorSearch"
                        x-cloak
                        @click="clearContractor()"
                        class="absolute inset-y-0 right-1 flex items-center px-1 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                        <x-filament::icon icon="heroicon-m-x-mark" class="h-3.5 w-3.5" />
                    </button>

                    <div x-show="contractorDropdownOpen && (contractorLoading || contractorOptions.length > 0 || contractorSearch)"
                        x-cloak
                        class="absolute z-[9999] mt-1 w-full rounded-md border border-gray-200 bg-white text-xs shadow-lg dark:border-gray-700 dark:bg-gray-900">
                        <div x-show="contractorLoading" class="px-2 py-1.5 text-gray-400">検索中...</div>
                        <select x-show="!contractorLoading && contractorOptions.length > 0"
                            x-model="filters.contractorId"
                            @change="selectContractorById($event.target.value)"
                            size="5"
                            class="block max-h-44 w-full border-0 bg-white px-2 py-1 text-xs text-gray-800 outline-none dark:bg-gray-900 dark:text-gray-100">
                            <template x-for="contractor in contractorOptions" :key="contractor.id">
                                <option :value="contractor.id" x-text="contractor.label || ('[' + contractor.code + ']' + contractor.name)"></option>
                            </template>
                        </select>
                        <div x-show="!contractorLoading && contractorOptions.length === 0 && contractorSearch && (contractorSearch.trim().length >= 2 || /^\d+$/.test(contractorSearch.trim()))"
                            class="px-2 py-1.5 text-gray-400">
                            該当する発注先がありません
                        </div>
                        <div x-show="!contractorLoading && contractorOptions.length === 0 && contractorSearch && contractorSearch.trim().length < 2 && !/^\d+$/.test(contractorSearch.trim())"
                            class="px-2 py-1.5 text-gray-400">
                            2文字以上、またはCDを入力してください
                        </div>
                    </div>
                </div>
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">大分類</label>
                <select x-model="filters.category1Id" @change="loadCategories2()"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded px-2 py-1 text-xs bg-white dark:bg-gray-900 text-gray-900 dark:text-white">
                    <option value="">全て</option>
                    @foreach(\App\Models\Sakemaru\ItemCategory::where('depth', 1)->where('is_active', true)->orderBy('code')->get() as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="grid grid-cols-5 gap-2">
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">中分類</label>
                <select x-model="filters.category2Id" @change="loadCategories3()"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded px-2 py-1 text-xs bg-white dark:bg-gray-900 text-gray-900 dark:text-white">
                    <option value="">全て</option>
                    <template x-for="cat in categories2" :key="cat.id">
                        <option :value="cat.id" x-text="cat.name"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">小分類</label>
                <select x-model="filters.category3Id"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded px-2 py-1 text-xs bg-white dark:bg-gray-900 text-gray-900 dark:text-white">
                    <option value="">全て</option>
                    <template x-for="cat in categories3" :key="cat.id">
                        <option :value="cat.id" x-text="cat.name"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">最終出荷日(から)</label>
                <div class="relative">
                    <input type="text" inputmode="numeric" x-model="filters.lastShippedFrom"
                        @focus="$event.target.select()"
                        @input="filters.lastShippedFrom = cleanDateInput(filters.lastShippedFrom)"
                        @blur="formatSmartDate('lastShippedFrom')"
                        @keyup.enter.prevent="formatSmartDate('lastShippedFrom'); search(1)"
                        placeholder="YYYY-MM-DD"
                        class="w-full border border-gray-300 dark:border-gray-600 rounded px-2 py-1 pr-8 text-xs bg-white dark:bg-gray-900 text-gray-900 dark:text-white" />
                    <button type="button" tabindex="-1" @click="openNativeDatePicker('lastShippedFromPicker', 'lastShippedFrom')"
                        class="absolute inset-y-0 right-1 flex items-center px-1 text-gray-400 hover:text-primary-600">
                        <x-filament::icon icon="heroicon-m-calendar" class="h-4 w-4" />
                    </button>
                    <input type="date" x-ref="lastShippedFromPicker" @change="filters.lastShippedFrom = $event.target.value"
                        class="pointer-events-none absolute bottom-0 right-0 h-px w-px opacity-0" tabindex="-1" aria-hidden="true" />
                </div>
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">最終出荷日(まで)</label>
                <div class="relative">
                    <input type="text" inputmode="numeric" x-model="filters.lastShippedTo"
                        @focus="$event.target.select()"
                        @input="filters.lastShippedTo = cleanDateInput(filters.lastShippedTo)"
                        @blur="formatSmartDate('lastShippedTo')"
                        @keyup.enter.prevent="formatSmartDate('lastShippedTo'); search(1)"
                        placeholder="YYYY-MM-DD"
                        class="w-full border border-gray-300 dark:border-gray-600 rounded px-2 py-1 pr-8 text-xs bg-white dark:bg-gray-900 text-gray-900 dark:text-white" />
                    <button type="button" tabindex="-1" @click="openNativeDatePicker('lastShippedToPicker', 'lastShippedTo')"
                        class="absolute inset-y-0 right-1 flex items-center px-1 text-gray-400 hover:text-primary-600">
                        <x-filament::icon icon="heroicon-m-calendar" class="h-4 w-4" />
                    </button>
                    <input type="date" x-ref="lastShippedToPicker" @change="filters.lastShippedTo = $event.target.value"
                        class="pointer-events-none absolute bottom-0 right-0 h-px w-px opacity-0" tabindex="-1" aria-hidden="true" />
                </div>
            </div>
            <div class="flex items-end gap-1">
                <button type="button" @click="search(1)"
                    class="px-3 py-1 bg-primary-600 text-white rounded text-xs font-medium hover:bg-primary-700">
                    検索
                </button>
                <button type="button" @click="resetFilters()"
                    class="px-3 py-1 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded text-xs font-medium hover:bg-gray-300 dark:hover:bg-gray-600">
                    リセット
                </button>
            </div>
        </div>
    </div>

    {{-- ローディング --}}
    <div x-show="loading" class="flex items-center justify-center py-6">
        <svg class="animate-spin h-5 w-5 text-primary-500 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        <span class="text-sm text-gray-500">検索中...</span>
    </div>

    {{-- 検索結果テーブル --}}
    <div x-show="searched && !loading" x-cloak>
        <div class="flex items-center justify-between mb-1">
            <div class="text-xs text-gray-500 dark:text-gray-400">
                検索結果: <span class="font-bold" x-text="totalCount"></span>件
                <span x-show="validCount > 0" class="ml-2 text-primary-600 dark:text-primary-400 font-bold">
                    （<span x-text="validCount"></span>件入力済み）
                </span>
            </div>
        </div>

        <div class="border border-gray-200 dark:border-white/10 rounded-lg overflow-y-auto overflow-x-hidden" style="max-height: 320px">
            <table class="w-full text-sm table-fixed">
                <colgroup>
                    <col />
                    <col style="width: 48px" />
                    <col style="width: 70px" />
                    <col style="width: 250px" />
                    <col style="width: 48px" />
                    <col style="width: 48px" />
                    <col style="width: 58px" />
                    <col style="width: 58px" />
                    <col style="width: 58px" />
                    <col style="width: 58px" />
                </colgroup>
                <thead class="sticky top-0 z-10 bg-gray-100 dark:bg-white/10">
                    <tr class="divide-x divide-gray-200 dark:divide-white/10">
                        <th class="px-1.5 py-1 text-center text-xs font-medium text-gray-500 dark:text-gray-400">商品</th>
                        <th class="px-1.5 py-1 text-center text-xs font-medium text-gray-500 dark:text-gray-400">入数</th>
                        <th class="px-1.5 py-1 text-left text-xs font-medium text-gray-500 dark:text-gray-400">容量</th>
                        <th class="px-1.5 py-1 text-left text-xs font-medium text-gray-500 dark:text-gray-400">発注先</th>
                        <th class="px-1.5 py-1 text-right text-xs font-medium text-gray-500 dark:text-gray-400">3日</th>
                        <th class="px-1.5 py-1 text-right text-xs font-medium text-gray-500 dark:text-gray-400">7日</th>
                        <th class="px-1.5 py-1 text-right text-xs font-medium text-gray-500 dark:text-gray-400">30日</th>
                        <th class="px-1 py-1 text-center text-xs font-medium text-gray-500 dark:text-gray-400">ケース</th>
                        <th class="px-1 py-1 text-center text-xs font-medium text-gray-500 dark:text-gray-400">バラ</th>
                        <th class="px-1 py-1 text-center text-xs font-medium text-gray-500 dark:text-gray-400">総バラ</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(item, index) in results" :key="item.id">
                        <tr :class="String(item.contractor_code) === '9012'
                                ? 'bg-red-50 dark:bg-red-950/30'
                                : (String(item.id) in pinnedItems)
                                    ? 'bg-green-50 dark:bg-green-950/30'
                                    : (index % 2 === 0 ? 'bg-white dark:bg-gray-900' : 'bg-blue-50/30 dark:bg-blue-950/20')"
                            class="divide-x divide-gray-200 dark:divide-white/10 border-t border-gray-200 dark:border-white/10">
                            <td class="px-1.5 py-0.5">
                                <span class="block text-[11px] font-mono text-gray-500 dark:text-gray-400" x-text="item.code"></span>
                                <span
                                    class="block cursor-help whitespace-normal break-words text-xs leading-4 text-gray-700 dark:text-gray-300"
                                    x-text="item.name"
                                    x-on:mouseenter="showItemNameTooltip($event, item.name)"
                                    x-on:mousemove="updateItemNameTooltipPosition($event)"
                                    x-on:mouseleave="hoveredItemName = null"
                                ></span>
                            </td>
                            <td class="px-1.5 py-0.5 text-right">
                                <span class="text-xs font-mono text-gray-500 dark:text-gray-400" x-text="item.capacity_case || '-'"></span>
                            </td>
                            <td class="px-1.5 py-0.5 text-center">
                                <span class="text-xs text-gray-500 dark:text-gray-400" x-text="formatVolume(item)"></span>
                            </td>
                            <td class="px-1.5 py-0.5 text-left">
                                <span x-show="String(item.contractor_code) === '9012'" class="text-[10px] font-bold text-red-600 dark:text-red-400">移動発注対象です</span>
                                <span class="block text-left text-xs whitespace-normal break-words"
                                    :class="String(item.contractor_code) === '9012' ? 'text-red-600 dark:text-red-400 font-bold' : 'text-gray-500 dark:text-gray-400'"
                                    x-text="item.contractor_name || '-'"></span>
                            </td>
                            <td class="px-1.5 py-0.5 text-right">
                                <span class="text-xs font-mono" :class="item.last_3d_qty > 0 ? 'text-gray-900 dark:text-white' : 'text-gray-400'" x-text="item.last_3d_qty || 0"></span>
                            </td>
                            <td class="px-1.5 py-0.5 text-right">
                                <span class="text-xs font-mono" :class="item.last_7d_qty > 0 ? 'text-gray-900 dark:text-white' : 'text-gray-400'" x-text="item.last_7d_qty || 0"></span>
                            </td>
                            <td class="px-1.5 py-0.5 text-right">
                                <span class="text-xs font-mono" :class="item.last_30d_qty > 0 ? 'text-gray-900 dark:text-white' : 'text-gray-400'" x-text="item.last_30d_qty || 0"></span>
                            </td>
                            <td class="px-0.5 py-0.5">
                                <input type="text"
                                    inputmode="text"
                                    autocomplete="off"
                                    :value="getQty(item.id).caseQty"
                                    @input="setQty(item, 'caseQty', $event)"
                                    @keydown.enter.prevent
                                    :disabled="isTransferOrderItem(item)"
                                    placeholder=""
                                    class="w-full border border-gray-300 dark:border-gray-600 rounded px-1 py-0.5 text-xs text-right font-mono bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-1 focus:ring-primary-500 focus:border-primary-500 disabled:bg-gray-100 disabled:text-gray-400 disabled:cursor-not-allowed dark:disabled:bg-gray-800/60"
                                />
                            </td>
                            <td class="px-0.5 py-0.5">
                                <input type="text"
                                    inputmode="text"
                                    autocomplete="off"
                                    :value="getQty(item.id).pieceQty"
                                    @input="setQty(item, 'pieceQty', $event)"
                                    @keydown.enter.prevent
                                    :disabled="isTransferOrderItem(item)"
                                    placeholder=""
                                    class="w-full border border-gray-300 dark:border-gray-600 rounded px-1 py-0.5 text-xs text-right font-mono bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-1 focus:ring-primary-500 focus:border-primary-500 disabled:bg-gray-100 disabled:text-gray-400 disabled:cursor-not-allowed dark:disabled:bg-gray-800/60"
                                />
                            </td>
                            <td class="px-1 py-0.5 text-right">
                                <span class="text-base font-mono font-bold leading-none tabular-nums"
                                    :class="((getQty(item.id).caseQty || 0) * (item.capacity_case || 1) + (getQty(item.id).pieceQty || 0)) > 0 ? 'text-primary-600 dark:text-primary-400' : 'text-gray-400'"
                                    x-text="(getQty(item.id).caseQty || 0) * (item.capacity_case || 1) + (getQty(item.id).pieceQty || 0) || ''"></span>
                            </td>
                        </tr>
                    </template>
                    <template x-if="results.length === 0">
                        <tr>
                            <td colspan="10" class="px-4 py-6 text-center text-sm text-gray-400 dark:text-gray-500">
                                検索結果がありません
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        {{-- ページネーション --}}
        <div x-show="lastPage > 1" class="flex items-center justify-center gap-1 mt-2">
            <button type="button" @click="search(currentPage - 1)" :disabled="currentPage <= 1"
                class="px-2 py-0.5 text-xs rounded border border-gray-300 dark:border-gray-600 disabled:opacity-40 hover:bg-gray-100 dark:hover:bg-gray-700">
                &lt;
            </button>
            <template x-for="p in lastPage" :key="p">
                <button type="button" @click="search(p)"
                    :class="p === currentPage ? 'bg-primary-600 text-white border-primary-600' : 'border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700'"
                    class="px-2 py-0.5 text-xs rounded border" x-text="p"
                    x-show="Math.abs(p - currentPage) <= 2 || p === 1 || p === lastPage">
                </button>
            </template>
            <button type="button" @click="search(currentPage + 1)" :disabled="currentPage >= lastPage"
                class="px-2 py-0.5 text-xs rounded border border-gray-300 dark:border-gray-600 disabled:opacity-40 hover:bg-gray-100 dark:hover:bg-gray-700">
                &gt;
            </button>
        </div>
    </div>

    {{-- 未検索時 --}}
    <div x-show="!searched && !loading" class="flex items-center justify-center py-8">
        <div class="text-center text-sm text-gray-400 dark:text-gray-500">
            <svg class="mx-auto h-8 w-8 mb-2 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
            </svg>
            検索条件を入力して「検索」ボタンを押してください
        </div>
    </div>

    {{-- フッター情報 --}}
    <div class="flex items-center justify-end">
        <div class="text-xs text-gray-500 dark:text-gray-400">
            <span x-text="validCount"></span>件の商品を追加予定
        </div>
    </div>
</div>
