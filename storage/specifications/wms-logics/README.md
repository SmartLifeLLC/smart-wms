# WMS 出荷システム 仕様書 概要

## 📘 目的
本ドキュメント群は、販売管理システム（BoozeCore）と同一DB上で動作する
**倉庫管理（WMS）出荷機能**の全体仕様をまとめたものです。

システム全体の流れは次の通りです：

```
販売管理システム (BoozeCore)
   ├─ trades / trade_items（受注明細）
   ├─ earnings（出荷伝票）
   └─ locations（ロケーションマスタ）

WMS（倉庫管理層）
   ├─ 波動管理（wms_waves）
   ├─ ピッキング／在庫拘束（wms_picking_tasks, wms_reservations）
   ├─ 欠品／再配分（wms_reallocations）
   ├─ 出荷確定（wms_shipments）
   ├─ 倉庫ロケーション拡張（wms_locations）
   └─ 監査・冪等制御（wms_op_logs, wms_idempotency_keys）
```

---

## 📦 ドキュメント構成

| ファイル名 | 内容概要 |
|-------------|-----------|
| **01_wave_generation.md** | 波動生成フェーズ：受注伝票を倉庫・コース・時間帯別にまとめ、`wms_waves` を作成。 |
| **02_picking.md** | ピッキング・在庫拘束フェーズ：伝票単位のタスクと在庫拘束の処理。 |
| **03_shortage_reallocation.md** | 欠品・再配分フェーズ：こうない欠品報告、他倉庫再配分、欠品確定までのフロー。 |
| **04_shipment_confirmation.md** | 出荷確定フェーズ：納品伝票出力、在庫差引、売上確定処理。 |
| **05_location_path_optimization.md** | ロケーション動線最適化フェーズ：倉庫内移動を最短化するための `wms_locations` 設計。 |
| **06_db_schema_summary.md** | DBスキーマ定義サマリ。全テーブル関係を整理。 |
| **07_system_rules.md** | 排他・冪等・監査・非機能要件。安全で安定した運用設計。 |

---

## 🚀 システムの流れ（概要）

1. **波動生成（Wave Generation）**
   - 倉庫 × 配送コース × 時間帯単位で波動を自動生成。
   - `wms_waves` に出荷単位を登録。

2. **ピッキング・在庫拘束（Picking & Reservation）**
   - 各伝票（`earnings`）に `wms_picking_tasks` を作成。
   - 商品ごとに在庫を `wms_reservations` に拘束。

3. **欠品処理（Shortage）**
   - ピッカーが欠品を報告 (`shortage_qty > 0`)。
   - 欠品ボードに自動反映。

4. **再配分（Reallocation）**
   - 他倉庫に在庫がある場合は仮引当 → 本引当。
   - 再配分タスク（`task_type='REALLOCATION'`）を生成しピック。

5. **ロケーション最適化（Location Path Optimization）**
   - 既存 `locations` に対し `wms_locations` を拡張。
   - ピッキングリストは `(zone_code, walking_order, earning_id)` で最短順序に並べる。

6. **出荷確定（Shipment Confirmation）**
   - 全伝票完了後、`wms_shipments` を登録。
   - `real_stocks` 減算、`earnings.is_delivered=1` 更新。

---

## ⚙️ DBテーブル関係（概要）

```
earnings ─┬─< wms_picking_tasks >─< wms_picking_results
           │
           ├─< wms_reservations
           │
           ├─< wms_shipments
           │
           └─< wms_reallocations

locations ──< wms_locations
real_stocks ──< wms_real_stocks
```

---

## 🔒 運用上の基本ルール

| 項目 | 方針 |
|------|------|
| 排他制御 | `SELECT FOR UPDATE` + `lock_version` による楽観ロック |
| 冪等制御 | `wms_idempotency_keys` に scope/key_hash を保持 |
| 欠品処理 | 再配分中は欠品印字せず、欠品確定のみ販売管理側印字 |
| 操作監査 | `wms_op_logs` に before/after JSON を記録 |
| 性能目標 | ピッキングリスト取得 < 1秒、波動生成 < 1分 |

---

## 📅 スケジュールジョブ

| ジョブ | 概要 | 実行間隔 |
|--------|------|----------|
| `wms:generate-waves` | 波動自動生成 | 6:00, 7:00, 8:00 |
| `wms:reallocation-expire` | 再配分仮引当の期限解除 | 毎分 |
| `wms:attach-reallocation-to-wave` | 再配分伝票を次便波動に自動割当 | 毎10分 |

---

## 🧭 拡張予定
- 座標ベースの自動ルート最適化（AI経路計算）
- トート／ゾーンピッキング対応
- 出荷KPI／生産性ダッシュボード
- 配送アプリ・積込管理連携

---

## 📄 バージョン情報
- バージョン: v1
- 発行日: 2025-10-22
- 作成者: Smart Life 開発部

---

## 🔗 関連ドキュメントリンク

- [01_wave_generation.md](01_wave_generation.md)
- [02_picking.md](02_picking.md)
- [03_shortage_reallocation.md](03_shortage_reallocation.md)
- [04_shipment_confirmation.md](04_shipment_confirmation.md)
- [05_location_path_optimization.md](05_location_path_optimization.md)
- [06_db_schema_summary.md](06_db_schema_summary.md)
- [07_system_rules.md](07_system_rules.md)
