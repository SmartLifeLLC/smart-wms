<?php

namespace App\Enums\AutoOrder;

use Filament\Support\Contracts\HasLabel;

enum TransmissionDocumentStatus: string implements HasLabel
{
    case TEST = 'TEST';             // テストファイル（JX送信不可）
    case DRAFT = 'DRAFT';           // テスト生成（確定前）- 旧ステータス
    case PENDING = 'PENDING';       // 送信待ち（確定済み）
    case TRANSMITTING = 'TRANSMITTING';
    case TRANSMITTED = 'TRANSMITTED';
    case CONFIRMED = 'CONFIRMED';
    case ERROR = 'ERROR';
    case CANCELLED = 'CANCELLED';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::TEST => 'テスト',
            self::DRAFT => 'テスト生成',
            self::PENDING => '送信待ち',
            self::TRANSMITTING => '送信中',
            self::TRANSMITTED => '送信済み',
            self::CONFIRMED => '確認済み',
            self::ERROR => 'エラー',
            self::CANCELLED => '送信取消',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::TEST => 'gray',
            self::DRAFT => 'gray',
            self::PENDING => 'warning',
            self::TRANSMITTING => 'info',
            self::TRANSMITTED => 'success',
            self::CONFIRMED => 'info',
            self::ERROR => 'danger',
            self::CANCELLED => 'gray',
        };
    }

    /**
     * JX送信可能かどうか
     */
    public function canTransmit(): bool
    {
        return match ($this) {
            self::PENDING => true,
            default => false,
        };
    }
}
