<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Services\AutoOrder\LegacyEosSlipNumberService;
use Carbon\Carbon;
use Tests\TestCase;

class LegacyEosSlipNumberServiceTest extends TestCase
{
    public function test_format_slip_number_uses_legacy_eos_layout(): void
    {
        $service = new LegacyEosSlipNumberService;

        $this->assertSame('91461018629', $service->formatSlipNumber('91', 46, 18629));
        $this->assertSame('01451018907', $service->formatSlipNumber('1', 45, 18907));
    }

    public function test_year_code_uses_legacy_base_year(): void
    {
        $service = new LegacyEosSlipNumberService;

        $this->assertSame(46, $service->yearCode(Carbon::parse('2026-07-13')));
        $this->assertSame(45, $service->yearCode('2025-08-04'));
    }

    public function test_legacy_slip_number_requires_fixed_middle_code_10(): void
    {
        $service = new LegacyEosSlipNumberService;

        $this->assertTrue($service->isLegacySlipNumber('91461018629'));
        $this->assertFalse($service->isLegacySlipNumber('26070801011'));
        $this->assertFalse($service->isLegacySlipNumber('91460018629'));
    }
}
