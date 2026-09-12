<?php

namespace App\Models\Sakemaru;

use App\Enums\AvailableQuantityFlag;
use App\Enums\TemperatureType;
use App\Models\WmsPickingArea;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Location extends CustomModel
{
    use HasFactory;

    public const ALL_ALLOCATABLE_QUANTITY_FLAGS = 7; // CASE | PIECE | CARTON

    /**
     * Disable is_active filter as locations table doesn't have this column
     */
    protected bool $hasIsActiveColumn = false;

    protected $guarded = [];

    protected $casts = [
        'available_quantity_flags' => 'integer',
        'temperature_type' => TemperatureType::class,
        'is_restricted_area' => 'boolean',
        'is_disabled' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Location $location) {
            static::fillClientId($location);
            static::fillAllocatableQuantityFlags($location);
        });

        static::updating(function (Location $location) {
            static::fillClientId($location);
        });
    }

    private static function fillClientId(Location $location): void
    {
        if (! empty($location->client_id)) {
            return;
        }

        $firstClient = Client::first();
        if ($firstClient) {
            $location->client_id = $firstClient->id;
        }
    }

    private static function fillAllocatableQuantityFlags(Location $location): void
    {
        if (
            $location->available_quantity_flags !== null
            && (int) $location->available_quantity_flags !== AvailableQuantityFlag::UNKNOWN->value
        ) {
            return;
        }

        $location->available_quantity_flags = self::ALL_ALLOCATABLE_QUANTITY_FLAGS;
    }

    public function warehouse(): belongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    /**
     * このロケーションが属するピッキングエリア
     */
    public function pickingArea(): BelongsTo
    {
        return $this->belongsTo(WmsPickingArea::class, 'wms_picking_area_id');
    }

    public function joinedLocation(): Attribute
    {
        return Attribute::make(
            get: function () {
                return self::formatCode($this->code1, $this->code2, $this->code3, ' ');
            }
        );
    }

    public static function formatCode(?string $code1, ?string $code2 = null, ?string $code3 = null, string $separator = ''): string
    {
        return collect([$code1, $code2, $code3])
            ->filter(fn ($code) => filled($code))
            ->map(fn ($code) => (string) $code)
            ->implode($separator);
    }

    public static function formatDisplayCode(?string $code1, ?string $code2 = null, ?string $code3 = null, string $separator = ''): string
    {
        $displayCode3 = filled($code3) && ! preg_match('/^0+$/', (string) $code3)
            ? $code3
            : null;

        return self::formatCode($code1, $code2, $displayCode3, $separator);
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
            ->where('is_disabled', false)
            ->first();
    }

    public static function firstOrCreateDefault(?int $warehouse_id = null, ?int $client_id = null): Location
    {
        $user = auth()->user();
        $client_id = $client_id ?? $user?->client_id;
        $warehouse_id = $warehouse_id ?? $user?->warehouse?->id;

        $location = Location::query()
            ->where('client_id', $client_id)
            ->where('warehouse_id', $warehouse_id)
            ->where('code1', 'Z')
            ->where('code2', '0')
            ->where('code3', '0')
            ->where('is_disabled', false)
            ->first();

        if ($location) {
            return $location;
        }

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

    public static function getDefaultBaseInfo(): array
    {
        return [
            'code1' => 'Z',
            'code2' => '0',
            'code3' => '0',
            'name' => 'デフォルト',
        ];
    }

    /**
     * Check if location supports given quantity type
     */
    public function supports(AvailableQuantityFlag $flag): bool
    {
        return AvailableQuantityFlag::supports($this->available_quantity_flags ?? self::ALL_ALLOCATABLE_QUANTITY_FLAGS, $flag);
    }

    /**
     * Set available quantity flags from array of AvailableQuantityFlag enums
     *
     * @param  array<AvailableQuantityFlag>  $flags
     */
    public function setAvailableUnits(array $flags): void
    {
        $bitmask = AvailableQuantityFlag::toBitmask($flags);

        if (! AvailableQuantityFlag::isValid($bitmask)) {
            throw new \InvalidArgumentException('UNKNOWN flag cannot be combined with other flags');
        }

        $this->available_quantity_flags = $bitmask;
    }

    /**
     * Get array of supported AvailableQuantityFlag enums
     *
     * @return array<AvailableQuantityFlag>
     */
    public function getAvailableUnits(): array
    {
        return AvailableQuantityFlag::fromBitmask($this->available_quantity_flags ?? self::ALL_ALLOCATABLE_QUANTITY_FLAGS);
    }
}
