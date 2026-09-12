<?php

namespace App\Enums;

enum EMenu: string
{
    // 入荷管理
    case INBOUND_DASHBOARD = 'inbound.dashboard';
    case WMS_ORDER_INCOMING_SCHEDULES = 'inbound.wms_order_incoming_schedules';
    case WMS_INCOMING_COMPLETED = 'inbound.wms_incoming_completed';
    case WMS_INCOMING_COMPLETED_SUMMARY = 'inbound.wms_incoming_completed_summary';
    case WMS_INCOMING_TRANSMITTED = 'inbound.wms_incoming_transmitted';
    case WMS_INCOMING_RECEIVED_DATA = 'inbound.wms_incoming_received_data';
    case WMS_INCOMING_IMPORT_LOGS = 'inbound.wms_incoming_import_logs';
    case WMS_INCOMING_IMPORT_ERRORS = 'inbound.wms_incoming_import_errors';
    case PURCHASES = 'inbound.purchases';
    case RECEIPT_INSPECTIONS = 'inbound.receipt_inspections';
    case WMS_INCOMING_APP_INSPECTIONS = 'inbound.wms_incoming_app_inspections';

    // 出荷管理
    case OUTBOUND_DASHBOARD = 'outbound.dashboard';
    case WMS_PICKER_ATTENDANCE = 'outbound.wms_picker_attendance';
    case WMS_PICKING_WAITINGS = 'outbound.wms_picking_waitings';
    case PICKING_TASKS = 'outbound.picking_tasks';
    case WAVES = 'outbound.waves';
    case PICKING_ROUTE_VISUALIZATION = 'outbound.picking_route_visualization';
    case WMS_PICKING_ITEM_RESULTS = 'outbound.wms_picking_item_results';
    case DELIVERY_COURSE_CHANGE = 'outbound.delivery_course_change';
    case SHIPMENT_INSPECTIONS = 'outbound.shipment_inspections';
    case WMS_SHIPMENT_SLIPS = 'outbound.wms_shipment_slips';

    // 欠品管理
    case WMS_SHORTAGES = 'shortage.wms_shortages';
    case WMS_SHORTAGES_WAITING_APPROVALS = 'shortage.wms_shortages_waiting_approvals';
    case WMS_SHORTAGES_APPROVED = 'shortage.wms_shortages_approved';

    // 倉庫移動
    case WMS_SHORTAGE_ALLOCATIONS = 'horizontal_shipment.wms_shortage_allocations';

    // 発注処理
    case WMS_AUTO_ORDER_JOBS = 'auto_order.wms_auto_order_jobs';
    case WMS_STOCK_TRANSFER_CANDIDATES = 'auto_order.wms_stock_transfer_candidates';
    case WMS_ORDER_REGISTRATION = 'auto_order.wms_order_registration';
    case WMS_ORDER_CANDIDATES = 'auto_order.wms_order_candidates';
    case WMS_ORDER_CONFIRMATION_WAITING = 'auto_order.wms_order_confirmation_waiting';

    // 発注履歴
    case WMS_ORDER_CONFIRMED = 'order_history.wms_order_confirmed';
    case WMS_ORDER_FOR_JX = 'order_history.wms_order_for_jx';
    case WMS_EOS_INCOMING_RECEIVE_SETTINGS = 'order_history.wms_eos_incoming_receive_settings';
    case WMS_STOCK_TRANSFER_CONFIRMED = 'order_history.wms_stock_transfer_confirmed';
    case WMS_ORDER_DATA_FILES = 'order_history.wms_order_data_files';
    case WMS_ORDER_DOCUMENTS = 'order_history.wms_order_documents';

    // 在庫管理
    case REAL_STOCKS = 'inventory.real_stocks';
    case EXPIRATION_ALERTS = 'inventory.expiration_alerts';
    case WMS_STOCK_SNAPSHOTS = 'inventory.wms_stock_snapshots';
    case WMS_STOCK_DIFFERENCE_ANALYSIS = 'inventory.wms_stock_difference_analysis';

