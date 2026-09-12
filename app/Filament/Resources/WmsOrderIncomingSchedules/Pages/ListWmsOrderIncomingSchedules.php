<?php

namespace App\Filament\Resources\WmsOrderIncomingSchedules\Pages;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\QuantityType;
use App\Filament\Concerns\HasStockSubqueries;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsOrderIncomingSchedules\WmsOrderIncomingScheduleResource;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\ItemContractor;
use App\Models\Sakemaru\Warehouse;
use App\Models\StatsItemWarehouseSalesSummary;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\OrderExecutionService;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ListWmsOrderIncomingSchedules extends ListRecords
{
    use AdvancedTables;
    use HasStockSubqueries;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsOrderIncomingScheduleResource::class;

    public array $incomingItems = [];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createManual')
                ->label('手動入荷予定追加')
                ->icon('heroicon-o-plus')
                ->color('success')
                ->modalHeading('手動入荷予定を追加')
                ->modalWidth('7xl')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('入荷予定を登録')->color('danger'))
                ->modalCancelActionLabel('変更せず閉じる')
                ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
                ->schema([
                    \Filament\Schemas\Components\Grid::make(3)->schema([
                        Select::make('warehouse_id')
                            ->label('入荷倉庫')
                            ->options(fn () => Warehouse::query()
                                ->where('is_active', true)
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"]))
                            ->default(fn () => auth()->user()?->getSelectedWarehouseId())
                            ->searchable()
                            ->required(),

                        DatePicker::make('expected_arrival_date')
                            ->label('入荷予定日')
                            ->required()
                            ->default(now()->addDays(3)),

                        Textarea::make('note')
                            ->label('備考')
                            ->rows(1),
                    ]),

                    ViewField::make('incoming_items')
                        ->view('filament.components.incoming-schedule-create-items')
                        ->hiddenLabel(),
                ])
                ->action(function (array $data) {
                    $items = $this->incomingItems;

                    if (empty($items)) {
                        Notification::make()
                            ->title('商品が選択されていません')
                            ->body('商品を検索し、数量を入力してください')
                            ->warning()
                            ->send();

                        return;
                    }

                    $service = app(OrderExecutionService::class);
                    $warehouseId = $data['warehouse_id'];

                    // 仮想倉庫対応
                    $orderWarehouse = Warehouse::find($warehouseId);
                    $incomingWarehouseId = ($orderWarehouse?->is_virtual && $orderWarehouse->stock_warehouse_id)
                        ? $orderWarehouse->stock_warehouse_id
                        : $warehouseId;

                    $created = 0;
                    $errors = [];

                    foreach ($items as $itemData) {
                        $itemId = $itemData['item_id'];
                        $itemCode = $itemData['item_code'] ?? null;
                        $capacityCase = (int) ($itemData['capacity_case'] ?? 1);
                        if ($capacityCase < 1) {
                            $capacityCase = 1;
                        }
                        $caseQty = (int) ($itemData['case_qty'] ?? 0);
                        $pieceQty = (int) ($itemData['piece_qty'] ?? 0);

                        if ($caseQty < 1 && $pieceQty < 1) {
                            continue;
                        }

                        // 販売終了品チェック
                        $item = Item::find($itemId);
                        if ($item && $item->end_of_sale_type !== 'NORMAL') {
                            $errors[] = "[{$itemCode}] {$item->name}: 販売終了品";

                            continue;
                        }

                        // 発注先を自動判別（item_contractors から）
                        $itemContractor = ItemContractor::where('warehouse_id', $incomingWarehouseId)
                            ->where('item_id', $itemId)
                            ->first();

                        $contractorId = $itemContractor?->contractor_id;
                        $supplierId = $itemContractor?->supplier_id;

                        if (! $contractorId) {
                            $errors[] = "[{$itemCode}] {$item->name}: 発注先未設定";

                            continue;
                        }

                        $arrivalDate = $data['expected_arrival_date'];

                        try {
                            // ケース行を作成
                            if ($caseQty > 0) {
                                $service->createManualIncomingSchedule([
                                    'warehouse_id' => $warehouseId,
                                    'item_id' => $itemId,
                                    'contractor_id' => $contractorId,
                                    'supplier_id' => $supplierId,
                                    'expected_quantity' => $caseQty,
                                    'quantity_type' => QuantityType::CASE->value,
                                    'expected_arrival_date' => $arrivalDate,
                                    'note' => $data['note'] ?? null,
                                ], auth()->id());
                                $created++;
                            }

                            // バラ行を作成
                            if ($pieceQty > 0) {
                                $service->createManualIncomingSchedule([
                                    'warehouse_id' => $warehouseId,
                                    'item_id' => $itemId,
                                    'contractor_id' => $contractorId,
                                    'supplier_id' => $supplierId,
                                    'expected_quantity' => $pieceQty,
                                    'quantity_type' => QuantityType::PIECE->value,
                                    'expected_arrival_date' => $arrivalDate,
                                    'note' => $data['note'] ?? null,
                                ], auth()->id());
                                $created++;
                            }
                        } catch (\Exception $e) {
                            $errors[] = "[{$itemCode}]: {$e->getMessage()}";
                        }
                    }

                    $this->incomingItems = [];

                    if ($created > 0) {
                        Notification::make()
                            ->title("入荷予定を{$created}件追加しました")
                            ->success()
                            ->send();
                    }

                    if (! empty($errors)) {
                        Notification::make()
                            ->title('一部エラーがあります')
                            ->body(implode("\n", $errors))
                            ->warning()
                            ->send();
                    }
                }),

            Action::make('uploadCsv')
                ->label('CSV一括登録')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->modalHeading('CSV一括登録')
                ->modalDescription('形式: 商品CD, JANコード, 倉庫コード(必須), 入荷予定日(必須), ケース, バラ — 商品CDまたはJANコードのどちらか1つ必須 / S-JIS・UTF-8対応')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('登録')->color('danger'))
                ->modalCancelActionLabel('登録せず閉じる')
                ->schema([
                    FileUpload::make('csv_file')
                        ->label('CSVファイル')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->required()
                        ->disk('local')
                        ->directory('temp-csv')
                        ->storeFileNamesIn('csv_original_name'),
                    Textarea::make('note')
                        ->label('備考')
                        ->rows(1),
                ])
                ->action(function (array $data) {
                    $this->processUploadedCsv($data);
                }),

            Action::make('downloadSampleCsv')
                ->label('CSVサンプル')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function () {
                    // 実データからサンプル行を生成
                    $sampleItems = \Illuminate\Support\Facades\DB::connection('sakemaru')
                        ->table('item_contractors as ic')
                        ->join('items', 'items.id', '=', 'ic.item_id')
                        ->join('warehouses', 'warehouses.id', '=', 'ic.warehouse_id')
                        ->where('items.end_of_sale_type', 'NORMAL')
                        ->where('warehouses.is_active', true)
                        ->where('items.capacity_case', '>', 1)
                        ->selectRaw('items.code as item_code, warehouses.code as wh_code')
                        ->orderBy('items.code')
                        ->limit(3)
                        ->get();

                    $arrivalDate = now()->addDays(3)->format('Y-m-d');

                    $bom = "\xEF\xBB\xBF";
                    $csv = $bom."商品CD,JANコード,倉庫コード,入荷予定日,ケース,バラ\n";

                    if ($sampleItems->isNotEmpty()) {
                        foreach ($sampleItems as $i => $s) {
                            $case = $i === 0 ? 5 : ($i === 1 ? 0 : 3);
                            $piece = $i === 0 ? 0 : ($i === 1 ? 10 : 6);
                            $csv .= "{$s->item_code},,{$s->wh_code},{$arrivalDate},{$case},{$piece}\n";
                        }
                    } else {
                        $csv .= "110002,,1,{$arrivalDate},5,0\n";
                    }

                    return response()->streamDownload(function () use ($csv) {
                        echo $csv;
                    }, 'incoming_schedule_sample.csv', [
                        'Content-Type' => 'text/csv; charset=UTF-8',
                    ]);
                }),
        ];
    }

    /**
     * 入荷予定追加モーダル用の商品検索
     */
    public function searchItemsForIncomingModal(
        int $warehouseId,
        ?string $itemCode = null,
        ?string $janCode = null,
        ?string $itemName = null,
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
            ->where('items.end_of_sale_type', 'NORMAL');

        // 商品CD検索
        if ($itemCode && strlen($itemCode) >= 1) {
            $itemCode = mb_convert_kana($itemCode, 'as');
            $query->where('items.code', 'like', "%{$itemCode}%");
        }

        // JANコード検索
        if ($janCode && strlen($janCode) >= 1) {
            $janCode = mb_convert_kana($janCode, 'as');
            $query->whereHas('piece_jan_code_information', function ($sq) use ($janCode) {
                $sq->where('search_string', 'like', "%{$janCode}%");
            });
        }

        // 商品名検索
        if ($itemName && strlen($itemName) >= 2) {
            $itemName = mb_convert_kana($itemName, 'as');
            $query->where('items.name', 'like', "%{$itemName}%");
        }

        $query->orderBy('items.code');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        // 出荷実績サマリを一括取得
        $itemIds = collect($paginator->items())->pluck('id')->toArray();
        $summaries = StatsItemWarehouseSalesSummary::where('warehouse_id', $warehouseId)
            ->whereIn('item_id', $itemIds)
            ->get()
            ->keyBy('item_id');

        // 発注先情報を一括取得
        $orderWarehouse = Warehouse::find($warehouseId);
        $incomingWarehouseId = ($orderWarehouse?->is_virtual && $orderWarehouse->stock_warehouse_id)
            ? $orderWarehouse->stock_warehouse_id
            : $warehouseId;

        $itemContractors = ItemContractor::where('warehouse_id', $incomingWarehouseId)
            ->whereIn('item_id', $itemIds)
            ->with('contractor')
            ->get()
            ->keyBy('item_id');

        // 既存PENDING入荷予定の数量を取得
        $pendingSchedules = WmsOrderIncomingSchedule::where('warehouse_id', $warehouseId)
            ->where('status', IncomingScheduleStatus::PENDING)
            ->whereIn('item_id', $itemIds)
            ->get()
            ->groupBy('item_id');

        $data = collect($paginator->items())->map(function ($item) use ($summaries, $itemContractors, $pendingSchedules) {
            $summary = $summaries->get($item->id);
            $ic = $itemContractors->get($item->id);
            $pending = $pendingSchedules->get($item->id);

            $pendingCaseQty = 0;
            $pendingPieceQty = 0;
            if ($pending) {
                foreach ($pending as $schedule) {
                    if ($schedule->quantity_type === QuantityType::CASE) {
                        $pendingCaseQty += $schedule->expected_quantity;
                    } else {
                        $pendingPieceQty += $schedule->expected_quantity;
                    }
                }
            }

            return [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'packaging' => $item->packaging,
                'capacity_case' => $item->capacity_case ?? 1,
                'contractor_name' => $ic?->contractor
                    ? "[{$ic->contractor->code}]{$ic->contractor->name}"
                    : null,
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

    /**
     * CSV一括登録の処理
     */
    protected function processUploadedCsv(array $data): void
    {
        $filePath = $data['csv_file'];
        $fullPath = storage_path('app/private/'.$filePath);

        if (! file_exists($fullPath)) {
            Notification::make()
                ->title('ファイルが見つかりません')
                ->danger()
                ->send();

            return;
        }

        try {
            $content = file_get_contents($fullPath);

            // エンコーディング検出（UTF-8 / S-JIS対応）
            $encoding = mb_detect_encoding($content, ['UTF-8', 'SJIS', 'SJIS-win', 'CP932'], true);
            if ($encoding && $encoding !== 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $encoding);
            }

            // BOM除去
            if (str_starts_with($content, "\xEF\xBB\xBF")) {
                $content = substr($content, 3);
            }

            $lines = array_filter(explode("\n", str_replace("\r\n", "\n", $content)), fn ($l) => trim($l) !== '');

            $rows = [];
            foreach ($lines as $i => $line) {
                $cols = array_map(fn ($c) => trim($c, " \t\"'"), str_getcsv($line));

                // ヘッダー行スキップ（先頭が数字でない場合）
                if ($i === 0 && ! empty($cols[0]) && ! preg_match('/^\d/', $cols[0])) {
                    continue;
                }

                $itemCode = $cols[0] ?? '';
                $janCode = $cols[1] ?? '';
                $warehouseCode = $cols[2] ?? '';
                $arrivalDate = $cols[3] ?? '';
                $caseQty = (int) ($cols[4] ?? 0);
                $pieceQty = (int) ($cols[5] ?? 0);

                if (! $itemCode && ! $janCode) {
                    continue;
                }

                $rows[] = [
                    'line' => $i + 1,
                    'item_code' => $itemCode,
                    'jan_code' => $janCode,
                    'warehouse_code' => $warehouseCode,
                    'arrival_date' => $arrivalDate,
                    'case_qty' => $caseQty,
                    'piece_qty' => $pieceQty,
                ];
            }

            if (empty($rows)) {
                Notification::make()
                    ->title('CSVにデータがありません')
                    ->warning()
                    ->send();

                return;
            }

            // バリデーション & 商品解決
            $errors = [];
            $schedules = [];

            // 倉庫コードを一括取得
            $warehouseCodes = array_unique(array_column($rows, 'warehouse_code'));
            $warehouses = Warehouse::whereIn('code', $warehouseCodes)
                ->where('is_active', true)
                ->get()
                ->keyBy('code');

            // 商品CD/JANコードを一括取得
            $itemCodes = array_filter(array_unique(array_column($rows, 'item_code')));
            $janCodes = array_filter(array_unique(array_column($rows, 'jan_code')));

            $itemsByCode = [];
            if (! empty($itemCodes)) {
                $itemsByCode = Item::whereIn('code', $itemCodes)
                    ->where('end_of_sale_type', 'NORMAL')
                    ->get()
                    ->keyBy('code');
            }

            $itemsByJan = [];
            if (! empty($janCodes)) {
                $janItems = DB::connection('sakemaru')
                    ->table('item_search_information')
                    ->whereIn('search_string', $janCodes)
                    ->where('is_active', true)
                    ->pluck('item_id', 'search_string');

                if ($janItems->isNotEmpty()) {
                    $items = Item::whereIn('id', $janItems->values())
                        ->where('end_of_sale_type', 'NORMAL')
                        ->get()
                        ->keyBy('id');

                    foreach ($janItems as $jan => $itemId) {
                        if ($items->has($itemId)) {
                            $itemsByJan[$jan] = $items->get($itemId);
                        }
                    }
                }
            }

            foreach ($rows as $row) {
                $lineNum = $row['line'];

                // 倉庫コード必須チェック
                if (empty($row['warehouse_code'])) {
                    $errors[] = "{$lineNum}行目: 倉庫コードは必須です";

                    continue;
                }

                $warehouse = $warehouses->get($row['warehouse_code']);
                if (! $warehouse) {
                    $errors[] = "{$lineNum}行目: 倉庫コード [{$row['warehouse_code']}] が見つかりません";

                    continue;
                }

                // 入荷予定日必須チェック
                if (empty($row['arrival_date'])) {
                    $errors[] = "{$lineNum}行目: 入荷予定日は必須です";

                    continue;
                }

                // 日付フォーマット検証
                $parsedDate = date_create($row['arrival_date']);
                if (! $parsedDate) {
                    $errors[] = "{$lineNum}行目: 入荷予定日の形式が不正です [{$row['arrival_date']}]";

                    continue;
                }
                $arrivalDate = $parsedDate->format('Y-m-d');

                // 商品解決
                $item = null;
                if ($row['item_code'] && isset($itemsByCode[$row['item_code']])) {
                    $item = $itemsByCode[$row['item_code']];
                } elseif ($row['jan_code'] && isset($itemsByJan[$row['jan_code']])) {
                    $item = $itemsByJan[$row['jan_code']];
                }

                if (! $item) {
                    $code = $row['item_code'] ?: $row['jan_code'];
                    $errors[] = "{$lineNum}行目: [{$code}] 商品が見つかりません";

                    continue;
                }

                if ($row['case_qty'] < 1 && $row['piece_qty'] < 1) {
                    $errors[] = "{$lineNum}行目: [{$item->code}] ケースまたはバラの数量を入力してください";

                    continue;
                }

                $schedules[] = [
                    'item' => $item,
                    'warehouse' => $warehouse,
                    'arrival_date' => $arrivalDate,
                    'case_qty' => $row['case_qty'],
                    'piece_qty' => $row['piece_qty'],
                ];
            }

            if (empty($schedules) && ! empty($errors)) {
                Notification::make()
                    ->title('CSV取込エラー')
                    ->body(implode("\n", array_slice($errors, 0, 10)))
                    ->danger()
                    ->send();

                return;
            }

            // 入荷予定を一括作成
            $service = app(OrderExecutionService::class);
            $created = 0;

            foreach ($schedules as $schedule) {
                $item = $schedule['item'];
                $warehouse = $schedule['warehouse'];
                $warehouseId = $warehouse->id;

                // 仮想倉庫対応
                $incomingWarehouseId = ($warehouse->is_virtual && $warehouse->stock_warehouse_id)
                    ? $warehouse->stock_warehouse_id
                    : $warehouseId;

                // 発注先を自動判別
                $itemContractor = ItemContractor::where('warehouse_id', $incomingWarehouseId)
                    ->where('item_id', $item->id)
                    ->first();

                if (! $itemContractor?->contractor_id) {
                    $errors[] = "[{$item->code}] {$item->name}: 発注先未設定";

                    continue;
                }

                try {
                    if ($schedule['case_qty'] > 0) {
                        $service->createManualIncomingSchedule([
                            'warehouse_id' => $warehouseId,
                            'item_id' => $item->id,
                            'contractor_id' => $itemContractor->contractor_id,
                            'supplier_id' => $itemContractor->supplier_id,
                            'expected_quantity' => $schedule['case_qty'],
                            'quantity_type' => QuantityType::CASE->value,
                            'expected_arrival_date' => $schedule['arrival_date'],
                            'note' => $data['note'] ?? null,
                        ], auth()->id());
                        $created++;
                    }

                    if ($schedule['piece_qty'] > 0) {
                        $service->createManualIncomingSchedule([
                            'warehouse_id' => $warehouseId,
                            'item_id' => $item->id,
                            'contractor_id' => $itemContractor->contractor_id,
                            'supplier_id' => $itemContractor->supplier_id,
                            'expected_quantity' => $schedule['piece_qty'],
                            'quantity_type' => QuantityType::PIECE->value,
                            'expected_arrival_date' => $schedule['arrival_date'],
                            'note' => $data['note'] ?? null,
                        ], auth()->id());
                        $created++;
                    }
                } catch (\Exception $e) {
                    $errors[] = "[{$item->code}]: {$e->getMessage()}";
                }
            }

            if ($created > 0) {
                Notification::make()
                    ->title("入荷予定をCSVから{$created}件登録しました")
                    ->success()
                    ->send();
            }

            if (! empty($errors)) {
                Notification::make()
                    ->title('一部エラーがあります')
                    ->body(implode("\n", array_slice($errors, 0, 10)))
                    ->warning()
                    ->send();
            }
        } finally {
            // 一時ファイル削除
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with([
                    'warehouse',
                    'item',
                    'contractor',
                    'orderCandidate',
                    'transferCandidate',
                    'confirmedByUser',
                    'confirmedByPicker',
                ])
                ->select('wms_order_incoming_schedules.*')
                ->addSelect([
                    'computed_current_stock' => static::currentStockSubquery('wms_order_incoming_schedules'),
                    'computed_available_stock' => static::availableStockSubquery('wms_order_incoming_schedules'),
                    'computed_default_location' => static::defaultLocationSubquery('wms_order_incoming_schedules'),
                    'is_eos_sent_for_table' => DB::raw(
                        'CASE WHEN '.WmsOrderIncomingSchedule::eosSentConditionSql('wms_order_incoming_schedules').' THEN 1 ELSE 0 END'
                    ),
                ])
            );
    }

    protected ?array $presetViewContractorData = null;

    protected function getContractorDataForPresetViews(): array
    {
        if ($this->presetViewContractorData !== null) {
            return $this->presetViewContractorData;
        }

        $warehouseId = auth()->user()?->getSelectedWarehouseId();
        $cacheKey = 'incoming_schedules_pending_contractors_'.($warehouseId ?: 'none');
        $this->presetViewContractorData = cache()->remember($cacheKey, 30, function () use ($warehouseId) {
            if (! $warehouseId) {
                return [
                    'ids' => [],
                    'contractors' => collect(),
                ];
            }

            $contractorIds = WmsOrderIncomingSchedule::whereIn('status', [
                IncomingScheduleStatus::PENDING,
                IncomingScheduleStatus::PARTIAL,
            ])
                ->where('warehouse_id', $warehouseId)
                ->whereNotNull('contractor_id')
                ->distinct()
                ->pluck('contractor_id')
                ->toArray();

            $contractors = Contractor::whereIn('id', $contractorIds)
                ->orderBy('code')
                ->get(['id', 'code', 'name']);

            return [
                'ids' => $contractorIds,
                'contractors' => $contractors,
            ];
        });

        return $this->presetViewContractorData;
    }

    public function getPresetViews(): array
    {
        $contractorData = $this->getContractorDataForPresetViews();
        $contractors = $contractorData['contractors'];

        $views = [
            'default' => PresetView::make()
                ->favorite()
                ->label('全て')
                ->default(),
        ];

        foreach ($contractors as $contractor) {
            $label = filled($contractor->code)
                ? "[{$contractor->code}]{$contractor->name}"
                : $contractor->name;

            $views["contractor_{$contractor->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('contractor_id', $contractor->id))
                ->favorite()
                ->label($label);
        }

        return $views;
    }
}
