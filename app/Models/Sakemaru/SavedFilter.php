<?php

namespace App\Models\Sakemaru;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class SavedFilter extends Model
{
    protected $guarded = [];

    public static function getDefaultFilter(string $datatable, ?User $user = null) : array
    {
        $user = $user ?? auth()->user();
        $filters = self::query()
            ->where('client_id', $user->client_id)
            ->where('is_default', true)
            ->where('datatable', $datatable)
            ->where(function($query) use ($user) {
                $query->whereNull('user_id')
                    ->orWhere('user_id', $user->id);
            })
            ->get();

        $user_filter = $filters->firstWhere('user_id', $user->id);

        // ユーザごとのデフォルトフィルタがない場合、クライアントのデフォルトフィルタを返す
        $filter = $user_filter ?? $filters->first();

        return json_decode($filter?->filter, true) ?: [];
    }


    public static function convertAttribute(mixed $value, ?Carbon $system_date = null) : mixed
    {
        // 配列の場合は再帰的に処理
        if (is_array($value)) {
            foreach ($value as $key => $val) {
                $value[$key] = self::convertAttribute($val, $system_date);
            }
            return $value;
        }

        $system_date = $system_date ?? ClientSetting::systemDate(true);
        return match ($value) {
            ":system_date" => $system_date->toDateString(),
            default => $value,
        };
    }
}