    // マスタ管理
    case WAREHOUSES = 'master.warehouses';
    case WAREHOUSE_CONTRACTORS = 'master.warehouse_contractors';
    case CONTRACTORS = 'master.contractors';
    case ITEM_CONTRACTORS = 'master.item_contractors';
    case WMS_MONTHLY_SAFETY_STOCKS = 'master.wms_monthly_safety_stocks';
    case WMS_ITEM_SUPPLY_SETTINGS = 'master.wms_item_supply_settings';
    case WMS_WAREHOUSE_CALENDARS = 'master.wms_warehouse_calendars';
    case WMS_CONTRACTOR_HOLIDAYS = 'master.wms_contractor_holidays';
    case WMS_ORDER_JX_SETTINGS = 'master.wms_order_jx_settings';
    case WMS_CONTRACTOR_WAREHOUSE_SETTINGS = 'master.wms_contractor_warehouse_settings';
    case LOCATIONS = 'master.locations';
    case FLOORS = 'master.floors';
    case WMS_PICKING_AREAS = 'master.wms_picking_areas';
    case WMS_PICKERS = 'master.wms_pickers';
    case WMS_PICKING_ASSIGNMENT_STRATEGIES = 'master.wms_picking_assignment_strategies';
    case FLOOR_PLAN_EDITOR = 'master.floor_plan_editor';

    // 統計データ
    case EARNINGS = 'statistics.earnings';
    case WMS_DAILY_STATS_REPORT = 'statistics.wms_daily_stats_report';
    case STATS_DAILY_SALES = 'statistics.daily_sales';
    case STATS_SALES_SUMMARIES = 'statistics.sales_summaries';

    // ログ
    case WMS_AUTO_ORDER_EXECUTION_LOG = 'logs.auto_order_execution_log';
    case WMS_PICKING_LOGS = 'logs.wms_picking_logs';
    case WMS_JX_TRANSMISSION_LOGS = 'logs.wms_jx_transmission_logs';
    case WMS_JX_EOS_LINES = 'logs.wms_jx_eos_lines';
    case WMS_IMPORT_LOGS = 'logs.wms_import_logs';
    case WMS_QUEUE_JOBS = 'logs.wms_queue_jobs';
    case WMS_EXPORT_LOGS = 'logs.wms_export_logs';
    case WMS_JX_UNKNOWN_INCOMING_SLIPS = 'logs.wms_jx_unknown_incoming_slips';

    // システム設定
    case WAVE_SETTINGS = 'settings.wave_settings';
    case DELIVERY_COURSE_SWITCH_SETTINGS = 'settings.delivery_course_switch_settings';
    case CLIENT_PRINTER_COURSE_SETTINGS = 'settings.client_printer_course_settings';

    // テストデータ
    case TEST_DATA_GENERATOR = 'test_data.generator';
    case JX_TEST_DATA = 'test_data.jx_test_data';

    // 波動管理
    case WAVE_MANAGEMENT_ADJUST_LOT = 'wave_management.adjust_lot';
    case WAVE_MANAGEMENT_ADJUST_LOT_HISTORY = 'wave_management.adjust_lot_history';

