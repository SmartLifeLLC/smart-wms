<?php

namespace App\Models\Sakemaru;

use App\Enums\TimeZone;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ClientSetting extends CustomModel
{
    use HasFactory;

    private const CACHE_TTL_SECONDS = 600;

    private const IN_MEMORY_CACHE_TTL_SECONDS = 60;

    private const CACHE_KEY_FIRST = 'client_settings:first';

    private const CACHE_KEY_CLIENT_PREFIX = 'client_settings:client:';

    private static array $cachedSettings = [];

    private static array $cachedSettingStoredAt = [];

    protected $guarded = [];

    // client_settingsテーブルにはis_activeカラムがない
    protected bool $hasIsActiveColumn = false;

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected static function booted(): void
    {
        static::saved(fn (self $setting) => self::clearCachedSetting($setting->client_id));
        static::deleted(fn (self $setting) => self::clearCachedSetting($setting->client_id));
    }

    public static function cachedFirst(): ?self
    {
        return self::rememberSetting(self::CACHE_KEY_FIRST, fn () => self::query()->first());
    }

    public static function cachedByClient(?int $clientId): ?self
    {
        return self::rememberSetting(
            self::CACHE_KEY_CLIENT_PREFIX.($clientId ?? 'null'),
            fn () => self::query()->where('client_id', $clientId)->first(),
        );
    }

    public static function clearCachedSetting(?int $clientId = null): void
    {
        unset(self::$cachedSettings[self::CACHE_KEY_FIRST]);
        unset(self::$cachedSettingStoredAt[self::CACHE_KEY_FIRST]);
        Cache::forget(self::CACHE_KEY_FIRST);

        $clientKey = self::CACHE_KEY_CLIENT_PREFIX.($clientId ?? 'null');
        unset(self::$cachedSettings[$clientKey]);
        unset(self::$cachedSettingStoredAt[$clientKey]);
        Cache::forget($clientKey);
    }

    /**
     * @param  int|null  $client_id  (client id deprecated for a whole system)
     */
    public static function systemDate(bool $default_now = false, ?int $client_id = null): ?Carbon
    {
        //        if ($client_id) {
        //            $client_setting = ClientSetting::firstWhere('client_id', $client_id);
        //        } else {
        //            $client_setting = auth()->user()?->client?->setting;
        //        }
        //        if ($client_setting?->system_date) {
        //            return new Carbon($client_setting->system_date);
        //        }
        //        if ($default_now) {
        //            return TimeZone::TOKYO->now();
        //        }

        $systemDate = self::cachedFirst()?->system_date;

        if ($systemDate) {
            return new Carbon($systemDate);
        }

        if ($default_now) {
            return TimeZone::TOKYO->now();
        }

        return null;

    }

    public static function systemYesterdayYMD(): string
    {
        return self::systemDate()->copy()->subDay()->format('Y-m-d');
    }

    public static function systemDateYMD(): string
    {
        return self::systemDate()->format('Y-m-d');
    }

    public static function freshSystemDate(bool $default_now = false, ?string $context = null): ?Carbon
    {
        $setting = self::query()->first();

        if ($setting?->system_date) {
            self::detectStaleFirstCache($setting, $context);
            self::storeInMemorySetting(self::CACHE_KEY_FIRST, $setting);
            Cache::put(self::CACHE_KEY_FIRST, $setting, self::CACHE_TTL_SECONDS);

            return new Carbon($setting->system_date);
        }

        if ($default_now) {
            return TimeZone::TOKYO->now();
        }

        return null;
    }

    public static function freshSystemDateYMD(?string $context = null): string
    {
        return self::freshSystemDate(context: $context)->format('Y-m-d');
    }

    public static function systemMonth(): ?int
    {
        $client_setting = auth()->user()?->client?->setting;
        if ($client_setting?->system_month) {
            return $client_setting->system_month;
        }

        return null;
    }

    public static function endOfSystemMonth(bool $default_now = false): ?Carbon
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
        if (! $user) {
            return false;
        }

        return cacheValue("locked-{$user->id}", function () use ($user) {
            return (bool) $user->client?->setting?->is_locked;
        });
    }

    /**
     * 操作をロックする
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
     */
    public static function unlock(): void
    {
        self::lock(false);
    }

    public static function hasWms()
    {
        $client_id = auth()?->user()?->client_id ?? null;

        return self::cachedByClient($client_id)?->has_wms ?? false;
    }

    private static function rememberSetting(string $key, callable $resolver): ?self
    {
        if (array_key_exists($key, self::$cachedSettings) && self::isInMemoryCacheFresh($key)) {
            return self::$cachedSettings[$key];
        }

        $previous = self::$cachedSettings[$key] ?? null;
        unset(self::$cachedSettings[$key], self::$cachedSettingStoredAt[$key]);

        $setting = self::resolveSetting($key, $resolver);

        self::storeInMemorySetting($key, $setting);
        self::logInMemoryCacheRefreshIfChanged($key, $previous, $setting);

        return $setting;
    }

    private static function resolveSetting(string $key, callable $resolver): ?self
    {
        if ($key === self::CACHE_KEY_FIRST) {
            $setting = $resolver();
            Cache::put($key, $setting, self::CACHE_TTL_SECONDS);

            return $setting;
        }

        return Cache::remember(
            $key,
            self::CACHE_TTL_SECONDS,
            $resolver,
        );
    }

    private static function isInMemoryCacheFresh(string $key): bool
    {
        $storedAt = self::$cachedSettingStoredAt[$key] ?? null;

        return $storedAt !== null && (time() - $storedAt) < self::IN_MEMORY_CACHE_TTL_SECONDS;
    }

    private static function storeInMemorySetting(string $key, ?self $setting): void
    {
        self::$cachedSettings[$key] = $setting;
        self::$cachedSettingStoredAt[$key] = time();
    }

    private static function detectStaleFirstCache(self $freshSetting, ?string $context = null): void
    {
        $cached = self::$cachedSettings[self::CACHE_KEY_FIRST] ?? null;

        if (! $cached instanceof self) {
            return;
        }

        self::logInMemoryCacheRefreshIfChanged(self::CACHE_KEY_FIRST, $cached, $freshSetting, $context);
    }

    private static function logInMemoryCacheRefreshIfChanged(
        string $key,
        ?self $previous,
        ?self $current,
        ?string $context = null
    ): void {
        if (! $previous instanceof self || ! $current instanceof self) {
            return;
        }

        $previousSystemDate = $previous->system_date ? Carbon::parse($previous->system_date)->format('Y-m-d') : null;
        $currentSystemDate = $current->system_date ? Carbon::parse($current->system_date)->format('Y-m-d') : null;

        if ($previousSystemDate === $currentSystemDate) {
            return;
        }

        Log::warning('Stale ClientSetting in-memory cache detected', [
            'cache_key' => $key,
            'context' => $context,
            'previous_setting_id' => $previous->getKey(),
            'current_setting_id' => $current->getKey(),
            'previous_system_date' => $previousSystemDate,
            'current_system_date' => $currentSystemDate,
        ]);
    }

    public static function authSetting(): ?self
    {
        $user = auth()->user();

        return $user?->client?->setting;
    }

    /**
     * 酒丸シリーズのサブドメインURLを生成
     *
     * @param  string  $subdomain  search, trade, documents, delivery, insights, knowledge
     */
    public static function getSakemaruSubdomainUrl(string $subdomain): string
    {
        $coreUrl = parse_url(config('app.core_url'));
        $scheme = $coreUrl['scheme'] ?? 'https';
        $host = $coreUrl['host'] ?? 'localhost';

        return "{$scheme}://{$subdomain}.{$host}";
    }
}
