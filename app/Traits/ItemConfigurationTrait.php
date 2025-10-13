<?php


namespace App\Traits;


use App\Enums\EItemSearchCodeType;
use App\Models\Contractor;
use App\Models\ItemSearchInformation;
use App\Models\Partner;
use App\Models\Warehouse;
use Illuminate\Support\Str;

trait ItemConfigurationTrait
{
    public array $item_contractors = [];

    public function updatedItemConfigurationTrait($property, $value): void
    {
        $components = explode('.', $property);
        if (count($components) == 3) {
            [$base, $index, $key] = $components;
            switch ($key) {
                case 'warehouse_code':
                    $warehouse = Warehouse::firstWhere('code', $value);
                    data_set($this->$base, $index . '.warehouse_id', $warehouse?->id);
                    data_set($this->$base, $index . '.warehouse_name', $warehouse?->name);
                    break;
                case 'contractor_code':
                    $contractor = Contractor::firstWhere('code', $value);
                    data_set($this->$base, $index . '.contractor_id', $contractor?->id);
                    data_set($this->$base, $index . '.contractor_name', $contractor?->name);
                    break;
                case 'supplier_code':
                    $partner = Partner::where('is_supplier', true)->firstWhere('code', $value);
                    data_set($this->$base, $index . '.supplier_id', $partner?->supplier?->id);
                    data_set($this->$base, $index . '.supplier_name', $partner?->name);
                    break;
            }


            // JANコードの重複チェック
            if (Str::contains($property, 'search_string')) {
                $code_type = $this->getProperty(Str::replace('search_string', 'code_type', $property));
                if (EItemSearchCodeType::JAN->isSameAs($code_type)) {
                    $is_duplicated = ItemSearchInformation::where('item_id', '!=', $this->edit_id)
                        ->where('code_type', EItemSearchCodeType::JAN->value)
                        ->where('search_string', $value)
                        ->exists();
                } else {
                    $is_duplicated = false;
                }
                
                $this->setWarning($is_duplicated, $property, "既に利用されているJANコードです。");
            }
        }
    }

    public function addContractors(): void
    {
        $this->item_contractors[] = [
            'id' => 0,
        ];
    }

    public function removeContractors(): void
    {
        data_forget($this->item_contractors, count($this->item_contractors) - 1);
    }

}
