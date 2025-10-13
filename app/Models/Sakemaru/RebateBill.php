<?php

namespace App\Models\Sakemaru;

use App\Enums\BillingType;
use App\Enums\EAllocationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RebateBill extends CustomModel
{
    protected $guarded = [];
    protected $casts = [];

    public function partner() : belongsTo
    {
        return $this->belongsTo(Partner::class);
    }
    public function rebate() : belongsTo
    {
        return $this->belongsTo(Rebate::class);
    }
    public function accountClassification() : belongsTo
    {
        return $this->belongsTo(AccountClassification::class);
    }

    public function rebate_deposits() : HasMany
    {
        return $this->hasMany(RebateDeposit::class);
    }

    public static function tentativeBill(?int $partner_id, bool $or_create = false, ?int $client_id = null, ?int $creator_id = null) : ?self
    {
        if ($or_create) {
            $client_id = $client_id ?? Partner::find($partner_id)->client_id;
            $creator_id = $creator_id ?? auth()->user()->id ?? 0;
            return static::firstOrCreate([
                'partner_id' => $partner_id,
                'is_tentative' => true,
            ], [
                'client_id' => $client_id,
                'partner_id' => $partner_id,
                'creator_id' => $creator_id,
                'last_updater_id' => $creator_id,
                'closing_rebate_id' => 0,
                'rebate_type_id' => null,
                'number' => 0,
                'is_tentative' => true,
            ]);
        }
        return static::where('partner_id', $partner_id)
            ->where('is_tentative', true)
            ->first();
    }


    public static function allocateTentative($client_id, $partner_id, $creator_id, $price): self
    {
        $bill = self::tentativeBill($partner_id, true, $client_id, $creator_id);
        $bill->allocation_amount += $price;
        $bill->save();
        return $bill;
    }
}