    public function category(): EMenuCategory
    {
        return match ($this) {
            self::INBOUND_DASHBOARD,
            self::WMS_ORDER_INCOMING_SCHEDULES,
            self::WMS_INCOMING_COMPLETED,
            self::WMS_INCOMING_COMPLETED_SUMMARY,
            self::WMS_INCOMING_TRANSMITTED,
            self::WMS_INCOMING_RECEIVED_DATA,
            self::WMS_INCOMING_IMPORT_LOGS,
            self::WMS_INCOMING_IMPORT_ERRORS,
            self::PURCHASES,
            self::RECEIPT_INSPECTIONS,
            self::WMS_INCOMING_APP_INSPECTIONS => EMenuCategory::INBOUND,

            self::OUTBOUND_DASHBOARD,
            self::WMS_PICKING_WAITINGS,
            self::PICKING_TASKS,
            self::WAVES,
            self::PICKING_ROUTE_VISUALIZATION,
            self::WMS_PICKING_ITEM_RESULTS,
            self::DELIVERY_COURSE_CHANGE,
            self::SHIPMENT_INSPECTIONS,
            self::WMS_SHIPMENT_SLIPS => EMenuCategory::OUTBOUND,

            self::WMS_SHORTAGES,
            self::WMS_SHORTAGES_WAITING_APPROVALS,
            self::WMS_SHORTAGES_APPROVED => EMenuCategory::SHORTAGE,

            self::WMS_SHORTAGE_ALLOCATIONS => EMenuCategory::HORIZONTAL_SHIPMENT,

            self::WMS_AUTO_ORDER_JOBS,
            self::WMS_STOCK_TRANSFER_CANDIDATES,
            self::WMS_ORDER_REGISTRATION,
            self::WMS_ORDER_CANDIDATES,
            self::WMS_ORDER_CONFIRMATION_WAITING => EMenuCategory::AUTO_ORDER,

            self::WMS_ORDER_CONFIRMED,
            self::WMS_STOCK_TRANSFER_CONFIRMED,
            self::WMS_ORDER_DATA_FILES,
            self::WMS_ORDER_DOCUMENTS,
            self::WMS_JX_TRANSMISSION_LOGS,
            self::WMS_JX_UNKNOWN_INCOMING_SLIPS => EMenuCategory::ORDER_HISTORY,

            self::WMS_ORDER_FOR_JX,
            self::WMS_EOS_INCOMING_RECEIVE_SETTINGS => EMenuCategory::ORDER_TRANSMISSION,

            self::REAL_STOCKS,
            self::EXPIRATION_ALERTS,
            self::WMS_STOCK_SNAPSHOTS,
            self::WMS_STOCK_DIFFERENCE_ANALYSIS => EMenuCategory::INVENTORY,

            // 倉庫マスタ
            self::WAREHOUSES,
            self::WMS_WAREHOUSE_CALENDARS,
            self::FLOORS,
            self::LOCATIONS,
            self::FLOOR_PLAN_EDITOR,
            self::WMS_PICKING_AREAS => EMenuCategory::MASTER_WAREHOUSE,

            // 発注マスタ
            self::WAREHOUSE_CONTRACTORS,
            self::CONTRACTORS,
            self::ITEM_CONTRACTORS,
            self::WMS_MONTHLY_SAFETY_STOCKS,
            self::WMS_ITEM_SUPPLY_SETTINGS,
            self::WMS_CONTRACTOR_HOLIDAYS,
            self::WMS_ORDER_JX_SETTINGS,
            self::WMS_CONTRACTOR_WAREHOUSE_SETTINGS => EMenuCategory::MASTER_ORDER,

            // ピッキングマスタ
            self::WMS_PICKERS,
            self::WMS_PICKER_ATTENDANCE,
            self::WMS_PICKING_ASSIGNMENT_STRATEGIES => EMenuCategory::MASTER_PICKING,

            self::EARNINGS,
            self::WMS_DAILY_STATS_REPORT,
            self::STATS_DAILY_SALES,
            self::STATS_SALES_SUMMARIES => EMenuCategory::STATISTICS,

            self::WMS_AUTO_ORDER_EXECUTION_LOG,
            self::WMS_PICKING_LOGS,
            self::WMS_JX_EOS_LINES,
            self::WMS_IMPORT_LOGS,
            self::WMS_QUEUE_JOBS,
            self::WMS_EXPORT_LOGS => EMenuCategory::LOGS,

            self::WAVE_SETTINGS,
            self::DELIVERY_COURSE_SWITCH_SETTINGS,
            self::CLIENT_PRINTER_COURSE_SETTINGS => EMenuCategory::SETTINGS,

            self::TEST_DATA_GENERATOR => EMenuCategory::TEST_DATA,
            self::JX_TEST_DATA => EMenuCategory::TEST_DATA,

            self::WAVE_MANAGEMENT_ADJUST_LOT,
            self::WAVE_MANAGEMENT_ADJUST_LOT_HISTORY => EMenuCategory::WAVE_MANAGEMENT,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::INBOUND_DASHBOARD => '入荷ダッシュボード',
            self::WMS_ORDER_INCOMING_SCHEDULES => '入荷予定',
            self::WMS_INCOMING_COMPLETED => '入荷完了',
            self::WMS_INCOMING_COMPLETED_SUMMARY => '入荷完了サマリー',
            self::WMS_INCOMING_TRANSMITTED => '仕入連携済み',
            self::WMS_INCOMING_RECEIVED_DATA => '入荷データ受信',
            self::WMS_INCOMING_IMPORT_LOGS => '取込ログ',
            self::WMS_INCOMING_IMPORT_ERRORS => '取込エラー',
            self::PURCHASES => '発注データ',
            self::RECEIPT_INSPECTIONS => '入荷検品',
            self::WMS_INCOMING_APP_INSPECTIONS => 'アプリ入荷検品履歴',

            self::OUTBOUND_DASHBOARD => '出荷ダッシュボード',
            self::WMS_PICKER_ATTENDANCE => 'ピッカー勤怠管理',
            self::WMS_PICKING_WAITINGS => 'ピッキング待機',
            self::PICKING_TASKS => 'ピッキングタスク',
            self::WAVES => '出荷波動管理',
            self::PICKING_ROUTE_VISUALIZATION => 'ピッキング経路確認',
            self::WMS_PICKING_ITEM_RESULTS => 'ピッキング商品リスト',
            self::DELIVERY_COURSE_CHANGE => '配送コース変更',
            self::SHIPMENT_INSPECTIONS => '出荷検品',
            self::WMS_SHIPMENT_SLIPS => '出荷伝票',

            self::WMS_SHORTAGES => '欠品一覧',
            self::WMS_SHORTAGES_WAITING_APPROVALS => '承認待ち欠品',
            self::WMS_SHORTAGES_APPROVED => '欠品承認済み',

            self::WMS_SHORTAGE_ALLOCATIONS => '横持ち出荷依頼',

            self::WMS_AUTO_ORDER_EXECUTION_LOG => '自動発注実行ログ',
            self::WMS_STOCK_TRANSFER_CANDIDATES => '物流発注(店間）',
            self::WMS_ORDER_REGISTRATION => '（新）外部発注',
            self::WMS_ORDER_CANDIDATES => '外部発注',
            self::WMS_ORDER_CONFIRMATION_WAITING => '発注確定待ち',
            self::WMS_ORDER_CONFIRMED => '発注確定済み',
            self::WMS_ORDER_FOR_JX => 'JX発注データ作成',
            self::WMS_EOS_INCOMING_RECEIVE_SETTINGS => 'EOSデータ受信設定',
            self::WMS_STOCK_TRANSFER_CONFIRMED => '移動確定済み',
            self::WMS_ORDER_DATA_FILES => '発注データファイル',
            self::WMS_AUTO_ORDER_JOBS => '発注・移動候補生成',
            self::WMS_ORDER_DOCUMENTS => 'JX送信ファイル',

            self::REAL_STOCKS => '在庫管理',
            self::EXPIRATION_ALERTS => '賞味期限管理',
            self::WMS_STOCK_SNAPSHOTS => '在庫スナップショット',
            self::WMS_STOCK_DIFFERENCE_ANALYSIS => '在庫差異分析',

            self::WAREHOUSES => '倉庫',
            self::WAREHOUSE_CONTRACTORS => '発注先別ロット条件',
            self::CONTRACTORS => '発注先',
            self::ITEM_CONTRACTORS => '商品発注管理',
            self::WMS_MONTHLY_SAFETY_STOCKS => '月別発注点',
            self::WMS_ITEM_SUPPLY_SETTINGS => '供給設定',
            self::WMS_WAREHOUSE_CALENDARS => '倉庫カレンダー',
            self::WMS_CONTRACTOR_HOLIDAYS => '発注先休日',
            self::WMS_ORDER_JX_SETTINGS => 'JX接続設定',
            self::WMS_CONTRACTOR_WAREHOUSE_SETTINGS => '発注先指定倉庫コード',
            self::LOCATIONS => '倉庫ロケーション',
            self::FLOORS => '倉庫フロア',
            self::WMS_PICKING_AREAS => 'ピッキングエリア',
            self::WMS_PICKERS => 'ピッカー管理',
            self::WMS_PICKING_ASSIGNMENT_STRATEGIES => 'ピッキング割当戦略',
            self::FLOOR_PLAN_EDITOR => '倉庫レイアウト',

            self::EARNINGS => '売上データ',
            self::WMS_DAILY_STATS_REPORT => 'WMS日次出荷統計',
            self::STATS_DAILY_SALES => '日別商品出荷データ',
            self::STATS_SALES_SUMMARIES => '商品別出荷サマリ',

            self::WMS_PICKING_LOGS => 'ピッキングログ',
            self::WMS_JX_TRANSMISSION_LOGS => 'JX受信履歴',
            self::WMS_JX_EOS_LINES => 'EOS受信明細',
            self::WMS_JX_UNKNOWN_INCOMING_SLIPS => '伝票番号不明',
            self::WMS_IMPORT_LOGS => 'インポート履歴',
            self::WMS_QUEUE_JOBS => 'Queueジョブ',
            self::WMS_EXPORT_LOGS => 'ダウンロードログ',

            self::WAVE_SETTINGS => '波動設定',
            self::DELIVERY_COURSE_SWITCH_SETTINGS => '配送コース切替設定',

            self::CLIENT_PRINTER_COURSE_SETTINGS => 'プリンター設定',

            self::TEST_DATA_GENERATOR => 'テストデータ生成',
            self::JX_TEST_DATA => 'JXテストデータ',

            self::WAVE_MANAGEMENT_ADJUST_LOT => 'ロット調節',
            self::WAVE_MANAGEMENT_ADJUST_LOT_HISTORY => 'ロット調節履歴',
        };
    }

