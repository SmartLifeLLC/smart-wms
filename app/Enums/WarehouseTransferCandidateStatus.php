<?php

namespace App\Enums;

/**
 * 倉庫移動候補（HANDY起点）の状態
 */
enum WarehouseTransferCandidateStatus: string
{
    case PENDING = 'PENDING';
    case CONFIRMED = 'CONFIRMED';
    case EXECUTED = 'EXECUTED';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => '未確定',
            self::CONFIRMED => '確定済',
            self::EXECUTED => '伝票作成済',
            self::FAILED => '伝票作成失敗',
            self::CANCELLED => '取消',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::CONFIRMED => 'info',
            self::EXECUTED => 'success',
            self::FAILED => 'danger',
            self::CANCELLED => 'gray',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::PENDING;
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status) => [$status->value => $status->label()])
            ->all();
    }
}
