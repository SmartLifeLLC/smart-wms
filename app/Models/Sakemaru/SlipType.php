<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class SlipType extends CustomModel
{
    use HasFactory;

    protected $guarded = [];


    public static function createDefaultSlipType($client_id, $user_id)
    {
        SlipType::where('client_id', $client_id)->delete();
        $code_names = [
            '0' => '自社伝票',
            '9' => '伝票発行なし',
        ];

        foreach ($code_names as $code => $name) {
            SlipType::create([
                'client_id' => $client_id,
                'code' => $code,
                'name' => $name,
                'creator_id' => $user_id,
                'last_updater_id' => $user_id,
                'is_active'=>true,

            ]);
        }
    }
}
