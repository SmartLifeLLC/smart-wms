# 01. 波動生成フェーズ仕様書

## 目的
販売管理システム（BoozeCore）の受注データ（`earnings`）を基点に、
倉庫・配送コース・ピッキング時間帯ごとに出荷波動（`wms_waves`）を自動生成する。

---

## 概要
波動とは、特定時間帯に出荷される伝票群をまとめて
ピッキング・納品処理を行うための出荷単位である。

- 対象：倉庫 × 配送コース × 出荷日 × ピッキング時間帯
- 生成タイミング：6:00、7:00、8:00 などの定時ジョブ
- 出荷伝票の親：`earnings`
- 波動データ：`wms_waves`

---

## 対象抽出条件

| 条件                                        | 説明            |
|-------------------------------------------|---------------|
| `earnings.delivered_date = today`         | 本日の出荷分        |
| `earnings.is_delivered = 0`               | 未出荷           |
| earnings.picking_status = 'BEFORE'        | ピッキング開始前      |
| `earnings.warehouse_id IS NOT NULL`       | 倉庫が指定されている    |
| `earnings.delivery_course_id IS NOT NULL` | 配送コースが指定されている |

---

## 波動単位の定義
| 項目 | 内容 |
|------|------|
| 倉庫 | `warehouse_id` |
| 配送コース | `delivery_course_id` |
| 出荷日 | `shipping_date` |
| ピッキング開始時刻 | `picking_start_time` |
| ピッキング締切時刻 | `picking_deadline_time` |

---

## テーブル構成：`wms_wave_settings`

```sql
create table wms_wave_settings (
    id bigint unsigned primary key auto_increment,
    warehouse_id bigint unsigned not null,
    delivery_course_id bigint unsigned not null,
    picking_start_time time null,
    picking_deadline_time time null, 
    created_at timestamp null,
    updated_at timestamp null,
    creator_id bigint unsigned not null,
    last_updater_id bigint unsigned not null
);
```

## テーブル構成：`wms_waves`

```sql
create table wms_waves (
    id bigint unsigned primary key auto_increment,
    wms_wave_setting_id bigint unsigned not null,
    wave_no varchar(40) not null comment 'W###-C###-YYYYMMDD-id',
    shipping_date date not null, #= earnings.delivered_date
    status enum('PENDING','PICKING','SHORTAGE','COMPLETED','CLOSED') default 'PENDING', 
    created_at timestamp null,
    updated_at timestamp null
);
```

### wave_no 形式
```
W{warehouse_code:03d}-C{course_code:03d}-{YYYYMMDD}-{wave_id}
```
例：`W003-C015-20251022-1007`

---

## 波動生成処理の流れ

1. `earnings` から対象伝票抽出  
2. 倉庫 × コース × 時間帯でグルーピング  
3. `wms_waves` レコード作成  
4. `wms_picking_tasks`（伝票単位）作成  
5. `wms_reservations`（引当）生成  
6. Android／Web で波動別ピッキングリスト表示

---

## 波動生成ジョブ（Laravel Command）

```bash
php artisan wms:generate-waves # wms_wave_settingsより該当するwaveを生成
```

### ジョブ仕様
| 処理 | 内容 |
|------|------|
| 起動時刻 | 06:00, 07:00, 08:00 など |
| 入力 | 日付・時間帯 |
| 出力 | `wms_waves` + `wms_picking_tasks` |
| 冪等性 | 同条件で再実行しても重複生成しない（UNIQUEキー制御） |

---

## ステータス遷移
| 状態 | 意味 |
|-------|------|
| `PENDING` | 波動生成直後 |
| `PICKING` | ピッキング進行中 |
| `SHORTAGE` | 欠品を含む |
| `COMPLETED` | 全伝票完了 |
| `CLOSED` | 出荷確定済み |

---

## 出力例
| wave_id | wave_no | warehouse_id | delivery_course_id | shipping_date | start_time | deadline |
|----------|----------|---------------|--------------------|----------------|-------------|-----------|
| 1007 | W003-C015-20251022-1007 | 3 | 15 | 2025-10-22 | 06:00 | 07:30 |

---

## 備考
- `wave_no` はデバッグ・帳票用途の表示キーであり、主キーは `id`。
- 1日複数波動（6:00, 7:00, 8:00…）を生成可能。
- `wms:generate-waves` は cron 定義で毎時実行可。
