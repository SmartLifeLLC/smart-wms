<?php

namespace App\Models\Sakemaru;

use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\CustomModel;
use App\Models\Sakemaru\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Client extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function setting(): HasOne
    {
        return $this->hasOne(ClientSetting::class);
    }

    public function joinedAddress() : Attribute
    {
        return Attribute::make(
            get: function() {
                return $this->address1 . " " . $this->address2;
            }
        );
    }

    public function isTaniguchi():bool{
        return $this->code == '240603000100';
    }

    public function isMotohara():bool{
        return $this->code == '241003000300';
    }
}
