<?php

namespace Tests\Unit\Models;

use App\Models\WmsContractorSetting;
use Tests\TestCase;

class WmsContractorSettingJxTransmissionTimeTest extends TestCase
{
    public function test_jx_transmission_time_for_day_uses_weekday_time_from_monday_to_saturday(): void
    {
        $setting = (new WmsContractorSetting)->forceFill([
            'jx_transmission_time' => '13:40',
            'jx_transmission_sunday_time' => '23:40',
        ]);

        foreach ([1, 2, 3, 4, 5, 6] as $dayOfWeek) {
            $this->assertSame('13:40', $setting->jxTransmissionTimeForDay($dayOfWeek));
        }
    }

    public function test_jx_transmission_time_for_day_uses_sunday_time_on_sunday(): void
    {
        $setting = (new WmsContractorSetting)->forceFill([
            'jx_transmission_time' => '13:40',
            'jx_transmission_sunday_time' => '23:40',
        ]);

        $this->assertSame('23:40', $setting->jxTransmissionTimeForDay(0));
    }
}
