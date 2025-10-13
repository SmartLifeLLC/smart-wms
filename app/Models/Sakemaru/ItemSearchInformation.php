<?php

namespace App\Models\Sakemaru;

use App\Enums\EItemSearchCodeType;
use App\Enums\QuantityType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemSearchInformation extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [];

    public function item() : BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function dataTransferFromSDPData($client_id, $sdp_data){
        //insert sdp to item
        $this->where('client_id',$client_id)->where('is_created_from_data_transfer',true)->delete();
        $code_type = EItemSearchCodeType::SDP->value;
        $item_codes = (new  Item())->onOffIsActive(false)->where('client_id',$client_id)->pluck('id','code');
        $save_data = [];
        foreach($sdp_data as $code => $data ){
            $item_id = $item_codes[$code];
            $sdp_code = intval($data['SDP_CODE']);
            if($sdp_code == 0)continue;
            $save_data[] = [
                'client_id' => $client_id,
                'item_id' => $item_id,
                'code_type' => $code_type,
                'quantity_type' => QuantityType::PIECE,
                'priority' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'search_string' => $sdp_code,
                'is_created_from_data_transfer' => true,
            ];
            if(count($save_data) > 3000) {
                $this->insert($save_data);
                $save_data = [];
            }
        }
        $this->insert($save_data);
    }
}
