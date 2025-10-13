<?php


namespace App\Traits;

use App\Enums\QuantityType;
use Illuminate\Database\Eloquent\Casts\Attribute;

trait QuantityTrait{

    public function pieceQuantity() : Attribute
    {
        return new Attribute(function () {
            return QuantityType::PIECE->isSameAs($this->quantity_type) ? $this->quantity : 0;
        });
    }

    public function caseQuantity() : Attribute
    {
        return new Attribute(function () {
            return QuantityType::CASE->isSameAs($this->quantity_type) ? $this->quantity : 0;
        });
    }
}
