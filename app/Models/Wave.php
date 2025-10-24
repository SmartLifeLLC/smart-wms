<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wave extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'wms_waves';

    protected $fillable = [
        'wms_wave_setting_id',
        'wave_no',
        'shipping_date',
        'status',
    ];

    protected $casts = [
        'shipping_date' => 'date',
    ];

    public function waveSetting(): BelongsTo
    {
        return $this->belongsTo(WaveSetting::class, 'wms_wave_setting_id');
    }

    /**
     * Generate wave number in format: W###-C###-YYYYMMDD-{id}
     */
    public static function generateWaveNo(int $warehouseCode, int $courseCode, string $date, int $waveId): string
    {
        $dateFormatted = date('Ymd', strtotime($date));
        return sprintf('W%03d-C%03d-%s-%d', $warehouseCode, $courseCode, $dateFormatted, $waveId);
    }
}
