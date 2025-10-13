<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum PrintType: string
{
    use EnumExtensionTrait;

    case ORDER_CHECK = 'ORDER_CHECK';
    case DELIVERY_CHECK = 'DELIVERY_CHECK';
    case DELIVERY_PLAN = 'DELIVERY_PLAN';
    case EARNING_CHECK = 'EARNING_CHECK';
    case EARNING_DIRECT_CHECK = 'EARNING_DIRECT_CHECK';
    case PURCHASE_CHECK = 'PURCHASE_CHECK';
    case PICKING_LIST = 'PICKING_LIST';
    case PICKING_LIST_PRINTER = 'PICKING_LIST_PRINTER';
    case CONTAINER_RETURN_CHECK = 'CONTAINER_RETURN_CHECK';
    case CONTAINER_PICKUP_CHECK = 'CONTAINER_PICKUP_CHECK';
    case CONTAINER_DIRECT_CHECK = 'CONTAINER_DIRECT_CHECK';
    case STOCK_TRANSFER_CHECK = 'STOCK_TRANSFER_CHECK';

    case DEPOSIT_CHECK = 'DEPOSIT_CHECK';
    case REBATE_DEPOSIT_CHECK = 'REBATE_DEPOSIT_CHECK';
    case DEPOSIT_BALANCE = 'DEPOSIT_BALANCE';
    case DEPOSIT_PLAN = 'DEPOSIT_PLAN';
    case INVOICE = 'INVOICE';
    case INVOICE_FOR_PAYMENT_SLIP = 'INVOICE_FOR_PAYMENT_SLIP';
    case PAYMENT_SLIP = 'PAYMENT_SLIP';
    case REBATE_INVOICE = 'REBATE_INVOICE';
    case REBATE_DETAIL = 'REBATE_DETAIL';

    case PAYMENT_CHECK = 'PAYMENT_CHECK';
    case PAYMENT_BALANCE = 'PAYMENT_BALANCE';
    case PAYMENT_LEDGER = 'PAYMENT_LEDGER';
    case PAYMENT_PLAN = 'PAYMENT_PLAN';

    case DAILY_BALANCE = 'DAILY_BALANCE';
    case ALCOHOL_SALES = 'ALCOHOL_SALES';
    case ESTIMATE = 'ESTIMATE';
    case CLIENT_SLIP = 'CLIENT_SLIP';
    case CLIENT_SLIP_PRINTER = 'CLIENT_SLIP_PRINTER';

    case REAL_STOCK = 'REAL_STOCKS';
    case INVENTORY = 'INVENTORIES';
    case INVENTORY_DIFF = 'INVENTORY_DIFFS';
    case INVENTORY_EXECUTE = 'INVENTORY_EXECUTES';
    case MONTHLY_STOCK_OVERVIEW = 'MONTHLY_STOCKS';

    case PURCHASE_DIRECT_ORDER = 'PURCHASE_DIRECT_ORDER';

    case ORDER = 'ORDER';
    case ARRIVAL_PLAN = 'ARRIVAL_PLAN';

    case EXTERNAL_DATA_IMPORT_EARNING_CHECK = "EXTERNAL_DATA_IMPORT_EARNING_CHECK";
    case EXTERNAL_DATA_IMPORT_EARNING_COMMENT_LIST = "EXTERNAL_DATA_IMPORT_EARNING_COMMENT_LIST";

    public function name(): string
    {
        return match ($this) {
            self::ORDER_CHECK => '注文チェックリスト',
            self::DELIVERY_CHECK => '仕入入力チェックリスト',
            self::DELIVERY_PLAN => '入荷予定リスト',
            self::EARNING_CHECK => '売上チェックリスト',
            self::EARNING_DIRECT_CHECK => '直送チェックリスト',
            self::PICKING_LIST => 'ピッキングリスト',
            self::PICKING_LIST_PRINTER => 'ピッキングリスト(プリンター出力)',
            self::PURCHASE_CHECK => '仕入チェックリスト',
            self::DEPOSIT_CHECK => '入金チェックリスト',
            self::REBATE_DEPOSIT_CHECK => 'リベート入金チェックリスト',
            self::DEPOSIT_BALANCE => '売掛金残高',
            self::DEPOSIT_PLAN => '回収予定表',
            self::INVOICE => '請求書',
            self::INVOICE_FOR_PAYMENT_SLIP => 'コンビニ支払い請求書',
            self::PAYMENT_SLIP => 'コンビニ支払用紙',
            self::REBATE_INVOICE => 'リベート請求書',
            self::REBATE_DETAIL => 'リベート内訳書',
            self::PAYMENT_CHECK => '支払チェックリスト',
            self::PAYMENT_BALANCE => '買掛金残高',
            self::PAYMENT_LEDGER => '買掛金元帳',
            self::PAYMENT_PLAN => '支払予定表',
            self::CONTAINER_PICKUP_CHECK => '空容器回収チェックリスト',
            self::CONTAINER_RETURN_CHECK => '空容器返却チェックリスト',
            self::CONTAINER_DIRECT_CHECK => '空容器直送返却チェックリスト',
            self::DAILY_BALANCE => '総合日計表',
            self::ALCOHOL_SALES => '酒類販売数量等報告書',
            self::ESTIMATE => '見積書',
            self::CLIENT_SLIP => '伝票',
            self::CLIENT_SLIP_PRINTER => '伝票(プリンター出力)',
            self::REAL_STOCK => '商品在庫表',
            self::INVENTORY => '棚卸指示書',
            self::INVENTORY_DIFF => '棚卸差異リスト',
            self::INVENTORY_EXECUTE => '棚卸確定リスト',
            self::MONTHLY_STOCK_OVERVIEW => '商品受払表',
            self::PURCHASE_DIRECT_ORDER => '発注書',
            self::STOCK_TRANSFER_CHECK => '倉庫移動チェックリスト',
            self::ORDER => '発注依頼書',
            self::ARRIVAL_PLAN => '入荷予定表',
            self::EXTERNAL_DATA_IMPORT_EARNING_CHECK => "取込エラーリスト",
            self::EXTERNAL_DATA_IMPORT_EARNING_COMMENT_LIST => '受注データコメント表'
        };
    }

    public function category(): string
    {
        return match ($this) {
            self::ORDER_CHECK,
            self::DELIVERY_CHECK,
            self::EARNING_CHECK,
            self::EARNING_DIRECT_CHECK,
            self::PICKING_LIST,
            self::PICKING_LIST_PRINTER,
            self::PAYMENT_CHECK,
            self::DEPOSIT_CHECK,
            self::REBATE_DEPOSIT_CHECK,
            self::PURCHASE_CHECK,
            self::CONTAINER_PICKUP_CHECK,
            self::CONTAINER_RETURN_CHECK,
            self::CONTAINER_DIRECT_CHECK,
            self::STOCK_TRANSFER_CHECK,
            self::EXTERNAL_DATA_IMPORT_EARNING_CHECK => 'チェックリスト',
            self::DEPOSIT_BALANCE,
            self::PAYMENT_BALANCE,
            self::PAYMENT_LEDGER,
            self::INVOICE,
            self::INVOICE_FOR_PAYMENT_SLIP,
            self::REBATE_INVOICE,
            self::REBATE_DETAIL,
            self::CLIENT_SLIP,
            self::CLIENT_SLIP_PRINTER,
            self::DAILY_BALANCE => '帳票',
            self::PAYMENT_SLIP => '支払い用紙',
            self::PAYMENT_PLAN,
            self::DELIVERY_PLAN,
            self::DEPOSIT_PLAN,
            self::ARRIVAL_PLAN => '予定表',
            self::REAL_STOCK,
            self::INVENTORY,
            self::INVENTORY_DIFF,
            self::INVENTORY_EXECUTE,
            self::MONTHLY_STOCK_OVERVIEW => '在庫表',
            self::PURCHASE_DIRECT_ORDER,
            self::ORDER => '発注書',

            default => 'その他'
        };
    }


    public function isNeededIds(): bool
    {
        return match ($this) {
            self::REAL_STOCK,
            self::MONTHLY_STOCK_OVERVIEW,
            self::ALCOHOL_SALES => false,
            default => true
        };
    }


    public function canSpecifyWarehouse(): bool
    {
        return match ($this) {
            self::ALCOHOL_SALES, self::MONTHLY_STOCK_OVERVIEW => true,
            default => false
        };
    }

    public function isNeededWarehouse(): bool
    {
        return match ($this) {
            self::ALCOHOL_SALES, self::MONTHLY_STOCK_OVERVIEW => true,
            default => false
        };
    }

    public function canSpecifyMonthPeriod(): bool
    {
        return match ($this) {
            self::ALCOHOL_SALES, self::MONTHLY_STOCK_OVERVIEW => true,
            default => false
        };
    }

    public function isNeededMonthPeriod(): bool
    {
        return match ($this) {
            self::ALCOHOL_SALES, self::MONTHLY_STOCK_OVERVIEW => true,
            default => false
        };
    }

    public function canSpecifyItemCategory1(): bool
    {
        return match ($this) {
            self::MONTHLY_STOCK_OVERVIEW => true,
            default => false
        };
    }

    public function canSpecifyItemType(): bool
    {
        return match ($this) {
            self::MONTHLY_STOCK_OVERVIEW => true,
            default => false
        };
    }
}
