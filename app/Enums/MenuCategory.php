<?php


namespace App\Enums;


namespace App\Enums;

enum MenuCategory: string
{
    case ORDER = 'ORDER';
    case SALES = 'SALES';
    case REBATE = 'REBATE';
    case STOCK = 'STOCK';
    case REPORT = 'REPORT';
    case STATS = 'STATS';

    case DATA = 'DATA';

    case EXTERNAL_COLLABORATION = 'EXTERNAL_COLLABORATION';
    case DATA_VERIFICATION = 'DATA_VERIFICATION';
    case SETTING = 'SETTING';

    case WMS = 'WMS'; //WMS外部システムの場合
    case TOOLS = 'TOOLS';

    case AI = 'AI';

    case SMART_TRADE = 'SMART_TRADE';
    public function getMenuCategoryDescription(): string
    {
        return match ($this) {
            self::ORDER => '仕入',
            self::SALES => '売上',
            self::REBATE => '割戻',
            self::STOCK => '在庫',
            self::REPORT => '支援',
            self::DATA => '管理',
            self::EXTERNAL_COLLABORATION => '連携',
            self::DATA_VERIFICATION => '照合',
            self::SETTING => '設定',
            self::WMS => 'WMS',
            self::TOOLS => 'ツール',
            self::STATS => '統計',
            self::AI => 'AI',
            self::SMART_TRADE =>'スマトレ'
        };
    }

}
