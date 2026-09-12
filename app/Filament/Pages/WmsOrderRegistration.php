<?php

namespace App\Filament\Pages;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderChannel;
use App\Enums\AutoOrder\OrderDataFileChannel;
use App\Enums\AutoOrder\OrderEntrySource;
use App\Enums\AutoOrder\TransmissionType;
use App\Enums\EMenu;
use App\Enums\EVolumeUnit;
use App\Enums\QuantityType;
use App\Filament\Support\AdminPage;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\ItemCategory;
use App\Models\Sakemaru\ItemContractor;
use App\Models\StatsItemWarehouseSalesSummary;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderDataFile;
use App\Models\WmsWarehouseAutoOrderSetting;
use App\Services\AutoOrder\OrderRegistrationListPdfService;
use App\Services\AutoOrder\OrderRegistrationSearchService;
use App\Services\AutoOrder\OrderRegistrationService;
use App\Services\AutoOrder\PurchaseOrderPdfService;
use App\Services\AutoOrder\PurchasePriceService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class WmsOrderRegistration extends AdminPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentPlus;

    protected static ?string $slug = 'wms-order-registration';

    protected static string $permissionResource = 'wms-order-candidate';

    protected string $view = 'filament.pages.wms-order-registration';

    private const LINE_DUPLICATE_WAREHOUSE_CODES = [
        '01',
        '02',
        '03',
        '04',
        '06',
        '07',
        '08',
        '09',
        '11',
        '21',
        '22',
        '71',
        '72',
        '73',
        '74',
        '75',
        '80',
        '90',
        '91',
        '92',
        '93',
        '94',
        '95',
        '96',
        '97',
        '98',
    ];

    public string $orderChannel = 'EOS';

    public ?int $warehouseId = null;

    public array $warehouses = [];

    public array $category2Options = [];

    public array $orderCandidateItems = [];

    public bool $showCandidateSearchModal = false;

    public bool $showSalesHistoryModal = false;

    public bool $showSalesBasedExternalOrderPreviewModal = false;

    public bool $showContractorChangeModal = false;

    public ?int $contractorChangeLineIndex = null;

    public string $contractorChangeSearch = '';

    public array $contractorChangeRows = [];

    public bool $showLineDuplicateModal = false;

    public ?int $duplicateLineIndex = null;

    public array $duplicateWarehouseIds = [];

    public string $itemSearch = '';

    public string $contractorSearch = '';

    public ?int $category2Id = null;

    public array $searchRows = [];

    public array $searchQuantities = [];

    public string $salesStartDate = '';

    public string $salesEndDate = '';

    public string $salesContractorSearch = '';

    public ?int $salesCategory2Id = null;

    public array $salesRows = [];

    public array $salesQuantities = [];

    public ?string $salesSearchError = null;

    public array $externalOrderContractorsData = [];

    public array $externalOrderJxContractorsData = [];

    public array $externalOrderOtherContractorsData = [];

    public array $selectedExternalOrderContractorIds = [];

    public array $externalOrderCategory2Data = [];

    public array $selectedExternalOrderCategory2Ids = [];

    public array $salesBasedExternalOrderPreviewRows = [];

    public array $salesBasedExternalOrderPreviewConditions = [];

    public ?string $salesBasedExternalOrderPreviewError = null;

    public array $lines = [];

    public string $lineContractorFilter = '';

    public array $selectedRegistrationLineIndexes = [];

    public string $bulkExpectedArrivalDate = '';

    public bool $showCompletionFaxDownloadModal = false;

    public ?int $completionFaxDownloadDataFileId = null;

    public string $completionFaxCommunicationNotes = '';

    public bool $showCompletionDetailModal = false;

    public ?int $completionDetailDataFileId = null;

    public bool $showWarehouseStockModal = false;

    public ?int $warehouseStockItemId = null;

    public string $warehouseStockItemCode = '';

    public string $warehouseStockItemName = '';

    public array $warehouseStockRows = [];

    public array $completionResult = [];

    public bool $isConfirming = false;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_ORDER_REGISTRATION->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_ORDER_REGISTRATION->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_ORDER_REGISTRATION->sort();
    }

    public function getTitle(): string|HtmlString
    {
        return EMenu::WMS_ORDER_REGISTRATION->label();
    }

    public function getHeading(): string|HtmlString|null
    {
        return null;
    }

    public function mount(OrderRegistrationSearchService $searchService): void
    {
        $this->warehouses = $searchService->warehouseOptions();
        $this->category2Options = $searchService->category2Options();
        $this->warehouseId = auth()->user()?->getSelectedWarehouseId();
        $this->salesStartDate = now()->subDays(2)->toDateString();
        $this->salesEndDate = now()->toDateString();
    }

    public function defaultOrderExpectedArrivalDate(): string
    {
        return $this->fallbackExpectedArrivalDate();
    }

    public function setOrderChannel(string $channel): void
    {
        if (! OrderChannel::tryFrom($channel)) {
            return;
        }

        $this->orderChannel = $channel;
        $this->resetSearchState();
    }

    public function openCandidateSearchModal(): void
    {
        $this->showCandidateSearchModal = true;
        $this->orderCandidateItems = [];
        $this->searchRows = [];
        $this->searchQuantities = [];
    }

    public function closeCandidateSearchModal(): void
    {
        $this->showCandidateSearchModal = false;
        $this->orderCandidateItems = [];
    }

    public function openSalesHistoryModal(): void
    {
        $this->showSalesHistoryModal = true;
        $this->showSalesBasedExternalOrderPreviewModal = false;
        $this->salesRows = [];
        $this->salesQuantities = [];
        $this->salesSearchError = null;
        $this->resetSalesBasedExternalOrderPreview();
        $this->initializeExternalOrderContractors();
        $this->initializeExternalOrderCategory2();
        $this->selectedExternalOrderContractorIds = [];
        $this->selectedExternalOrderCategory2Ids = [];
    }

    public function closeSalesHistoryModal(): void
    {
        $this->showSalesHistoryModal = false;
    }

    public function closeSalesBasedExternalOrderPreviewModal(): void
    {
        $this->showSalesBasedExternalOrderPreviewModal = false;
        $this->resetSalesBasedExternalOrderPreview();
    }

    public function openContractorChangeModal(int $index): void
    {
        if (! isset($this->lines[$index])) {
            $this->notifyWarning('変更対象の明細が見つかりません。');

            return;
        }

        $this->contractorChangeLineIndex = $index;
        $this->contractorChangeSearch = '';
        $this->showContractorChangeModal = true;
        $this->searchContractorChangeCandidates();
    }

    public function closeContractorChangeModal(): void
    {
        $this->showContractorChangeModal = false;
        $this->contractorChangeLineIndex = null;
        $this->contractorChangeSearch = '';
        $this->contractorChangeRows = [];
    }

    public function openWarehouseStockModal(int $itemId): void
    {
        $warehouseId = (int) $this->warehouseId;
        if ($warehouseId < 1 || $itemId < 1) {
            $this->notifyWarning('他倉庫在庫を確認できませんでした。');

            return;
        }

        $item = Item::query()
            ->select(['id', 'code', 'name'])
            ->find($itemId);

        if (! $item) {
            $this->notifyWarning('商品が見つかりません。');

            return;
        }

        $this->warehouseStockItemId = (int) $item->id;
        $this->warehouseStockItemCode = (string) $item->code;
        $this->warehouseStockItemName = (string) $item->name;
        $this->warehouseStockRows = $this->otherWarehouseStockRows((int) $item->id, $warehouseId);
        $this->showWarehouseStockModal = true;
    }

    public function closeWarehouseStockModal(): void
    {
        $this->showWarehouseStockModal = false;
        $this->warehouseStockItemId = null;
        $this->warehouseStockItemCode = '';
        $this->warehouseStockItemName = '';
        $this->warehouseStockRows = [];
    }

    public function searchContractorChangeCandidates(): void
    {
        if ($this->contractorChangeLineIndex === null || ! isset($this->lines[$this->contractorChangeLineIndex])) {
            $this->contractorChangeRows = [];

            return;
        }

        $line = $this->lines[$this->contractorChangeLineIndex];
        $itemId = (int) ($line['item_id'] ?? 0);
        $warehouseId = (int) ($line['warehouse_id'] ?? $this->warehouseId ?? 0);
        if ($itemId < 1 || $warehouseId < 1) {
            $this->contractorChangeRows = [];

            return;
        }

        $search = trim(mb_convert_kana($this->contractorChangeSearch, 'as'));
        $this->contractorChangeRows = $this->searchCandidateContractorChangeOptions(
            $itemId,
            $search !== '' ? $search : null
        );
    }

    public function applyContractorChange(int $contractorId, ?int $supplierId = null, ?int $itemContractorWarehouseId = null): void
    {
        if ($this->contractorChangeLineIndex === null || ! isset($this->lines[$this->contractorChangeLineIndex])) {
            $this->notifyWarning('変更対象の明細が見つかりません。');

            return;
        }

        $lineIndex = $this->contractorChangeLineIndex;
        $line = $this->lines[$lineIndex];
        $itemId = (int) ($line['item_id'] ?? 0);
        $warehouseId = (int) ($line['warehouse_id'] ?? $this->warehouseId ?? 0);
        $contractor = DB::connection('sakemaru')
            ->table('contractors as c')
            ->leftJoin('suppliers as default_suppliers', 'default_suppliers.id', '=', 'c.supplier_id')
            ->leftJoin('partners as default_supplier_partners', 'default_supplier_partners.id', '=', 'default_suppliers.partner_id')
            ->where('c.id', $contractorId)
            ->first([
                'c.id as contractor_id',
                'c.code as contractor_code',
                'c.name as contractor_name',
                'c.supplier_id as default_supplier_id',
                'default_suppliers.partner_id as default_supplier_partner_id',
                'default_supplier_partners.code as default_supplier_code',
                'default_supplier_partners.name as default_supplier_name',
            ]);

        if (! $contractor || $itemId < 1 || $warehouseId < 1) {
            $this->notifyWarning('発注先を変更できませんでした。');

            return;
        }

        $searchService = app(OrderRegistrationSearchService::class);
        $incomingWarehouseId = $searchService->incomingWarehouseId($warehouseId);
        $selectedSupplierId = (int) ($supplierId ?? 0);
        $selectedItemContractorWarehouseId = (int) ($itemContractorWarehouseId ?? 0);
        $itemContractor = ItemContractor::query()
            ->where('warehouse_id', $selectedItemContractorWarehouseId > 0 ? $selectedItemContractorWarehouseId : $incomingWarehouseId)
            ->where('item_id', $itemId)
            ->where('contractor_id', $contractorId)
            ->when($selectedSupplierId > 0, fn ($query) => $query->where('supplier_id', $selectedSupplierId))
            ->with(['supplier.partner'])
            ->first();

        $isItemLinked = $itemContractor !== null;
        $supplier = $itemContractor?->supplier;
        $supplierPartner = $supplier?->partner;
        $supplierId = (int) ($supplier?->id ?? $contractor->default_supplier_id ?? 0);
        if ($supplierId < 1) {
            $this->notifyWarning('この発注先に紐づく仕入先がありません。');

            return;
        }

        $isEosAvailable = $isItemLinked && $searchService->isJxContractor($contractorId);
        $currentChannel = OrderChannel::tryFrom((string) ($line['order_channel'] ?? '')) ?? OrderChannel::FAX;
        $orderChannel = $isEosAvailable
            ? $currentChannel
            : OrderChannel::FAX;
        $expectedArrivalDate = $searchService->defaultExpectedArrivalDate($warehouseId, $contractorId, $orderChannel);

        $line['contractor_id'] = $contractorId;
        $line['contractor_code'] = (string) ($contractor->contractor_code ?? '');
        $line['contractor_name'] = (string) ($contractor->contractor_name ?? '');
        $line['supplier_id'] = $supplierId;
        $line['supplier_partner_id'] = (int) ($supplier?->partner_id ?? $contractor->default_supplier_partner_id ?? 0);
        $line['supplier_code'] = (string) ($supplierPartner?->code ?? $contractor->default_supplier_code ?? '');
        $line['supplier_name'] = (string) ($supplierPartner?->name ?? $contractor->default_supplier_name ?? '-');
        $line['purchase_unit'] = max(1, (int) ($itemContractor?->purchase_unit ?? $line['purchase_unit'] ?? 1));
        $line['default_expected_arrival_date'] = $expectedArrivalDate;
        $line['expected_arrival_date'] = $expectedArrivalDate;
        $line['is_eos_available'] = $isEosAvailable;
        $line['item_contractor_linked'] = $isItemLinked;
        $line['item_contractor_warehouse_id'] = $itemContractor ? (int) $itemContractor->warehouse_id : null;
        $line['order_channel'] = $orderChannel->value;
        $line['order_channel_label'] = $orderChannel->label();
        $quantityType = QuantityType::tryFrom((string) ($line['quantity_type'] ?? '')) ?? QuantityType::PIECE;
        $purchasePrice = $this->purchasePriceForLine($itemId, (int) ($line['supplier_partner_id'] ?? 0), $warehouseId, $quantityType);
        $line['purchase_unit_price'] = $purchasePrice['price'];
        $line['purchase_unit_price_source'] = $purchasePrice['source'];

        $this->lines[$lineIndex] = $line;
        $this->closeContractorChangeModal();

        Notification::make()
            ->title('発注先を変更しました')
            ->body($isItemLinked ? null : '商品に紐づかない発注先のため、この明細はFAX発注固定にしました。')
            ->success()
            ->send();
    }

    public function searchItemCandidates(): void
    {
        $searchService = app(OrderRegistrationSearchService::class);

        if (! $this->warehouseId) {
            $this->notifyWarning('倉庫を選択してください');

            return;
        }

        $this->searchRows = $searchService->searchItems(
            (int) $this->warehouseId,
            $this->orderChannelEnum(),
            [
                'item_search' => $this->itemSearch,
                'contractor_search' => $this->contractorSearch,
                'category2_id' => $this->category2Id,
            ],
        );
        $this->searchQuantities = [];

        if ($this->searchRows === []) {
            $this->notifyWarning($this->orderChannel === OrderChannel::EOS->value
                ? 'EOS発注対象の候補がありません'
                : '条件に一致する候補がありません');
        }
    }

    public function searchSalesHistoryCandidates(): void
    {
        $searchService = app(OrderRegistrationSearchService::class);

        if (! $this->warehouseId) {
            $this->notifyWarning('倉庫を選択してください');

            return;
        }

        $result = $searchService->salesHistoryCandidates(
            (int) $this->warehouseId,
            $this->orderChannelEnum(),
            [
                'sales_start_date' => $this->salesStartDate,
                'sales_end_date' => $this->salesEndDate,
                'category2_id' => $this->salesCategory2Id,
                'contractor_search' => $this->salesContractorSearch,
            ],
        );

        $this->salesRows = $result['rows'];
        $this->salesSearchError = $result['error'];
        $this->salesQuantities = [];

        if ($this->salesSearchError) {
            $this->notifyWarning($this->salesSearchError);
        }
    }

    public function addSearchRow(string $key): void
    {
        $row = collect($this->searchRows)->firstWhere('key', $key);
        if (! $row) {
            return;
        }

        $quantities = $this->searchQuantities[$key] ?? [];
        $this->addLine($row, $quantities, OrderEntrySource::SEARCH);
    }

    public function addSalesRow(string $key): void
    {
        $row = collect($this->salesRows)->firstWhere('key', $key);
        if (! $row) {
            return;
        }

        $quantities = $this->salesQuantities[$key] ?? [];
        if (blank($quantities['case'] ?? null) && blank($quantities['piece'] ?? null)) {
            $quantities['piece'] = (int) ($row['order_piece_qty'] ?? 0);
        }

        $this->addLine($row, $quantities, OrderEntrySource::SALES_HISTORY);
    }

    public function resetSalesBasedExternalOrderPreview(): void
    {
        $this->salesBasedExternalOrderPreviewRows = [];
        $this->salesBasedExternalOrderPreviewConditions = [];
        $this->salesBasedExternalOrderPreviewError = null;
    }

    public function calculateSalesBasedExternalOrderPreview(): void
    {
        $this->resetSalesBasedExternalOrderPreview();

        if (! $this->warehouseId) {
            $this->salesBasedExternalOrderPreviewError = '倉庫が選択されていません。';
            $this->notifyWarning($this->salesBasedExternalOrderPreviewError);

            return;
        }

        $selectedWarehouseId = (int) $this->warehouseId;
        $contractorIds = array_values(array_unique(array_map('intval', $this->selectedExternalOrderContractorIds)));
        $jxContractorIds = collect($this->externalOrderJxContractorsData ?: $this->getExternalOrderJxContractorsForSalesBasedGeneration())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->toArray();
        $category2Ids = array_values(array_unique(array_map('intval', $this->selectedExternalOrderCategory2Ids)));
        $allCategory2Ids = collect($this->externalOrderCategory2Data ?: $this->getExternalOrderCategory2ForSalesBasedGeneration())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->toArray();

        if ($contractorIds === []) {
            $this->salesBasedExternalOrderPreviewError = '仕入先を1件以上選択してください。';
            $this->notifyWarning($this->salesBasedExternalOrderPreviewError);

            return;
        }

        if ($category2Ids === []) {
            $this->salesBasedExternalOrderPreviewError = '中分類を1件以上選択してください。';
            $this->notifyWarning($this->salesBasedExternalOrderPreviewError);

            return;
        }

        try {
            $startDate = Carbon::parse($this->salesStartDate ?: now()->subDays(2)->toDateString())->toDateString();
            $endDate = Carbon::parse($this->salesEndDate ?: now()->toDateString())->toDateString();
        } catch (\Throwable) {
            $this->salesBasedExternalOrderPreviewError = '販売実績の期間を正しく指定してください。';
            $this->notifyWarning($this->salesBasedExternalOrderPreviewError);

            return;
        }

        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $days = max(1, Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1);
        $warehouseIds = $this->getSalesBasedExternalOrderGenerationWarehouseIds($selectedWarehouseId);
        if ($warehouseIds === []) {
            $this->salesBasedExternalOrderPreviewError = '対象倉庫がありません。';
            $this->notifyWarning($this->salesBasedExternalOrderPreviewError);

            return;
        }

        $searchService = app(OrderRegistrationSearchService::class);

        $internalContractorIds = WmsContractorSetting::query()
            ->where('transmission_type', TransmissionType::INTERNAL->value)
            ->pluck('contractor_id')
            ->toArray();

        $salesSubquery = DB::connection('sakemaru')
            ->table('stats_item_warehouse_daily_sales')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereBetween('business_date', [$startDate, $endDate])
            ->selectRaw('
                item_id,
                SUM(shipped_piece_qty) as sales_qty,
                SUM(sales_piece_qty) as sales_piece_qty,
                SUM(return_piece_qty) as return_piece_qty,
                SUM(transfer_piece_qty) as transfer_piece_qty
            ')
            ->groupBy('item_id')
            ->havingRaw('SUM(shipped_piece_qty) > 0');

        $stockSubquery = DB::connection('sakemaru')
            ->table('real_stocks')
            ->where('warehouse_id', $selectedWarehouseId)
            ->selectRaw('item_id, SUM(available_quantity) as effective_stock')
            ->groupBy('item_id');

        $incomingSubquery = DB::connection('sakemaru')
            ->table('wms_order_incoming_schedules')
            ->join('items as incoming_items', 'incoming_items.id', '=', 'wms_order_incoming_schedules.item_id')
            ->where('wms_order_incoming_schedules.warehouse_id', $selectedWarehouseId)
            ->whereIn('wms_order_incoming_schedules.status', ['PENDING', 'PARTIAL'])
            ->whereRaw('(wms_order_incoming_schedules.expected_quantity - wms_order_incoming_schedules.received_quantity) > 0')
            ->selectRaw('
                wms_order_incoming_schedules.item_id,
                SUM(
                    CASE wms_order_incoming_schedules.quantity_type
                        WHEN \'CASE\' THEN GREATEST(wms_order_incoming_schedules.expected_quantity - wms_order_incoming_schedules.received_quantity, 0) * GREATEST(COALESCE(incoming_items.capacity_case, 1), 1)
                        WHEN \'CARTON\' THEN GREATEST(wms_order_incoming_schedules.expected_quantity - wms_order_incoming_schedules.received_quantity, 0) * GREATEST(COALESCE(incoming_items.capacity_carton, 1), 1)
                        ELSE GREATEST(wms_order_incoming_schedules.expected_quantity - wms_order_incoming_schedules.received_quantity, 0)
                    END
                ) as incoming_qty
            ')
            ->groupBy('wms_order_incoming_schedules.item_id');

        $lastOrderArrivalSubquery = DB::connection('sakemaru')
            ->table('wms_order_incoming_schedules')
            ->where('warehouse_id', $selectedWarehouseId)
            ->whereNotIn('status', [
                IncomingScheduleStatus::CANCELLED->value,
                IncomingScheduleStatus::PARTIAL_CANCELLED->value,
                IncomingScheduleStatus::DELETED->value,
            ])
            ->whereNotNull('expected_arrival_date')
            ->selectRaw('
                item_id,
                SUBSTRING_INDEX(
                    GROUP_CONCAT(
                        expected_arrival_date
                        ORDER BY order_date DESC, expected_arrival_date DESC, id DESC
                    ),
                    \',\',
                    1
                ) as incoming_expected_arrival_date
            ')
            ->groupBy('item_id');

        $targetItemContractorsSubquery = DB::connection('sakemaru')
            ->table('item_contractors')
            ->where('warehouse_id', $selectedWarehouseId)
            ->when($internalContractorIds !== [], fn ($query) => $query->whereNotIn('contractor_id', $internalContractorIds))
            ->whereIn('contractor_id', $contractorIds)
            ->selectRaw('
                warehouse_id,
                item_id,
                contractor_id,
                MIN(supplier_id) as supplier_id,
                MAX(COALESCE(purchase_unit, 1)) as purchase_unit,
                MAX(COALESCE(safety_stock, 0)) as safety_stock,
                MAX(note) as item_contractor_note
            ')
            ->groupBy('warehouse_id', 'item_id', 'contractor_id');

        $query = DB::connection('sakemaru')
            ->query()
            ->fromSub($targetItemContractorsSubquery, 'item_contractors')
            ->join('items', 'item_contractors.item_id', '=', 'items.id')
            ->leftJoin('item_categories as item_category2', 'item_category2.id', '=', 'items.item_category2_id')
            ->join('contractors', 'item_contractors.contractor_id', '=', 'contractors.id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'item_contractors.supplier_id')
            ->leftJoin('partners as supplier_partners', 'supplier_partners.id', '=', 'suppliers.partner_id')
            ->joinSub($salesSubquery, 'sales', function ($join): void {
                $join->on('sales.item_id', '=', 'item_contractors.item_id');
            })
            ->leftJoinSub($stockSubquery, 'stocks', function ($join): void {
                $join->on('stocks.item_id', '=', 'item_contractors.item_id');
            })
            ->leftJoinSub($incomingSubquery, 'incoming', function ($join): void {
                $join->on('incoming.item_id', '=', 'item_contractors.item_id');
            })
            ->leftJoinSub($lastOrderArrivalSubquery, 'last_order_arrivals', function ($join): void {
                $join->on('last_order_arrivals.item_id', '=', 'item_contractors.item_id');
            })
            ->where('items.end_of_sale_type', 'NORMAL')
            ->where('items.is_ended', false)
            ->where(fn ($query) => $query->whereNull('items.start_of_sale_date')->orWhere('items.start_of_sale_date', '<=', now()->toDateString()))
            ->where(fn ($query) => $query->whereNull('items.end_of_sale_date')->orWhere('items.end_of_sale_date', '>', now()->toDateString()))
            ->where('contractors.is_auto_change_order', true)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('item_search_information as isi')
                    ->whereColumn('isi.item_id', 'item_contractors.item_id')
                    ->where('isi.is_used_for_ordering', true)
                    ->where('isi.is_active', true)
                    ->whereRaw("isi.search_string REGEXP '[1-9]'");
            });

        if (count($category2Ids) < count($allCategory2Ids)) {
            $query->whereIn('items.item_category2_id', $category2Ids);
        }

        $previewRows = $query
            ->selectRaw('
                item_contractors.warehouse_id as warehouse_id,
                item_contractors.item_id as item_id,
                item_contractors.contractor_id as contractor_id,
                item_contractors.supplier_id as supplier_id,
                suppliers.partner_id as supplier_partner_id,
                item_category2.code as item_category2_code,
                items.code as item_code,
                items.name as item_name,
                items.packaging as item_packaging,
                items.volume as item_volume,
                items.volume_unit as item_volume_unit,
                items.capacity_case as capacity_case,
                contractors.code as contractor_code,
                contractors.name as contractor_name,
                supplier_partners.name as supplier_name,
                sales.sales_qty as sales_qty,
                sales.sales_piece_qty as sales_piece_qty,
                sales.return_piece_qty as return_piece_qty,
                sales.transfer_piece_qty as transfer_piece_qty,
                COALESCE(stocks.effective_stock, 0) as effective_stock,
                COALESCE(incoming.incoming_qty, 0) as incoming_qty,
                last_order_arrivals.incoming_expected_arrival_date as incoming_expected_arrival_date,
                (COALESCE(stocks.effective_stock, 0) + COALESCE(incoming.incoming_qty, 0)) as projected_stock,
                GREATEST(sales.sales_qty - (COALESCE(stocks.effective_stock, 0) + COALESCE(incoming.incoming_qty, 0)), 0) as shortage_qty,
                COALESCE(item_contractors.purchase_unit, 1) as purchase_unit,
                COALESCE(item_contractors.safety_stock, 0) as safety_stock,
                item_contractors.item_contractor_note as item_contractor_note
            ')
            ->orderBy('contractors.code')
            ->orderBy('supplier_partners.code')
            ->orderBy('items.code')
            ->get();

        $previewItemIds = $previewRows
            ->pluck('item_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $lastOrderDates = $this->lastOrderDates($selectedWarehouseId, $previewItemIds);
        $weeklySalesQuantities = $this->weeklySalesQuantities($selectedWarehouseId, $previewItemIds);
        $defaultLocationCodes = $this->defaultLocationCodes($selectedWarehouseId, $previewItemIds);

        $this->salesBasedExternalOrderPreviewRows = $previewRows
            ->map(function ($row) use ($days, $defaultLocationCodes, $jxContractorIds, $lastOrderDates, $searchService, $selectedWarehouseId, $weeklySalesQuantities): array {
                $contractorId = (int) $row->contractor_id;
                $itemId = (int) $row->item_id;
                $isEosAvailable = in_array($contractorId, $jxContractorIds, true);
                $orderChannel = $isEosAvailable ? OrderChannel::EOS : OrderChannel::FAX;
                $defaultExpectedArrivalDate = $searchService->defaultExpectedArrivalDate($selectedWarehouseId, $contractorId, $orderChannel);
                $weeklySales = $weeklySalesQuantities[$itemId] ?? [
                    'sales_week1_qty' => 0,
                    'sales_week2_qty' => 0,
                    'sales_week3_qty' => 0,
                    'previous_month_sales_qty' => 0,
                ];

                return [
                    'warehouse_id' => (int) $row->warehouse_id,
                    'item_id' => $itemId,
                    'contractor_id' => $contractorId,
                    'supplier_id' => (int) ($row->supplier_id ?? 0),
                    'supplier_partner_id' => (int) ($row->supplier_partner_id ?? 0),
                    'item_category2_code' => (string) ($row->item_category2_code ?? ''),
                    'item_code' => (string) $row->item_code,
                    'item_name' => (string) $row->item_name,
                    'item_packaging' => $this->itemStandardLabel($row->item_volume ?? null, $row->item_volume_unit ?? null, (string) ($row->item_packaging ?? '')),
                    'capacity_case' => max(1, (int) ($row->capacity_case ?? 1)),
                    'item_contractor_note' => (string) ($row->item_contractor_note ?? ''),
                    'default_location_code' => (string) ($defaultLocationCodes[$itemId] ?? ''),
                    'contractor_code' => (string) $row->contractor_code,
                    'contractor_name' => (string) $row->contractor_name,
                    'supplier_name' => (string) ($row->supplier_name ?? '-'),
                    'sales_qty' => (int) $row->sales_qty,
                    'sales_piece_qty' => (int) $row->sales_piece_qty,
                    'return_piece_qty' => (int) $row->return_piece_qty,
                    'transfer_piece_qty' => (int) $row->transfer_piece_qty,
                    'last_order_date' => $lastOrderDates[$itemId] ?? null,
                    'sales_week1_qty' => (int) $weeklySales['sales_week1_qty'],
                    'sales_week2_qty' => (int) $weeklySales['sales_week2_qty'],
                    'sales_week3_qty' => (int) $weeklySales['sales_week3_qty'],
                    'previous_month_sales_qty' => (int) $weeklySales['previous_month_sales_qty'],
                    'daily_avg_qty' => round(((int) $row->sales_qty) / $days, 2),
                    'safety_stock' => (int) $row->safety_stock,
                    'effective_stock' => (int) $row->effective_stock,
                    'incoming_qty' => (int) $row->incoming_qty,
                    'incoming_expected_arrival_date' => filled($row->incoming_expected_arrival_date)
                        ? Carbon::parse((string) $row->incoming_expected_arrival_date)->toDateString()
                        : null,
                    'projected_stock' => (int) $row->projected_stock,
                    'purchase_unit' => max(1, (int) $row->purchase_unit),
                    'order_piece_qty' => (int) $row->shortage_qty,
                    'default_expected_arrival_date' => $defaultExpectedArrivalDate,
                    'order_channel' => $orderChannel->value,
                    'is_eos_available' => $isEosAvailable,
                    'input_order_case_qty' => null,
                    'input_order_piece_qty' => null,
                ];
            })
            ->toArray();

        if ($this->salesBasedExternalOrderPreviewRows === []) {
            $this->salesBasedExternalOrderPreviewError = '現在の条件に該当する候補がありません。';
            $this->notifyWarning($this->salesBasedExternalOrderPreviewError);

            return;
        }

        $defaultExpectedArrivalDate = $this->earliestExpectedArrivalDateFromRows($this->salesBasedExternalOrderPreviewRows)
            ?? $this->defaultExpectedArrivalDateForContractors($selectedWarehouseId, $contractorIds, $jxContractorIds, OrderChannel::EOS);

        $this->salesBasedExternalOrderPreviewConditions = [
            'expected_arrival_date' => $defaultExpectedArrivalDate,
            'sales_start_date' => $startDate,
            'sales_end_date' => $endDate,
            'selected_warehouse_name' => $this->selectedWarehouseLabel(),
            'target_warehouse_name' => '外部発注',
            'auto_order_flag_filter' => '考慮しない',
            'order_channel' => '自動判定',
            'days' => $days,
            'contractor_count' => count($contractorIds),
            'category2_count' => count($category2Ids),
            'category2_total_count' => count($allCategory2Ids),
        ];

        $this->showSalesHistoryModal = false;
        $this->showSalesBasedExternalOrderPreviewModal = true;
    }

    public function updateSalesBasedExternalOrderPreviewRows(array $rows): void
    {
        if ($rows === [] && $this->salesBasedExternalOrderPreviewRows !== []) {
            return;
        }

        $this->salesBasedExternalOrderPreviewRows = collect($rows)
            ->map(function (array $row): array {
                $inputCaseQuantity = $row['input_order_case_qty'] ?? null;
                $inputPieceQuantity = $row['input_order_piece_qty'] ?? null;
                $row['input_order_case_qty'] = ($inputCaseQuantity === null || $inputCaseQuantity === '')
                    ? null
                    : max(0, (int) $inputCaseQuantity);
                $row['input_order_piece_qty'] = ($inputPieceQuantity === null || $inputPieceQuantity === '')
                    ? null
                    : max(0, (int) $inputPieceQuantity);
                try {
                    $expectedArrivalDate = $row['default_expected_arrival_date'] ?? null;
                    $row['default_expected_arrival_date'] = filled($expectedArrivalDate)
                        ? Carbon::parse((string) $expectedArrivalDate)->toDateString()
                        : null;
                } catch (\Throwable) {
                    $row['default_expected_arrival_date'] = null;
                }

                return $row;
            })
            ->values()
            ->toArray();
    }

    public function updateSalesBasedExternalOrderPreviewExpectedArrivalDate(?string $date): void
    {
        try {
            $this->salesBasedExternalOrderPreviewConditions['expected_arrival_date'] = Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            $this->salesBasedExternalOrderPreviewConditions['expected_arrival_date'] =
                $this->earliestExpectedArrivalDateFromRows($this->salesBasedExternalOrderPreviewRows)
                ?? $this->fallbackExpectedArrivalDate();
        }
    }

    public function addSalesBasedExternalOrderPreviewRowsToRegistration(): void
    {
        if ($this->salesBasedExternalOrderPreviewRows === []) {
            $this->notifyWarning('追加対象の候補がありません');

            return;
        }

        try {
            $expectedArrivalDate = Carbon::parse(
                $this->salesBasedExternalOrderPreviewConditions['expected_arrival_date'] ?? $this->fallbackExpectedArrivalDate()
            )->toDateString();
        } catch (\Throwable) {
            Notification::make()
                ->title('入荷予定日を正しく指定してください')
                ->danger()
                ->send();

            return;
        }

        $searchService = app(OrderRegistrationSearchService::class);
        $created = 0;
        $skipped = 0;
        $blankSkipped = 0;

        foreach ($this->salesBasedExternalOrderPreviewRows as $row) {
            $isEosAvailable = (bool) ($row['is_eos_available'] ?? false);
            $orderChannel = OrderChannel::tryFrom((string) ($row['order_channel'] ?? ''))
                ?? ($isEosAvailable ? OrderChannel::EOS : OrderChannel::FAX);
            if ($orderChannel === OrderChannel::EOS && ! $isEosAvailable) {
                $orderChannel = OrderChannel::FAX;
            }

            $inputCaseQuantity = $row['input_order_case_qty'] ?? null;
            $inputPieceQuantity = $row['input_order_piece_qty'] ?? null;
            if (($inputCaseQuantity === null || $inputCaseQuantity === '') && ($inputPieceQuantity === null || $inputPieceQuantity === '')) {
                $blankSkipped++;

                continue;
            }

            $caseQuantity = max(0, (int) $inputCaseQuantity);
            $pieceQuantity = max(0, (int) $inputPieceQuantity);
            if ($caseQuantity > 0 && $pieceQuantity > 0) {
                $skipped++;

                continue;
            }

            try {
                $rowExpectedArrivalDate = filled($row['default_expected_arrival_date'] ?? null)
                    ? Carbon::parse((string) $row['default_expected_arrival_date'])->toDateString()
                    : $expectedArrivalDate;
            } catch (\Throwable) {
                $skipped++;

                continue;
            }

            if (Carbon::parse($rowExpectedArrivalDate)->lt(Carbon::today())) {
                $skipped++;

                continue;
            }

            $lineRow = [
                'item_id' => (int) ($row['item_id'] ?? 0),
                'item_code' => (string) ($row['item_code'] ?? ''),
                'item_name' => (string) ($row['item_name'] ?? ''),
                'item_packaging' => (string) ($row['item_packaging'] ?? ''),
                'capacity_case' => max(1, (int) ($row['capacity_case'] ?? 1)),
                'item_contractor_note' => (string) ($row['item_contractor_note'] ?? ''),
                'contractor_id' => (int) ($row['contractor_id'] ?? 0),
                'contractor_code' => (string) ($row['contractor_code'] ?? ''),
                'contractor_name' => (string) ($row['contractor_name'] ?? ''),
                'supplier_id' => (int) ($row['supplier_id'] ?? 0),
                'supplier_partner_id' => (int) ($row['supplier_partner_id'] ?? 0),
                'supplier_name' => (string) ($row['supplier_name'] ?? '-'),
                'search_code' => $searchService->searchCodeForItem((int) ($row['item_id'] ?? 0)),
                'ordering_code' => $searchService->orderingCodeForItem((int) ($row['item_id'] ?? 0)),
                'purchase_unit' => max(1, (int) ($row['purchase_unit'] ?? 1)),
                'default_expected_arrival_date' => $rowExpectedArrivalDate,
                'order_piece_qty' => (int) ($row['order_piece_qty'] ?? 0),
                'sales_qty' => (int) ($row['sales_qty'] ?? 0),
                'order_channel' => $orderChannel->value,
                'is_eos_available' => $isEosAvailable,
                'item_contractor_linked' => true,
            ];

            if ($this->addLine($lineRow, ['case' => $caseQuantity, 'piece' => $pieceQuantity], OrderEntrySource::SALES_HISTORY, false)) {
                $created++;
            } else {
                $skipped++;
            }
        }

        if ($created <= 0) {
            Notification::make()
                ->title('登録リストに追加できませんでした')
                ->body(collect([
                    $blankSkipped > 0 ? "未入力の候補 {$blankSkipped}件 は追加しませんでした。" : null,
                    $skipped > 0 ? "不正な候補など {$skipped}件 はスキップしました。" : null,
                ])->filter()->implode("\n") ?: null)
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title("販売履歴から {$created}件 を登録リストに追加しました")
            ->body(collect([
                $blankSkipped > 0 ? "未入力の候補 {$blankSkipped}件 は追加しませんでした。" : null,
                $skipped > 0 ? "不正な候補など {$skipped}件 はスキップしました。" : null,
            ])->filter()->implode("\n") ?: null)
            ->success()
            ->send();

        $this->showSalesBasedExternalOrderPreviewModal = false;
        $this->resetSalesBasedExternalOrderPreview();
    }

    public function addOrderCandidateItems(): void
    {
        if (! $this->warehouseId) {
            $this->notifyWarning('倉庫を選択してください');

            return;
        }

        if ($this->orderCandidateItems === []) {
            $this->notifyWarning('商品を追加してください');

            return;
        }

        $searchService = app(OrderRegistrationSearchService::class);
        $warehouseId = (int) $this->warehouseId;
        $incomingWarehouseId = $searchService->incomingWarehouseId($warehouseId);
        $jxContractorIds = $searchService->jxContractorIds();
        $created = 0;
        $errors = [];

        foreach ($this->orderCandidateItems as $itemData) {
            $itemId = (int) ($itemData['item_id'] ?? 0);
            $itemCode = (string) ($itemData['item_code'] ?? '');
            $caseQty = max(0, (int) ($itemData['case_qty'] ?? 0));
            $pieceQty = max(0, (int) ($itemData['piece_qty'] ?? 0));
            $requestedChannel = OrderChannel::tryFrom((string) ($itemData['order_channel'] ?? ''));

            if ($itemId < 1) {
                $errors[] = '[商品不明]: 商品情報が不正です';

                continue;
            }

            if ($caseQty <= 0 && $pieceQty <= 0) {
                $quantityType = QuantityType::tryFrom((string) ($itemData['quantity_type'] ?? ''));
                $orderQuantity = max(0, (int) ($itemData['order_quantity'] ?? 0));
                if ($quantityType === QuantityType::CASE) {
                    $caseQty = $orderQuantity;
                } else {
                    $pieceQty = $orderQuantity;
                }
            }

            if ($caseQty <= 0 && $pieceQty <= 0) {
                $errors[] = "[{$itemCode}]: 発注数量を入力してください";

                continue;
            }

            $item = Item::query()
                ->select(['id', 'code', 'name', 'packaging', 'volume', 'volume_unit', 'capacity_case', 'end_of_sale_type', 'is_ended'])
                ->where('id', $itemId)
                ->where('end_of_sale_type', 'NORMAL')
                ->where('is_ended', false)
                ->first();

            if (! $item) {
                $errors[] = "[{$itemCode}]: 発注できない商品です";

                continue;
            }

            $contractorId = (int) ($itemData['contractor_id'] ?? 0);
            $supplierId = (int) ($itemData['supplier_id'] ?? 0);
            $itemContractorLinked = array_key_exists('item_contractor_linked', $itemData)
                ? (bool) $itemData['item_contractor_linked']
                : true;
            $itemContractors = ItemContractor::query()
                ->where('warehouse_id', $incomingWarehouseId)
                ->where('item_id', $itemId)
                ->when($contractorId > 0, fn ($query) => $query->where('contractor_id', $contractorId))
                ->when($supplierId > 0, fn ($query) => $query->where('supplier_id', $supplierId))
                ->with(['contractor', 'supplier.partner'])
                ->orderBy('contractor_id')
                ->orderBy('supplier_id')
                ->get();
            $itemContractor = $requestedChannel === OrderChannel::EOS
                ? ($itemContractors->first(fn (ItemContractor $itemContractor): bool => in_array((int) $itemContractor->contractor_id, $jxContractorIds, true)) ?? $itemContractors->first())
                : $itemContractors->first();

            $itemContractorWarehouseId = (int) ($itemData['item_contractor_warehouse_id'] ?? 0);
            if (! $itemContractor && $itemContractorLinked && $itemContractorWarehouseId > 0) {
                $itemContractor = ItemContractor::query()
                    ->where('warehouse_id', $itemContractorWarehouseId)
                    ->where('item_id', $itemId)
                    ->when($contractorId > 0, fn ($query) => $query->where('contractor_id', $contractorId))
                    ->when($supplierId > 0, fn ($query) => $query->where('supplier_id', $supplierId))
                    ->with(['contractor', 'supplier.partner'])
                    ->orderBy('contractor_id')
                    ->orderBy('supplier_id')
                    ->first();
            }

            if (! $itemContractor || ! $itemContractor->contractor) {
                if ($itemContractorLinked) {
                    $errors[] = "[{$item->code}] {$item->name}: 発注先未設定";

                    continue;
                }

                $contractor = DB::connection('sakemaru')
                    ->table('contractors as c')
                    ->leftJoin('suppliers as default_suppliers', 'default_suppliers.id', '=', 'c.supplier_id')
                    ->leftJoin('partners as default_supplier_partners', 'default_supplier_partners.id', '=', 'default_suppliers.partner_id')
                    ->where('c.id', $contractorId)
                    ->first([
                        'c.id as contractor_id',
                        'c.code as contractor_code',
                        'c.name as contractor_name',
                        'c.supplier_id as default_supplier_id',
                        'default_suppliers.partner_id as default_supplier_partner_id',
                        'default_supplier_partners.code as default_supplier_code',
                        'default_supplier_partners.name as default_supplier_name',
                    ]);

                $supplierId = (int) ($itemData['supplier_id'] ?? $contractor?->default_supplier_id ?? 0);
                if (! $contractor || $contractorId < 1 || $supplierId < 1) {
                    $errors[] = "[{$item->code}] {$item->name}: 発注先に紐づく仕入先がありません";

                    continue;
                }

                $isEosAvailable = false;
                $requestedChannel = OrderChannel::FAX;
                $effectiveContractorId = $contractorId;
                $contractorCode = (string) ($contractor->contractor_code ?? '');
                $contractorName = (string) ($contractor->contractor_name ?? $itemData['contractor_name'] ?? '');
                $supplierPartnerId = (int) ($itemData['supplier_partner_id'] ?? $contractor->default_supplier_partner_id ?? 0);
                $supplierCode = (string) ($itemData['supplier_code'] ?? $contractor->default_supplier_code ?? '');
                $supplierName = (string) ($itemData['supplier_name'] ?? $contractor->default_supplier_name ?? '-');
                $purchaseUnit = max(1, (int) ($itemData['purchase_unit'] ?? 1));
                $itemContractorNote = (string) ($itemData['item_contractor_note'] ?? '');
            } else {
                $isEosAvailable = in_array((int) $itemContractor->contractor_id, $jxContractorIds, true);
                $effectiveContractorId = (int) $itemContractor->contractor_id;
                $contractorCode = (string) $itemContractor->contractor->code;
                $contractorName = (string) $itemContractor->contractor->name;
                $supplierId = (int) ($itemContractor->supplier_id ?? 0);
                $supplierPartnerId = (int) ($itemContractor->supplier?->partner_id ?? 0);
                $supplierCode = (string) ($itemContractor->supplier?->partner?->code ?? '');
                $supplierName = (string) ($itemContractor->supplier?->partner?->name ?? '-');
                $purchaseUnit = max(1, (int) ($itemContractor->purchase_unit ?? 1));
                $itemContractorNote = (string) ($itemContractor->item_contractor_note ?? $itemContractor->note ?? '');
            }

            $requestedChannel ??= $isEosAvailable ? OrderChannel::EOS : OrderChannel::FAX;
            if ($requestedChannel === OrderChannel::EOS && ! $isEosAvailable) {
                $requestedChannel = OrderChannel::FAX;
            }

            $defaultExpectedArrivalDate = $searchService->defaultExpectedArrivalDate($warehouseId, $effectiveContractorId, $requestedChannel);
            try {
                $expectedArrivalDate = filled($itemData['default_expected_arrival_date'] ?? null)
                    ? Carbon::parse((string) $itemData['default_expected_arrival_date'])->toDateString()
                    : $defaultExpectedArrivalDate;
            } catch (\Throwable) {
                $errors[] = "[{$item->code}] {$item->name}: 予定日を正しく指定してください";

                continue;
            }

            if (Carbon::parse($expectedArrivalDate)->lt(Carbon::today())) {
                $errors[] = "[{$item->code}] {$item->name}: 予定日は本日以降を選択してください";

                continue;
            }

            $row = [
                'item_id' => (int) $item->id,
                'item_code' => (string) $item->code,
                'item_name' => (string) $item->name,
                'item_packaging' => $this->itemStandardLabel($item->volume ?? null, $item->volume_unit ?? null, (string) ($item->packaging ?? '')),
                'capacity_case' => max(1, (int) ($item->capacity_case ?? 1)),
                'item_contractor_note' => $itemContractorNote,
                'contractor_id' => $effectiveContractorId,
                'contractor_code' => $contractorCode,
                'contractor_name' => $contractorName,
                'supplier_id' => $supplierId,
                'supplier_partner_id' => $supplierPartnerId,
                'supplier_code' => $supplierCode,
                'supplier_name' => $supplierName,
                'search_code' => filled($itemData['search_code'] ?? null)
                    ? (string) $itemData['search_code']
                    : $searchService->searchCodeForItem((int) $item->id),
                'ordering_code' => filled($itemData['ordering_code'] ?? null)
                    ? (string) $itemData['ordering_code']
                    : $searchService->orderingCodeForItem((int) $item->id),
                'purchase_unit' => $purchaseUnit,
                'default_expected_arrival_date' => $expectedArrivalDate,
                'item_contractor_warehouse_id' => $itemContractor
                    ? (int) $itemContractor->warehouse_id
                    : ($itemContractorWarehouseId > 0 ? $itemContractorWarehouseId : null),
                'current_stock' => $searchService->availableStock($warehouseId, (int) $item->id),
                'incoming_quantity' => $searchService->incomingQuantity($incomingWarehouseId, (int) $item->id),
                'order_channel' => $requestedChannel->value,
                'is_eos_available' => $isEosAvailable,
                'item_contractor_linked' => $itemContractorLinked && $itemContractor !== null,
            ];

            if ($this->addLine($row, ['case' => $caseQty, 'piece' => $pieceQty], OrderEntrySource::SEARCH, false)) {
                $created++;
            }
        }

        $this->orderCandidateItems = [];

        if ($created > 0) {
            $this->showCandidateSearchModal = false;
            $notification = Notification::make()
                ->title("{$created}件を登録リストに追加しました")
                ->body($errors === [] ? null : implode("\n", array_slice($errors, 0, 5)));

            ($errors === [] ? $notification->success() : $notification->warning())->send();

            return;
        }

        Notification::make()
            ->title('追加できませんでした')
            ->body(implode("\n", array_slice($errors, 0, 5)))
            ->danger()
            ->send();
    }

    public function searchContractorsForOrderCreate(string $search): array
    {
        $search = trim(mb_convert_kana($search, 'as'));
        if ($search === '' || (mb_strlen($search) < 2 && ! preg_match('/^\d+$/', $search))) {
            return [];
        }

        return Contractor::query()
            ->select(['id', 'code', 'name'])
            ->where(function ($query) use ($search): void {
                $query->where('code', 'like', "{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            })
            ->orderBy('code')
            ->limit(30)
            ->get()
            ->map(fn (Contractor $contractor): array => [
                'id' => (int) $contractor->id,
                'code' => (string) $contractor->code,
                'name' => (string) $contractor->name,
                'label' => "[{$contractor->code}]{$contractor->name}",
            ])
            ->toArray();
    }

    public function searchCandidateContractorChangeOptions(int $itemId, ?string $search = null): array
    {
        if ($itemId < 1) {
            return [];
        }

        $searchService = app(OrderRegistrationSearchService::class);
        $selectedWarehouseId = (int) ($this->warehouseId ?? 0);
        $warehouse91Id = (int) (DB::connection('sakemaru')
            ->table('warehouses')
            ->where('code', '91')
            ->value('id') ?? 0);
        $priorityWarehouseId = $warehouse91Id > 0 ? $searchService->incomingWarehouseId($warehouse91Id) : 0;
        $jxContractorIds = $searchService->jxContractorIds();

        $priorityItemContractors = $priorityWarehouseId > 0
            ? ItemContractor::query()
                ->where('warehouse_id', $priorityWarehouseId)
                ->where('item_id', $itemId)
                ->with(['contractor', 'supplier.partner'])
                ->orderBy('contractor_id')
                ->orderBy('supplier_id')
                ->get()
            : collect();

        $options = $priorityItemContractors
            ->map(fn (ItemContractor $itemContractor): ?array => $this->candidateContractorChangeOptionFromItemContractor(
                $itemContractor,
                $selectedWarehouseId,
                $jxContractorIds,
                '91倉庫'
            ))
            ->filter()
            ->values();

        $search = trim(mb_convert_kana((string) $search, 'as'));
        if ($search !== '' && (mb_strlen($search) >= 2 || preg_match('/^\d+$/', $search))) {
            $contractorRows = DB::connection('sakemaru')
                ->table('contractors as c')
                ->leftJoin('suppliers as default_suppliers', 'default_suppliers.id', '=', 'c.supplier_id')
                ->leftJoin('partners as default_supplier_partners', 'default_supplier_partners.id', '=', 'default_suppliers.partner_id')
                ->where(function ($query) use ($search): void {
                    $query->where('c.code', 'like', "{$search}%")
                        ->orWhere('c.name', 'like', "%{$search}%")
                        ->orWhere('c.id', (int) $search);
                })
                ->orderBy('c.code')
                ->limit(50)
                ->get([
                    'c.id as contractor_id',
                    'c.code as contractor_code',
                    'c.name as contractor_name',
                    'c.supplier_id as default_supplier_id',
                    'default_suppliers.partner_id as default_supplier_partner_id',
                    'default_supplier_partners.code as default_supplier_code',
                    'default_supplier_partners.name as default_supplier_name',
                ]);
            $contractorIds = $contractorRows
                ->pluck('contractor_id')
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->values()
                ->all();
            $linkedByContractor = $priorityWarehouseId > 0 && $contractorIds !== []
                ? ItemContractor::query()
                    ->where('warehouse_id', $priorityWarehouseId)
                    ->where('item_id', $itemId)
                    ->whereIn('contractor_id', $contractorIds)
                    ->with(['contractor', 'supplier.partner'])
                    ->get()
                    ->keyBy(fn (ItemContractor $itemContractor): int => (int) $itemContractor->contractor_id)
                : collect();

            $searchOptions = $contractorRows
                ->map(function ($contractor) use ($linkedByContractor, $selectedWarehouseId, $jxContractorIds): ?array {
                    $contractorId = (int) $contractor->contractor_id;
                    $itemContractor = $linkedByContractor->get($contractorId);

                    if ($itemContractor instanceof ItemContractor) {
                        return $this->candidateContractorChangeOptionFromItemContractor(
                            $itemContractor,
                            $selectedWarehouseId,
                            $jxContractorIds,
                            '91倉庫'
                        );
                    }

                    return $this->candidateContractorChangeOptionFromContractorRow($contractor, $selectedWarehouseId);
                })
                ->filter()
                ->values();

            $options = $options
                ->merge($searchOptions)
                ->unique(fn (array $option): string => ((int) $option['contractor_id']).':'.((int) $option['supplier_id']))
                ->values();
        }

        return $options->take(80)->values()->toArray();
    }

    public function searchItemsForModal(
        int $warehouseId,
        ?string $itemCode = null,
        ?string $janCode = null,
        ?string $itemName = null,
        ?int $contractorId = null,
        ?int $category1Id = null,
        ?int $category2Id = null,
        ?int $category3Id = null,
        ?string $lastShippedFrom = null,
        ?string $lastShippedTo = null,
        int $page = 1,
        int $perPage = 25,
    ): array {
        $searchService = app(OrderRegistrationSearchService::class);
        $incomingWarehouseId = $searchService->incomingWarehouseId($warehouseId);
        $jxContractorIds = $searchService->jxContractorIds();
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));

        $query = Item::query()
            ->select([
                'items.id',
                'items.code',
                'items.name',
                'items.packaging',
                'items.volume',
                'items.volume_unit',
                'items.capacity_case',
                'items.item_category2_id',
            ])
            ->with('piece_jan_code_information')
            ->where('items.end_of_sale_type', 'NORMAL')
            ->where('items.is_ended', false);

        $hasItemCode = $itemCode && strlen($itemCode) >= 1;
        $hasJanCode = $janCode && strlen($janCode) >= 1;
        if ($hasItemCode || $hasJanCode) {
            $itemCode = $hasItemCode ? mb_convert_kana($itemCode, 'as') : null;
            $janCode = $hasJanCode ? mb_convert_kana($janCode, 'as') : null;
            $query->where(function ($query) use ($itemCode, $janCode): void {
                if ($itemCode) {
                    $query->where('items.code', 'like', "%{$itemCode}%");
                }
                if ($janCode) {
                    $query->orWhereHas('item_search_information', function ($query) use ($janCode): void {
                        $query->where('search_string', 'like', "%{$janCode}%");
                    });
                }
            });
        }

        if ($itemName && strlen($itemName) >= 2) {
            $itemName = mb_convert_kana($itemName, 'as');
            $query->where('items.name', 'like', "%{$itemName}%");
        }

        $query->whereHas('item_contractors', function ($query) use ($incomingWarehouseId, $contractorId): void {
            $query->where('warehouse_id', $incomingWarehouseId);
            if ($contractorId) {
                $query->where('contractor_id', $contractorId);
            }
        });

        if ($category1Id) {
            $query->where('items.item_category1_id', $category1Id);
        }
        if ($category2Id) {
            $query->where('items.item_category2_id', $category2Id);
        }
        if ($category3Id) {
            $query->where('items.item_category3_id', $category3Id);
        }

        if ($lastShippedFrom || $lastShippedTo) {
            $query->whereExists(function ($query) use ($warehouseId, $lastShippedFrom, $lastShippedTo): void {
                $query->select(DB::raw(1))
                    ->from('stats_item_warehouse_sales_summaries')
                    ->whereColumn('stats_item_warehouse_sales_summaries.item_id', 'items.id')
                    ->where('stats_item_warehouse_sales_summaries.warehouse_id', $warehouseId);
                if ($lastShippedFrom) {
                    $query->where('stats_item_warehouse_sales_summaries.last_shipped_at', '>=', $lastShippedFrom);
                }
                if ($lastShippedTo) {
                    $query->where('stats_item_warehouse_sales_summaries.last_shipped_at', '<=', $lastShippedTo);
                }
            });
        }

        $items = $query
            ->orderBy('items.code')
            ->limit($perPage)
            ->get();

        $itemIds = $items->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $category2Codes = ItemCategory::query()
            ->whereIn('id', $items->pluck('item_category2_id')->filter()->unique()->values()->all())
            ->pluck('code', 'id');
        $summaries = StatsItemWarehouseSalesSummary::where('warehouse_id', $warehouseId)
            ->whereIn('item_id', $itemIds)
            ->get()
            ->keyBy('item_id');

        $itemContractors = ItemContractor::where('warehouse_id', $incomingWarehouseId)
            ->whereIn('item_id', $itemIds)
            ->when($contractorId, fn ($query) => $query->where('contractor_id', $contractorId))
            ->with(['contractor', 'supplier.partner'])
            ->orderBy('contractor_id')
            ->orderBy('supplier_id')
            ->get()
            ->groupBy('item_id')
            ->map(fn ($contractors) => $contractors->first(
                fn (ItemContractor $itemContractor): bool => in_array((int) $itemContractor->contractor_id, $jxContractorIds, true)
            ) ?? $contractors->first());
        $defaultLocationCodes = $this->defaultLocationCodes($incomingWarehouseId, $itemIds);
        $effectiveStocks = $this->availableStockQuantities($warehouseId, $itemIds);
        $incomingQuantities = $this->incomingQuantities($incomingWarehouseId, $itemIds);
        $incomingExpectedArrivalDates = $this->incomingExpectedArrivalDates($incomingWarehouseId, $itemIds);
        $lastOrderDates = $this->lastOrderDates($incomingWarehouseId, $itemIds);
        $weeklySalesQuantities = $this->weeklySalesQuantities($warehouseId, $itemIds);

        $registeredLines = collect($this->lines)
            ->whereIn('item_id', $itemIds)
            ->groupBy('item_id');

        $data = $items->map(function (Item $item) use ($summaries, $itemContractors, $registeredLines, $searchService, $warehouseId, $jxContractorIds, $category2Codes, $defaultLocationCodes, $effectiveStocks, $incomingQuantities, $incomingExpectedArrivalDates, $lastOrderDates, $weeklySalesQuantities) {
            $summary = $summaries->get($item->id);
            $itemContractor = $itemContractors->get($item->id);
            $registered = $registeredLines->get($item->id, collect());
            $searchInfo = $item->piece_jan_code_information;
            $contractorId = $itemContractor ? (int) $itemContractor->contractor_id : null;
            $isEosAvailable = $contractorId !== null && in_array($contractorId, $jxContractorIds, true);
            $effectiveStock = (int) ($effectiveStocks[(int) $item->id] ?? 0);
            $incomingQuantity = (int) ($incomingQuantities[(int) $item->id] ?? 0);
            $weeklySales = $weeklySalesQuantities[(int) $item->id] ?? [
                'sales_week1_qty' => 0,
                'sales_week2_qty' => 0,
                'sales_week3_qty' => 0,
                'previous_month_sales_qty' => 0,
            ];
            $registeredCaseQty = 0;
            $registeredPieceQty = 0;

            foreach ($registered as $line) {
                if (($line['quantity_type'] ?? null) === QuantityType::CASE->value) {
                    $registeredCaseQty += (int) ($line['order_quantity'] ?? 0);
                } elseif (($line['quantity_type'] ?? null) === QuantityType::PIECE->value) {
                    $registeredPieceQty += (int) ($line['order_quantity'] ?? 0);
                }
            }

            return [
                'id' => (int) $item->id,
                'code' => (string) $item->code,
                'name' => (string) $item->name,
                'packaging' => $this->itemStandardLabel($item->volume ?? null, $item->volume_unit ?? null, (string) ($item->packaging ?? '')),
                'capacity_case' => max(1, (int) ($item->capacity_case ?? 1)),
                'item_contractor_note' => (string) ($itemContractor->item_contractor_note ?? $itemContractor->note ?? ''),
                'item_category2_code' => (string) ($category2Codes[(int) ($item->item_category2_id ?? 0)] ?? ''),
                'search_code' => $searchInfo?->search_string ?? '',
                'ordering_code' => $searchService->orderingCodeForItem((int) $item->id),
                'contractor_id' => $contractorId,
                'contractor_code' => $itemContractor?->contractor?->code,
                'contractor_name' => $itemContractor?->contractor
                    ? "[{$itemContractor->contractor->code}]{$itemContractor->contractor->name}"
                    : null,
                'contractor_raw_name' => $itemContractor?->contractor?->name,
                'supplier_id' => $itemContractor ? (int) ($itemContractor->supplier_id ?? 0) : null,
                'supplier_partner_id' => $itemContractor ? (int) ($itemContractor->supplier?->partner_id ?? 0) : null,
                'supplier_code' => $itemContractor ? (string) ($itemContractor->supplier?->partner?->code ?? '') : '',
                'supplier_name' => $itemContractor?->supplier?->partner?->name,
                'item_contractor_warehouse_id' => $itemContractor ? (int) $itemContractor->warehouse_id : null,
                'purchase_unit' => max(1, (int) ($itemContractor->purchase_unit ?? 1)),
                'default_location_code' => (string) ($defaultLocationCodes[(int) $item->id] ?? ''),
                'safety_stock' => (int) ($itemContractor->safety_stock ?? 0),
                'default_expected_arrival_date' => $itemContractor
                    ? $searchService->defaultExpectedArrivalDate(
                        $warehouseId,
                        (int) $itemContractor->contractor_id,
                        $isEosAvailable ? OrderChannel::EOS : OrderChannel::FAX
                    )
                    : null,
                'last_order_date' => $lastOrderDates[(int) $item->id] ?? null,
                'last_shipped_at' => $summary?->last_shipped_at?->format('y/m/d'),
                'sales_today_qty' => $summary?->sales_today_qty ?? 0,
                'sales_yesterday_qty' => $summary?->sales_yesterday_qty ?? 0,
                'sales_2days_ago_qty' => $summary?->sales_2days_ago_qty ?? 0,
                'last_3d_qty' => $summary?->last_3d_qty ?? 0,
                'last_5d_qty' => $summary?->last_5d_qty ?? 0,
                'last_7d_qty' => $summary?->last_7d_qty ?? 0,
                'last_30d_qty' => $summary?->last_30d_qty ?? 0,
                'sales_week1_qty' => (int) $weeklySales['sales_week1_qty'],
                'sales_week2_qty' => (int) $weeklySales['sales_week2_qty'],
                'sales_week3_qty' => (int) $weeklySales['sales_week3_qty'],
                'previous_month_sales_qty' => (int) $weeklySales['previous_month_sales_qty'],
                'effective_stock' => $effectiveStock,
                'incoming_qty' => $incomingQuantity,
                'incoming_expected_arrival_date' => $incomingExpectedArrivalDates[(int) $item->id] ?? null,
                'projected_stock' => $effectiveStock + $incomingQuantity,
                'pending_case_qty' => $registeredCaseQty ?: null,
                'pending_piece_qty' => $registeredPieceQty ?: null,
                'order_channel' => $isEosAvailable ? OrderChannel::EOS->value : OrderChannel::FAX->value,
                'is_eos_available' => $isEosAvailable,
                'item_contractor_linked' => $itemContractor !== null,
            ];
        })->values()->toArray();

        return [
            'data' => $data,
            'total' => count($data),
            'current_page' => 1,
            'last_page' => 1,
        ];
    }

    private function candidateContractorChangeOptionFromItemContractor(
        ItemContractor $itemContractor,
        int $selectedWarehouseId,
        array $jxContractorIds,
        string $priorityLabel
    ): ?array {
        if (! $itemContractor->contractor) {
            return null;
        }

        $contractorId = (int) $itemContractor->contractor_id;
        $supplier = $itemContractor->supplier;
        $supplierPartner = $supplier?->partner;
        $supplierId = (int) ($supplier?->id ?? 0);
        $isEosAvailable = in_array($contractorId, $jxContractorIds, true);
        $orderChannel = $isEosAvailable ? OrderChannel::EOS : OrderChannel::FAX;

        return [
            'contractor_id' => $contractorId,
            'item_contractor_warehouse_id' => (int) $itemContractor->warehouse_id,
            'contractor_code' => (string) ($itemContractor->contractor->code ?? ''),
            'contractor_name' => (string) ($itemContractor->contractor->name ?? ''),
            'contractor_label' => '['.(string) ($itemContractor->contractor->code ?? '').']'.(string) ($itemContractor->contractor->name ?? ''),
            'supplier_id' => $supplierId,
            'supplier_partner_id' => (int) ($supplier?->partner_id ?? 0),
            'supplier_code' => (string) ($supplierPartner?->code ?? ''),
            'supplier_name' => (string) ($supplierPartner?->name ?? '-'),
            'supplier_label' => filled($supplierPartner?->code)
                ? '['.(string) $supplierPartner->code.']'.(string) ($supplierPartner?->name ?? '-')
                : (string) ($supplierPartner?->name ?? '-'),
            'is_selectable' => $contractorId > 0 && $supplierId > 0,
            'is_item_linked' => true,
            'is_eos_available' => $isEosAvailable,
            'will_force_fax' => false,
            'order_channel' => $orderChannel->value,
            'purchase_unit' => max(1, (int) ($itemContractor->purchase_unit ?? 1)),
            'safety_stock' => (int) ($itemContractor->safety_stock ?? 0),
            'item_contractor_note' => (string) ($itemContractor->item_contractor_note ?? $itemContractor->note ?? ''),
            'default_expected_arrival_date' => $selectedWarehouseId > 0
                ? app(OrderRegistrationSearchService::class)->defaultExpectedArrivalDate($selectedWarehouseId, $contractorId, $orderChannel)
                : null,
            'priority_label' => $priorityLabel,
        ];
    }

    private function candidateContractorChangeOptionFromContractorRow(object $contractor, int $selectedWarehouseId): ?array
    {
        $contractorId = (int) ($contractor->contractor_id ?? 0);
        if ($contractorId < 1) {
            return null;
        }

        $supplierId = (int) ($contractor->default_supplier_id ?? 0);

        return [
            'contractor_id' => $contractorId,
            'item_contractor_warehouse_id' => null,
            'contractor_code' => (string) ($contractor->contractor_code ?? ''),
            'contractor_name' => (string) ($contractor->contractor_name ?? ''),
            'contractor_label' => '['.(string) ($contractor->contractor_code ?? '').']'.(string) ($contractor->contractor_name ?? ''),
            'supplier_id' => $supplierId,
            'supplier_partner_id' => (int) ($contractor->default_supplier_partner_id ?? 0),
            'supplier_code' => (string) ($contractor->default_supplier_code ?? ''),
            'supplier_name' => (string) ($contractor->default_supplier_name ?? '-'),
            'supplier_label' => filled($contractor->default_supplier_code ?? null)
                ? '['.(string) $contractor->default_supplier_code.']'.(string) ($contractor->default_supplier_name ?? '-')
                : (string) ($contractor->default_supplier_name ?? '-'),
            'is_selectable' => $supplierId > 0,
            'is_item_linked' => false,
            'is_eos_available' => false,
            'will_force_fax' => true,
            'order_channel' => OrderChannel::FAX->value,
            'purchase_unit' => 1,
            'safety_stock' => 0,
            'item_contractor_note' => '',
            'default_expected_arrival_date' => $selectedWarehouseId > 0
                ? app(OrderRegistrationSearchService::class)->defaultExpectedArrivalDate($selectedWarehouseId, $contractorId, OrderChannel::FAX)
                : null,
            'priority_label' => '検索結果',
        ];
    }

    private function itemStandardLabel(mixed $volume, mixed $volumeUnit, ?string $fallback = null): string
    {
        $fallback = trim((string) $fallback);
        if ($fallback !== '') {
            return $fallback;
        }

        $volumeNumber = is_numeric($volume) ? (float) $volume : null;
        if (
            $volumeUnit !== null
            && $volumeUnit !== ''
            && (($volumeNumber !== null && $volumeNumber > 0) || ($volumeNumber === null && trim((string) $volume) !== ''))
        ) {
            $volumeLabel = $volumeNumber !== null && abs($volumeNumber - round($volumeNumber)) < 0.0001
                ? (string) (int) round($volumeNumber)
                : (string) $volume;
            $unitLabel = EVolumeUnit::tryFrom((string) $volumeUnit)?->name() ?? (string) $volumeUnit;

            return $volumeLabel.$unitLabel;
        }

        return '-';
    }

    /**
     * @param  array<int>  $itemIds
     * @return array<int, string>
     */
    private function defaultLocationCodes(int $warehouseId, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return DB::connection('sakemaru')
            ->table('item_incoming_default_locations as idl')
            ->join('locations as l', 'l.id', '=', 'idl.location_id')
            ->where('idl.warehouse_id', $warehouseId)
            ->whereIn('idl.item_id', $itemIds)
            ->selectRaw("idl.item_id, TRIM(CONCAT_WS(' ', NULLIF(l.code1, ''), NULLIF(l.code2, ''), NULLIF(l.code3, ''))) as location_code")
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->item_id => (string) ($row->location_code ?? '')])
            ->all();
    }

    /**
     * @param  array<int>  $itemIds
     * @return array<int, int>
     */
    private function availableStockQuantities(int $warehouseId, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return DB::connection('sakemaru')
            ->table('real_stocks')
            ->where('warehouse_id', $warehouseId)
            ->whereIn('item_id', $itemIds)
            ->selectRaw('item_id, SUM(available_quantity) as available_qty')
            ->groupBy('item_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->item_id => (int) ($row->available_qty ?? 0)])
            ->all();
    }

    /**
     * @return array<int, array{warehouse_id: int, warehouse_code: string, warehouse_name: string, theoretical_stock: int}>
     */
    private function otherWarehouseStockRows(int $itemId, int $currentWarehouseId): array
    {
        return DB::connection('sakemaru')
            ->table('real_stocks as stock')
            ->where('stock.item_id', $itemId)
            ->where('stock.warehouse_id', '<>', $currentWarehouseId)
            ->join('warehouses as w', 'w.id', '=', 'stock.warehouse_id')
            ->selectRaw('
                w.id as warehouse_id,
                w.code as warehouse_code,
                w.name as warehouse_name,
                SUM(stock.available_quantity) as theoretical_stock
            ')
            ->groupBy('w.id', 'w.code', 'w.name')
            ->orderBy('w.code')
            ->get()
            ->filter(fn ($row): bool => (int) ($row->theoretical_stock ?? 0) !== 0)
            ->map(fn ($row): array => [
                'warehouse_id' => (int) $row->warehouse_id,
                'warehouse_code' => (string) ($row->warehouse_code ?? ''),
                'warehouse_name' => (string) ($row->warehouse_name ?? ''),
                'theoretical_stock' => (int) ($row->theoretical_stock ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int>  $itemIds
     * @return array<int, int>
     */
    private function incomingQuantities(int $warehouseId, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return DB::connection('sakemaru')
            ->table('wms_order_incoming_schedules')
            ->join('items as incoming_items', 'incoming_items.id', '=', 'wms_order_incoming_schedules.item_id')
            ->where('wms_order_incoming_schedules.warehouse_id', $warehouseId)
            ->whereIn('wms_order_incoming_schedules.item_id', $itemIds)
            ->whereIn('wms_order_incoming_schedules.status', [
                IncomingScheduleStatus::PENDING->value,
                IncomingScheduleStatus::PARTIAL->value,
            ])
            ->selectRaw('
                wms_order_incoming_schedules.item_id,
                SUM(
                    CASE wms_order_incoming_schedules.quantity_type
                        WHEN \'CASE\' THEN GREATEST(wms_order_incoming_schedules.expected_quantity - wms_order_incoming_schedules.received_quantity, 0) * GREATEST(COALESCE(incoming_items.capacity_case, 1), 1)
                        WHEN \'CARTON\' THEN GREATEST(wms_order_incoming_schedules.expected_quantity - wms_order_incoming_schedules.received_quantity, 0) * GREATEST(COALESCE(incoming_items.capacity_carton, 1), 1)
                        ELSE GREATEST(wms_order_incoming_schedules.expected_quantity - wms_order_incoming_schedules.received_quantity, 0)
                    END
                ) as incoming_qty
            ')
            ->groupBy('wms_order_incoming_schedules.item_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->item_id => (int) ($row->incoming_qty ?? 0)])
            ->all();
    }

    /**
     * @param  array<int>  $itemIds
     * @return array<int, string>
     */
    private function incomingExpectedArrivalDates(int $warehouseId, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return DB::connection('sakemaru')
            ->table('wms_order_incoming_schedules')
            ->where('warehouse_id', $warehouseId)
            ->whereIn('item_id', $itemIds)
            ->whereNotIn('status', [
                IncomingScheduleStatus::CANCELLED->value,
                IncomingScheduleStatus::PARTIAL_CANCELLED->value,
                IncomingScheduleStatus::DELETED->value,
            ])
            ->whereNotNull('expected_arrival_date')
            ->selectRaw('
                item_id,
                SUBSTRING_INDEX(
                    GROUP_CONCAT(
                        expected_arrival_date
                        ORDER BY order_date DESC, expected_arrival_date DESC, id DESC
                    ),
                    \',\',
                    1
                ) as incoming_expected_arrival_date
            ')
            ->groupBy('item_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->item_id => filled($row->incoming_expected_arrival_date)
                    ? Carbon::parse((string) $row->incoming_expected_arrival_date)->toDateString()
                    : '',
            ])
            ->filter()
            ->all();
    }

    /**
     * @param  array<int>  $itemIds
     * @return array<int, string>
     */
    private function lastOrderDates(int $warehouseId, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return DB::connection('sakemaru')
            ->table('wms_order_incoming_schedules')
            ->where('warehouse_id', $warehouseId)
            ->whereIn('item_id', $itemIds)
            ->whereNotIn('status', [
                IncomingScheduleStatus::CANCELLED->value,
                IncomingScheduleStatus::PARTIAL_CANCELLED->value,
                IncomingScheduleStatus::DELETED->value,
            ])
            ->selectRaw('item_id, MAX(order_date) as last_order_date')
            ->groupBy('item_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->item_id => filled($row->last_order_date)
                    ? Carbon::parse($row->last_order_date)->format('y/m/d')
                    : '',
            ])
            ->all();
    }

    /**
     * @param  array<int>  $itemIds
     * @return array<int, array{sales_week1_qty: int, sales_week2_qty: int, sales_week3_qty: int, previous_month_sales_qty: int}>
     */
    private function weeklySalesQuantities(int $warehouseId, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $ranges = $this->weeklySalesDateRanges();
        $week1Start = $ranges['week1']['start'];
        $week1End = $ranges['week1']['end'];
        $week2Start = $ranges['week2']['start'];
        $week2End = $ranges['week2']['end'];
        $week3Start = $ranges['week3']['start'];
        $week3End = $ranges['week3']['end'];
        $previousMonthStart = $ranges['previous_month']['start'];
        $previousMonthEnd = $ranges['previous_month']['end'];

        return DB::connection('sakemaru')
            ->table('stats_item_warehouse_daily_sales')
            ->where('warehouse_id', $warehouseId)
            ->whereIn('item_id', $itemIds)
            ->whereBetween('business_date', [min($previousMonthStart, $week3Start), max($previousMonthEnd, $week1End)])
            ->selectRaw(
                '
                    item_id,
                    SUM(CASE WHEN business_date BETWEEN ? AND ? THEN shipped_piece_qty ELSE 0 END) as sales_week1_qty,
                    SUM(CASE WHEN business_date BETWEEN ? AND ? THEN shipped_piece_qty ELSE 0 END) as sales_week2_qty,
                    SUM(CASE WHEN business_date BETWEEN ? AND ? THEN shipped_piece_qty ELSE 0 END) as sales_week3_qty,
                    SUM(CASE WHEN business_date BETWEEN ? AND ? THEN shipped_piece_qty ELSE 0 END) as previous_month_sales_qty
                ',
                [$week1Start, $week1End, $week2Start, $week2End, $week3Start, $week3End, $previousMonthStart, $previousMonthEnd],
            )
            ->groupBy('item_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->item_id => [
                    'sales_week1_qty' => (int) ($row->sales_week1_qty ?? 0),
                    'sales_week2_qty' => (int) ($row->sales_week2_qty ?? 0),
                    'sales_week3_qty' => (int) ($row->sales_week3_qty ?? 0),
                    'previous_month_sales_qty' => (int) ($row->previous_month_sales_qty ?? 0),
                ],
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function weeklySalesColumnHelps(): array
    {
        $ranges = $this->weeklySalesDateRanges();
        $basisDate = $this->formatWeeklySalesDate($ranges['basis']);
        $week1Range = $this->formatWeeklySalesRange($ranges['week1']);
        $week2Range = $this->formatWeeklySalesRange($ranges['week2']);
        $week3Range = $this->formatWeeklySalesRange($ranges['week3']);
        $previousMonthRange = $this->formatWeeklySalesRange($ranges['previous_month']);

        return [
            '1週' => "基準日 {$basisDate} を含む直近7日間（{$week1Range}）の販売数量です。",
            '2週' => "基準日 {$basisDate} の7日前から13日前まで（{$week2Range}）の販売数量です。",
            '3週' => "基準日 {$basisDate} の14日前から20日前まで（{$week3Range}）の販売数量です。",
            '前月' => "基準日 {$basisDate} の前月1か月分（{$previousMonthRange}）の販売数量です。",
        ];
    }

    /**
     * @return array{basis: string, week1: array{start: string, end: string}, week2: array{start: string, end: string}, week3: array{start: string, end: string}, previous_month: array{start: string, end: string}}
     */
    private function weeklySalesDateRanges(): array
    {
        $basisDate = Carbon::parse(ClientSetting::freshSystemDateYMD('order_registration:modal_weekly_sales'));

        return [
            'basis' => $basisDate->toDateString(),
            'week1' => [
                'start' => $basisDate->copy()->subDays(6)->toDateString(),
                'end' => $basisDate->toDateString(),
            ],
            'week2' => [
                'start' => $basisDate->copy()->subDays(13)->toDateString(),
                'end' => $basisDate->copy()->subDays(7)->toDateString(),
            ],
            'week3' => [
                'start' => $basisDate->copy()->subDays(20)->toDateString(),
                'end' => $basisDate->copy()->subDays(14)->toDateString(),
            ],
            'previous_month' => [
                'start' => $basisDate->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                'end' => $basisDate->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ],
        ];
    }

    /**
     * @param  array{start: string, end: string}  $range
     */
    private function formatWeeklySalesRange(array $range): string
    {
        return $this->formatWeeklySalesDate($range['start']).'〜'.$this->formatWeeklySalesDate($range['end']);
    }

    private function formatWeeklySalesDate(string $date): string
    {
        return Carbon::parse($date)->format('Y/m/d');
    }

    public function getSubCategories(int $parentId): array
    {
        return ItemCategory::where('parent_id', $parentId)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'name'])
            ->toArray();
    }

    public function getItemStockForOrderCreate(int $warehouseId, int $itemId): ?int
    {
        return app(OrderRegistrationSearchService::class)->availableStock($warehouseId, $itemId);
    }

    public function getItemIncomingQuantityForOrderCreate(int $warehouseId, int $itemId): int
    {
        return app(OrderRegistrationSearchService::class)->incomingQuantity($warehouseId, $itemId);
    }

    public function getExternalOrderContractorsForSalesBasedGeneration(): array
    {
        $internalContractorIds = WmsContractorSetting::query()
            ->where('transmission_type', TransmissionType::INTERNAL->value)
            ->pluck('contractor_id')
            ->toArray();

        return WmsContractorSetting::query()
            ->when($internalContractorIds !== [], fn ($query) => $query->whereNotIn('contractor_id', $internalContractorIds))
            ->whereHas('contractor', fn ($query) => $query->where('is_auto_change_order', true))
            ->with(['contractor:id,code,name', 'transmissionContractor:id,code,name'])
            ->get()
            ->map(fn (WmsContractorSetting $setting): array => [
                'id' => (int) $setting->contractor_id,
                'code' => (string) $setting->contractor->code,
                'name' => (string) $setting->contractor->name,
                'transmission_type' => $setting->transmission_type?->value ?? 'UNKNOWN',
                'transmission_type_label' => $setting->transmission_type
                    ? $setting->transmission_type->label()
                    : '未設定',
                'transmission_parent_code' => $setting->transmissionContractor?->code,
                'transmission_parent_name' => $setting->transmissionContractor?->name,
                'generation_time' => $setting->auto_order_generation_time,
            ])
            ->sortBy('code')
            ->values()
            ->toArray();
    }

    public function getExternalOrderJxContractorsForSalesBasedGeneration(): array
    {
        $jxContractorIds = WmsContractorSetting::query()
            ->where('transmission_type', TransmissionType::JX_FINET->value)
            ->pluck('contractor_id')
            ->toArray();

        if ($jxContractorIds === []) {
            return [];
        }

        $aggregatedContractorIds = WmsContractorSetting::query()
            ->whereIn('transmission_contractor_id', $jxContractorIds)
            ->pluck('contractor_id')
            ->toArray();

        $targetContractorIds = array_values(array_unique(array_merge($jxContractorIds, $aggregatedContractorIds)));

        return collect($this->getExternalOrderContractorsForSalesBasedGeneration())
            ->whereIn('id', $targetContractorIds)
            ->values()
            ->toArray();
    }

    public function getExternalOrderOtherContractorsForSalesBasedGeneration(): array
    {
        $jxContractorIds = collect($this->externalOrderJxContractorsData ?: $this->getExternalOrderJxContractorsForSalesBasedGeneration())
            ->pluck('id')
            ->values()
            ->toArray();

        return collect($this->getExternalOrderContractorsForSalesBasedGeneration())
            ->when($jxContractorIds !== [], fn ($contractors) => $contractors->whereNotIn('id', $jxContractorIds))
            ->values()
            ->toArray();
    }

    public function getExternalOrderCategory2ForSalesBasedGeneration(): array
    {
        return ItemCategory::query()
            ->where('is_active', true)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('items')
                    ->whereColumn('items.item_category2_id', 'item_categories.id')
                    ->where('items.end_of_sale_type', 'NORMAL')
                    ->where('items.is_ended', false);
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (ItemCategory $category): array => [
                'id' => (int) $category->id,
                'code' => (string) $category->code,
                'name' => (string) $category->name,
            ])
            ->values()
            ->toArray();
    }

    /**
     * @param  array<int>  $supplierIds
     * @return array<int, array<int, array{id: int, code: string, name: string}>>
     */
    private function contractorsBySupplierIds(array $supplierIds): array
    {
        $supplierIds = collect($supplierIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($supplierIds === []) {
            return [];
        }

        $mappedRows = DB::connection('sakemaru')
            ->table('wms_contractor_suppliers as wcs')
            ->join('contractors as c', 'c.id', '=', 'wcs.contractor_id')
            ->whereIn('wcs.supplier_id', $supplierIds)
            ->get([
                'wcs.supplier_id',
                'c.id as contractor_id',
                'c.code as contractor_code',
                'c.name as contractor_name',
            ]);
        $defaultRows = DB::connection('sakemaru')
            ->table('contractors as c')
            ->whereIn('c.supplier_id', $supplierIds)
            ->get([
                'c.supplier_id',
                'c.id as contractor_id',
                'c.code as contractor_code',
                'c.name as contractor_name',
            ]);

        return $mappedRows
            ->merge($defaultRows)
            ->unique(fn ($row): string => ((int) $row->supplier_id).':'.((int) $row->contractor_id))
            ->groupBy(fn ($row): int => (int) $row->supplier_id)
            ->map(fn ($rows): array => $rows
                ->map(fn ($row): array => [
                    'id' => (int) $row->contractor_id,
                    'code' => (string) ($row->contractor_code ?? ''),
                    'name' => (string) ($row->contractor_name ?? ''),
                ])
                ->values()
                ->toArray())
            ->all();
    }

    /**
     * @param  array<int>  $supplierIds
     * @param  array<int, array<int, array{id: int, code: string, name: string}>>  $contractorsBySupplier
     * @return array<string, ItemContractor>
     */
    private function itemContractorsBySupplierAndContractor(
        int $warehouseId,
        int $itemId,
        array $supplierIds,
        array $contractorsBySupplier
    ): array {
        $contractorIds = collect($contractorsBySupplier)
            ->flatten(1)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $supplierIds = collect($supplierIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($warehouseId < 1 || $itemId < 1 || $supplierIds === [] || $contractorIds === []) {
            return [];
        }

        return ItemContractor::query()
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->whereIn('supplier_id', $supplierIds)
            ->whereIn('contractor_id', $contractorIds)
            ->get()
            ->mapWithKeys(fn (ItemContractor $itemContractor): array => [
                ((int) $itemContractor->supplier_id).':'.((int) $itemContractor->contractor_id) => $itemContractor,
            ])
            ->all();
    }

    private function initializeExternalOrderContractors(): void
    {
        $this->externalOrderContractorsData = $this->getExternalOrderContractorsForSalesBasedGeneration();
        $this->externalOrderJxContractorsData = $this->getExternalOrderJxContractorsForSalesBasedGeneration();
        $this->externalOrderOtherContractorsData = $this->getExternalOrderOtherContractorsForSalesBasedGeneration();
    }

    private function initializeExternalOrderCategory2(): void
    {
        $this->externalOrderCategory2Data = $this->getExternalOrderCategory2ForSalesBasedGeneration();
    }

    private function defaultExternalOrderContractorIds(): array
    {
        return collect($this->externalOrderJxContractorsData)
            ->merge($this->externalOrderOtherContractorsData)
            ->pluck('id')
            ->values()
            ->toArray();
    }

    private function getSalesBasedExternalOrderGenerationWarehouseIds(int $warehouseId): array
    {
        $enabledWarehouseIds = WmsWarehouseAutoOrderSetting::enabled()
            ->pluck('warehouse_id')
            ->toArray();

        return array_values(array_intersect(
            $enabledWarehouseIds,
            [$warehouseId],
        ));
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
        $this->clearRegistrationLineSelection();
        $this->resetLineContractorFilterIfInvalid();
    }

    public function updatedLineContractorFilter(): void
    {
        $this->clearRegistrationLineSelection();
    }

    public function toggleVisibleRegistrationLineSelection(): void
    {
        $visibleIndexes = $this->visibleRegistrationLineIndexes();
        if ($visibleIndexes === []) {
            $this->clearRegistrationLineSelection();

            return;
        }

        $selectedIndexes = $this->normalizedSelectedRegistrationLineIndexes();
        $allVisibleSelected = collect($visibleIndexes)
            ->every(fn (int $index): bool => in_array($index, $selectedIndexes, true));

        $nextIndexes = $allVisibleSelected
            ? collect($selectedIndexes)->reject(fn (int $index): bool => in_array($index, $visibleIndexes, true))
            : collect($selectedIndexes)->merge($visibleIndexes);

        $this->selectedRegistrationLineIndexes = $nextIndexes
            ->unique()
            ->values()
            ->map(fn (int $index): string => (string) $index)
            ->toArray();
    }

    public function applyBulkExpectedArrivalDate(): void
    {
        if (blank($this->bulkExpectedArrivalDate)) {
            $this->notifyWarning('一括変更する入荷予定日を選択してください。');

            return;
        }

        try {
            $expectedArrivalDate = Carbon::parse($this->bulkExpectedArrivalDate)->toDateString();
        } catch (\Throwable) {
            $this->notifyWarning('入荷予定日を正しく選択してください。');

            return;
        }

        if ($expectedArrivalDate < now()->toDateString()) {
            $this->notifyWarning('入荷予定日に過去日は選択できません。');

            return;
        }

        $targetIndexes = $this->selectedVisibleRegistrationLineIndexes();
        if ($targetIndexes === []) {
            $this->notifyWarning('一括変更する登録リスト行を選択してください。');

            return;
        }

        foreach ($targetIndexes as $index) {
            $this->lines[$index]['expected_arrival_date'] = $expectedArrivalDate;
        }

        $this->clearRegistrationLineSelection();

        Notification::make()
            ->title('選択した登録リストの入荷予定日を変更しました')
            ->body(number_format(count($targetIndexes)).'件')
            ->success()
            ->send();
    }

    public function setLineOrderQuantity(int $index, string $quantityTypeValue, mixed $quantity): void
    {
        if (! isset($this->lines[$index])) {
            return;
        }

        $quantityType = QuantityType::tryFrom($quantityTypeValue);
        if (! $quantityType) {
            return;
        }

        $line = $this->lines[$index];
        $orderQuantity = $this->normalizeLineOrderQuantity($quantity);
        $line['quantity_type'] = $quantityType->value;
        $line['quantity_type_label'] = $quantityType->name();
        $line['order_quantity'] = $orderQuantity;
        $line['total_piece_quantity'] = $quantityType === QuantityType::CASE
            ? $orderQuantity * max(1, (int) ($line['capacity_case'] ?? 1))
            : $orderQuantity;

        $purchasePrice = $this->purchasePriceForLine(
            (int) ($line['item_id'] ?? 0),
            (int) ($line['supplier_partner_id'] ?? 0),
            (int) ($line['warehouse_id'] ?? $this->warehouseId ?? 0),
            $quantityType,
        );
        $line['purchase_unit_price'] = $purchasePrice['price'];
        $line['purchase_unit_price_source'] = $purchasePrice['source'];

        $this->lines[$index] = $line;
    }

    public function openLineDuplicateModal(int $index): void
    {
        if (! isset($this->lines[$index])) {
            $this->notifyWarning('複製対象の明細が見つかりません。');

            return;
        }

        $this->duplicateLineIndex = $index;
        $this->duplicateWarehouseIds = [];
        $this->showLineDuplicateModal = true;
    }

    public function closeLineDuplicateModal(): void
    {
        $this->showLineDuplicateModal = false;
        $this->duplicateLineIndex = null;
        $this->duplicateWarehouseIds = [];
    }

    public function duplicateLineToWarehouses(): void
    {
        if ($this->duplicateLineIndex === null || ! isset($this->lines[$this->duplicateLineIndex])) {
            $this->notifyWarning('複製対象の明細が見つかりません。');

            return;
        }

        $sourceLine = $this->lines[$this->duplicateLineIndex];
        $sourceWarehouseId = (int) ($sourceLine['warehouse_id'] ?? 0);
        $warehouseById = collect($this->duplicateWarehouseOptions($sourceWarehouseId))->keyBy('id');
        $targetWarehouseIds = collect($this->duplicateWarehouseIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->filter(fn (int $id): bool => $warehouseById->has($id))
            ->unique()
            ->values();
        $created = 0;

        foreach ($targetWarehouseIds as $warehouseId) {
            if (! $warehouseById->has($warehouseId)) {
                continue;
            }

            $line = $this->lineForWarehouse($sourceLine, $warehouseId);
            $this->lines[] = $line;
            $created++;
        }

        $this->closeLineDuplicateModal();

        $notification = Notification::make()
            ->title($created > 0 ? "{$created}件を複製しました" : '複製できる納入先がありませんでした');

        ($created > 0 ? $notification->success() : $notification->warning())->send();
    }

    /**
     * @return array<int, array{id: int, code: string, name: string, label?: string}>
     */
    public function duplicateWarehouseOptions(?int $sourceWarehouseId = null): array
    {
        $allowedCodeOrder = array_flip(self::LINE_DUPLICATE_WAREHOUSE_CODES);

        return collect($this->warehouses)
            ->filter(function (array $warehouse) use ($sourceWarehouseId): bool {
                $warehouseId = (int) ($warehouse['id'] ?? 0);

                if ($warehouseId <= 0 || ($sourceWarehouseId !== null && $warehouseId === $sourceWarehouseId)) {
                    return false;
                }

                return in_array($this->normalizedWarehouseCode($warehouse['code'] ?? $warehouseId), self::LINE_DUPLICATE_WAREHOUSE_CODES, true);
            })
            ->sortBy(fn (array $warehouse): int => $allowedCodeOrder[$this->normalizedWarehouseCode($warehouse['code'] ?? $warehouse['id'] ?? '')] ?? PHP_INT_MAX)
            ->values()
            ->all();
    }

    public function confirmOrders(): void
    {
        $registrationService = app(OrderRegistrationService::class);

        if ($this->isConfirming) {
            return;
        }

        $this->isConfirming = true;

        try {
            $result = $registrationService->register(
                warehouseId: (int) $this->warehouseId,
                lines: $this->registrationLines(),
                userId: (int) auth()->id(),
            );

            $fileCount = $result['data_file_result']['total_files'] ?? 0;
            $faxErrors = collect($result['data_file_result']['files'] ?? [])
                ->filter(fn (array $file): bool => filled($file['fax_error'] ?? null))
                ->count();

            Notification::make()
                ->title('発注を確定しました')
                ->body("実行CD: {$result['batch_code']} / 発注明細 ".count($result['candidate_ids'])."件 / 入荷予定 {$result['incoming_schedule_count']}件 / PDF {$fileCount}件".($faxErrors > 0 ? " / PDFエラー {$faxErrors}件" : ''))
                ->success()
                ->send();

            $this->completionResult = $this->buildCompletionResult($result);
            $this->lines = [];
            $this->lineContractorFilter = '';
            $this->clearRegistrationLineSelection();
            $this->bulkExpectedArrivalDate = '';
            $this->resetCompletionFaxDownloadModal();
            $this->resetCompletionDetailModal();
            $this->resetSearchState();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('発注確定に失敗しました')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->isConfirming = false;
        }
    }

    public function downloadRegistrationListPdf()
    {
        $lines = $this->visibleRegistrationLinesForOutput();

        if ($lines === []) {
            Notification::make()
                ->title('PDF出力対象の登録リストがありません')
                ->warning()
                ->send();

            return null;
        }

        $pdfBinary = app(OrderRegistrationListPdfService::class)->generate($lines, [
            'warehouse_label' => $this->selectedWarehouseLabel(),
            'contractor_filter_label' => $this->registrationListContractorFilterLabel($lines),
        ]);
        $filename = 'order-registration-list_'.now()->format('YmdHis').'.pdf';

        return response()->streamDownload(
            fn () => print ($pdfBinary),
            $filename,
            ['Content-Type' => 'application/pdf']
        );
    }

    public function startNewRegistration(): void
    {
        $this->completionResult = [];
        $this->lineContractorFilter = '';
        $this->clearRegistrationLineSelection();
        $this->bulkExpectedArrivalDate = '';
        $this->resetCompletionFaxDownloadModal();
        $this->resetCompletionDetailModal();
        $this->resetSearchState();
    }

    public function openCompletionDetailModal(int $dataFileId): void
    {
        if (! in_array($dataFileId, $this->completionFaxFileIds(), true)) {
            Notification::make()
                ->title('今回生成したPDFではありません')
                ->danger()
                ->send();

            return;
        }

        $this->resetCompletionFaxDownloadModal();
        $this->completionDetailDataFileId = $dataFileId;
        $this->showCompletionDetailModal = true;
    }

    public function closeCompletionDetailModal(): void
    {
        $this->resetCompletionDetailModal();
    }

    public function openCompletionFaxDownloadModal(int $dataFileId): void
    {
        if (! in_array($dataFileId, $this->completionFaxFileIds(), true)) {
            Notification::make()
                ->title('今回生成したFAXではありません')
                ->danger()
                ->send();

            return;
        }

        $dataFile = WmsOrderDataFile::query()->find($dataFileId);
        if (! $dataFile || blank($dataFile->fax_file_path)) {
            Notification::make()
                ->title('FAX PDFが見つかりません')
                ->danger()
                ->send();

            return;
        }

        $this->resetCompletionDetailModal();
        $this->completionFaxDownloadDataFileId = $dataFileId;
        $this->completionFaxCommunicationNotes = '';
        $this->showCompletionFaxDownloadModal = true;
    }

    public function closeCompletionFaxDownloadModal(): void
    {
        $this->resetCompletionFaxDownloadModal();
    }

    public function downloadCompletionFaxWithNotes(): void
    {
        if ($this->completionFaxDownloadDataFileId === null) {
            Notification::make()
                ->title('ダウンロード対象が選択されていません')
                ->danger()
                ->send();

            return;
        }

        if ($this->downloadCompletionFax(
            $this->completionFaxDownloadDataFileId,
            $this->normalizedCompletionFaxCommunicationNotes(),
        )) {
            $this->resetCompletionFaxDownloadModal();
        }
    }

    public function downloadCompletionFax(int $dataFileId, ?string $communicationNotes = null): bool
    {
        if (! in_array($dataFileId, $this->completionFaxFileIds(), true)) {
            Notification::make()
                ->title('今回生成したFAXではありません')
                ->danger()
                ->send();

            return false;
        }

        $dataFile = WmsOrderDataFile::query()->find($dataFileId);
        if (! $dataFile || blank($dataFile->fax_file_path)) {
            Notification::make()
                ->title('FAX PDFが見つかりません')
                ->danger()
                ->send();

            return false;
        }

        try {
            app(PurchaseOrderPdfService::class)->generateAndStore($dataFile, $communicationNotes);
            $dataFile->refresh();

            $url = Storage::disk('s3')->temporaryUrl($dataFile->fax_file_path, now()->addHour());
            $dataFile->markAsFaxDownloaded((int) auth()->id());
            $this->syncCompletionFaxFilePath($dataFile->id, $dataFile->fax_file_path);
            $this->js('window.open('.json_encode($url).", '_blank')");

            return true;
        } catch (\Throwable $e) {
            Notification::make()
                ->title('FAX PDFのダウンロードURL生成に失敗しました')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return false;
        }
    }

    public function updatedWarehouseId(): void
    {
        $this->resetSearchState();
    }

    public function orderChannelLabel(): string
    {
        return $this->orderChannelEnum()->label();
    }

    public function selectedWarehouseLabel(): string
    {
        $warehouse = collect($this->warehouses)->firstWhere('id', (int) $this->warehouseId);

        return $warehouse['label'] ?? '-';
    }

    private function addLine(array $row, array $quantities, OrderEntrySource $entrySource, bool $notify = true): bool
    {
        $caseQty = max(0, (int) ($quantities['case'] ?? 0));
        $pieceQty = max(0, (int) ($quantities['piece'] ?? 0));

        if ($caseQty > 0 && $pieceQty > 0) {
            if ($notify) {
                $this->notifyWarning('ケースとバラはどちらか一方だけ入力してください。');
            }

            return false;
        }

        $quantityType = $caseQty > 0 ? QuantityType::CASE : QuantityType::PIECE;
        $orderQuantity = $caseQty > 0 ? $caseQty : $pieceQty;
        $contractorId = (int) ($row['contractor_id'] ?? 0);
        $itemContractorLinked = array_key_exists('item_contractor_linked', $row)
            ? (bool) $row['item_contractor_linked']
            : true;
        $isEosAvailable = array_key_exists('is_eos_available', $row)
            ? (bool) $row['is_eos_available']
            : app(OrderRegistrationSearchService::class)->isJxContractor($contractorId);
        if (! $itemContractorLinked) {
            $isEosAvailable = false;
        }
        $orderChannel = OrderChannel::tryFrom((string) ($row['order_channel'] ?? ''))
            ?? ($isEosAvailable ? OrderChannel::EOS : OrderChannel::FAX);

        if ($orderQuantity <= 0) {
            if ($notify) {
                $this->notifyWarning('発注数量を入力してください。');
            }

            return false;
        }

        if ($orderChannel === OrderChannel::EOS && ! $isEosAvailable) {
            if ($notify) {
                $this->notifyWarning('EOS発注不可の商品は追加できません。');
            }

            return false;
        }

        $lineWarehouseId = (int) ($row['warehouse_id'] ?? $this->warehouseId ?? 0);
        $purchasePrice = $this->purchasePriceForLine(
            (int) ($row['item_id'] ?? 0),
            (int) ($row['supplier_partner_id'] ?? 0),
            $lineWarehouseId,
            $quantityType,
        );
        $lineWarehouse = $this->warehouseOptionById($lineWarehouseId);

        $this->lines[] = [
            'warehouse_id' => $lineWarehouseId,
            'warehouse_code' => (string) ($lineWarehouse['code'] ?? $row['warehouse_code'] ?? ''),
            'warehouse_name' => (string) ($lineWarehouse['name'] ?? $row['warehouse_name'] ?? ''),
            'item_id' => (int) $row['item_id'],
            'item_code' => (string) $row['item_code'],
            'item_name' => (string) $row['item_name'],
            'item_packaging' => (string) ($row['item_packaging'] ?? ''),
            'capacity_case' => max(1, (int) ($row['capacity_case'] ?? 1)),
            'item_contractor_note' => (string) ($row['item_contractor_note'] ?? ''),
            'contractor_id' => $contractorId,
            'contractor_code' => (string) ($row['contractor_code'] ?? ''),
            'contractor_name' => (string) $row['contractor_name'],
            'supplier_id' => (int) ($row['supplier_id'] ?? 0),
            'supplier_partner_id' => (int) ($row['supplier_partner_id'] ?? 0),
            'supplier_code' => (string) ($row['supplier_code'] ?? ''),
            'supplier_name' => (string) ($row['supplier_name'] ?? '-'),
            'item_contractor_warehouse_id' => isset($row['item_contractor_warehouse_id'])
                ? ((int) $row['item_contractor_warehouse_id'] ?: null)
                : null,
            'search_code' => $row['search_code'] ?? null,
            'ordering_code' => $row['ordering_code'] ?? null,
            'purchase_unit' => max(1, (int) ($row['purchase_unit'] ?? 1)),
            'purchase_unit_price' => $purchasePrice['price'],
            'purchase_unit_price_source' => $purchasePrice['source'],
            'order_quantity' => $orderQuantity,
            'quantity_type' => $quantityType->value,
            'quantity_type_label' => $quantityType->name(),
            'total_piece_quantity' => $quantityType === QuantityType::CASE
                ? $orderQuantity * max(1, (int) ($row['capacity_case'] ?? 1))
                : $orderQuantity,
            'expected_arrival_date' => $this->resolveExpectedArrivalDateForLine($row, $contractorId),
            'order_channel' => $orderChannel->value,
            'order_channel_label' => $orderChannel->label(),
            'is_eos_available' => $isEosAvailable,
            'item_contractor_linked' => $itemContractorLinked,
            'entry_source' => $entrySource->value,
            'entry_source_label' => $entrySource->label(),
            'suggested_quantity' => (int) ($row['order_piece_qty'] ?? $orderQuantity),
            'calculated_shortage_qty' => (int) ($row['order_piece_qty'] ?? $orderQuantity),
            'sales_qty' => (int) ($row['sales_qty'] ?? 0),
        ];

        if ($notify) {
            Notification::make()
                ->title('登録リストに追加しました')
                ->body("[{$row['item_code']}] {$row['item_name']}")
                ->success()
                ->send();
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function lineForWarehouse(array $line, int $warehouseId): array
    {
        $warehouse = $this->warehouseOptionById($warehouseId);
        $line['warehouse_id'] = $warehouseId;
        $line['warehouse_code'] = (string) ($warehouse['code'] ?? '');
        $line['warehouse_name'] = (string) ($warehouse['name'] ?? '');

        $quantityType = QuantityType::tryFrom((string) ($line['quantity_type'] ?? '')) ?? QuantityType::PIECE;
        $line['default_expected_arrival_date'] = app(OrderRegistrationSearchService::class)->defaultExpectedArrivalDate(
            $warehouseId,
            (int) ($line['contractor_id'] ?? 0),
            OrderChannel::tryFrom((string) ($line['order_channel'] ?? '')) ?? OrderChannel::FAX,
        );
        $line['expected_arrival_date'] = $this->resolveExpectedArrivalDateForLine($line, (int) ($line['contractor_id'] ?? 0));
        $purchasePrice = $this->purchasePriceForLine(
            (int) ($line['item_id'] ?? 0),
            (int) ($line['supplier_partner_id'] ?? 0),
            $warehouseId,
            $quantityType,
        );
        $line['purchase_unit_price'] = $purchasePrice['price'];
        $line['purchase_unit_price_source'] = $purchasePrice['source'];

        return $line;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function warehouseOptionById(int $warehouseId): ?array
    {
        $warehouse = collect($this->warehouses)->firstWhere('id', $warehouseId);

        return is_array($warehouse) ? $warehouse : null;
    }

    private function normalizedWarehouseCode(mixed $code): string
    {
        $code = trim((string) $code);

        return $code === '' ? '' : str_pad($code, 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function earliestExpectedArrivalDateFromRows(array $rows, ?OrderChannel $orderChannel = null): ?string
    {
        return collect($rows)
            ->filter(fn (array $row): bool => $orderChannel !== OrderChannel::EOS || (bool) ($row['is_eos_available'] ?? false))
            ->pluck('default_expected_arrival_date')
            ->filter()
            ->map(function ($date): ?string {
                try {
                    return Carbon::parse((string) $date)->toDateString();
                } catch (\Throwable) {
                    return null;
                }
            })
            ->filter()
            ->sort()
            ->first();
    }

    /**
     * @param  array<int>  $contractorIds
     * @param  array<int>  $jxContractorIds
     */
    private function defaultExpectedArrivalDateForContractors(
        int $warehouseId,
        array $contractorIds,
        array $jxContractorIds,
        OrderChannel $orderChannel
    ): string {
        $searchService = app(OrderRegistrationSearchService::class);
        $targetContractorIds = collect($contractorIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->when(
                $orderChannel === OrderChannel::EOS,
                fn ($contractors) => $contractors->filter(fn (int $id): bool => in_array($id, $jxContractorIds, true))
            )
            ->unique()
            ->values();

        $date = $targetContractorIds
            ->map(fn (int $contractorId): string => $searchService->defaultExpectedArrivalDate($warehouseId, $contractorId, $orderChannel))
            ->filter()
            ->sort()
            ->first();

        return $date ?: $this->fallbackExpectedArrivalDate();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveExpectedArrivalDateForLine(array $row, int $contractorId): string
    {
        $warehouseId = (int) ($row['warehouse_id'] ?? $this->warehouseId ?? 0);
        $orderChannel = OrderChannel::tryFrom((string) ($row['order_channel'] ?? '')) ?? OrderChannel::FAX;
        $fallback = ($warehouseId > 0 && $contractorId > 0)
            ? app(OrderRegistrationSearchService::class)->defaultExpectedArrivalDate($warehouseId, $contractorId, $orderChannel)
            : $this->fallbackExpectedArrivalDate();

        try {
            $date = filled($row['default_expected_arrival_date'] ?? null)
                ? Carbon::parse((string) $row['default_expected_arrival_date'])->toDateString()
                : $fallback;
        } catch (\Throwable) {
            $date = $fallback;
        }

        return Carbon::parse($date)->lt(Carbon::today())
            ? Carbon::today()->toDateString()
            : $date;
    }

    private function fallbackExpectedArrivalDate(): string
    {
        return Carbon::parse(ClientSetting::freshSystemDateYMD('order_registration:default_arrival'))
            ->addDay()
            ->toDateString();
    }

    /**
     * @return array{price: float, source: string}
     */
    private function purchasePriceForLine(int $itemId, int $supplierPartnerId, int $warehouseId, QuantityType $quantityType): array
    {
        if ($itemId < 1) {
            return ['price' => 0.0, 'source' => 'NONE'];
        }

        $prices = app(PurchasePriceService::class)->getPrice(
            $itemId,
            $supplierPartnerId > 0 ? $supplierPartnerId : null,
            $warehouseId > 0 ? $warehouseId : null,
            now()->toDateString(),
        );

        return [
            'price' => (float) ($quantityType === QuantityType::CASE
                ? ($prices['case_price'] ?? 0)
                : ($prices['unit_price'] ?? 0)),
            'source' => (string) ($prices['source'] ?? 'NONE'),
        ];
    }

    private function normalizeLineOrderQuantity(mixed $quantity): int
    {
        $value = preg_replace('/[^0-9]/', '', mb_convert_kana((string) $quantity, 'as'));

        return $value === '' ? 0 : max(0, (int) $value);
    }

    private function resetLineContractorFilterIfInvalid(): void
    {
        if ($this->lineContractorFilter === '') {
            return;
        }

        $hasSelectedContractor = collect($this->lines)
            ->contains(fn (array $line): bool => (string) ($line['contractor_id'] ?? '') === $this->lineContractorFilter);

        if (! $hasSelectedContractor) {
            $this->lineContractorFilter = '';
        }
    }

    /**
     * @return array<int>
     */
    private function completionFaxFileIds(): array
    {
        return collect($this->completionResult['files'] ?? [])
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function syncCompletionFaxFilePath(int $dataFileId, ?string $faxFilePath): void
    {
        $this->completionResult['files'] = collect($this->completionResult['files'] ?? [])
            ->map(function (array $file) use ($dataFileId, $faxFilePath): array {
                if ((int) ($file['id'] ?? 0) === $dataFileId) {
                    $file['fax_file_path'] = $faxFilePath;
                    $file['fax_error'] = null;
                }

                return $file;
            })
            ->values()
            ->toArray();
    }

    private function resetCompletionFaxDownloadModal(): void
    {
        $this->showCompletionFaxDownloadModal = false;
        $this->completionFaxDownloadDataFileId = null;
        $this->completionFaxCommunicationNotes = '';
    }

    private function resetCompletionDetailModal(): void
    {
        $this->showCompletionDetailModal = false;
        $this->completionDetailDataFileId = null;
    }

    private function normalizedCompletionFaxCommunicationNotes(): ?string
    {
        $notes = trim(str_replace(["\r\n", "\r"], "\n", $this->completionFaxCommunicationNotes));

        return $notes === '' ? null : mb_substr($notes, 0, 500);
    }

    private function resetSearchState(): void
    {
        $this->searchRows = [];
        $this->searchQuantities = [];
        $this->orderCandidateItems = [];
        $this->salesRows = [];
        $this->salesQuantities = [];
        $this->salesSearchError = null;
        $this->showSalesHistoryModal = false;
        $this->showSalesBasedExternalOrderPreviewModal = false;
        $this->closeWarehouseStockModal();
        $this->resetSalesBasedExternalOrderPreview();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function registrationLines(): array
    {
        return collect($this->lines)
            ->map(function (array $line): array {
                $itemContractorLinked = array_key_exists('item_contractor_linked', $line)
                    ? (bool) $line['item_contractor_linked']
                    : true;
                $isEosAvailable = $itemContractorLinked && (bool) ($line['is_eos_available'] ?? false);
                $orderChannel = OrderChannel::tryFrom((string) ($line['order_channel'] ?? ''))
                    ?? ($isEosAvailable ? OrderChannel::EOS : OrderChannel::FAX);

                if (! $isEosAvailable) {
                    $orderChannel = OrderChannel::FAX;
                }

                $line['order_channel'] = $orderChannel->value;
                $line['order_channel_label'] = $orderChannel->label();
                $line['is_eos_available'] = $isEosAvailable;
                $line['item_contractor_linked'] = $itemContractorLinked;

                return $line;
            })
            ->values()
            ->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function visibleRegistrationLinesForOutput(): array
    {
        $contractorFilter = (string) $this->lineContractorFilter;

        return collect($this->registrationLines())
            ->filter(fn (array $line): bool => $contractorFilter === '' || (string) ($line['contractor_id'] ?? '') === $contractorFilter)
            ->values()
            ->toArray();
    }

    /**
     * @return array<int>
     */
    private function visibleRegistrationLineIndexes(): array
    {
        $contractorFilter = (string) $this->lineContractorFilter;

        return collect($this->lines)
            ->filter(fn (array $line): bool => $contractorFilter === '' || (string) ($line['contractor_id'] ?? '') === $contractorFilter)
            ->keys()
            ->map(fn (int|string $index): int => (int) $index)
            ->values()
            ->toArray();
    }

    /**
     * @return array<int>
     */
    private function normalizedSelectedRegistrationLineIndexes(): array
    {
        return collect($this->selectedRegistrationLineIndexes)
            ->map(fn (mixed $index): int => (int) $index)
            ->filter(fn (int $index): bool => $index >= 0 && isset($this->lines[$index]))
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * @return array<int>
     */
    private function selectedVisibleRegistrationLineIndexes(): array
    {
        $visibleIndexes = $this->visibleRegistrationLineIndexes();

        return collect($this->normalizedSelectedRegistrationLineIndexes())
            ->filter(fn (int $index): bool => in_array($index, $visibleIndexes, true))
            ->values()
            ->toArray();
    }

    private function clearRegistrationLineSelection(): void
    {
        $this->selectedRegistrationLineIndexes = [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $visibleLines
     */
    private function registrationListContractorFilterLabel(array $visibleLines): string
    {
        if ($this->lineContractorFilter === '') {
            return '';
        }

        $line = collect($visibleLines)->first();
        if (! is_array($line)) {
            return '';
        }

        $code = trim((string) ($line['contractor_code'] ?? ''));
        $name = trim((string) ($line['contractor_name'] ?? ''));

        return trim(($code !== '' ? "[{$code}]" : '').($name !== '' ? $name : ''));
    }

    /**
     * @param  array<int, array<string, mixed>>  $files
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function completionDetailLinesByFile(array $files): array
    {
        $candidateIdsByFileId = collect($files)
            ->mapWithKeys(function (array $file): array {
                $fileId = (int) ($file['id'] ?? 0);
                $candidateIds = collect($file['candidate_ids'] ?? [])
                    ->map(fn ($id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->values()
                    ->all();

                return $fileId > 0 && $candidateIds !== []
                    ? [$fileId => $candidateIds]
                    : [];
            })
            ->all();

        $candidateIds = collect($candidateIdsByFileId)
            ->flatten()
            ->unique()
            ->values()
            ->all();

        if ($candidateIds === []) {
            return [];
        }

        $candidatesById = WmsOrderCandidate::query()
            ->with(['item', 'contractor', 'supplier.partner'])
            ->whereIn('id', $candidateIds)
            ->get()
            ->keyBy('id');

        return collect($candidateIdsByFileId)
            ->mapWithKeys(function (array $ids, int|string $fileId) use ($candidatesById): array {
                $lines = collect($ids)
                    ->map(fn (int $id): ?WmsOrderCandidate => $candidatesById->get($id))
                    ->filter()
                    ->map(fn (WmsOrderCandidate $candidate): array => $this->completionDetailLineFromCandidate($candidate))
                    ->values()
                    ->toArray();

                return [(int) $fileId => $lines];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function completionDetailLineFromCandidate(WmsOrderCandidate $candidate): array
    {
        $quantityType = $candidate->quantity_type instanceof QuantityType
            ? $candidate->quantity_type
            : QuantityType::tryFrom((string) $candidate->quantity_type);
        $orderChannel = $candidate->order_channel instanceof OrderChannel
            ? $candidate->order_channel
            : OrderChannel::tryFrom((string) $candidate->order_channel);
        $orderQuantity = max(0, (int) $candidate->order_quantity);
        $capacityCase = max(1, (int) ($candidate->item?->capacity_case ?? $candidate->purchase_unit ?? 1));
        $purchaseUnitPrice = (float) $candidate->purchase_unit_price;

        return [
            'id' => (int) $candidate->id,
            'order_channel_label' => $orderChannel?->label() ?? '-',
            'expected_arrival_date' => $candidate->expected_arrival_date?->format('Y-m-d') ?? '-',
            'contractor_code' => (string) ($candidate->contractor?->code ?? ''),
            'contractor_name' => (string) ($candidate->contractor?->name ?? '-'),
            'supplier_name' => (string) ($candidate->supplier?->partner?->name ?? '-'),
            'item_code' => (string) ($candidate->item?->code ?? $candidate->item_code ?? ''),
            'item_name' => (string) ($candidate->item?->name ?? '-'),
            'capacity_case' => $capacityCase,
            'quantity_type_label' => $quantityType?->name() ?? '-',
            'order_quantity' => $orderQuantity,
            'total_piece_quantity' => $quantityType === QuantityType::CASE
                ? $orderQuantity * $capacityCase
                : $orderQuantity,
            'purchase_unit_price' => $purchaseUnitPrice,
            'total_amount' => $purchaseUnitPrice * $orderQuantity,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function buildCompletionResult(array $result): array
    {
        $dataFiles = $result['data_file_result']['files'] ?? [];
        $detailLinesByFile = $this->completionDetailLinesByFile($dataFiles);

        $files = collect($dataFiles)
            ->map(function (array $file) use ($detailLinesByFile): array {
                $channel = OrderDataFileChannel::tryFrom((string) ($file['order_channel'] ?? ''));
                $id = (int) ($file['id'] ?? 0);

                return [
                    'id' => $id,
                    'order_channel' => $channel?->value,
                    'order_channel_label' => $channel?->label() ?? 'FAX発注',
                    'warehouse_name' => (string) ($file['warehouse_name'] ?? '-'),
                    'contractor_name' => (string) ($file['contractor_name'] ?? '-'),
                    'supplier_name' => (string) ($file['supplier_name'] ?? $file['contractor_name'] ?? '-'),
                    'expected_arrival_date' => (string) ($file['expected_arrival_date'] ?? '-'),
                    'order_count' => (int) ($file['order_count'] ?? 0),
                    'total_quantity' => (int) ($file['total_quantity'] ?? 0),
                    'total_piece_quantity' => (int) ($file['total_piece_quantity'] ?? $file['total_quantity'] ?? 0),
                    'total_amount' => collect($detailLinesByFile[$id] ?? [])->sum(fn (array $line): float => (float) ($line['total_amount'] ?? 0)),
                    'fax_file_path' => $file['fax_file_path'] ?? null,
                    'fax_error' => $file['fax_error'] ?? null,
                    'lines' => $detailLinesByFile[$id] ?? [],
                ];
            })
            ->filter(fn (array $file): bool => $file['id'] > 0)
            ->values()
            ->toArray();

        return [
            'batch_code' => (string) ($result['batch_code'] ?? ''),
            'candidate_count' => count($result['candidate_ids'] ?? []),
            'incoming_schedule_count' => (int) ($result['incoming_schedule_count'] ?? 0),
            'file_count' => count($files),
            'files' => $files,
        ];
    }

    private function orderChannelEnum(): OrderChannel
    {
        return OrderChannel::from($this->orderChannel);
    }

    private function notifyWarning(string $message): void
    {
        Notification::make()
            ->title($message)
            ->warning()
            ->send();
    }
}
