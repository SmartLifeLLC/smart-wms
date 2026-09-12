<?php

namespace App\Enums\AutoOrder;

enum IncomingScheduleStatus: string
{
    case PENDING = 'PENDING';
    case PARTIAL = 'PARTIAL';
    case CONFIRMED = 'CONFIRMED';
    case TRANSMITTED = 'TRANSMITTED';
    case CANCELLED = 'CANCELLED';
    case PARTIAL_CANCELLED = 'PARTIAL_CANCELLED';
    case DELETED = 'DELETED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => '未入荷',
            self::PARTIAL => '一部入荷',
            self::CONFIRMED => '入荷完了',
            self::TRANSMITTED => '連携済み',
            self::CANCELLED => 'キャンセル',
            self::PARTIAL_CANCELLED => '一部入荷キャンセル',
            self::DELETED => '削除済み',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::PARTIAL => 'info',
            self::CONFIRMED => 'success',
            self::TRANSMITTED => 'gray',
            self::CANCELLED => 'danger',
            self::PARTIAL_CANCELLED => 'danger',
            self::DELETED => 'gray',
        };
    }
}
