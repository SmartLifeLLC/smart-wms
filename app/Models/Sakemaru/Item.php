<?php

namespace App\Models\Sakemaru;

use App\Casts\NullSetter;
use App\Domains\JanCode;
use App\Enums\EExternalCollaborationPartner;
use App\Enums\EItemPartnerPriceType;
use App\Enums\EItemSearchCodeType;
use App\Enums\EVolumeUnit;
use App\Enums\QuantityType;
use App\Enums\TimeZone;
use App\ValueObjects\ItemPartnerPriceVO;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Item extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [
        'start_of_sale_date' => NullSetter::class,
        'end_of_sale_date' => NullSetter::class,
    ];

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function item_contractors(): HasMany
    {
        return $this->hasMany(ItemContractor::class);
    }

    public function item_contractor1(): HasOne
    {
        return $this->hasOne(ItemContractor::class)->orderBy('id', 'asc');
    }

    public function item_contractor2(): HasOne
    {
        return $this->hasOne(ItemContractor::class)->orderBy('id', 'asc')->take(1)->offset(1);
    }

    public function item_contractor3(): HasOne
    {
        return $this->hasOne(ItemContractor::class)->orderBy('id', 'asc')->take(2)->offset(2);
    }

    public function item_prices(): HasMany
    {
        return $this->hasMany(ItemPrice::class);
    }

    public function item_price1(): HasOne
    {

        return $this->hasOne(ItemPrice::class)->orderBy('id', 'asc');
    }

    public function item_price2(): HasOne
    {
        return $this->hasOne(ItemPrice::class)->orderBy('id', 'asc')->take(1)->offset(1);
    }

    public function item_price3(): HasOne
    {
        return $this->hasOne(ItemPrice::class)->orderBy('id', 'asc')->take(2)->offset(2);
    }

    public function current_price(): HasOne
    {
        $system_date = ClientSetting::systemDate(true);
        return $this->hasOne(ItemPrice::class)
            ->whereDate('start_date', '<=', $system_date->toDateString())
            ->orderBy('start_date', 'desc');
    }

    public function currentPriceForStartDate(?string $start_date)
    {
        $start_date = $start_date ? Carbon::parse($start_date) : ClientSetting::systemDate(true);
        return $this->hasOne(ItemPrice::class)
            ->whereDate('start_date', '<=', $start_date->toDateString())
            ->orderBy('start_date', 'desc')
            ->first();
    }

    public function pricesAroundDate(?string $start_date = null): array
    {
        $start = $start_date ? Carbon::parse($start_date) : ClientSetting::systemDate(true);

        $result = [
            'previous' => null,
            'current'  => null,
            'next'     => null,
        ];

        // 現在適用中（基準日以前の最新）
        $current = $this->hasOne(ItemPrice::class)
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $start->toDateString())
            ->orderBy('start_date', 'desc')
            ->first();

        if (!$current) {
            // 現在が無い場合：最も近い未来だけ返す
            $result['next'] = $this->hasOne(ItemPrice::class)
                ->where('is_active', true)
                ->whereDate('start_date', '>', $start->toDateString())
                ->orderBy('start_date', 'asc')
                ->first();

            return $result;
        }

        $result['current'] = $current;

        // 直前（1件）
        $result['previous'] = $this->hasOne(ItemPrice::class)
            ->where('is_active', true)
            ->whereDate('start_date', '<', $current->start_date)
            ->orderBy('start_date', 'desc')
            ->first();

        // 次回（1件）
        $result['next'] = $this->hasOne(ItemPrice::class)
            ->where('is_active', true)
            ->whereDate('start_date', '>', $current->start_date)
            ->orderBy('start_date', 'asc')
            ->first();

        return $result;
    }

    public function currentPartnerPrice(?int $partner_id, ?int $warehouse_id, ?string $price_start_date): ?ItemPartnerPrice
    {
        return $this->currentPartnerPriceVO($partner_id, $warehouse_id, $price_start_date)->price;
    }

    public function currentPartnerPriceVO(?int $partner_id, ?int $warehouse_id, ?string $price_start_date = null): ItemPartnerPriceVO
    {
        $price_start_date = $price_start_date ? Carbon::parse($price_start_date)->toDateString() : ClientSetting::systemDate(true)->toDateString();

        // 取引先の単価
        $partner_price = ItemPartnerPrice::currentData($this->id, $partner_id, $warehouse_id, $price_start_date);
        if (!is_null($partner_price)) {
            return new ItemPartnerPriceVO($partner_price, EItemPartnerPriceType::PARTNER);
        }

        // 個別単価Gの単価
        $partner = Partner::find($partner_id);
        $partner_price_group_id = $partner?->partner_price_group_id;
        if ($partner_price_group_id) {
            $partner_price = ItemPartnerPrice::currentData($this->id, $partner_price_group_id, $warehouse_id, $price_start_date);
        }
        if (!is_null($partner_price)) {
            return new ItemPartnerPriceVO($partner_price, EItemPartnerPriceType::PARTNER_PRICE_GROUP);
        }

        // 個別単価G2の単価
        $partner_price_group2_id = $partner?->partner_price_group2_id;
        if ($partner_price_group2_id) {
            $partner_price = ItemPartnerPrice::currentData($this->id, $partner_price_group2_id, $warehouse_id, $price_start_date);

            if (!is_null($partner_price)) {
                $unknown_price_partner = Partner::unknownPriceGroup();
                if (!is_null($unknown_price_partner) && $unknown_price_partner->id == $partner_price_group2_id) {
                    return new ItemPartnerPriceVO($partner_price, EItemPartnerPriceType::UNKNOWN);
                }

                return new ItemPartnerPriceVO($partner_price, EItemPartnerPriceType::PARTNER_PRICE_GROUP2);
            }
        }

        return new ItemPartnerPriceVO($partner_price, EItemPartnerPriceType::PARTNER);
    }

    public function item_search_information(): HasMany
    {
        return $this->hasMany(ItemSearchInformation::class);
    }

    public function item_search_information1(): HasOne
    {
        return $this->hasOne(ItemSearchInformation::class)->orderBy('id', 'asc');
    }

    public function item_search_information2(): HasOne
    {
        return $this->hasOne(ItemSearchInformation::class)->orderBy('id', 'asc')->take(1)->offset(1);
    }

    public function item_search_information3(): HasOne
    {
        return $this->hasOne(ItemSearchInformation::class)->orderBy('id', 'asc')->take(2)->offset(2);
    }

    public function piece_jan_code_information(): HasOne
    {
        return $this->hasOne(ItemSearchInformation::class)
            ->where('quantity_type', QuantityType::PIECE->value)
            ->where('code_type', EItemSearchCodeType::JAN->value)
            ->orderBy('priority');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function container_type(): BelongsTo
    {
        return $this->belongsTo(ContainerType::class);
    }

    public function item_category1(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class);
    }

    public function item_category2(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class);
    }

    public function item_category3(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function place_of_origin(): BelongsTo
    {
        return $this->belongsTo(PlaceOfOrigin::class);
    }

    public function country_of_origin(): BelongsTo
    {
        return $this->belongsTo(CountryOfOrigin::class, 'country_of_origin_id', 'id');
    }

    public function main_material(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'main_material_id', 'id');
    }

    public function alcohol_tax_category(): BelongsTo
    {
        return $this->belongsTo(AlcoholTaxCategory::class);
    }

    public function manufacture_type(): BelongsTo
    {
        return $this->belongsTo(ManufactureType::class);
    }

    public function storage_type(): BelongsTo
    {
        return $this->belongsTo(StorageType::class);
    }

    public function ledger_classification(): BelongsTo
    {
        return $this->belongsTo(LedgerClassification::class);
    }

    public function slip_classification(): BelongsTo
    {
        return $this->belongsTo(LedgerClassification::class);
    }

    public function container_aggregation_unit(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'container_aggregation_unit_id', 'id');
    }

    public function container_aggregation_completed(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'container_aggregation_completed_id', 'id');
    }

    public function container_aggregation_empty(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'container_aggregation_empty_id', 'id');
    }

    public function sumatyu_setting(): HasOne
    {
        return $this->hasOne(ExternalCollaborationItemSetting::class)
            ->where('collaborator', EExternalCollaborationPartner::SUMATYU);
    }

    public function infomart_setting(): HasOne
    {
        return $this->hasOne(ExternalCollaborationItemSetting::class)
            ->where('collaborator', EExternalCollaborationPartner::INFOMART);
    }

    public function fit_setting(): HasOne
    {
        return $this->hasOne(ExternalCollaborationItemSetting::class)
            ->where('collaborator', EExternalCollaborationPartner::FIT);
    }

    public function mitsui_setting(): HasOne
    {
        return $this->hasOne(ExternalCollaborationItemSetting::class)
            ->where('collaborator', EExternalCollaborationPartner::MITSUI_PERFECT);
    }


    protected function literVolume(): Attribute
    {
        return Attribute::make(
            get: fn() => EVolumeUnit::from($this->volume_unit)->calculateLiter($this->volume),
        );
    }

    protected function categories(): Attribute
    {
        $categories = collect()
            ->push($this->item_category1)
            ->push($this->item_category2)
            ->push($this->item_category3)
            ->filter();

        return Attribute::make(
            get: fn() => $categories,
        );
    }

    public function getAnotherCode(EItemSearchCodeType $code_type, QuantityType $quantity_type): ?string
    {
        $item_search_information = $this->item_search_information()
            ->where([
                'code_type' => $code_type->value,
                'quantity_type' => $quantity_type->value,
            ])->orderBy('priority')
            ->first();
        return $item_search_information?->search_string;
    }

    public function capacityOfQuantityType(QuantityType|string $quantity_type): ?int
    {
        if (gettype($quantity_type) == 'string') {
            $quantity_type = QuantityType::tryFrom($quantity_type);
        }
        if (is_null($quantity_type)) {
            return null;
        }
        return match ($quantity_type) {
            QuantityType::PIECE => 1,
            QuantityType::CARTON => $this->capacity_carton,
            QuantityType::CASE => $this->capacity_case,
        };
    }


    public function getCost(?int $warehouse_id, QuantityType $quantity_type = QuantityType::PIECE): string
    {
        if ($warehouse_id) {
            // 直近の日時処理時の原価を取得
            $last_closing_monthly = ClosingMonthly::lastClosing();
            if ($last_closing_monthly) {
                $stock_overview = $last_closing_monthly->stock_overviews()->where([
                    'item_id' => $this->id,
                    'warehouse_id' => $warehouse_id,
                ])->first();
                if ($stock_overview) {
                    $piece_cost = $stock_overview->cost;
                }
            }
        }

        if (!isset($piece_cost)) {
            // 倉庫指定がない場合や取得できない場合はマスタ原価
            $piece_cost = $this->current_price?->cost_unit_price ?? 0;
        }

        // 数量区分によって入数をかける
        $capacity_col = $quantity_type->capacityCol();
        if ($capacity_col) {
            return $piece_cost * $this->{$capacity_col};
        }
        return $piece_cost;
    }

    public function isWithinTradingPeriod($target_date): bool
    {
        if ($this->is_ended) {
            return false;
        }

        $target_date = Carbon::parse($target_date);

        if (!empty($this->start_of_sale_date)) {
            $start_of_sale_date = Carbon::parse($this->start_of_sale_date);
            if ($target_date->lessThan($start_of_sale_date)) {
                return false;
            }
        }

        if (!empty($this->end_of_sale_date)) {
            $end_of_sale_date = Carbon::parse($this->end_of_sale_date);
            if ($target_date->greaterThanOrEqualTo($end_of_sale_date)) {
                return false;
            }
        }

        return true;
    }

    /**
     * POSコード取得
     * @return string
     */
    public function posCode(): string
    {
        $jan_code_information = $this->piece_jan_code_information;
        if ($jan_code_information) {
            return $jan_code_information->search_string;
        } else {
            return JanCode::getPrivateCode($this);
        }
    }

    public function rank()
    {
        return $this->hasMany(RankHistory::class);
    }

    public function real_stocks(): HasMany
    {
        return $this->hasMany(RealStock::class);
    }
}
