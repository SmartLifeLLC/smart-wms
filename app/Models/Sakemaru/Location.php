<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Location extends CustomModel
{
    use HasFactory;
    protected $guarded = [];
    protected $casts = [];

    public function warehouse() : belongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function wmsLocation()
    {
        return $this->hasOne(\App\Models\WmsLocation::class, 'location_id', 'id');
    }
    public function joinedLocation() : Attribute
    {
        return Attribute::make(
            get: function() {
                return $this->code1 . " " . $this->code2 . " " . $this->code3;
            }
        );
    }

    public static function defaultLocation(?string $warehouse_id = null): ?Location
    {
        $client_id = auth()->user()?->client_id;
        $warehouse_id = $warehouse_id ?? auth()->user()?->warehouse?->id;

        return Location::query()
            ->where('client_id', '=', $client_id)
            ->where('warehouse_id', '=', $warehouse_id)
            ->where('code1', '=', 'Z')
            ->where('code2', '=', '0')
            ->where('code3', '=', '0')
            ->first();
    }

    public static function firstOrCreateDefault(?int $warehouse_id = null, ?int $client_id = null): Location
    {
        $user = auth()->user();
        $client_id = $client_id ?? $user?->client_id;
        $warehouse_id = $warehouse_id ?? $user?->warehouse?->id;
        return Location::firstOrCreate([
            'client_id' => $client_id,
            'warehouse_id' => $warehouse_id,
            'code1' => 'Z',
            'code2' => '0',
            'code3' => '0',
        ], [
            'name' => 'デフォルト',
        ]);
    }

    public static function getDefaultBaseInfo() : array
    {
        return [
            'code1' => 'Z',
            'code2' => '0',
            'code3' => '0',
            'name' => 'デフォルト',
        ];
    }
}
