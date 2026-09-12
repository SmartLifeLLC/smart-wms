<?php

namespace App\Filament\Resources\WmsOrderCandidates\Pages;

use App\Enums\AutoOrder\CalculationType;
use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\JobProcessName;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\AutoOrder\OriginType;
use App\Enums\AutoOrder\SettlementStatus;
use App\Enums\AutoOrder\TransmissionType;
use App\Enums\QuantityType;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsOrderCandidates\Tables\WmsOrderCandidatesTable;
use App\Filament\Resources\WmsOrderCandidates\WmsOrderCandidateResource;
use App\Filament\Resources\WmsOrderConfirmationWaiting\Tables\WmsOrderConfirmationWaitingTable;
use App\Models\Concerns\OptimisticLockException;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\ItemCategory;
use App\Models\Sakemaru\ItemContractor;
use App\Models\Sakemaru\Warehouse;
use App\Models\StatsItemWarehouseSalesSummary;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsOrderCandidate;
use App\Models\WmsWarehouseAutoOrderSetting;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

class ListWmsOrderCandidates extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsOrderCandidateResource::class;

    public array $orderCandidateItems = [];

    public array $fastQuantityInputPayload = [];

    public array $externalOrderContractorsData = [];

    public array $externalOrderJxContractorsData = [];

    public array $externalOrderOtherContractorsData = [];

    public array $selectedExternalOrderContractorIds = [];

    public array $externalOrderCategory2Data = [];

    public array $selectedExternalOrderCategory2Ids = [];

    public array $salesBasedExternalOrderPreviewRows = [];

    public array $salesBasedExternalOrderPreviewConditions = [];

    public ?string $salesBasedExternalOrderPreviewError = null;

    private function getOrderingCodeForItem(int $itemId): ?string
    {
        $code = DB::connection('sakemaru')
            ->table('item_search_information')
            ->where('item_id', $itemId)
            ->where('is_used_for_ordering', true)
            ->where('is_active', true)
            ->orderBy('id')
            ->value('search_string');

        return filled($code) ? str_pad((string) $code, 13, '0', STR_PAD_LEFT) : null;
    }

    public function searchItemsForOrderCreate(string $search): array
    {
        if (strlen($search) < 2) {
            return [];
        }
        $search = mb_convert_kana($search, 'as');

        return Item::query()
            ->with('piece_jan_code_information')
            ->where(function ($query) use ($search) {
                $query->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhereHas('item_search_information', function ($q) use ($search) {
                        $q->where('search_string', 'like', "%{$search}%");
                    });
            })
            ->orderBy('code')
            ->limit(20)
            ->get()
            ->map(function ($item) {
                $searchInfo = $item->piece_jan_code_information;
                $searchCode = $searchInfo?->search_string ?? '';

                return [
                    'id' => $item->id,
                    'code' => $item->code,
                    'name' => $item->name,
                    'search_code' => $searchCode,
                    'ordering_code' => $this->getOrderingCodeForItem($item->id),
                    'capacity_case' => $item->capacity_case ?? 1,
                ];
            })
            ->toArray();
    }

    public function searchContractorsForOrderCreate(string $search): array
    {
        $search = trim(mb_convert_kana($search, 'as'));
        if ($search === '' || (mb_strlen($search) < 2 && ! preg_match('/^\d+$/', $search))) {
            return [];
        }

        return Contractor::query()
            ->select(['id', 'code', 'name'])
            ->where(function ($query) use ($search) {
                $query->where('code', 'like', "{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            })
            ->orderBy('code')
            ->limit(30)
            ->get()
            ->map(fn ($contractor) => [
                'id' => $contractor->id,
                'code' => $contractor->code,
                'name' => $contractor->name,
                'label' => "[{$contractor->code}]{$contractor->name}",
            ])
            ->toArray();
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
        $query = Item::query()
            ->select([
                'items.id',
                'items.code',
                'items.name',
                'items.packaging',
                'items.capacity_case',
            ])
            ->with('piece_jan_code_information')
            ->where('items.end_of_sale_type', 'NORMAL');

        // 商品CD・JANコード検索（OR）
        $hasItemCode = $itemCode && strlen($itemCode) >= 1;
        $hasJanCode = $janCode && strlen($janCode) >= 1;
        if ($hasItemCode || $hasJanCode) {
            $itemCode = $hasItemCode ? mb_convert_kana($itemCode, 'as') : null;
            $janCode = $hasJanCode ? mb_convert_kana($janCode, 'as') : null;
            $query->where(function ($q) use ($itemCode, $janCode) {
                if ($itemCode) {
                    $q->where('items.code', 'like', "%{$itemCode}%");
                }
                if ($janCode) {
                    $q->orWhereHas('item_search_information', function ($sq) use ($janCode) {
                        $sq->where('search_string', 'like', "%{$janCode}%");
                    });
                }
            });
        }

        // 商品名検索
        if ($itemName && strlen($itemName) >= 2) {
            $itemName = mb_convert_kana($itemName, 'as');
            $query->where('items.name', 'like', "%{$itemName}%");
        }

        // 発注先フィルタ
        if ($contractorId) {
            $query->whereHas('item_contractors', function ($q) use ($contractorId, $warehouseId) {
                $q->where('contractor_id', $contractorId)
                    ->where('warehouse_id', $warehouseId);
            });
        }

        // カテゴリフィルタ
        if ($category1Id) {
            $query->where('items.item_category1_id', $category1Id);
        }
        if ($category2Id) {
            $query->where('items.item_category2_id', $category2Id);
        }
        if ($category3Id) {
            $query->where('items.item_category3_id', $category3Id);
        }

        // 最終出荷日フィルタ（summariesテーブルから）
        if ($lastShippedFrom || $lastShippedTo) {
            $query->whereExists(function ($q) use ($warehouseId, $lastShippedFrom, $lastShippedTo) {
                $q->select(DB::raw(1))
                    ->from('stats_item_warehouse_sales_summaries')
                    ->whereColumn('stats_item_warehouse_sales_summaries.item_id', 'items.id')
                    ->where('stats_item_warehouse_sales_summaries.warehouse_id', $warehouseId);
                if ($lastShippedFrom) {
                    $q->where('stats_item_warehouse_sales_summaries.last_shipped_at', '>=', $lastShippedFrom);
                }
                if ($lastShippedTo) {
                    $q->where('stats_item_warehouse_sales_summaries.last_shipped_at', '<=', $lastShippedTo);
                }
            });
        }

        $query->orderBy('items.code');

        // ページネーション
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        // 出荷実績サマリを一括取得
        $itemIds = collect($paginator->items())->pluck('id')->toArray();
        $summaries = StatsItemWarehouseSalesSummary::where('warehouse_id', $warehouseId)
            ->whereIn('item_id', $itemIds)
            ->get()
            ->keyBy('item_id');

        // 発注先情報を一括取得
        // 入荷倉庫を特定（仮想倉庫対応）
        $orderWarehouse = Warehouse::find($warehouseId);
        $incomingWarehouseId = ($orderWarehouse?->is_virtual && $orderWarehouse->stock_warehouse_id)
            ? $orderWarehouse->stock_warehouse_id
            : $warehouseId;

        $itemContractors = ItemContractor::where('warehouse_id', $incomingWarehouseId)
            ->whereIn('item_id', $itemIds)
            ->with('contractor')
            ->get()
            ->keyBy('item_id');

        // 既存PENDING候補の数量を取得
        $pendingCandidates = WmsOrderCandidate::where('warehouse_id', $warehouseId)
            ->where('status', CandidateStatus::PENDING)
            ->forCreatedBy(auth()->id())
            ->whereIn('item_id', $itemIds)
            ->get()
            ->groupBy('item_id');

        // 結果を整形
        $data = collect($paginator->items())->map(function ($item) use ($summaries, $itemContractors, $pendingCandidates) {
            $summary = $summaries->get($item->id);
            $ic = $itemContractors->get($item->id);
            $pending = $pendingCandidates->get($item->id);

            $searchInfo = $item->piece_jan_code_information;

            $pendingCaseQty = 0;
            $pendingPieceQty = 0;
            if ($pending) {
                foreach ($pending as $candidate) {
                    if ($candidate->quantity_type === QuantityType::CASE) {
                        $pendingCaseQty += $candidate->order_quantity;
                    } else {
                        $pendingPieceQty += $candidate->order_quantity;
                    }
                }
            }

            return [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'packaging' => $item->packaging,
                'capacity_case' => $item->capacity_case ?? 1,
                'search_code' => $searchInfo?->search_string ?? '',
                'ordering_code' => $this->getOrderingCodeForItem($item->id),
                'contractor_code' => $ic?->contractor?->code,
                'contractor_name' => $ic?->contractor
                    ? "[{$ic->contractor->code}]{$ic->contractor->name}"
                    : null,
                'last_shipped_at' => $summary?->last_shipped_at?->format('m/d'),
                'sales_today_qty' => $summary?->sales_today_qty ?? 0,
                'sales_yesterday_qty' => $summary?->sales_yesterday_qty ?? 0,
                'sales_2days_ago_qty' => $summary?->sales_2days_ago_qty ?? 0,
                'last_3d_qty' => $summary?->last_3d_qty ?? 0,
                'last_5d_qty' => $summary?->last_5d_qty ?? 0,
                'last_7d_qty' => $summary?->last_7d_qty ?? 0,
                'last_30d_qty' => $summary?->last_30d_qty ?? 0,
                'pending_case_qty' => $pendingCaseQty ?: null,
                'pending_piece_qty' => $pendingPieceQty ?: null,
            ];
        })->values()->toArray();

        return [
            'data' => $data,
            'total' => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
        ];
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
        return (int) DB::connection('sakemaru')
            ->table('wms_v_stock_available')
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->sum('available_quantity');
    }

    public function getItemIncomingQuantityForOrderCreate(int $warehouseId, int $itemId): int
    {
        return (int) (DB::connection('sakemaru')
            ->table('wms_order_incoming_schedules')
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->whereIn('status', ['PENDING', 'PARTIAL'])
            ->selectRaw('SUM(expected_quantity - received_quantity) as total_incoming')
            ->value('total_incoming') ?? 0);
    }

    public function mount(): void
    {
        parent::mount();

        // 発注候補生成からのリダイレクト時に通知を表示
        if ($result = session('order_generation_result')) {
            Notification::make()
                ->title('発注候補を生成しました')
                ->body("実行CD: {$result['batchCode']} / {$result['calculated']}件")
                ->success()
                ->send();
        }

        $this->initializeExternalOrderContractors();
        $this->selectedExternalOrderContractorIds = collect($this->externalOrderJxContractorsData)
            ->merge($this->externalOrderOtherContractorsData)
            ->pluck('id')
            ->values()
            ->toArray();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('発注追加')
                ->icon('heroicon-o-plus')
                ->color('success')
                ->modalHeading('発注候補を追加')
                ->modalWidth('7xl')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('追加する')->color('danger'))
                ->modalCancelActionLabel('変更せず閉じる')
                ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
                ->schema([
                    Grid::make(3)->schema([
                        Select::make('warehouse_id')
                            ->label('発注倉庫')
                            ->options(fn () => Warehouse::query()
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"]))
                            ->default(fn () => auth()->user()?->getSelectedWarehouseId())
                            ->searchable()
                            ->required()
                            ->live(),

                        Placeholder::make('incoming_warehouse_display')
                            ->label('入荷倉庫')
                            ->content(function ($get) {
                                $warehouseId = $get('warehouse_id');
                                if (! $warehouseId) {
                                    return new HtmlString("<span class='text-gray-400'>-</span>");
                                }
                                $warehouse = Warehouse::find($warehouseId);
                                if (! $warehouse) {
                                    return new HtmlString("<span class='text-gray-400'>-</span>");
                                }
                                $incoming = $warehouse;
                                if ($warehouse->is_virtual && $warehouse->stock_warehouse_id) {
                                    $incoming = Warehouse::find($warehouse->stock_warehouse_id) ?? $warehouse;
                                }

                                return new HtmlString("<span class='font-bold text-blue-600'>[{$incoming->code}]{$incoming->name}</span>");
                            }),

                        ViewField::make('expected_arrival_date')
                            ->label('入荷予定日')
                            ->view('filament.forms.components.smart-date-input')
                            ->viewData(['size' => 'large'])
                            ->default(now()->addDay()->toDateString())
                            ->live()
                            ->required(),
                    ]),

                    ViewField::make('items_table')
                        ->view('filament.components.order-candidate-create-items')
                        ->hiddenLabel(),
                ])
                ->action(function (array $data) {
                    $items = $this->orderCandidateItems;

                    if (empty($items)) {
                        Notification::make()
                            ->title('エラー')
                            ->body('商品を追加してください')
                            ->danger()
                            ->send();

                        return;
                    }

                    // 同日・同倉庫のPENDINGジョブを再利用、なければ新規作成
                    $warehouseId = $data['warehouse_id'];
                    $existingJob = WmsAutoOrderJobControl::where('process_name', JobProcessName::ORDER_CALC)
                        ->where('settlement_status', SettlementStatus::PENDING)
                        ->where('created_by', auth()->id())
                        ->where('warehouse_id', $warehouseId)
                        ->whereDate('started_at', today())
                        ->orderByDesc('id')
                        ->first();

                    if ($existingJob) {
                        $batchCode = $existingJob->batch_code;
                    } else {
                        $newJob = WmsAutoOrderJobControl::startJob(
                            processName: JobProcessName::ORDER_CALC,
                            createdBy: auth()->id(),
                            warehouseId: $warehouseId,
                            batchCode: WmsAutoOrderJobControl::generateBatchCode($warehouseId),
                        );
                        $batchCode = $newJob->batch_code;
                        $newJob->markAsSuccess(0);
                    }

                    // 入荷倉庫を特定（仮想倉庫対応）
                    $orderWarehouse = Warehouse::find($data['warehouse_id']);
                    $incomingWarehouseId = ($orderWarehouse?->is_virtual && $orderWarehouse->stock_warehouse_id)
                        ? $orderWarehouse->stock_warehouse_id
                        : $data['warehouse_id'];

                    $created = 0;
                    $errors = [];

                    foreach ($items as $itemData) {
                        $itemId = $itemData['item_id'];
                        $orderQuantity = (int) ($itemData['order_quantity'] ?? 0);
                        $itemCode = $itemData['item_code'] ?? null;
                        $searchCode = $itemData['search_code'] ?? null;
                        $orderingCode = $this->getOrderingCodeForItem($itemId)
                            ?? (filled($itemData['ordering_code'] ?? null) ? $itemData['ordering_code'] : null);

                        if ($orderQuantity <= 0) {
                            $errors[] = "[{$itemCode}]: 数量が不正です";

                            continue;
                        }

                        // 販売終了品チェック
                        $item = Item::find($itemId);
                        if ($item && $item->end_of_sale_type !== 'NORMAL') {
                            $errors[] = "[{$itemCode}] {$item->name}: 販売終了品";

                            continue;
                        }

                        // quantity_type の判定
                        $quantityTypeValue = $itemData['quantity_type'] ?? 'PIECE';
                        $quantityType = QuantityType::from($quantityTypeValue);

                        // 重複チェック（同じ商品×同じquantity_type）
                        if (WmsOrderCandidate::where('warehouse_id', $data['warehouse_id'])
                            ->where('item_id', $itemId)
                            ->where('quantity_type', $quantityType)
                            ->where('status', CandidateStatus::PENDING)
                            ->forCreatedBy(auth()->id())
                            ->exists()) {
                            $errors[] = "[{$itemCode}] {$item->name} ({$quantityType->name()}): 既に存在";

                            continue;
                        }

                        // 発注先取得
                        $itemContractor = ItemContractor::where('warehouse_id', $incomingWarehouseId)
                            ->where('item_id', $itemId)
                            ->first();

                        if (! $itemContractor) {
                            $errors[] = "[{$itemCode}] {$item->name}: 発注先未設定";

                            continue;
                        }

                        $contractor = Contractor::find($itemContractor->contractor_id);
                        $supplierId = $itemContractor->supplier_id;

                        // quantity_type に応じた単価
                        $purchaseUnitPrice = $quantityType === QuantityType::CASE
                            ? $item->current_price?->purchase_case_price
                            : $item->current_price?->purchase_unit_price;

                        // 在庫・入荷予定数をリアルタイム取得
                        $currentStock = $this->getItemStockForOrderCreate($data['warehouse_id'], $itemId);
                        $incomingQty = $this->getItemIncomingQuantityForOrderCreate($incomingWarehouseId, $itemId);

                        WmsOrderCandidate::create([
                            'batch_code' => $batchCode,
                            'warehouse_id' => $data['warehouse_id'],
                            'item_id' => $itemId,
                            'item_code' => $itemCode,
                            'search_code' => $searchCode,
                            'ordering_code' => $orderingCode,
                            'contractor_id' => $itemContractor->contractor_id,
                            'supplier_id' => $supplierId,
                            'purchase_unit_price' => $purchaseUnitPrice,
                            'current_effective_stock' => $currentStock,
                            'incoming_quantity' => $incomingQty,
                            'safety_stock' => $itemContractor->safety_stock,
                            'self_shortage_qty' => 0,
                            'satellite_demand_qty' => 0,
                            'suggested_quantity' => $orderQuantity,
                            'order_quantity' => $orderQuantity,
                            'quantity_type' => $quantityType,
                            'expected_arrival_date' => $data['expected_arrival_date'],
                            'original_arrival_date' => $data['expected_arrival_date'],
                            'status' => CandidateStatus::PENDING,
                            'lot_status' => LotStatus::RAW,
                            'origin_type' => OriginType::USER,
                            'is_manually_modified' => true,
                            'modified_by' => auth()->id(),
                            'modified_at' => now(),
                        ]);

                        WmsOrderCalculationLog::create([
                            'batch_code' => $batchCode,
                            'warehouse_id' => $data['warehouse_id'],
                            'item_id' => $itemId,
                            'calculation_type' => CalculationType::EXTERNAL,
                            'contractor_id' => $itemContractor->contractor_id,
                            'source_warehouse_id' => null,
                            'current_effective_stock' => $currentStock,
                            'incoming_quantity' => $incomingQty,
                            'safety_stock_setting' => $itemContractor->safety_stock ?? 0,
                            'lead_time_days' => 0,
                            'calculated_shortage_qty' => $orderQuantity,
                            'calculated_order_quantity' => $orderQuantity,
                            'calculation_details' => [
                                'manual_entry' => true,
                                'created_by' => auth()->id(),
                                'created_at' => now()->toDateTimeString(),
                                'formula' => '手動追加',
                                'case_qty' => $itemData['case_qty'] ?? 0,
                                'piece_qty' => $itemData['piece_qty'] ?? 0,
                                'capacity_case' => $itemData['capacity_case'] ?? 1,
                            ],
                        ]);

                        $created++;
                    }

                    $this->orderCandidateItems = [];

                    if ($created > 0 && empty($errors)) {
                        Notification::make()
                            ->title("{$created}件の発注候補を追加しました")
                            ->success()
                            ->send();
                    } elseif ($created > 0) {
                        Notification::make()
                            ->title("{$created}件追加、".count($errors).'件スキップ')
                            ->body(implode("\n", $errors))
                            ->warning()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('追加できませんでした')
                            ->body(implode("\n", $errors))
                            ->danger()
                            ->send();
                    }

                    $this->redirect(static::getResource()::getUrl('index'));
                }),

            $this->getSalesBasedExternalOrderGenerateAction(),

            Action::make('fastQuantityInput')
                ->label('発注数量編集')
                ->icon('heroicon-o-pencil-square')
                ->color('primary')
                ->modalHeading('発注数量編集')
                ->modalWidth('7xl')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('一括保存')->color('danger'))
                ->modalCancelActionLabel('保存せず閉じる')
                ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
                ->schema(function (): array {
                    $records = $this->getFastQuantityInputRecords();

                    if ($records->isEmpty()) {
                        return [
                            Placeholder::make('empty')
                                ->label('')
                                ->content('現在の条件に該当する発注候補がありません。'),
                        ];
                    }

                    $rows = $this->buildFastQuantityInputRows($records);
                    $this->fastQuantityInputPayload = collect($rows)
                        ->map(fn (array $row): array => [
                            'id' => $row['id'],
                            'case_quantity' => $row['case_quantity'],
                            'piece_quantity' => $row['piece_quantity'],
                        ])
                        ->values()
                        ->toArray();

                    return [
                        ViewField::make('fast_quantity_input')
                            ->view('filament.components.order-candidate-fast-quantity-input')
                            ->viewData(['rows' => $rows])
                            ->hiddenLabel(),
                    ];
                })
                ->action(function () {
                    $payload = collect($this->fastQuantityInputPayload)
                        ->filter(fn ($row) => isset($row['id']))
                        ->keyBy(fn ($row) => (int) $row['id']);

                    if ($payload->isEmpty()) {
                        Notification::make()
                            ->title('更新対象がありません')
                            ->warning()
                            ->send();

                        return;
                    }

                    $records = WmsOrderCandidate::query()
                        ->with(['item.current_price'])
                        ->whereIn('id', $payload->keys()->all())
                        ->get();

                    $updated = 0;
                    $merged = 0;
                    $skipped = 0;
                    $errors = [];
                    $userId = auth()->id();

                    foreach ($records as $record) {
                        if ($record->status !== CandidateStatus::PENDING) {
                            $skipped++;

                            continue;
                        }

                        $row = $payload->get((int) $record->id, []);
                        $caseQty = max(0, (int) ($row['case_quantity'] ?? 0));
                        $pieceQty = max(0, (int) ($row['piece_quantity'] ?? 0));

                        if ($caseQty > 0 && $pieceQty > 0) {
                            $errors[] = "[{$record->item_code}] ケースとバラはどちらか一方だけ入力してください。";

                            continue;
                        }

                        $targetType = $caseQty > 0 ? QuantityType::CASE : QuantityType::PIECE;
                        $newQuantity = $caseQty > 0 ? $caseQty : $pieceQty;

                        if ($caseQty === 0 && $pieceQty === 0) {
                            $targetType = $record->quantity_type;
                        }

                        try {
                            $result = WmsOrderCandidatesTable::applyQuantityChange($record, $targetType, $newQuantity, $userId);

                            match ($result) {
                                'updated' => $updated++,
                                'merged' => $merged++,
                                default => $skipped++,
                            };
                        } catch (OptimisticLockException $e) {
                            $errors[] = "[{$record->item_code}] {$e->getMessage()}";
                        } catch (\Throwable $e) {
                            $errors[] = "[{$record->item_code}] 更新できませんでした: {$e->getMessage()}";
                        }
                    }

                    if ($updated > 0 || $merged > 0) {
                        Notification::make()
                            ->title("発注数量を保存しました: 更新 {$updated}件 / 統合 {$merged}件")
                            ->body($skipped > 0 ? "変更なし・対象外 {$skipped}件" : null)
                            ->success()
                            ->send();
                    } elseif (empty($errors)) {
                        Notification::make()
                            ->title('変更はありません')
                            ->warning()
                            ->send();
                    }

                    if (! empty($errors)) {
                        Notification::make()
                            ->title(count($errors).'件で更新できませんでした')
                            ->body(implode("\n", array_slice($errors, 0, 5)))
                            ->danger()
                            ->send();
                    }
                }),

            ActionGroup::make([
                Action::make('approveAll')
                    ->label('全て承認')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('発注候補を全て承認')
                    ->modalDescription(function () {
                        $count = WmsOrderCandidate::where('status', CandidateStatus::PENDING)
                            ->forCreatedBy(auth()->id())
                            ->count();

                        return "承認前（PENDING）の発注候補 {$count}件 を全て承認します。";
                    })
                    ->modalSubmitActionLabel('全て承認')
                    ->action(function () {
                        $updated = 0;
                        WmsOrderCandidate::where('status', CandidateStatus::PENDING)
                            ->forCreatedBy(auth()->id())
                            ->orderBy('id')
                            ->chunkById(500, function ($candidates) use (&$updated) {
                                foreach ($candidates as $candidate) {
                                    $candidate->update([
                                        'status' => CandidateStatus::APPROVED,
                                        'updated_at' => now(),
                                    ]);
                                    $updated++;
                                }
                            });

                        Notification::make()
                            ->title('発注候補を全て承認しました')
                            ->body("{$updated}件 を承認しました。")
                            ->success()
                            ->send();
                    }),

                Action::make('deleteAllPending')
                    ->label('承認前を全削除')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('承認前の発注候補を全削除')
                    ->modalDescription(function () {
                        $count = WmsOrderCandidate::where('status', CandidateStatus::PENDING)
                            ->forCreatedBy(auth()->id())
                            ->count();

                        return "承認前（PENDING）の発注候補 {$count}件 を全て削除します。この操作は取り消せません。";
                    })
                    ->modalSubmitActionLabel('全削除')
                    ->action(function () {
                        $deleted = WmsOrderCandidate::where('status', CandidateStatus::PENDING)
                            ->forCreatedBy(auth()->id())
                            ->delete();

                        Notification::make()
                            ->title('承認前の発注候補を削除しました')
                            ->body("{$deleted}件 を削除しました。")
                            ->success()
                            ->send();
                    }),
            ])
                ->label('管理者メニュー')
                ->icon('heroicon-o-shield-check')
                ->color('gray')
                ->button(),
        ];
    }

    private function getSalesBasedExternalOrderGenerateAction(): Action
    {
        $selectedWarehouseId = auth()->user()?->getSelectedWarehouseId();
        $selectedWarehouse = $selectedWarehouseId ? Warehouse::find($selectedWarehouseId) : null;
        $selectedWarehouseName = $selectedWarehouse?->name ?? '未選択';

        return Action::make('generateSalesBasedExternalOrder')
            ->label('外部発注候補生成')
            ->icon('heroicon-o-chart-bar')
            ->color('info')
            ->modalWidth('7xl')
            ->extraModalWindowAttributes(['class' => 'incoming-detail-modal sales-based-transfer-generate-modal'])
            ->modalHeading("外部発注候補生成（{$selectedWarehouseName}）")
            ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
            ->modalSubmitAction(fn (Action $action) => $action->makeModalSubmitAction('submit')->label('候補表示')->color('danger'))
            ->modalCancelActionLabel('表示せず閉じる')
            ->disabled(! $selectedWarehouse)
            ->mountUsing(function ($schema): void {
                $this->resetSalesBasedExternalOrderPreview();
                $this->initializeExternalOrderContractors();
                $this->initializeExternalOrderCategory2();
                if (empty($this->selectedExternalOrderContractorIds)) {
                    $this->selectedExternalOrderContractorIds = collect($this->externalOrderJxContractorsData)
                        ->merge($this->externalOrderOtherContractorsData)
                        ->pluck('id')
                        ->values()
                        ->toArray();
                }
                if (empty($this->selectedExternalOrderCategory2Ids)) {
                    $this->selectedExternalOrderCategory2Ids = collect($this->externalOrderCategory2Data)
                        ->pluck('id')
                        ->values()
                        ->toArray();
                }
                $schema?->fill([
                    'sales_start_date' => now()->subDays(2)->toDateString(),
                    'sales_end_date' => now()->toDateString(),
                    'auto_order_flag_filter' => 'ignore',
                ]);
            })
            ->schema([
                Grid::make(3)->schema([
                    Grid::make(2)->schema([
                        Grid::make(2)->schema([
                            ViewField::make('sales_start_date')
                                ->label('販売実績 開始日')
                                ->view('filament.forms.components.smart-date-input')
                                ->extraAttributes(['class' => 'external-order-generation-date-field'])
                                ->default(now()->subDays(2)->toDateString())
                                ->live()
                                ->afterStateUpdated(fn () => $this->resetSalesBasedExternalOrderPreview())
                                ->required(),
                            ViewField::make('sales_end_date')
                                ->label('販売実績 終了日')
                                ->view('filament.forms.components.smart-date-input')
                                ->extraAttributes(['class' => 'external-order-generation-date-field'])
                                ->default(now()->toDateString())
                                ->live()
                                ->afterStateUpdated(fn () => $this->resetSalesBasedExternalOrderPreview())
                                ->required(),
                        ])
                            ->columnSpanFull(),
                        ViewField::make('category2_selector')
                            ->view('filament.components.category-selection')
                            ->viewData([
                                'categoriesProperty' => 'externalOrderCategory2Data',
                                'fallbackMethod' => 'getExternalOrderCategory2ForSalesBasedGeneration',
                                'selectedProperty' => 'selectedExternalOrderCategory2Ids',
                                'label' => '中分類',
                                'compactListHeight' => true,
                                'layout' => 'externalOrderGeneration',
                            ])
                            ->hiddenLabel()
                            ->columnSpanFull(),
                    ])
                        ->columnSpan(1),
                    ViewField::make('contractor_selector')
                        ->view('filament.components.contractor-selection')
                        ->viewData([
                            'grouped' => true,
                            'primaryContractorsProperty' => 'externalOrderJxContractorsData',
                            'primaryFallbackMethod' => 'getExternalOrderJxContractorsForSalesBasedGeneration',
                            'secondaryContractorsProperty' => 'externalOrderOtherContractorsData',
                            'secondaryFallbackMethod' => 'getExternalOrderOtherContractorsForSalesBasedGeneration',
                            'selectedProperty' => 'selectedExternalOrderContractorIds',
                            'primaryLabel' => 'EOS仕入先',
                            'secondaryLabel' => 'FAX仕入先',
                            'compactListHeight' => true,
                            'layout' => 'externalOrderGeneration',
                        ])
                        ->hiddenLabel()
                        ->columnSpan(2),
                ])->extraAttributes(['class' => 'external-order-generation-layout']),
            ])
            ->action(function (Action $action) {
                $this->calculateSalesBasedExternalOrderPreview();

                if (empty($this->salesBasedExternalOrderPreviewRows)) {
                    Notification::make()
                        ->title('表示できる候補がありません')
                        ->body($this->salesBasedExternalOrderPreviewError ?? '条件を変更して再度候補表示してください。')
                        ->warning()
                        ->send();

                    $action->halt();
                }

                $this->replaceMountedAction('salesBasedExternalOrderPreviewModal');
            });
    }

    protected function salesBasedExternalOrderPreviewModalAction(): Action
    {
        return Action::make('salesBasedExternalOrderPreviewModal')
            ->label('外部発注候補表示')
            ->modalWidth('full')
            ->extraModalWindowAttributes(['class' => 'incoming-detail-modal sales-based-transfer-preview-modal'])
            ->modalHeading('外部発注候補リスト')
            ->modalSubmitAction(fn (Action $action) => $action->makeModalSubmitAction('submit')->label('候補生成')->color('danger'))
            ->modalCancelAction(fn (Action $action) => $action
                ->label('閉じる')
                ->alpineClickHandler("if (confirm('外部発注候補リストを閉じますか？入力中の内容は破棄されます。')) close()"))
            ->closeModalByClickingAway(false)
            ->closeModalByEscaping(false)
            ->modalCloseButton(false)
            ->schema([
                ViewField::make('sales_based_external_order_preview_edit')
                    ->view('filament.components.sales-based-external-order-preview-edit')
                    ->hiddenLabel(),
            ])
            ->action(function (): void {
                $this->createSalesBasedExternalOrderPreviewCandidates();
            });
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

        $selectedWarehouseId = auth()->user()?->getSelectedWarehouseId();
        if (! $selectedWarehouseId) {
            $this->salesBasedExternalOrderPreviewError = '倉庫が選択されていません。';

            return;
        }

        $contractorIds = array_values(array_unique(array_map('intval', $this->selectedExternalOrderContractorIds)));
        $category2Ids = array_values(array_unique(array_map('intval', $this->selectedExternalOrderCategory2Ids)));
        $allCategory2Ids = collect($this->externalOrderCategory2Data ?: $this->getExternalOrderCategory2ForSalesBasedGeneration())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->toArray();
        if (empty($contractorIds)) {
            $this->salesBasedExternalOrderPreviewError = '仕入先を1件以上選択してください。';

            return;
        }
        if (empty($category2Ids)) {
            $this->salesBasedExternalOrderPreviewError = '中分類を1件以上選択してください。';

            return;
        }

        $selectedWarehouse = Warehouse::find($selectedWarehouseId);
        $data = $this->getMountedSalesBasedExternalOrderActionData();
        $startDate = $data['sales_start_date'] ?? now()->subDays(2)->toDateString();
        $endDate = $data['sales_end_date'] ?? now()->toDateString();

        try {
            $startDate = \Carbon\Carbon::parse($startDate)->toDateString();
            $endDate = \Carbon\Carbon::parse($endDate)->toDateString();
        } catch (\Throwable) {
            $this->salesBasedExternalOrderPreviewError = '販売実績の期間を正しく指定してください。';

            return;
        }

        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $days = max(1, \Carbon\Carbon::parse($startDate)->diffInDays(\Carbon\Carbon::parse($endDate)) + 1);
        $warehouseIds = $this->getSalesBasedExternalOrderGenerationWarehouseIds($selectedWarehouseId);
        if (empty($warehouseIds)) {
            $this->salesBasedExternalOrderPreviewError = '対象倉庫がありません。';

            return;
        }

        $this->salesBasedExternalOrderPreviewConditions = [
            'expected_arrival_date' => now()->addDay()->toDateString(),
            'sales_start_date' => $startDate,
            'sales_end_date' => $endDate,
            'selected_warehouse_name' => $selectedWarehouse?->name ?? '未選択',
            'target_warehouse_name' => '外部発注',
            'auto_order_flag_filter' => '考慮しない',
            'days' => $days,
            'contractor_count' => count($contractorIds),
            'category2_count' => count($category2Ids),
            'category2_total_count' => count($allCategory2Ids),
        ];

        $pendingJob = WmsAutoOrderJobControl::findPendingSettlementForWarehouse(
            $selectedWarehouseId,
            auth()->id(),
            [JobProcessName::ORDER_CALC, JobProcessName::SALES_BASED_CALC]
        );
        $batchCode = $pendingJob?->batch_code;

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
            ->query()
            ->fromSub(
                DB::connection('sakemaru')
                    ->table('wms_v_stock_available')
                    ->where('warehouse_id', $selectedWarehouseId)
                    ->selectRaw('DISTINCT warehouse_id, item_id, real_stock_id, available_for_wms as stock_qty'),
                'dedup_stocks'
            )
            ->selectRaw('item_id, SUM(stock_qty) as effective_stock')
            ->groupBy('item_id');

        $incomingSubquery = DB::connection('sakemaru')
            ->table('wms_order_incoming_schedules')
            ->where('warehouse_id', $selectedWarehouseId)
            ->whereIn('status', ['PENDING', 'PARTIAL'])
            ->selectRaw('item_id, SUM(expected_quantity - received_quantity) as incoming_qty')
            ->groupBy('item_id');

        $targetItemContractorsSubquery = DB::connection('sakemaru')
            ->table('item_contractors')
            ->where('warehouse_id', $selectedWarehouseId)
            ->whereNotIn('contractor_id', $internalContractorIds ?: [0])
            ->whereIn('contractor_id', $contractorIds)
            ->selectRaw('
                warehouse_id,
                item_id,
                contractor_id,
                MIN(supplier_id) as supplier_id,
                MAX(COALESCE(purchase_unit, 1)) as purchase_unit
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
            ->joinSub($salesSubquery, 'sales', function ($join) {
                $join->on('sales.item_id', '=', 'item_contractors.item_id');
            })
            ->leftJoinSub($stockSubquery, 'stocks', function ($join) {
                $join->on('stocks.item_id', '=', 'item_contractors.item_id');
            })
            ->leftJoinSub($incomingSubquery, 'incoming', function ($join) {
                $join->on('incoming.item_id', '=', 'item_contractors.item_id');
            })
            ->where('items.end_of_sale_type', 'NORMAL')
            ->where('items.is_ended', false)
            ->where(fn ($query) => $query->whereNull('items.start_of_sale_date')->orWhere('items.start_of_sale_date', '<=', now()->toDateString()))
            ->where(fn ($query) => $query->whereNull('items.end_of_sale_date')->orWhere('items.end_of_sale_date', '>', now()->toDateString()))
            ->where('contractors.is_auto_change_order', true)
            ->whereExists(function ($query) {
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

        if ($batchCode) {
            $query->whereNotExists(function ($query) use ($batchCode) {
                $query->selectRaw('1')
                    ->from('wms_order_candidates as existing_candidates')
                    ->where('existing_candidates.batch_code', $batchCode)
                    ->where('existing_candidates.status', CandidateStatus::APPROVED->value)
                    ->whereColumn('existing_candidates.warehouse_id', 'item_contractors.warehouse_id')
                    ->whereColumn('existing_candidates.item_id', 'item_contractors.item_id')
                    ->whereColumn('existing_candidates.contractor_id', 'item_contractors.contractor_id')
                    ->whereColumn('existing_candidates.supplier_id', 'item_contractors.supplier_id');
            });
        }

        $this->salesBasedExternalOrderPreviewRows = $query
            ->selectRaw('
                item_contractors.warehouse_id as warehouse_id,
                item_contractors.item_id as item_id,
                item_contractors.contractor_id as contractor_id,
                item_contractors.supplier_id as supplier_id,
                item_category2.code as item_category2_code,
                items.code as item_code,
                items.name as item_name,
                items.packaging as item_packaging,
                items.capacity_case as capacity_case,
                contractors.name as contractor_name,
                supplier_partners.name as supplier_name,
                sales.sales_qty as sales_qty,
                sales.sales_piece_qty as sales_piece_qty,
                sales.return_piece_qty as return_piece_qty,
                sales.transfer_piece_qty as transfer_piece_qty,
                COALESCE(stocks.effective_stock, 0) as effective_stock,
                COALESCE(incoming.incoming_qty, 0) as incoming_qty,
                (COALESCE(stocks.effective_stock, 0) + COALESCE(incoming.incoming_qty, 0)) as projected_stock,
                GREATEST(sales.sales_qty - (COALESCE(stocks.effective_stock, 0) + COALESCE(incoming.incoming_qty, 0)), 0) as shortage_qty,
                COALESCE(item_contractors.purchase_unit, 1) as purchase_unit
            ')
            ->orderBy('contractors.code')
            ->orderBy('supplier_partners.code')
            ->orderBy('items.code')
            ->get()
            ->map(fn ($row) => [
                'warehouse_id' => (int) $row->warehouse_id,
                'item_id' => (int) $row->item_id,
                'contractor_id' => (int) $row->contractor_id,
                'supplier_id' => (int) ($row->supplier_id ?? 0),
                'item_category2_code' => (string) ($row->item_category2_code ?? ''),
                'item_code' => (string) $row->item_code,
                'item_name' => (string) $row->item_name,
                'item_packaging' => (string) ($row->item_packaging ?? ''),
                'capacity_case' => max(1, (int) ($row->capacity_case ?? 1)),
                'contractor_name' => (string) $row->contractor_name,
                'supplier_name' => (string) ($row->supplier_name ?? '-'),
                'sales_qty' => (int) $row->sales_qty,
                'sales_piece_qty' => (int) $row->sales_piece_qty,
                'return_piece_qty' => (int) $row->return_piece_qty,
                'transfer_piece_qty' => (int) $row->transfer_piece_qty,
                'daily_avg_qty' => round(((int) $row->sales_qty) / $days, 2),
                'effective_stock' => (int) $row->effective_stock,
                'incoming_qty' => (int) $row->incoming_qty,
                'projected_stock' => (int) $row->projected_stock,
                'purchase_unit' => max(1, (int) $row->purchase_unit),
                'order_piece_qty' => (int) $row->shortage_qty,
                'input_order_case_qty' => null,
                'input_order_piece_qty' => null,
            ])
            ->toArray();

        if (empty($this->salesBasedExternalOrderPreviewRows)) {
            $this->salesBasedExternalOrderPreviewError = '現在の条件に該当する候補がありません。';
        }
    }

    public function updateSalesBasedExternalOrderPreviewRows(array $rows): void
    {
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

                return $row;
            })
            ->values()
            ->toArray();
    }

    public function updateSalesBasedExternalOrderPreviewExpectedArrivalDate(?string $date): void
    {
        try {
            $this->salesBasedExternalOrderPreviewConditions['expected_arrival_date'] = \Carbon\Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            $this->salesBasedExternalOrderPreviewConditions['expected_arrival_date'] = now()->addDay()->toDateString();
        }
    }

    public function createSalesBasedExternalOrderPreviewCandidates(): void
    {
        $userId = auth()->id();
        if (! $userId) {
            Notification::make()
                ->title('ログインユーザーを取得できませんでした')
                ->danger()
                ->send();

            return;
        }

        if (empty($this->salesBasedExternalOrderPreviewRows)) {
            Notification::make()
                ->title('生成対象の候補がありません')
                ->warning()
                ->send();

            return;
        }

        $selectedWarehouseId = auth()->user()?->getSelectedWarehouseId();
        if (! $selectedWarehouseId) {
            Notification::make()
                ->title('倉庫が選択されていません')
                ->danger()
                ->send();

            return;
        }

        $job = WmsAutoOrderJobControl::findPendingSettlementForWarehouse(
            $selectedWarehouseId,
            $userId,
            [JobProcessName::ORDER_CALC, JobProcessName::SALES_BASED_CALC]
        );

        if (! $job) {
            $job = WmsAutoOrderJobControl::startJob(
                processName: JobProcessName::SALES_BASED_CALC,
                createdBy: $userId,
                warehouseId: $selectedWarehouseId,
                batchCode: WmsAutoOrderJobControl::generateBatchCode($selectedWarehouseId),
            );
            $job->markAsSuccess(0);
        }

        $batchCode = $job->batch_code;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $blankSkipped = 0;
        $now = now();
        try {
            $expectedArrivalDate = \Carbon\Carbon::parse(
                $this->salesBasedExternalOrderPreviewConditions['expected_arrival_date'] ?? $now->copy()->addDay()->toDateString()
            )->toDateString();
        } catch (\Throwable) {
            Notification::make()
                ->title('入荷予定日を正しく指定してください')
                ->danger()
                ->send();

            return;
        }

        DB::connection('sakemaru')->transaction(function () use ($batchCode, $now, $userId, $expectedArrivalDate, &$created, &$updated, &$skipped, &$blankSkipped): void {
            foreach ($this->salesBasedExternalOrderPreviewRows as $row) {
                $warehouseId = (int) ($row['warehouse_id'] ?? 0);
                $itemId = (int) ($row['item_id'] ?? 0);
                $contractorId = (int) ($row['contractor_id'] ?? 0);
                $supplierId = (int) ($row['supplier_id'] ?? 0);

                if ($warehouseId < 1 || $itemId < 1 || $contractorId < 1 || $supplierId < 1) {
                    $skipped++;

                    continue;
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

                $usesCaseQuantity = ! ($inputCaseQuantity === null || $inputCaseQuantity === '');
                $quantityType = $usesCaseQuantity ? QuantityType::CASE : QuantityType::PIECE;
                $quantity = $usesCaseQuantity ? $caseQuantity : $pieceQuantity;
                $existingCandidate = WmsOrderCandidate::query()
                    ->where('warehouse_id', $warehouseId)
                    ->where('item_id', $itemId)
                    ->where('contractor_id', $contractorId)
                    ->where('supplier_id', $supplierId)
                    ->where('status', CandidateStatus::PENDING)
                    ->forCreatedBy($userId)
                    ->first();

                $searchCode = DB::connection('sakemaru')
                    ->table('item_search_information')
                    ->where('item_id', $itemId)
                    ->where('is_used_for_ordering', true)
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->value('search_string');

                if (! $searchCode) {
                    $skipped++;

                    continue;
                }

                $item = Item::with('current_price')->find($itemId);

                $candidateData = [
                    'batch_code' => $batchCode,
                    'warehouse_id' => $warehouseId,
                    'item_id' => $itemId,
                    'item_code' => $row['item_code'] ?? null,
                    'search_code' => $searchCode,
                    'ordering_code' => str_pad((string) $searchCode, 13, '0', STR_PAD_LEFT),
                    'contractor_id' => $contractorId,
                    'supplier_id' => $supplierId,
                    'purchase_unit_price' => $quantityType === QuantityType::CASE
                        ? $item?->current_price?->purchase_case_price
                        : $item?->current_price?->purchase_unit_price,
                    'self_shortage_qty' => (int) ($row['sales_qty'] ?? 0),
                    'satellite_demand_qty' => 0,
                    'suggested_quantity' => max(0, (int) ($row['order_piece_qty'] ?? 0)),
                    'order_quantity' => $quantity,
                    'current_effective_stock' => (int) ($row['effective_stock'] ?? 0),
                    'incoming_quantity' => (int) ($row['incoming_qty'] ?? 0),
                    'safety_stock' => 0,
                    'calculated_shortage_qty' => max(0, (int) ($row['order_piece_qty'] ?? 0)),
                    'purchase_unit' => max(1, (int) ($row['purchase_unit'] ?? 1)),
                    'quantity_type' => $quantityType,
                    'expected_arrival_date' => $expectedArrivalDate,
                    'original_arrival_date' => $expectedArrivalDate,
                    'status' => CandidateStatus::PENDING,
                    'lot_status' => LotStatus::RAW,
                    'origin_type' => OriginType::MANUAL_SALES_BASED,
                    'is_manually_modified' => true,
                    'modified_by' => $userId,
                    'modified_at' => $now,
                ];

                if ($existingCandidate) {
                    $existingCandidate->update($candidateData);
                    $updated++;
                } else {
                    WmsOrderCandidate::create($candidateData);
                    $created++;
                }

                WmsOrderCalculationLog::create([
                    'batch_code' => $batchCode,
                    'warehouse_id' => $warehouseId,
                    'item_id' => $itemId,
                    'calculation_type' => CalculationType::EXTERNAL,
                    'contractor_id' => $contractorId,
                    'source_warehouse_id' => null,
                    'current_effective_stock' => (int) ($row['effective_stock'] ?? 0),
                    'incoming_quantity' => (int) ($row['incoming_qty'] ?? 0),
                    'safety_stock_setting' => 0,
                    'lead_time_days' => 1,
                    'calculated_shortage_qty' => max(0, (int) ($row['order_piece_qty'] ?? 0)),
                    'calculated_order_quantity' => $quantity,
                    'calculation_details' => [
                        'source' => 'sales_based_external_order_preview',
                        'expected_arrival_date' => $expectedArrivalDate,
                        'sales_start_date' => $this->salesBasedExternalOrderPreviewConditions['sales_start_date'] ?? null,
                        'sales_end_date' => $this->salesBasedExternalOrderPreviewConditions['sales_end_date'] ?? null,
                        'sales_qty' => (int) ($row['sales_qty'] ?? 0),
                        'sales_piece_qty' => (int) ($row['sales_piece_qty'] ?? 0),
                        'return_piece_qty' => (int) ($row['return_piece_qty'] ?? 0),
                        'transfer_piece_qty' => (int) ($row['transfer_piece_qty'] ?? 0),
                        'input_case_qty' => $caseQuantity,
                        'input_piece_qty' => $pieceQuantity,
                        'input_blank_as_zero' => false,
                        'created_by' => $userId,
                    ],
                ]);
            }
        });

        $title = $updated > 0
            ? "外部発注候補を {$created}件 生成、{$updated}件 更新しました"
            : "外部発注候補を {$created}件 生成しました";

        Notification::make()
            ->title($title)
            ->body(collect([
                $blankSkipped > 0 ? "未入力の候補 {$blankSkipped}件 は生成しませんでした。" : null,
                $skipped > 0 ? "不正な候補など {$skipped}件 はスキップしました。" : null,
            ])->filter()->implode("\n") ?: null)
            ->success()
            ->send();

        $this->resetSalesBasedExternalOrderPreview();
        $this->dispatch('$refresh');
    }

    public function getExternalOrderContractorsForSalesBasedGeneration(): array
    {
        $internalContractorIds = WmsContractorSetting::query()
            ->where('transmission_type', TransmissionType::INTERNAL->value)
            ->pluck('contractor_id')
            ->toArray();

        return WmsContractorSetting::query()
            ->when(! empty($internalContractorIds), fn ($query) => $query->whereNotIn('contractor_id', $internalContractorIds))
            ->whereHas('contractor', fn ($query) => $query->where('is_auto_change_order', true))
            ->with(['contractor:id,code,name', 'transmissionContractor:id,code,name'])
            ->get()
            ->map(fn ($setting) => [
                'id' => $setting->contractor_id,
                'code' => (string) $setting->contractor->code,
                'name' => $setting->contractor->name,
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

        if (empty($jxContractorIds)) {
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
            ->when(! empty($jxContractorIds), fn ($contractors) => $contractors->whereNotIn('id', $jxContractorIds))
            ->values()
            ->toArray();
    }

    private function initializeExternalOrderContractors(): void
    {
        $this->externalOrderContractorsData = $this->getExternalOrderContractorsForSalesBasedGeneration();
        $this->externalOrderJxContractorsData = $this->getExternalOrderJxContractorsForSalesBasedGeneration();
        $this->externalOrderOtherContractorsData = $this->getExternalOrderOtherContractorsForSalesBasedGeneration();
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
            ->map(fn (ItemCategory $category) => [
                'id' => (int) $category->id,
                'code' => (string) $category->code,
                'name' => (string) $category->name,
            ])
            ->values()
            ->toArray();
    }

    private function initializeExternalOrderCategory2(): void
    {
        $this->externalOrderCategory2Data = $this->getExternalOrderCategory2ForSalesBasedGeneration();
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

    private function getMountedSalesBasedExternalOrderActionData(): array
    {
        $mountedActionKey = array_key_last($this->mountedActions ?? []);

        if ($mountedActionKey === null) {
            return [];
        }

        return $this->mountedActions[$mountedActionKey]['data'] ?? [];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => WmsOrderConfirmationWaitingTable::applyItemContractorJoin(
                $query
                    ->with([
                        'warehouse',
                        'item.current_price',
                        'item.piece_jan_code_information',
                        'contractor',
                        'supplier.partner',
                    ])
            ));
    }

    private function getFastQuantityInputRecords()
    {
        return $this->getFilteredSortedTableQuery()
            ->with(['item.current_price', 'supplier'])
            ->get();
    }

    private function buildFastQuantityInputRows($records): array
    {
        return $records
            ->map(function (WmsOrderCandidate $record): array {
                $capacityCase = (int) ($record->item?->capacity_case ?? 1);
                $isEditable = $record->status === CandidateStatus::PENDING;
                $supplierName = $record->supplier ? "[{$record->supplier->partner_code}]{$record->supplier->partner_name}" : '-';

                return [
                    'id' => $record->id,
                    'item_code' => $record->item_code ?? '-',
                    'item_name' => $record->item?->name ?? '-',
                    'packaging' => $record->item?->packaging ?? '-',
                    'supplier_name' => $supplierName,
                    'calculated_available' => (int) ($record->calculated_available ?? 0),
                    'safety_stock' => (int) ($record->ic_safety_stock ?? $record->safety_stock ?? 0),
                    'auto_order_quantity' => (int) ($record->ic_auto_order_quantity ?? 0),
                    'shortage_qty' => (int) ($record->shortage_qty ?? 0),
                    'case_quantity' => $record->quantity_type === QuantityType::CASE ? (int) $record->order_quantity : 0,
                    'piece_quantity' => $record->quantity_type === QuantityType::PIECE ? (int) $record->order_quantity : 0,
                    'capacity_case' => $capacityCase,
                    'case_disabled' => ! $isEditable || $capacityCase <= 1,
                    'piece_disabled' => ! $isEditable,
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * テーブルレコード取得後に計算ログとItemContractorをプリロード（N+1対策）
     */
    protected function paginateTableQuery(Builder $query): \Illuminate\Contracts\Pagination\Paginator
    {
        $paginator = parent::paginateTableQuery($query);

        // 計算ログとItemContractorを一括プリロード
        $items = $paginator->getCollection();
        if ($items->isNotEmpty()) {
            WmsOrderCandidate::preloadCalculationLogs($items);
            WmsOrderCandidate::preloadItemContractors($items);
            WmsOrderConfirmationWaitingTable::preloadOrderingUnitQuantities($items);

            // 出荷実績サマリを一括プリロード（N+1対策）
            $warehouseItemPairs = $items->map(fn ($r) => ['warehouse_id' => $r->warehouse_id, 'item_id' => $r->item_id]);
            $warehouseIds = $warehouseItemPairs->pluck('warehouse_id')->unique()->toArray();
            $itemIds = $warehouseItemPairs->pluck('item_id')->unique()->toArray();
            $summaries = StatsItemWarehouseSalesSummary::whereIn('warehouse_id', $warehouseIds)
                ->whereIn('item_id', $itemIds)
                ->get()
                ->keyBy(fn ($s) => "{$s->warehouse_id}_{$s->item_id}");
            $items->each(function ($record) use ($summaries) {
                $record->salesSummary = $summaries->get("{$record->warehouse_id}_{$record->item_id}");
            });
        }

        return $paginator;
    }

    protected ?array $presetViewWarehouseData = null;

    protected function getWarehouseDataForPresetViews(): array
    {
        if ($this->presetViewWarehouseData !== null) {
            return $this->presetViewWarehouseData;
        }

        $cacheKey = 'order_candidates_pending_warehouses_all';
        $this->presetViewWarehouseData = cache()->remember($cacheKey, 30, function () {
            $warehouseIds = WmsOrderCandidate::where('status', CandidateStatus::PENDING)
                ->distinct()
                ->pluck('warehouse_id')
                ->toArray();

            $warehouses = Warehouse::whereIn('id', $warehouseIds)
                ->orderBy('code')
                ->get(['id', 'name']);

            return [
                'ids' => $warehouseIds,
                'warehouses' => $warehouses,
            ];
        });

        return $this->presetViewWarehouseData;
    }

    public function getPresetViews(): array
    {
        $userDefaultWarehouseId = auth()->user()?->getSelectedWarehouseId();

        $warehouseData = $this->getWarehouseDataForPresetViews();
        $warehouseIds = $warehouseData['ids'];
        $warehouses = $warehouseData['warehouses'];

        // デフォルト倉庫が発注候補に存在するかチェック
        $hasDefaultWarehouse = $userDefaultWarehouseId && in_array($userDefaultWarehouseId, $warehouseIds);
        $defaultWarehouse = $hasDefaultWarehouse ? $warehouses->firstWhere('id', $userDefaultWarehouseId) : null;

        // 「全て」タブは常に表示（キー'default'でAdvancedTablesのDefaultビューを上書き）
        $views = [
            'default' => PresetView::make()
                ->favorite()
                ->label('全て')
                ->default(! $hasDefaultWarehouse),
        ];

        // デフォルト倉庫タブ（設定されている場合は先頭に配置してデフォルト選択）
        if ($defaultWarehouse) {
            $views["default_{$defaultWarehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where((new WmsOrderCandidate)->getTable().'.warehouse_id', $userDefaultWarehouseId))
                ->favorite()
                ->label($defaultWarehouse->name)
                ->default();
        }

        // 他の倉庫タブ（デフォルト倉庫は除外）
        foreach ($warehouses as $warehouse) {
            if ($hasDefaultWarehouse && $warehouse->id === $userDefaultWarehouseId) {
                continue;
            }
            $views["default_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where((new WmsOrderCandidate)->getTable().'.warehouse_id', $warehouse->id))
                ->favorite()
                ->label($warehouse->name);
        }

        return $views;
    }
}
