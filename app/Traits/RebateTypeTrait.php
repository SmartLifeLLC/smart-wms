<?php

namespace App\Traits;

use App\Actions\Updaters\UpdateDeliveryDestination;
use App\Models\DeliveryDestination;
use App\Models\RebateType;
use Illuminate\Support\Str;

trait RebateTypeTrait
{
    public string $rebate_type = '';
    public ?int $rebate_type_id = null;
    public ?string $rebate_type_code = null;

    public function updatedRebateTypeCode($value): void
    {
        $rebate_type = RebateType::firstWhere('code', $value);
        $this->rebate_type_id = $rebate_type?->id;
        if ($rebate_type) {
            $this->rebate_type = $rebate_type->name;
        }
    }

    public function saveRebateType(): void
    {
        if (!Str::replace("\n", '', $this->rebate_type)) {
            return;
        }
//        $result = UpdateRebateType::executeWithTransaction(
//            DeliveryDestination::class,
//            $this->delivery_destination_id,
//            $this->delivery_destination_code,
//            $this->delivery_destination,
//            $this->buyer_id
//        );
        $rebate_type = RebateType::find($this->rebate_type_id) ?? new RebateType([
            'creator_id' => auth()->user()->id,
        ]);
        try {
            $rebate_type
                ->fill([
                    'client_id' => auth()->user()->client_id,
                    'name' => $this->rebate_type,
                    'code' => $this->rebate_type_code,
                    'last_updater_id' => auth()->user()->id,
                ])->save();
            session()->flash('delivery_destination_save_message', '届け先を更新しました');
            $this->rebate_type_id = $rebate_type?->id;
            $this->rebate_type_code = $rebate_type?->code;
            return;

        } catch (\Exception $e) {
            session()->flash('rebate_type_save_error', '更新失敗しました');
            return;
        }
//        $result->logError();
//        if ($result->isSuccess()) {
//            session()->flash('delivery_destination_save_message', '届け先を更新しました');
//            $destination = $result->getValue('delivery_destination');
//            $this->delivery_destination_id = $destination?->id;
//            $this->delivery_destination_code = $destination?->code;
//        } else {
//            session()->flash('delivery_destination_save_error', '更新失敗しました');
//        }
    }

    public function clearSaveMessage(): void
    {
        sleep(1);
        session()->forget(['rebate_type_save_message', 'rebate_type_save_error']);
    }
}
