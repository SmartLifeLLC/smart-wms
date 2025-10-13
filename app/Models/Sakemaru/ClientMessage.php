<?php

namespace App\Models\Sakemaru;
use App\Enums\TimeZone;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ClientMessage extends CustomModel
{
    protected $guarded = [];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public static function addMessage(string $message) : self
    {
        return self::create([
            'client_id' => auth()->user()->client_id,
            'message' => $message,
        ]);
    }

    public static function getMessages() : array
    {
        return self::where([
            'client_id' => auth()->user()->client_id
        ])
            ->pluck('message')
            ->toArray();
    }
}
