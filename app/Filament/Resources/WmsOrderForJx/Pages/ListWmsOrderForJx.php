<?php

namespace App\Filament\Resources\WmsOrderForJx\Pages;

use App\Enums\AutoOrder\OrderChannel;
use App\Enums\AutoOrder\OrderDataFileChannel;
use App\Enums\AutoOrder\TransmissionType;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsOrderConfirmationWaiting\Tables\WmsOrderConfirmationWaitingTable;
use App\Filament\Resources\WmsOrderForJx\WmsOrderForJxResource;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderCandidate;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ListWmsOrderForJx extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsOrderForJxResource::class;

    /**
     * JX発注代表グループのキャッシュ
     *
     * @var array<int, array{id: int, code: string, label: string, contractor_ids: array<int>}>|null
     */
    protected ?array $cachedJxGroups = null;

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with([
                    'warehouse',
                    'item',
                    'contractor',
                    'jxDocument',
                ])
                ->addSelect([
                    'order_data_file_generated' => DB::connection('sakemaru')
                        ->table('wms_order_data_files')
                        ->selectRaw('1')
                        ->whereColumn('wms_order_data_files.batch_code', (new WmsOrderCandidate)->getTable().'.batch_code')
                        ->whereColumn('wms_order_data_files.warehouse_id', (new WmsOrderCandidate)->getTable().'.warehouse_id')
                        ->whereColumn('wms_order_data_files.contractor_id', (new WmsOrderCandidate)->getTable().'.contractor_id')
                        ->whereColumn('wms_order_data_files.expected_arrival_date', (new WmsOrderCandidate)->getTable().'.expected_arrival_date')
                        ->where(function ($query) {
                            $candidateTable = (new WmsOrderCandidate)->getTable();

                            $query
                                ->whereNull("{$candidateTable}.order_channel")
                                ->orWhere("{$candidateTable}.order_channel", OrderChannel::FAX->value);
                        })
                        ->where(function ($query): void {
                            $query
                                ->whereNull('wms_order_data_files.order_channel')
                                ->orWhere('wms_order_data_files.order_channel', OrderDataFileChannel::FAX->value);
                        })
                        ->where(function ($query) {
                            $candidateTable = (new WmsOrderCandidate)->getTable();

                            $query
                                ->whereRaw("JSON_CONTAINS(wms_order_data_files.candidate_ids, JSON_ARRAY({$candidateTable}.id))")
                                ->orWhereNull('wms_order_data_files.candidate_ids');
                        })
                        ->limit(1),
                ])
            );
    }

    protected function paginateTableQuery(Builder $query): Paginator
    {
        $paginator = parent::paginateTableQuery($query);
        $items = $paginator->getCollection();

        if ($items->isNotEmpty()) {
            WmsOrderConfirmationWaitingTable::preloadItemContractorOrderSettings($items);
        }

        return $paginator;
    }

    /**
     * 発注代表グループ別のタブ（プリセットビュー）。
     * 倉庫タブ（発注確定済み）と同じ PresetView の仕組みを使用する。
     */
    public function getPresetViews(): array
    {
        $groups = $this->getJxRepresentativeGroups();

        if (empty($groups)) {
            return [
                'default' => PresetView::make()
                    ->favorite()
                    ->label('全て')
                    ->default(),
            ];
        }

        $views = [];
        $candidateTable = (new WmsOrderCandidate)->getTable();

        foreach ($groups as $index => $group) {
            $contractorIds = $group['contractor_ids'];

            $view = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereIn("{$candidateTable}.contractor_id", $contractorIds))
                ->favorite()
                ->label($group['label']);

            if ($index === 0) {
                $views['default'] = $view->default();
            } else {
                $views["jx_group_{$group['id']}"] = $view;
            }
        }

        return $views;
    }

    /**
     * JX 送信設定された発注代表（transmission_type=JX_FINET）と、その集約先に
     * 集約される子発注先（transmission_contractor_id）をまとめたグループ一覧。
     *
     * @return array<int, array{id: int, code: string, label: string, contractor_ids: array<int>}>
     */
    protected function getJxRepresentativeGroups(): array
    {
        if ($this->cachedJxGroups !== null) {
            return $this->cachedJxGroups;
        }

        // 発注代表 = JX送信設定(JX_FINET)を持ち、かつ自身が他社へ集約されない発注先。
        // カナカン子発注先のように transmission_contractor_id が他社を指すものは、
        // たとえ JX_FINET でも独立タブにせず、代表(集約先)の1タブにまとめる。
        $jxSettings = WmsContractorSetting::query()
            ->where('transmission_type', TransmissionType::JX_FINET->value)
            ->where(function ($q) {
                $q->whereNull('transmission_contractor_id')
                    ->orWhereColumn('transmission_contractor_id', 'contractor_id');
            })
            ->with('contractor:id,code,name')
            ->get();

        if ($jxSettings->isEmpty()) {
            return $this->cachedJxGroups = [];
        }

        $parentIds = $jxSettings->pluck('contractor_id')->all();

        // 各代表に集約される子発注先を1クエリで取得（N+1回避）
        $childrenByParent = WmsContractorSetting::query()
            ->whereIn('transmission_contractor_id', $parentIds)
            ->get(['contractor_id', 'transmission_contractor_id'])
            ->groupBy('transmission_contractor_id');

        $groups = $jxSettings
            ->map(function (WmsContractorSetting $setting) use ($childrenByParent) {
                $parentId = (int) $setting->contractor_id;
                $childIds = ($childrenByParent[$parentId] ?? collect())
                    ->pluck('contractor_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $contractorIds = array_values(array_unique(array_merge([$parentId], $childIds)));

                return [
                    'id' => $parentId,
                    'code' => (string) ($setting->contractor?->code ?? ''),
                    'label' => $setting->contractor?->name ?? "発注先{$parentId}",
                    'contractor_ids' => $contractorIds,
                ];
            })
            ->sortBy('code')
            ->values()
            ->all();

        return $this->cachedJxGroups = $groups;
    }
}
