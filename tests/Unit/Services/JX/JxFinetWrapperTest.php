<?php

namespace Tests\Unit\Services\JX;

use App\Services\JX\JxFinetWrapper;
use PHPUnit\Framework\TestCase;

class JxFinetWrapperTest extends TestCase
{
    public function test_detects_receiver_station_code_from_wrapper_record(): void
    {
        $this->assertSame('EA0683', JxFinetWrapper::detectReceiverStationCode($this->wrapperRecord('EA0683')));
    }

    public function test_detects_receiver_station_code_when_records_have_line_breaks(): void
    {
        $data = $this->wrapperRecord('MA0807')."\r\n".str_repeat('A', 128);

        $this->assertSame('MA0807', JxFinetWrapper::detectReceiverStationCode($data));
    }

    public function test_detects_receiver_station_code_even_if_wrapper_is_not_first_record(): void
    {
        $data = str_repeat('A', 128).$this->wrapperRecord('MB65D7');

        $this->assertSame('MB65D7', JxFinetWrapper::detectReceiverStationCode($data));
    }

    public function test_returns_null_when_wrapper_record_does_not_exist(): void
    {
        $this->assertNull(JxFinetWrapper::detectReceiverStationCode(str_repeat('A', 128)));
    }

    private function wrapperRecord(string $finetCode): string
    {
        $record = str_repeat(' ', 128);
        $record[0] = '1';

        return substr_replace($record, str_pad($finetCode, 6), 42, 6);
    }
}
