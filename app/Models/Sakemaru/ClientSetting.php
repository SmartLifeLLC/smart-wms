<?php

namespace App\Models\Sakemaru;

use App\Enums\TimeZone;
use App\Models\Sakemaru\Client;
use App\Models\Sakemaru\CustomModel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ClientSetting extends CustomModel
{
    use HasFactory;

    protected $guarded = [];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public static function systemDate(bool $default_now = false, ?int $client_id = null): Carbon|null
    {
        if ($client_id) {
            $client_setting = ClientSetting::firstWhere('client_id', $client_id);
        } else {
            $client_setting = auth()->user()?->client?->setting;
        }
        if ($client_setting?->system_date) {
            return new Carbon($client_setting->system_date);
        }
        if ($default_now) {
            return TimeZone::TOKYO->now();
        }

        return ClientSetting::first()->system_date ;

    }

    public static function systemMonth(): int|null
    {
        $client_setting = auth()->user()?->client?->setting;
        if ($client_setting?->system_month) {
            return $client_setting->system_month;
        }
        return null;
    }

    public static function endOfSystemMonth(bool $default_now = false): Carbon|null
    {
        $client_setting = auth()->user()?->client?->setting;
        $client_setting->refresh();
        $date = null;
        if ($client_setting?->system_month) {
            $date = Carbon::create($client_setting->system_year, $client_setting->system_month, 1);
        } else {
            if ($default_now) {
                $date = TimeZone::TOKYO->now();
            }
        }
        return $date?->endOfMonth();
    }

    public static function isLocked(): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }
        return cacheValue("locked-{$user->id}", function () use ($user) {
            return (bool)$user->client?->setting?->is_locked;
        });
    }

    /**
     * 操作をロックする
     * @param bool $is_lock
     * @return void
     */
    public static function lock(bool $is_lock = true): void
    {
        $user = auth()->user();
        $setting = $user?->client?->setting;
        if ($setting) {
            $setting->is_locked = $is_lock;
            $setting->save();
        }
        Artisan::call('cache:clear file');

        if ($is_lock) {
            sleep(config('app.lock_sleep_time')); // テストでわかりやすくするために一定時間sleep
        }
    }

    /**
     * 操作をアンロックする
     * @return void
     */
    public static function unlock(): void
    {
        self::lock(false);
    }

    public static function hasWms(){
        $client_id = auth()?->user()?->client_id??null;
        return ClientSetting::where('client_id',$client_id)->first()?->has_wms??false;
    }

    public static function authSetting() : ?self
    {
        $user = auth()->user();
        return $user?->client?->setting;
    }
}