    public function icon(): ?string
    {
        return match ($this) {
            self::INBOUND_DASHBOARD => 'heroicon-o-presentation-chart-line',
            self::WMS_ORDER_INCOMING_SCHEDULES => 'heroicon-o-inbox-arrow-down',
            self::WMS_INCOMING_COMPLETED => 'heroicon-o-check-circle',
            self::WMS_INCOMING_COMPLETED_SUMMARY => 'heroicon-o-list-bullet',
            self::WMS_INCOMING_TRANSMITTED => 'heroicon-o-cloud-arrow-up',
            self::WMS_INCOMING_RECEIVED_DATA => 'heroicon-o-arrow-down-tray',
            self::WMS_INCOMING_IMPORT_LOGS => 'heroicon-o-document-text',
            self::WMS_INCOMING_IMPORT_ERRORS => 'heroicon-o-exclamation-circle',
            self::PURCHASES => 'heroicon-o-shopping-cart',
            self::RECEIPT_INSPECTIONS => 'heroicon-o-clipboard-document-check',
            self::WMS_INCOMING_APP_INSPECTIONS => 'heroicon-o-clipboard-document-check',

            self::OUTBOUND_DASHBOARD => 'heroicon-o-presentation-chart-bar',
            self::WMS_PICKER_ATTENDANCE => 'heroicon-o-calendar-days',
            self::WMS_PICKING_WAITINGS => 'heroicon-o-clock',
            self::PICKING_TASKS => 'heroicon-o-clipboard-document-list',
            self::WAVES => 'heroicon-o-queue-list',
            self::PICKING_ROUTE_VISUALIZATION => 'heroicon-o-map',
            self::WMS_PICKING_ITEM_RESULTS => 'heroicon-o-list-bullet',
            self::DELIVERY_COURSE_CHANGE => 'heroicon-o-arrow-path',
            self::SHIPMENT_INSPECTIONS => 'heroicon-o-check-circle',
            self::WMS_SHIPMENT_SLIPS => 'heroicon-o-document-text',

            self::WMS_SHORTAGES => 'heroicon-o-exclamation-triangle',
            self::WMS_SHORTAGES_WAITING_APPROVALS => 'heroicon-o-hand-raised',
            self::WMS_SHORTAGES_APPROVED => 'heroicon-o-check-badge',

            self::WMS_SHORTAGE_ALLOCATIONS => 'heroicon-o-truck',

            self::WMS_AUTO_ORDER_EXECUTION_LOG => 'heroicon-o-clipboard-document-check',
            self::WMS_STOCK_TRANSFER_CANDIDATES => 'heroicon-o-arrows-right-left',
            self::WMS_ORDER_REGISTRATION => 'heroicon-o-document-plus',
            self::WMS_ORDER_CANDIDATES => 'heroicon-o-shopping-cart',
            self::WMS_ORDER_CONFIRMATION_WAITING => 'heroicon-o-clipboard-document-check',
            self::WMS_ORDER_CONFIRMED => 'heroicon-o-check-badge',
            self::WMS_ORDER_FOR_JX => 'heroicon-o-document-arrow-up',
            self::WMS_EOS_INCOMING_RECEIVE_SETTINGS => 'heroicon-o-clock',
            self::WMS_STOCK_TRANSFER_CONFIRMED => 'heroicon-o-arrows-right-left',
            self::WMS_ORDER_DATA_FILES => 'heroicon-o-document-text',
            self::WMS_AUTO_ORDER_JOBS => 'heroicon-o-queue-list',
            self::WMS_ORDER_DOCUMENTS => 'heroicon-o-document-arrow-down',

            self::REAL_STOCKS => 'heroicon-o-cube-transparent',
            self::EXPIRATION_ALERTS => 'heroicon-o-clock',
            self::WMS_STOCK_SNAPSHOTS => 'heroicon-o-circle-stack',
            self::WMS_STOCK_DIFFERENCE_ANALYSIS => 'heroicon-o-chart-bar-square',

            self::WAREHOUSES => 'heroicon-o-building-office-2',
            self::WAREHOUSE_CONTRACTORS => 'heroicon-o-building-storefront',
            self::CONTRACTORS => 'heroicon-o-truck',
            self::ITEM_CONTRACTORS => 'heroicon-o-document-text',
            self::WMS_MONTHLY_SAFETY_STOCKS => 'heroicon-o-calendar-days',
            self::WMS_ITEM_SUPPLY_SETTINGS => 'heroicon-o-cog-6-tooth',
            self::WMS_WAREHOUSE_CALENDARS => 'heroicon-o-calendar-days',
            self::WMS_CONTRACTOR_HOLIDAYS => 'heroicon-o-calendar',
            self::WMS_ORDER_JX_SETTINGS => 'heroicon-o-server',
            self::WMS_CONTRACTOR_WAREHOUSE_SETTINGS => 'heroicon-o-identification',
            self::LOCATIONS => 'heroicon-o-map-pin',
            self::FLOORS => 'heroicon-o-rectangle-stack',
            self::WMS_PICKING_AREAS => 'heroicon-o-squares-plus',
            self::WMS_PICKERS => 'heroicon-o-user-group',
            self::WMS_PICKING_ASSIGNMENT_STRATEGIES => 'heroicon-o-adjustments-horizontal',
            self::FLOOR_PLAN_EDITOR => 'heroicon-o-map',

            self::EARNINGS => 'heroicon-o-currency-yen',
            self::WMS_DAILY_STATS_REPORT => 'heroicon-o-chart-bar-square',
            self::STATS_DAILY_SALES => 'heroicon-o-calendar-days',
            self::STATS_SALES_SUMMARIES => 'heroicon-o-chart-bar-square',

            self::WMS_PICKING_LOGS => 'heroicon-o-rectangle-stack',
            self::WMS_JX_TRANSMISSION_LOGS => 'heroicon-o-arrow-down-tray',
            self::WMS_JX_EOS_LINES => 'heroicon-o-list-bullet',
            self::WMS_JX_UNKNOWN_INCOMING_SLIPS => 'heroicon-o-exclamation-triangle',
            self::WMS_IMPORT_LOGS => 'heroicon-o-arrow-up-tray',
            self::WMS_QUEUE_JOBS => 'heroicon-o-queue-list',
            self::WMS_EXPORT_LOGS => 'heroicon-o-arrow-down-tray',

            self::WAVE_SETTINGS => 'heroicon-o-cog-6-tooth',
            self::DELIVERY_COURSE_SWITCH_SETTINGS => 'heroicon-o-arrow-path-rounded-square',
            self::CLIENT_PRINTER_COURSE_SETTINGS => 'heroicon-o-printer',

            self::TEST_DATA_GENERATOR => 'heroicon-o-beaker',
            self::JX_TEST_DATA => 'heroicon-o-server',

            self::WAVE_MANAGEMENT_ADJUST_LOT => 'heroicon-o-adjustments-horizontal',
            self::WAVE_MANAGEMENT_ADJUST_LOT_HISTORY => 'heroicon-o-clock',
        };
    }

