<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class AccountClassification extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [];


    public static function initMobileCollect($client_id)
    {

//        手　形,71000
//先付小切手,71010
//現　金,72000
//銀行振込,72010
//小切手,72020
//ビール券,72030
//値引・端引,74000
//振込手数料,74010
//ネット使用料,74020
//その他・相殺,75000
//容器相殺,75010
//手　形,91000
//先付小切手,91010
//現　金,92000
//銀行振込,92010
//小切手,92020
//ビール券,92030
//値引・端引,94000
//振込手数料,94010
//その他・相殺,95000
//容器相殺,95010

        AccountClassification::where('client_id', $client_id)->whereIn('code', [
            71000, 71010, 72000, 72030, 74000, 75000, 75010
        ])->update(['can_use_on_mobile_collect' => true, 'display_order_on_mobile_collect' => 10]);

        AccountClassification::where('client_id', $client_id)->whereIn('code', [
            72000
        ])->update(['can_edit_received_amount_on_mobile_collect' => true, 'display_order_on_mobile_collect' => 1]);
    }


}
