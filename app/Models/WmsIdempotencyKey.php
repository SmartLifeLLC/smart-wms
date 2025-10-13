<?php

namespace App\Models;

class WmsIdempotencyKey extends WmsModel
{
    protected $table = 'wms_idempotency_keys';

    public $timestamps = false;

    protected $fillable = [
        'scope',
        'key_hash',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Check if an idempotency key exists
     */
    public static function exists(string $scope, string $key): bool
    {
        $hash = hash('sha256', $key);
        return self::where('scope', $scope)
            ->where('key_hash', $hash)
            ->exists();
    }

    /**
     * Store an idempotency key
     */
    public static function store(string $scope, string $key): bool
    {
        try {
            $hash = hash('sha256', $key);
            self::create([
                'scope' => $scope,
                'key_hash' => $hash,
                'created_at' => now(),
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
