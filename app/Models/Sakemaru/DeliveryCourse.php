<?php

namespace App\Models\Sakemaru;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryCourse extends CustomModel
{
    use HasFactory;
    protected $guarded = [];
    protected $casts = [];

    public function warehouse(): belongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public static function createUnknownDeliveryCourse($client_id, $code, $warehouse_code, $creator_id){
        $course = DeliveryCourse::where('client_id',intval($code))->first();
        if(!empty($course)) return $course;
        return DeliveryCourse::create([
            'client_id' => $client_id,
            'code' => intval($code),
            'name' => '不明配送コース',
            'warehouse_id' => Warehouse::where('client_id',$client_id)->where('code',$warehouse_code)->first()->id,
            'creator_id' => $creator_id,
            'last_updater_id' => $creator_id,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }
}
