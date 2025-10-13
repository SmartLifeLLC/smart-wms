<?php


namespace App\Enums;


namespace App\Enums;

enum TransactionType: string
{
    case ORDER = 'ORDER';
    case SALE = 'SALES';
    case EARNING_DIRECT = 'EARNING_DIRECT';
    case PURCHASE = 'PURCHASE';
    case DELIVERY = 'DELIVERY';
    case CLOSING_DAILY = 'CLOSING_DAILY';
    case CLOSING_MONTHLY = 'CLOSING_MONTHLY';
    case CLOSING_PAYMENT_BILL = 'CLOSING_PAYMENT_BILL';
    case CLOSING_DEPOSIT_BILL = 'CLOSING_DEPOSIT_BILL';
    case CLOSING_REBATE = 'CLOSING_REBATE';
    case ESTIMATE = 'ESTIMATE';
    case DEPOSIT = 'DEPOSIT';
    case REBATE_DEPOSIT = 'REBATE_DEPOSIT';
    case PAYMENT = 'PAYMENT';
    case CONTAINER_PICKUP = 'CONTAINER_PICKUP';
    case CONTAINER_RETURN = 'CONTAINER_RETURN';
    case CONTAINER_DIRECT = 'CONTAINER_DIRECT';
    case REAL_STOCK = 'REAL_STOCK';
    case INVENTORY = 'INVENTORY';
    case INVENTORY_DIFF = 'INVENTORY_DIFF';
    case INVENTORY_EXECUTE = 'INVENTORY_EXECUTE';
    case MONTHLY_STOCK_OVERVIEW = 'MONTHLY_STOCK_OVERVIEW';
    case STOCK_TRANSFER = 'STOCK_TRANSFER';

    case EXTERNAL_DATA_IMPORT_EARNING = 'EXTERNAL_DATA_IMPORT_EARNING';
    case EXTERNAL_DATA_IMPORT_EARNING_COMMENT = 'EXTERNAL_DATA_IMPORT_EARNING_COMMENT';
    public function name(): string
    {
        return match ($this) {
            self::ORDER => '発注',
            self::SALE => '売上',
            self::EARNING_DIRECT => '直送代配',
            self::PURCHASE => '仕入',
            self::DELIVERY => '配送',
            self::CLOSING_DAILY => '日次締め',
            self::CLOSING_MONTHLY => '月次締め',
            self::CLOSING_PAYMENT_BILL => '買掛締め',
            self::CLOSING_DEPOSIT_BILL => '売掛締め',
            self::CLOSING_REBATE => 'リベート締め',
            self::ESTIMATE => '見積',
            self::DEPOSIT => '入金',
            self::REBATE_DEPOSIT => 'リベート入金',
            self::PAYMENT => '支払',
            self::CONTAINER_PICKUP => '空容器回収',
            self::CONTAINER_RETURN => '空容器返却',
            self::CONTAINER_DIRECT => '空容器直送返却',
            self::REAL_STOCK => '即時在庫',
            self::INVENTORY => '棚卸指示書',
            self::INVENTORY_DIFF => '棚卸差異リスト',
            self::INVENTORY_EXECUTE => '棚卸確定リスト',
            self::MONTHLY_STOCK_OVERVIEW => '月次受払在庫',
            self::STOCK_TRANSFER => '倉庫移動',
            self::EXTERNAL_DATA_IMPORT_EARNING => '外部連携売上',
            self::EXTERNAL_DATA_IMPORT_EARNING_COMMENT=>'売上コメントリスト',
        };
    }

    public function print_types(): array
    {
        return match ($this) {
            self::ORDER => [
                PrintType::ORDER,
                PrintType::ARRIVAL_PLAN,
            ],
            self::EARNING_DIRECT => [
                PrintType::CLIENT_SLIP,
                PrintType::EARNING_DIRECT_CHECK,
                PrintType::PURCHASE_DIRECT_ORDER,
            ],
            self::PURCHASE => [
                PrintType::PURCHASE_CHECK,
            ],
            self::SALE => [
                PrintType::CLIENT_SLIP,
                PrintType::EARNING_CHECK,
            ],
            self::DELIVERY => [
                PrintType::PICKING_LIST,
            ],
            self::ESTIMATE => [
                PrintType::ESTIMATE,
            ],
            self::CLOSING_DAILY => [
                PrintType::DAILY_BALANCE,
            ],
            self::CLOSING_MONTHLY => [
                PrintType::DEPOSIT_BALANCE,
                PrintType::PAYMENT_BALANCE,
                PrintType::ALCOHOL_SALES,
            ],
            self::CLOSING_PAYMENT_BILL => [
                PrintType::PAYMENT_PLAN,
                PrintType::PAYMENT_LEDGER,
            ],
            self::CLOSING_DEPOSIT_BILL => [
                PrintType::DEPOSIT_PLAN,
                PrintType::INVOICE,
                PrintType::INVOICE_FOR_PAYMENT_SLIP,
                PrintType::PAYMENT_SLIP
            ],
            self::CLOSING_REBATE => [
                PrintType::REBATE_INVOICE,
                PrintType::REBATE_DETAIL,
            ],
            self::DEPOSIT => [
                PrintType::DEPOSIT_CHECK,
            ],
            self::REBATE_DEPOSIT => [
                PrintType::REBATE_DEPOSIT_CHECK,
            ],
            self::PAYMENT => [
                PrintType::PAYMENT_CHECK,
            ],
            self::CONTAINER_PICKUP => [
                PrintType::CONTAINER_PICKUP_CHECK,
            ],
            self::CONTAINER_RETURN => [
                PrintType::CONTAINER_RETURN_CHECK,
            ],
            self::CONTAINER_DIRECT => [
                PrintType::CONTAINER_DIRECT_CHECK,
            ],
            self::REAL_STOCK => [
                PrintType::REAL_STOCK,
            ],
            self::INVENTORY => [
                PrintType::INVENTORY,
            ],
            self::INVENTORY_DIFF => [
                PrintType::INVENTORY_DIFF,
            ],
            self::INVENTORY_EXECUTE => [
                PrintType::INVENTORY_EXECUTE,
            ],
            self::MONTHLY_STOCK_OVERVIEW => [
                PrintType::MONTHLY_STOCK_OVERVIEW,
            ],
            self::STOCK_TRANSFER => [
                PrintType::STOCK_TRANSFER_CHECK,
            ],
            self::EXTERNAL_DATA_IMPORT_EARNING => [
              PrintType::EXTERNAL_DATA_IMPORT_EARNING_CHECK
            ],
            self::EXTERNAL_DATA_IMPORT_EARNING_COMMENT => [
                PrintType::EXTERNAL_DATA_IMPORT_EARNING_COMMENT_LIST
            ],
            default => []
        };
    }
}