    public function sort(): int
    {
        return match ($this) {
            // 入荷管理
            self::INBOUND_DASHBOARD => 1,
            self::WMS_ORDER_INCOMING_SCHEDULES => 2,
            self::WMS_INCOMING_COMPLETED => 3,
            self::WMS_INCOMING_COMPLETED_SUMMARY => 4,
            self::WMS_INCOMING_TRANSMITTED => 5,
            self::WMS_INCOMING_RECEIVED_DATA => 6,
            self::WMS_INCOMING_IMPORT_LOGS => 7,
            self::WMS_INCOMING_IMPORT_ERRORS => 8,
            self::PURCHASES => 9,
            self::RECEIPT_INSPECTIONS => 10,
            self::WMS_INCOMING_APP_INSPECTIONS => 11,

            // 出荷管理
            self::WAVES => 0,
            self::DELIVERY_COURSE_CHANGE => 1,
            self::WMS_PICKING_WAITINGS => 3,
            self::PICKING_ROUTE_VISUALIZATION => 4,
            self::PICKING_TASKS => 5,
            self::WMS_PICKING_ITEM_RESULTS => 7,
            self::SHIPMENT_INSPECTIONS => 8,
            self::WMS_SHIPMENT_SLIPS => 10,
            self::OUTBOUND_DASHBOARD => 11,

            // 欠品管理
            self::WMS_SHORTAGES => 1,
            self::WMS_SHORTAGES_WAITING_APPROVALS => 2,
            self::WMS_SHORTAGES_APPROVED => 3,

            // 倉庫移動
            self::WMS_SHORTAGE_ALLOCATIONS => 1,

            // 発注処理
            self::WMS_AUTO_ORDER_JOBS => 0,
            self::WMS_STOCK_TRANSFER_CANDIDATES => 1,
            self::WMS_ORDER_REGISTRATION => 2,
            self::WMS_ORDER_CANDIDATES => 3,
            self::WMS_ORDER_CONFIRMATION_WAITING => 4,

            // 発注履歴
            self::WMS_ORDER_CONFIRMED => 1,
            self::WMS_STOCK_TRANSFER_CONFIRMED => 2,
            self::WMS_ORDER_DATA_FILES => 3,
            self::WMS_ORDER_DOCUMENTS => 4,
            self::WMS_JX_TRANSMISSION_LOGS => 5,
            self::WMS_JX_UNKNOWN_INCOMING_SLIPS => 6,

            // 発注送信管理
            self::WMS_ORDER_FOR_JX => 1,
            self::WMS_EOS_INCOMING_RECEIVE_SETTINGS => 2,

            // 在庫管理
            self::REAL_STOCKS => 1,
            self::EXPIRATION_ALERTS => 2,
            self::WMS_STOCK_SNAPSHOTS => 3,
            self::WMS_STOCK_DIFFERENCE_ANALYSIS => 4,

            // 倉庫マスタ
            self::WAREHOUSES => 1,
            self::WMS_WAREHOUSE_CALENDARS => 2,
            self::FLOORS => 3,
            self::LOCATIONS => 4,
            self::FLOOR_PLAN_EDITOR => 5,
            self::WMS_PICKING_AREAS => 6,

            // 発注マスタ
            self::WAREHOUSE_CONTRACTORS => 1,
            self::CONTRACTORS => 2,
            self::ITEM_CONTRACTORS => 3,
            self::WMS_MONTHLY_SAFETY_STOCKS => 4,
            self::WMS_ITEM_SUPPLY_SETTINGS => 5,
            self::WMS_CONTRACTOR_HOLIDAYS => 6,
            self::WMS_ORDER_JX_SETTINGS => 7,
            self::WMS_CONTRACTOR_WAREHOUSE_SETTINGS => 8,

            // ピッキングマスタ
            self::WMS_PICKERS => 1,
            self::WMS_PICKER_ATTENDANCE => 2,
            self::WMS_PICKING_ASSIGNMENT_STRATEGIES => 3,

            // 統計データ
            self::EARNINGS => 1,
            self::WMS_DAILY_STATS_REPORT => 2,
            self::STATS_DAILY_SALES => 3,
            self::STATS_SALES_SUMMARIES => 4,

            // ログ
            self::WMS_AUTO_ORDER_EXECUTION_LOG => 1,
            self::WMS_PICKING_LOGS => 2,
            self::WMS_JX_EOS_LINES => 4,
            self::WMS_IMPORT_LOGS => 5,
            self::WMS_QUEUE_JOBS => 6,
            self::WMS_EXPORT_LOGS => 7,

            // システム設定
            self::WAVE_SETTINGS => 1,
            self::DELIVERY_COURSE_SWITCH_SETTINGS => 2,
            self::CLIENT_PRINTER_COURSE_SETTINGS => 3,

            // テストデータ
            self::TEST_DATA_GENERATOR => 1,
            self::JX_TEST_DATA => 2,

            self::WAVE_MANAGEMENT_ADJUST_LOT => 1,
            self::WAVE_MANAGEMENT_ADJUST_LOT_HISTORY => 2,
        };
    }
}
