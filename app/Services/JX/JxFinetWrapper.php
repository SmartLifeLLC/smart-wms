<?php

namespace App\Services\JX;

class JxFinetWrapper
{
    private const RECORD_LENGTH = 128;

    private const FINET_CODE_OFFSET = 42;

    private const FINET_CODE_LENGTH = 6;

    public static function detectReceiverStationCode(?string $data): ?string
    {
        if ($data === null || $data === '') {
            return null;
        }

        $content = str_replace(["\r\n", "\r", "\n"], '', $data);
        $length = strlen($content);

        for ($offset = 0; $offset + self::RECORD_LENGTH <= $length; $offset += self::RECORD_LENGTH) {
            $record = substr($content, $offset, self::RECORD_LENGTH);

            if (($record[0] ?? '') !== '1') {
                continue;
            }

            $code = trim(substr($record, self::FINET_CODE_OFFSET, self::FINET_CODE_LENGTH));

            return $code !== '' ? $code : null;
        }

        return null;
    }
}
