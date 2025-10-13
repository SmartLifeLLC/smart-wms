<?php

namespace App\Models\Sakemaru;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataTransferLog extends Model
{
    use HasFactory;
    protected $guarded = null;


    public static function started($tag){
      return DataTransferLog::create(
            [
                'start_message'=> "$tag",
                'started_at'=>now()->format('Y-m-d H:i:s'),

            ]
        );
    }

    public static function finished($id){
        $record = DataTransferLog::find($id);
        $execution_time = Carbon::parse($record->started_at)->diffInSeconds(now());
        $record->update([
            'finished_message'=> "successfully finished at ". now()->format('Y-m-d H:i:s'),
            'is_succeed'=>true,
            'finished_at'=>now()->format('Y-m-d H:i:s'),
            'execution_time'=>$execution_time
        ]);
    }

    public static function failed($id,$failed_message){
        $record = DataTransferLog::find($id);
        $execution_time = Carbon::parse($record->started_at)->diffInSeconds(now());
        $record->update([
            'finished_message'=> "failed. ". now()->format('Y-m-d H:i:s'),
            'is_succeed'=>false,
            'finished_at'=>now()->format('Y-m-d H:i:s'),
            'execution_time'=>$execution_time,
            'error_message'=>$failed_message
        ]);
    }

}
