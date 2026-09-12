@php
    $lw = $lw ?? (isset($getLivewire) ? $getLivewire() : null);
    $selectedWarehouseLabel = $lw?->selectedWarehouseLabel() ?? '-';
    $today = now()->toDateString();
    $defaultLastShippedFrom = '';
    $defaultLastShippedTo = '';
    $defaultExpectedArrivalDate = $lw?->defaultOrderExpectedArrivalDate() ?? now()->addDay()->toDateString();
    $candidateColumnHelps = [
        '発注先' => 'この商品で発注する発注先です。EOS可・FAX専用の判定もここに表示します。',
        '商品CD' => '商品マスタの商品コードです。',
        '商品名' => '商品マスタの商品名です。',
        '規格' => '商品の規格・入数です。',
        '棚番' => '選択中の倉庫で設定されている入荷デフォルト棚番です。',
        '発注点' => '選択中の倉庫・発注先・商品に設定されている発注点です。',
        '予定日' => '今回この画面から発注した場合の入荷予定日です。必要に応じて明細ごとに変更できます。',
        'ケース' => 'ケース単位で発注する数量を入力します。ケースとバラはどちらか一方を入力します。',
        'バラ' => 'バラ単位で発注する数量を入力します。ケースとバラはどちらか一方を入力します。',
        '総バラ' => 'ケース入力は入数を掛け、バラ入力はそのまま集計した発注数量です。',
        '理論在庫' => '選択中の倉庫の理論在庫です。数値をクリックすると他倉庫の理論在庫を確認できます。',
        '見込在庫' => '理論在庫に、既に発注済みで未入荷の納品予定数を加えた見込在庫です。',
        '納品予定数' => '既に発注済みで、まだ入荷完了またはキャンセルされていない納品予定数です。',
        '納品予定日' => '既に発注済みの最新発注日に紐づく本来の納品予定日です。入荷確定済みでも表示します。',
        '最終発注日' => 'この商品の直近の発注日です。',
        '1週' => '基準日を含む直近7日間の販売数量です。',
        '2週' => '1週の前の7日間の販売数量です。',
        '3週' => '2週の前の7日間の販売数量です。',
        '前月' => '基準日の前月1か月分の販売数量です。',
        '備考' => '商品発注先マスタの備考です。ボタンで内容を確認できます。',
    ];
    $candidateColumnHelps = array_replace($candidateColumnHelps, $lw?->weeklySalesColumnHelps() ?? []);
@endphp

