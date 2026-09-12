<?php

namespace App\Enums;

enum EMenuCategory: string
{
    case INBOUND = 'inbound';
    case OUTBOUND = 'outbound';
    case SHORTAGE = 'shortage';
    case HORIZONTAL_SHIPMENT = 'horizontal_shipment';
    case AUTO_ORDER = 'auto_order';
    case ORDER_TRANSMISSION = 'order_transmission';
    case ORDER_HISTORY = 'order_history';
    case ORDER_SETTINGS = 'order_settings';
    case INVENTORY = 'inventory';
    case MASTER_WAREHOUSE = 'master_warehouse';
    case MASTER_ORDER = 'master_order';
    case MASTER_PICKING = 'master_picking';
    case STATISTICS = 'statistics';
    case SETTINGS = 'settings';
    case LOGS = 'logs';
    case TEST_DATA = 'test_data';
    case GUIDE_ORDER = 'guide_order';
    case WAVE_MANAGEMENT = 'wave_management';

    public function label(): string
    {
        return match ($this) {
            self::INBOUND => '入荷管理',
            self::OUTBOUND => '出荷管理',
            self::SHORTAGE => '欠品管理',
            self::HORIZONTAL_SHIPMENT => '倉庫移動',
            self::AUTO_ORDER => '発注処理',
            self::ORDER_TRANSMISSION => '発注送受信管理',
            self::ORDER_HISTORY => '発注履歴',
            self::ORDER_SETTINGS => '発注解説',
            self::INVENTORY => '在庫管理',
            self::MASTER_WAREHOUSE => '倉庫マスタ',
            self::MASTER_ORDER => '発注マスタ',
            self::MASTER_PICKING => 'ピッキングマスタ',
            self::STATISTICS => '統計データ',
            self::SETTINGS => 'システム設定',
            self::LOGS => 'ログ',
            self::TEST_DATA => 'テストデータ',
            self::GUIDE_ORDER => '発注',
            self::WAVE_MANAGEMENT => '波動管理',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::INBOUND => 'heroicon-o-arrow-down-tray',
            self::OUTBOUND => 'heroicon-o-arrow-up-tray',
            self::SHORTAGE => 'heroicon-o-exclamation-triangle',
            self::HORIZONTAL_SHIPMENT => 'heroicon-o-truck',
            self::AUTO_ORDER => 'heroicon-o-clipboard-document-check',
            self::ORDER_TRANSMISSION => 'heroicon-o-paper-airplane',
            self::ORDER_HISTORY => 'heroicon-o-clock',
            self::ORDER_SETTINGS => 'heroicon-o-cog-6-tooth',
            self::INVENTORY => 'heroicon-o-cube',
            self::MASTER_WAREHOUSE => 'heroicon-o-building-office-2',
            self::MASTER_ORDER => 'heroicon-o-shopping-cart',
            self::MASTER_PICKING => 'heroicon-o-user-group',
            self::STATISTICS => 'heroicon-o-chart-bar',
            self::SETTINGS => 'heroicon-o-cog-6-tooth',
            self::LOGS => 'heroicon-o-document-magnifying-glass',
            self::TEST_DATA => 'heroicon-o-beaker',
            self::GUIDE_ORDER => 'heroicon-o-shopping-cart',
            self::WAVE_MANAGEMENT => 'heroicon-o-adjustments-horizontal',
        };
    }

    public function sort(): int
    {
        return match ($this) {
            self::INBOUND => 1,
            self::OUTBOUND => 2,
            self::SHORTAGE => 3,
            self::HORIZONTAL_SHIPMENT => 4,
            self::AUTO_ORDER => 5,
            self::ORDER_TRANSMISSION => 6,
            self::ORDER_HISTORY => 7,
            self::ORDER_SETTINGS => 8,
            self::INVENTORY => 9,
            self::MASTER_WAREHOUSE => 10,
            self::MASTER_ORDER => 11,
            self::MASTER_PICKING => 12,
            self::STATISTICS => 13,
            self::WAVE_MANAGEMENT => 96,
            self::LOGS => 97,
            self::SETTINGS => 98,
            self::TEST_DATA => 99, // Last
            self::GUIDE_ORDER => 100,
        };
    }
}
