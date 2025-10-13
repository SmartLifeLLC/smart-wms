<?php


namespace App\Enums\Partners;

use App\Enums\TimeZone;
use App\Traits\EnumExtensionTrait;
use Carbon\Carbon;

enum EDepositPlan: string
{
    use EnumExtensionTrait;

    case THIS_MONTH = 'THIS_MONTH';
    case IN_ONE_MONTH = 'IN_ONE_MONTH';
    case IN_TWO_MONTHS = 'IN_TWO_MONTHS';
    case IN_THREE_MONTHS = 'IN_THREE_MONTHS';


    public function name() : string
    {
        return match($this) {
            self::THIS_MONTH => '当月',
            self::IN_ONE_MONTH => '翌月',
            self::IN_TWO_MONTHS => '翌々月',
            self::IN_THREE_MONTHS => '3ヶ月後',
        };
    }
    public function getID() : int
    {
        return match($this) {
            self::THIS_MONTH => 0,
            self::IN_ONE_MONTH => 1,
            self::IN_TWO_MONTHS => 2,
            self::IN_THREE_MONTHS => 3,
        };
    }

    public function getDate(int $date, string $closing_date) : Carbon
    {
        $base_date = Carbon::createFromDate($closing_date);

        $deposit_date = match($this) {
            self::THIS_MONTH => $base_date,
            self::IN_ONE_MONTH => $base_date->addMonthNoOverflow(),
            self::IN_TWO_MONTHS => $base_date->addMonthsNoOverflow(2),
            self::IN_THREE_MONTHS => $base_date->addMonthsNoOverflow(3),
        };

        if ($date >= 28) {      // 28日以降の場合は月末に
            $deposit_date = $base_date->endOfMonth();
        } else {
            $deposit_date->day = $date;
        }

        return $deposit_date;
    }
}