<div x-data="{
    columnHelpDescriptions: @js($candidateColumnHelps),
    columnHelpLabel: null,
    noteModalText: null,
    filters: {
        itemCode: '',
        janCode: '',
        itemName: '',
        contractorId: '',
        category1Id: '',
        category2Id: '',
        category3Id: '',
        lastShippedFrom: @js($defaultLastShippedFrom),
        lastShippedTo: @js($defaultLastShippedTo),
    },
    results: [],
    quantities: {},
    plannedDates: {},
    pinnedItems: {},
    totalCount: 0,
    currentPage: 1,
    lastPage: 1,
    perPage: 100,
    today: @js($today),
    fallbackExpectedArrivalDate: @js($defaultExpectedArrivalDate),
    orderChannel: 'AUTO',
    channelControlled: false,
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
    contractorChangeOpen: false,
    contractorChangeItemKey: null,
    contractorChangeSearch: '',
    contractorChangeOptions: [],
    contractorChangeLoading: false,

    updateItemNameTooltipPosition(event) {
        const padding = 16;
        this.itemNameTooltipX = Math.min(event.clientX + 14, window.innerWidth - 620 - padding);
        this.itemNameTooltipY = Math.min(event.clientY + 14, window.innerHeight - 160 - padding);
    },

    showItemNameTooltip(event, name) {
        this.hoveredItemName = name || null;
        this.updateItemNameTooltipPosition(event);
    },

    openColumnHelp(label) {
        this.columnHelpLabel = label;
    },

    closeColumnHelp() {
        this.columnHelpLabel = null;
    },

    openNoteModal(note) {
        this.noteModalText = String(note || '').trim() || '備考はありません。';
    },

    closeNoteModal() {
        this.noteModalText = null;
    },

    currentContractorChangeItem() {
        if (!this.contractorChangeItemKey) return null;

        return this.results.find(item => this.itemKey(item) === this.contractorChangeItemKey) || null;
    },

    async openCandidateContractorChange(item) {
        this.contractorChangeItemKey = this.itemKey(item);
        this.contractorChangeSearch = '';
        this.contractorChangeOptions = [];
        this.contractorChangeOpen = true;
        await this.loadCandidateContractorChangeOptions();
    },

    closeCandidateContractorChange() {
        this.contractorChangeOpen = false;
        this.contractorChangeItemKey = null;
        this.contractorChangeSearch = '';
        this.contractorChangeOptions = [];
        this.contractorChangeLoading = false;
    },

    async loadCandidateContractorChangeOptions() {
        const item = this.currentContractorChangeItem();
        if (!item) {
            this.contractorChangeOptions = [];
            return;
        }

        this.contractorChangeLoading = true;
        try {
            this.contractorChangeOptions = await $wire.searchCandidateContractorChangeOptions(
                Number(item.id || 0),
                this.contractorChangeSearch || null
            );
        } finally {
            this.contractorChangeLoading = false;
        }
    },

    selectCandidateContractor(option) {
        if (!option || !option.is_selectable) return;

        const item = this.currentContractorChangeItem();
        if (!item) return;

        const key = this.itemKey(item);
        item.contractor_id = option.contractor_id || null;
        item.item_contractor_warehouse_id = option.item_contractor_warehouse_id || null;
        item.contractor_code = option.contractor_code || '';
        item.contractor_name = option.contractor_label || option.contractor_name || '';
        item.contractor_raw_name = option.contractor_name || '';
        item.supplier_id = option.supplier_id || null;
        item.supplier_partner_id = option.supplier_partner_id || null;
        item.supplier_code = option.supplier_code || '';
        item.supplier_name = option.supplier_name || '';
        item.purchase_unit = Math.max(1, Number(option.purchase_unit || 1));
        item.safety_stock = Number(option.safety_stock || 0);
        item.item_contractor_note = option.item_contractor_note || '';
        item.is_eos_available = !!option.is_eos_available;
        item.item_contractor_linked = !!option.is_item_linked;
        item.order_channel = option.order_channel || (item.is_eos_available ? 'EOS' : 'FAX');

        if (option.default_expected_arrival_date) {
            this.plannedDates[key] = option.default_expected_arrival_date < this.today
                ? this.today
                : option.default_expected_arrival_date;
        }

        this.clearUnavailableQuantities();
        this.updatePinnedItems();
        this.syncToWire();
        this.closeCandidateContractorChange();
    },

    formatNumber(value) {
        const number = Number(value);
        return new Intl.NumberFormat('ja-JP').format(Number.isFinite(number) ? number : 0);
    },

    formatDecimal(value) {
        const number = Number(value);
        return (Number.isFinite(number) ? number : 0).toLocaleString('ja-JP', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },

    isTransferOrderItem(item) {
        return String(item.contractor_code) === '9012';
    },

    initOrderCandidateCreateItems() {
        this.channelControlled = false;
        this.orderChannel = 'AUTO';
        this.safeWireSet('orderCandidateItems', []);
    },

    setOrderChannel(channel) {
        this.orderChannel = 'AUTO';
    },

    isEosUnavailable(item) {
        return item.is_eos_available === false;
    },

    isUnhandledItem(item) {
        return !item.contractor_id;
    },

    itemKey(itemOrId) {
        const id = typeof itemOrId === 'object' ? itemOrId.id : itemOrId;

        return String(id);
    },

    canAddItem(item) {
        return !this.isTransferOrderItem(item) && !!item.contractor_id;
    },

    clearUnavailableQuantities() {
        for (const item of this.results) {
            if (this.canAddItem(item)) continue;

            const key = this.itemKey(item);
            if (this.quantities[key]) {
                this.quantities[key].caseQty = null;
                this.quantities[key].pieceQty = null;
            }
            delete this.pinnedItems[key];
        }
    },

    formatPackaging(item) {
        return String(item.packaging || '').trim() || '-';
    },

    formatShortDate(value) {
        if (!value) return '-';
        const text = String(value);
        const match = text.match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (match) return `${match[1]}年${match[2]}月${match[3]}日`;
        const shortMatch = text.match(/^(\d{2})\/(\d{2})\/(\d{2})/);
        return shortMatch ? `20${shortMatch[1]}年${shortMatch[2]}月${shortMatch[3]}日` : text;
    },

    orderTotalPieces(item) {
        const qty = this.getQty(item.id);
        const capacityCase = Math.max(1, Number(item.capacity_case || 1));
        const caseQty = Math.max(0, Number(qty.caseQty || 0));
        const pieceQty = Math.max(0, Number(qty.pieceQty || 0));

        return caseQty > 0 ? caseQty * capacityCase : pieceQty;
    },

    safeWireGet(path) {
        try {
            return $wire.get(path);
        } catch (error) {
            return null;
        }
    },

    safeWireSet(path, value) {
        try {
            $wire.set(path, value);
            return true;
        } catch (error) {
            return false;
        }
    },

    resolveWarehouseId() {
        return this.safeWireGet('mountedActions.0.data.warehouse_id') || this.safeWireGet('warehouseId');
    },

    async search(page = 1) {
        const warehouseId = this.resolveWarehouseId();
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
            const pinned = Object.entries(this.pinnedItems)
                .filter(([key, item]) => !newIds.has(String(item.id)))
                .map(([, item]) => item);
            this.results = [...pinned, ...result.data];

            this.results.forEach(item => {
                const key = this.itemKey(item);
                if (!(key in this.quantities)) {
                    this.quantities[key] = {
                        caseQty: null,
                        pieceQty: null,
                    };
                }
                this.plannedDateFor(item);
            });
            this.clearUnavailableQuantities();
            this.syncToWire();
        } finally {
            this.loading = false;
        }
    },

    updatePinnedItems() {
        this.results.forEach(item => {
            const key = this.itemKey(item);
            const qty = this.quantities[key];
            if (!this.canAddItem(item)) {
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
        this.filters = { itemCode: '', janCode: '', itemName: '', contractorId: '', category1Id: '', category2Id: '', category3Id: '', lastShippedFrom: @js($defaultLastShippedFrom), lastShippedTo: @js($defaultLastShippedTo) };
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
        this.plannedDates = {};
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

    plannedDateFor(item) {
        const key = this.itemKey(item);
        const defaultDate = item.default_expected_arrival_date || this.fallbackExpectedArrivalDate;
        if (!this.plannedDates[key]) {
            this.plannedDates[key] = defaultDate < this.today ? this.today : defaultDate;
        }
        if (this.plannedDates[key] < this.today) {
            this.plannedDates[key] = this.today;
        }

        return this.plannedDates[key];
    },

    setPlannedDate(item, value) {
        const key = this.itemKey(item);
        const date = value || item.default_expected_arrival_date || this.fallbackExpectedArrivalDate;
        this.plannedDates[key] = date < this.today ? this.today : date;
        this.syncToWire();
    },

    cleanQuantity(item, field, oppositeField) {
        if (!this.canAddItem(item)) {
            this.getQty(item.id).caseQty = null;
            this.getQty(item.id).pieceQty = null;
            return;
        }

        const qty = this.getQty(item.id);
        let value = String(qty[field] ?? '');
        value = value.replace(/[０-９]/g, char => String.fromCharCode(char.charCodeAt(0) - 0xFEE0));
        value = value.replace(/[^0-9]/g, '');
        qty[field] = value === '' ? null : parseInt(value, 10);
        if (Number(qty[field] || 0) > 0) {
            qty[oppositeField] = null;
        }
        this.onQtyChange();
    },

    commitQuantity(item, field, oppositeField) {
        this.cleanQuantity(item, field, oppositeField);
    },

    focusNextInput(event) {
        this.$nextTick(() => {
            const inputs = Array.from(this.$root.querySelectorAll('[data-order-quantity-input]'));
            const enabledInputs = inputs.filter(input => !input.disabled);
            const currentIndex = enabledInputs.indexOf(event.target);
            const nextInput = enabledInputs[currentIndex + 1] || enabledInputs[currentIndex];
            nextInput?.focus();
            nextInput?.select();
        });
    },

    focusAdjacentInput(event, direction) {
        this.$nextTick(() => {
            const inputs = Array.from(this.$root.querySelectorAll('[data-order-quantity-input]'));
            const currentIndex = inputs.indexOf(event.target);
            if (currentIndex < 0) return;

            const columnCount = 2;
            const step = direction === 'up'
                ? -columnCount
                : direction === 'down'
                    ? columnCount
                    : direction === 'left'
                        ? -1
                        : 1;

            let nextIndex = currentIndex + step;
            while (nextIndex >= 0 && nextIndex < inputs.length && inputs[nextIndex].disabled) {
                nextIndex += step > 0 ? 1 : -1;
            }

            const nextInput = inputs[nextIndex] || event.target;
            nextInput.focus();
            nextInput.select();
        });
    },

    syncToWire() {
        const items = [];
        for (const [key, qty] of Object.entries(this.quantities)) {
            const itemId = key;
            const item = this.results.find(r => String(r.id) === itemId);
            if (!item) continue;
            if (!this.canAddItem(item)) continue;
            const channel = item.order_channel || (item.is_eos_available ? 'EOS' : 'FAX');
            if ((qty.caseQty > 0) || (qty.pieceQty > 0)) {
                if (qty.caseQty > 0) {
                    items.push({
                        item_id: parseInt(itemId),
                        item_code: item.code,
                        item_name: item.name,
                        item_packaging: item.packaging || '',
                        item_contractor_note: item.item_contractor_note || '',
                        search_code: item.search_code || '',
                        ordering_code: item.ordering_code || '',
                        capacity_case: item.capacity_case || 1,
                        contractor_id: item.contractor_id || null,
                        item_contractor_warehouse_id: item.item_contractor_warehouse_id || null,
                        contractor_code: item.contractor_code || '',
                        contractor_name: item.contractor_raw_name || item.contractor_name || '',
                        supplier_id: item.supplier_id || null,
                        supplier_partner_id: item.supplier_partner_id || null,
                        supplier_code: item.supplier_code || '',
                        supplier_name: item.supplier_name || '',
                        purchase_unit: item.purchase_unit || 1,
                        default_expected_arrival_date: this.plannedDateFor(item),
                        quantity_type: 'CASE',
                        case_qty: qty.caseQty,
                        piece_qty: 0,
                        order_quantity: qty.caseQty,
                        order_channel: channel,
                        is_eos_available: !!item.is_eos_available,
                        item_contractor_linked: item.item_contractor_linked !== false,
                    });
                }
                if (qty.pieceQty > 0) {
                    items.push({
                        item_id: parseInt(itemId),
                        item_code: item.code,
                        item_name: item.name,
                        item_packaging: item.packaging || '',
                        item_contractor_note: item.item_contractor_note || '',
                        search_code: item.search_code || '',
                        ordering_code: item.ordering_code || '',
                        capacity_case: item.capacity_case || 1,
                        contractor_id: item.contractor_id || null,
                        item_contractor_warehouse_id: item.item_contractor_warehouse_id || null,
                        contractor_code: item.contractor_code || '',
                        contractor_name: item.contractor_raw_name || item.contractor_name || '',
                        supplier_id: item.supplier_id || null,
                        supplier_partner_id: item.supplier_partner_id || null,
                        supplier_code: item.supplier_code || '',
                        supplier_name: item.supplier_name || '',
                        purchase_unit: item.purchase_unit || 1,
                        default_expected_arrival_date: this.plannedDateFor(item),
                        quantity_type: 'PIECE',
                        case_qty: 0,
                        piece_qty: qty.pieceQty,
                        order_quantity: qty.pieceQty,
                        order_channel: channel,
                        is_eos_available: !!item.is_eos_available,
                        item_contractor_linked: item.item_contractor_linked !== false,
                    });
                }
            }
        }
        $wire.set('orderCandidateItems', items);
    },

    get validCount() {
        let count = 0;
        for (const [key, qty] of Object.entries(this.quantities)) {
            const item = this.results.find(r => String(r.id) === key);
            if (item && !this.canAddItem(item)) continue;
            if ((qty.caseQty > 0) || (qty.pieceQty > 0)) count++;
        }
        return count;
    },

    get eosUnavailableCount() {
        return this.results.filter(item => this.isEosUnavailable(item)).length;
    },

    getQty(itemId) {
        const key = this.itemKey(itemId);
        if (!(key in this.quantities)) {
            this.quantities[key] = { caseQty: null, pieceQty: null };
        }
        return this.quantities[key];
    }
}" x-init="initOrderCandidateCreateItems()" class="flex h-full min-h-0 flex-col gap-3">
    <div
        x-cloak
        x-show="hoveredItemName"
        x-transition.opacity.duration.100ms
        class="pointer-events-none fixed z-[9999] max-w-[620px] whitespace-normal rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold leading-6 text-slate-900 shadow-xl ring-1 ring-black/5 dark:border-slate-600 dark:bg-slate-900 dark:text-white"
        x-bind:style="`left: ${Math.max(16, itemNameTooltipX)}px; top: ${Math.max(16, itemNameTooltipY)}px;`"
        x-text="hoveredItemName"
    ></div>
    @include('filament.components.order-registration-column-help-modal')
    <div
        x-cloak
        x-show="noteModalText"
        class="fixed inset-0 z-[10030] flex items-center justify-center bg-slate-950/50 p-4"
    >
        <div class="w-full max-w-xl overflow-hidden rounded-lg bg-white shadow-xl dark:bg-gray-900">
            <div class="flex items-center justify-between bg-slate-800 px-4 py-3 text-white">
                <div class="text-sm font-semibold">商品発注先マスタ備考</div>
                <button type="button" x-on:click="closeNoteModal()" class="rounded p-1 text-white hover:bg-white/10">
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>
            <div class="whitespace-pre-wrap px-4 py-4 text-sm leading-6 text-slate-800 dark:text-gray-100" x-text="noteModalText"></div>
            <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
                <button type="button" x-on:click="closeNoteModal()" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">閉じる</button>
            </div>
        </div>
    </div>

    <div
        x-cloak
        x-show="contractorChangeOpen"
        class="fixed inset-0 z-[10030] flex items-center justify-center bg-slate-950/50 p-4"
    >
        <div class="flex max-h-[85vh] w-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white shadow-xl dark:bg-gray-900">
            <div class="flex items-center justify-between bg-slate-800 px-4 py-3 text-white">
                <div class="text-sm font-semibold">発注先変更</div>
                <button type="button" x-on:click="closeCandidateContractorChange()" class="rounded p-1 text-white hover:bg-white/10">
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>
            <div class="min-h-0 flex-1 space-y-3 overflow-y-auto p-4">
                <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200">
                    <template x-if="currentContractorChangeItem()">
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                            <span>
                                商品
                                <span class="font-mono font-semibold" x-text="'[' + currentContractorChangeItem().code + ']'"></span>
                                <span class="font-semibold" x-text="currentContractorChangeItem().name"></span>
                            </span>
                            <span>
                                現在
                                <span class="font-semibold" x-text="currentContractorChangeItem().contractor_name || '-'"></span>
                            </span>
                        </div>
                    </template>
                </div>

                <div class="rounded-md border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                    <div class="flex items-end gap-2">
                        <div class="min-w-0 flex-1">
                            <label class="mb-1 block text-xs font-semibold text-slate-600 dark:text-slate-300">発注先検索</label>
                            <input
                                type="text"
                                x-model="contractorChangeSearch"
                                x-on:keydown.enter.prevent="loadCandidateContractorChangeOptions()"
                                placeholder="発注先CD・名前で検索"
                                class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-slate-600 dark:bg-gray-900 dark:text-gray-100"
                            >
                        </div>
                        <button type="button" x-on:click="loadCandidateContractorChangeOptions()" class="rounded-md bg-slate-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-700">
                            検索
                        </button>
                    </div>
                    <div class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                        91番倉庫の商品発注先を先頭に表示します。商品未紐づきの発注先を選択した場合はFAX専用になります。
                    </div>
                </div>

                <div class="min-h-0 overflow-y-auto rounded-md border border-slate-200 dark:border-slate-700" style="max-height: 420px;">
                    <table class="w-full divide-y divide-slate-200 text-xs dark:divide-slate-700">
                        <thead class="sticky top-0 z-10 bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                            <tr>
                                <th class="whitespace-nowrap px-3 py-2 text-left font-semibold">優先</th>
                                <th class="whitespace-nowrap px-3 py-2 text-left font-semibold">発注先CD</th>
                                <th class="px-3 py-2 text-left font-semibold">発注先名</th>
                                <th class="px-3 py-2 text-left font-semibold">仕入先</th>
                                <th class="whitespace-nowrap px-3 py-2 text-left font-semibold">判定</th>
                                <th class="whitespace-nowrap px-3 py-2 text-center font-semibold">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr x-show="contractorChangeLoading">
                                <td colspan="6" class="px-4 py-8 text-center text-sm text-slate-400">
                                    発注先を検索中...
                                </td>
                            </tr>
                            <template x-for="option in contractorChangeOptions" :key="String(option.contractor_id) + '-' + String(option.supplier_id)">
                                <tr class="odd:bg-white even:bg-[#f5f9ff] dark:odd:bg-slate-900 dark:even:bg-[#1e2a3b]">
                                    <td class="whitespace-nowrap px-3 py-2 text-slate-600 dark:text-slate-300" x-text="option.priority_label || '-'"></td>
                                    <td class="whitespace-nowrap px-3 py-2 font-mono text-slate-700 dark:text-slate-200" x-text="option.contractor_code || '-'"></td>
                                    <td class="px-3 py-2 font-semibold text-slate-900 dark:text-white" x-text="option.contractor_name || '-'"></td>
                                    <td class="px-3 py-2 text-slate-700 dark:text-slate-200" x-text="option.supplier_label || option.supplier_name || '-'"></td>
                                    <td class="whitespace-nowrap px-3 py-2">
                                        <span x-show="option.is_item_linked && option.is_eos_available" class="inline-flex rounded bg-blue-100 px-2 py-0.5 text-[11px] font-bold text-blue-700 dark:bg-blue-900/40 dark:text-blue-200">EOS可</span>
                                        <span x-show="option.is_item_linked && !option.is_eos_available" class="inline-flex rounded bg-green-100 px-2 py-0.5 text-[11px] font-bold text-green-700 dark:bg-green-900/40 dark:text-green-200">FAX専用</span>
                                        <span x-show="!option.is_item_linked" class="inline-flex rounded bg-green-100 px-2 py-0.5 text-[11px] font-bold text-green-700 dark:bg-green-900/40 dark:text-green-200">商品未紐づき / FAX専用</span>
                                        <span x-show="!option.is_selectable" class="ml-1 inline-flex rounded bg-red-100 px-2 py-0.5 text-[11px] font-bold text-red-700 dark:bg-red-900/40 dark:text-red-200">仕入先未設定</span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-center">
                                        <button
                                            type="button"
                                            x-on:click="selectCandidateContractor(option)"
                                            x-bind:disabled="!option.is_selectable"
                                            class="rounded-md bg-danger-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-danger-500 disabled:bg-slate-300 disabled:text-slate-500 dark:disabled:bg-slate-700 dark:disabled:text-slate-400"
                                        >
                                            選択
                                        </button>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="!contractorChangeLoading && contractorChangeOptions.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center text-sm text-slate-400">
                                    表示できる発注先がありません
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
                <button type="button" x-on:click="closeCandidateContractorChange()" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">変更せず閉じる</button>
            </div>
        </div>
    </div>

    <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <span>
                発注店:
                <span class="font-semibold">{{ $selectedWarehouseLabel }}</span>
            </span>
            <span>
                候補表示:
                <span class="font-mono font-semibold">最大100件</span>
            </span>
            <span x-show="searched">
                件数:
                <span class="font-mono font-semibold" x-text="formatNumber(totalCount) + '件'"></span>
            </span>
            <span x-show="searched">
                FAX専用:
                <span class="font-mono font-semibold text-amber-700 dark:text-amber-300" x-text="formatNumber(eosUnavailableCount) + '件'"></span>
            </span>
            <div class="ml-auto flex items-center gap-2">
                <button type="button" wire:click="closeCandidateSearchModal" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800">追加せず閉じる</button>
                <button type="button" wire:click="addOrderCandidateItems" class="rounded-md bg-danger-600 px-4 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-danger-500">
                    追加する
                </button>
            </div>
        </div>
    </div>

    {{-- 検索フィルタ --}}
    <div class="space-y-2 rounded-md border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
        <div class="flex items-center justify-between gap-3">
            <div class="text-xs font-semibold text-slate-700 dark:text-slate-200">商品検索フィルタ</div>
            <div class="text-xs font-semibold text-gray-500 dark:text-gray-400">
                <span x-text="validCount"></span>件の商品を追加予定
            </div>
        </div>
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
    <div x-show="searched && !loading" x-cloak class="flex min-h-0 flex-1 flex-col">
        <div class="min-h-0 flex-1 overflow-x-scroll overflow-y-auto rounded-md border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <table class="logistics-candidate-table divide-y divide-slate-200 text-xs dark:divide-slate-700" style="table-layout: fixed; width: 1660px; min-width: 1660px;">
                <colgroup>
                    <col class="logistics-candidate-contractor-col" style="width: 180px !important;">
                    <col class="logistics-candidate-code-col" style="width: 64px !important;">
                    <col class="logistics-candidate-item-name-col" style="width: 220px !important;">
                    <col class="logistics-candidate-packaging-col" style="width: 82px !important;">
                    <col class="logistics-candidate-location-col" style="width: 78px !important;">
                    <col class="logistics-candidate-number-col" style="width: 54px !important;">
                    <col class="logistics-candidate-order-qty-col" style="width: 74px !important;">
                    <col class="logistics-candidate-order-qty-col" style="width: 74px !important;">
                    <col class="logistics-candidate-number-col" style="width: 74px !important;">
                    <col class="logistics-candidate-number-col" style="width: 92px !important;">
                    <col class="logistics-candidate-number-col" style="width: 110px !important;">
                    <col class="logistics-candidate-date-col" style="width: 136px !important;">
                    <col class="logistics-candidate-date-col" style="width: 136px !important;">
                    <col class="logistics-candidate-number-col" style="width: 54px !important;">
                    <col class="logistics-candidate-number-col" style="width: 54px !important;">
                    <col class="logistics-candidate-number-col" style="width: 54px !important;">
                    <col class="logistics-candidate-number-col" style="width: 54px !important;">
                    <col class="logistics-candidate-number-col" style="width: 54px !important;">
                </colgroup>
                <thead class="sticky top-0 z-10 bg-slate-100 text-slate-700 shadow-sm dark:bg-slate-800 dark:text-slate-200">
                    <tr>
                        <th class="whitespace-nowrap px-2 py-1.5 text-left font-semibold" style="width: 180px !important; min-width: 180px !important; max-width: 180px !important;"><x-order-registration.column-help-heading label="発注先" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-left font-semibold"><x-order-registration.column-help-heading label="商品CD" /></th>
                        <th class="logistics-candidate-item-name px-2 py-1.5 text-left font-semibold" style="width: 220px !important; min-width: 220px !important; max-width: 220px !important;"><x-order-registration.column-help-heading label="商品名" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-left font-semibold"><x-order-registration.column-help-heading label="規格" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-left font-semibold"><x-order-registration.column-help-heading label="棚番" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold"><x-order-registration.column-help-heading label="発注点" align="right" /></th>
                        <th class="logistics-candidate-order-qty whitespace-nowrap border-l-2 border-slate-300 bg-amber-100 px-1 py-1.5 text-right font-semibold text-amber-900 dark:border-slate-600 dark:bg-amber-900/40 dark:text-amber-100"><x-order-registration.column-help-heading label="ケース" align="right" /></th>
                        <th class="logistics-candidate-order-qty whitespace-nowrap bg-amber-100 px-1 py-1.5 text-right font-semibold text-amber-900 dark:bg-amber-900/40 dark:text-amber-100"><x-order-registration.column-help-heading label="バラ" align="right" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold" style="width: 74px !important; min-width: 74px !important; max-width: 74px !important;"><x-order-registration.column-help-heading label="総バラ" align="right" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold" style="width: 92px !important; min-width: 92px !important; max-width: 92px !important;"><x-order-registration.column-help-heading label="理論在庫" align="right" /></th>
                        <th class="px-2 py-1.5 text-right font-semibold" style="width: 110px !important; min-width: 110px !important; max-width: 110px !important;">
                            <div class="space-y-0.5 leading-4">
                                <x-order-registration.column-help-heading label="納品予定数" align="right" />
                                <x-order-registration.column-help-heading label="見込在庫" align="right" />
                            </div>
                        </th>
                        <th class="px-2 py-1.5 text-center font-semibold">
                            <div class="space-y-0.5 leading-4">
                                <x-order-registration.column-help-heading label="最終発注日" align="center" />
                                <x-order-registration.column-help-heading label="納品予定日" align="center" />
                            </div>
                        </th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-center font-semibold"><x-order-registration.column-help-heading label="予定日" align="center" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold"><x-order-registration.column-help-heading label="1週" align="right" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold"><x-order-registration.column-help-heading label="2週" align="right" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold"><x-order-registration.column-help-heading label="3週" align="right" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold"><x-order-registration.column-help-heading label="前月" align="right" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-center font-semibold"><x-order-registration.column-help-heading label="備考" align="center" /></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <template x-for="(item, index) in results" :key="item.id">
                        <tr
                            :class="index % 2 === 0 ? 'bg-white dark:bg-slate-900' : 'bg-[#f5f9ff] dark:bg-[#1e2a3b]'"
                            class="hover:bg-amber-50 dark:hover:bg-amber-950/30"
                        >
                            <td class="px-2 py-1.5 text-slate-700 dark:text-slate-200" style="width: 180px !important; min-width: 180px !important; max-width: 180px !important;">
                                <span x-show="String(item.contractor_code) === '9012'" class="block text-[10px] font-bold text-red-600 dark:text-red-400">移動発注対象</span>
                                <span x-show="isUnhandledItem(item)" class="inline-flex rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-bold text-red-700 dark:bg-red-900/40 dark:text-red-200">取扱なし</span>
                                <span x-show="!isUnhandledItem(item) && item.is_eos_available === false" class="inline-flex rounded bg-green-100 px-1.5 py-0.5 text-[10px] font-bold text-green-700 dark:bg-green-900/40 dark:text-green-200">FAX専用</span>
                                <span x-show="!isUnhandledItem(item) && item.is_eos_available === true" class="inline-flex rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-bold text-blue-700 dark:bg-blue-900/40 dark:text-blue-200">EOS可</span>
                                <span class="block whitespace-normal break-words leading-4" x-text="item.contractor_name || item.supplier_name || '-'"></span>
                                <button
                                    type="button"
                                    x-show="isTransferOrderItem(item)"
                                    x-on:click.stop="openCandidateContractorChange(item)"
                                    class="mt-1 rounded-md border border-slate-300 bg-white px-2 py-0.5 text-[11px] font-semibold text-slate-700 shadow-sm hover:border-slate-400 hover:bg-slate-50 dark:border-slate-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800"
                                >
                                    発注先変更
                                </button>
                            </td>
                            <td class="whitespace-nowrap px-2 py-1.5 font-mono text-slate-700 dark:text-slate-200" x-text="item.code"></td>
                            <td
                                class="logistics-candidate-item-name px-2 py-1.5 font-medium text-slate-900 dark:text-white"
                                style="width: 220px !important; min-width: 220px !important; max-width: 220px !important;"
                            >
                                <span
                                    class="block cursor-help whitespace-normal leading-4"
                                    style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"
                                    x-text="item.name"
                                    x-on:mouseenter="showItemNameTooltip($event, item.name)"
                                    x-on:mousemove="updateItemNameTooltipPosition($event)"
                                    x-on:mouseleave="hoveredItemName = null"
                                ></span>
                            </td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-slate-600 dark:text-slate-300" x-text="formatPackaging(item)"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 font-mono text-slate-700 dark:text-slate-200" x-text="item.default_location_code || '-'"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="formatNumber(item.safety_stock)"></td>
                            <td class="logistics-candidate-order-qty whitespace-nowrap border-l-2 border-slate-300 bg-amber-50 px-1 py-1.5 text-center dark:border-slate-600 dark:bg-amber-950/30">
                                <input
                                    type="text"
                                    inputmode="numeric"
                                    pattern="[0-9]*"
                                    autocomplete="off"
                                    :value="getQty(item.id).caseQty"
                                    x-bind:disabled="!canAddItem(item)"
                                    x-on:focus="$event.target.select()"
                                    x-on:input.debounce.150ms="getQty(item.id).caseQty = $event.target.value; cleanQuantity(item, 'caseQty', 'pieceQty')"
                                    x-on:blur="commitQuantity(item, 'caseQty', 'pieceQty')"
                                    x-on:change="commitQuantity(item, 'caseQty', 'pieceQty')"
                                    x-on:keydown.arrow-up.prevent="commitQuantity(item, 'caseQty', 'pieceQty'); focusAdjacentInput($event, 'up')"
                                    x-on:keydown.arrow-down.prevent="commitQuantity(item, 'caseQty', 'pieceQty'); focusAdjacentInput($event, 'down')"
                                    x-on:keydown.arrow-left.prevent="commitQuantity(item, 'caseQty', 'pieceQty'); focusAdjacentInput($event, 'left')"
                                    x-on:keydown.arrow-right.prevent="commitQuantity(item, 'caseQty', 'pieceQty'); focusAdjacentInput($event, 'right')"
                                    x-on:keydown.enter.prevent="commitQuantity(item, 'caseQty', 'pieceQty'); focusNextInput($event)"
                                    x-on:keydown.tab.prevent="commitQuantity(item, 'caseQty', 'pieceQty'); focusNextInput($event)"
                                    data-order-quantity-input
                                    class="w-[58px] rounded-md border-2 border-amber-300 bg-white px-1 py-0.5 text-right text-sm font-semibold text-slate-900 shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-200 disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400 dark:border-amber-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-amber-500 dark:focus:ring-amber-900 dark:disabled:border-slate-700 dark:disabled:bg-slate-800"
                                >
                            </td>
                            <td class="logistics-candidate-order-qty whitespace-nowrap bg-amber-50 px-1 py-1.5 text-center dark:bg-amber-950/30">
                                <input
                                    type="text"
                                    inputmode="numeric"
                                    pattern="[0-9]*"
                                    autocomplete="off"
                                    :value="getQty(item.id).pieceQty"
                                    x-bind:disabled="!canAddItem(item)"
                                    x-on:focus="$event.target.select()"
                                    x-on:input.debounce.150ms="getQty(item.id).pieceQty = $event.target.value; cleanQuantity(item, 'pieceQty', 'caseQty')"
                                    x-on:blur="commitQuantity(item, 'pieceQty', 'caseQty')"
                                    x-on:change="commitQuantity(item, 'pieceQty', 'caseQty')"
                                    x-on:keydown.arrow-up.prevent="commitQuantity(item, 'pieceQty', 'caseQty'); focusAdjacentInput($event, 'up')"
                                    x-on:keydown.arrow-down.prevent="commitQuantity(item, 'pieceQty', 'caseQty'); focusAdjacentInput($event, 'down')"
                                    x-on:keydown.arrow-left.prevent="commitQuantity(item, 'pieceQty', 'caseQty'); focusAdjacentInput($event, 'left')"
                                    x-on:keydown.arrow-right.prevent="commitQuantity(item, 'pieceQty', 'caseQty'); focusAdjacentInput($event, 'right')"
                                    x-on:keydown.enter.prevent="commitQuantity(item, 'pieceQty', 'caseQty'); focusNextInput($event)"
                                    x-on:keydown.tab.prevent="commitQuantity(item, 'pieceQty', 'caseQty'); focusNextInput($event)"
                                    data-order-quantity-input
                                    class="w-[58px] rounded-md border-2 border-amber-300 bg-white px-1 py-0.5 text-right text-sm font-semibold text-slate-900 shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-200 disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400 dark:border-amber-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-amber-500 dark:focus:ring-amber-900 dark:disabled:border-slate-700 dark:disabled:bg-slate-800"
                                >
                            </td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono font-bold text-sky-700 dark:text-sky-300" style="width: 74px !important; min-width: 74px !important; max-width: 74px !important;" x-text="formatNumber(orderTotalPieces(item))"></td>
                            <td class="whitespace-nowrap px-2 py-1.5" style="width: 92px !important; min-width: 92px !important; max-width: 92px !important;">
                                <div class="flex justify-end">
                                    <button
                                        type="button"
                                        x-on:click.stop="$wire.openWarehouseStockModal(Number(item.id || 0))"
                                        title="他倉庫在庫を見る"
                                        class="inline-flex min-w-14 justify-end rounded-md border border-sky-300 bg-sky-50 px-2 py-0.5 font-mono text-sm font-bold text-sky-800 shadow-sm hover:border-sky-500 hover:bg-sky-100 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-sky-700 dark:bg-sky-950/40 dark:text-sky-200 dark:hover:border-sky-500 dark:hover:bg-sky-900/50"
                                        x-text="formatNumber(item.effective_stock)"
                                    ></button>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" style="width: 110px !important; min-width: 110px !important; max-width: 110px !important;">
                                <div class="space-y-0.5 leading-4">
                                    <div x-text="formatNumber(item.incoming_qty)"></div>
                                    <div x-text="formatNumber(item.projected_stock)"></div>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-center font-mono text-slate-700 dark:text-slate-200">
                                <div class="space-y-0.5 leading-4">
                                    <div x-text="formatShortDate(item.last_order_date)"></div>
                                    <div x-text="formatShortDate(item.incoming_expected_arrival_date)"></div>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-1 py-1.5 text-center">
                                <input
                                    type="date"
                                    :value="plannedDateFor(item)"
                                    :min="today"
                                    x-bind:disabled="!canAddItem(item)"
                                    x-on:change="setPlannedDate(item, $event.target.value)"
                                    class="w-[128px] rounded-md border border-slate-300 bg-white px-1 py-0.5 text-xs font-mono text-slate-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-200 disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400 dark:border-slate-600 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-primary-500 dark:focus:ring-primary-900 dark:disabled:border-slate-700 dark:disabled:bg-slate-800"
                                >
                            </td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="formatNumber(item.sales_week1_qty)"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="formatNumber(item.sales_week2_qty)"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="formatNumber(item.sales_week3_qty)"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="formatNumber(item.previous_month_sales_qty)"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-center">
                                <button type="button" x-on:click.stop="openNoteModal(item.item_contractor_note)" class="rounded-md border border-slate-300 bg-white px-2 py-0.5 text-[11px] font-semibold text-slate-700 hover:bg-slate-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">表示</button>
                            </td>
                        </tr>
                    </template>
                    <template x-if="results.length === 0">
                        <tr>
                            <td colspan="15" class="px-4 py-8 text-center text-sm text-slate-400 dark:text-slate-500">
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

</div>
