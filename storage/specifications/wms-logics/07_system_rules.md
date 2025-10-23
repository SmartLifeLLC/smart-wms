
---

````markdown
# 07. 排他・冪等・非機能要件 仕様書

## 🎯 目的
WMS出荷システム全体に共通する **安全性・整合性・性能要件** を定義する。  
全フェーズ（波動生成〜出荷確定）を通じて、データ競合や再送による不整合を防ぎ、  
安定稼働を実現するためのルールをまとめる。

---

## 1️⃣ 排他制御（Concurrency Control）

### 目的
在庫データやピッキング実績の多重更新を防止し、  
同一レコードへの同時アクセス時に一貫性を保つ。

### 対応方針

| 対象 | 制御方法 | 備考 |
|------|------------|------|
| 在庫 (`real_stocks`, `wms_real_stocks`) | **悲観ロック**：`SELECT ... FOR UPDATE` | 出庫・拘束時に使用 |
| ピッキング結果 (`wms_picking_results`) | **楽観ロック**：`lock_version` チェック | 更新競合を防ぐ |
| 再配分確定 (`wms_reallocations`) | **トランザクション分離**：`REPEATABLE READ` | 状態遷移競合を防止 |
| 出荷確定 (`wms_shipments`) | **一括ロック制御** | 波動単位で完了時に排他確保 |

---

## 2️⃣ 冪等性（Idempotency）

### 目的
APIやバッチが再送・再実行された場合でも、同一結果を保証する。

### テーブル：`wms_idempotency_keys`
```sql
CREATE TABLE wms_idempotency_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64),
    key_hash CHAR(64),
    created_at TIMESTAMP NULL,
    UNIQUE KEY uniq_scope_key (scope, key_hash)
);
````

### 使用例

| 処理        | scope例                 | key_hash生成要素                               |
| --------- | ---------------------- | ------------------------------------------ |
| ピッキング実績登録 | `picking_result`       | task_id + item_id + picked_qty + user_id   |
| 再配分確定     | `reallocation_confirm` | reallocation_id + reservation_id + user_id |
| 出荷確定      | `shipment_confirm`     | wave_id + warehouse_id + timestamp(日単位)    |

---

## 3️⃣ トランザクション管理

| 項目     | 内容                                |
| ------ | --------------------------------- |
| 分離レベル  | `REPEATABLE READ`（MySQL InnoDB標準） |
| ロールバック | すべての書込操作はトランザクション内で完結             |
| 外部連携   | 出荷確定後の販売管理更新も1トランザクション内で処理        |

---

## 4️⃣ 監査ログ（Audit Log）

### テーブル：`wms_op_logs`

```sql
CREATE TABLE wms_op_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED,
    warehouse_id BIGINT UNSIGNED,
    model_name VARCHAR(64),
    action VARCHAR(64),
    before_json JSON,
    after_json JSON,
    idempotency_key VARCHAR(64),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### ログ出力対象イベント

| 区分         | アクション                                                               |
| ---------- | ------------------------------------------------------------------- |
| 波動生成       | `WAVE_CREATE`                                                       |
| ピッキング開始／完了 | `PICKING_START`, `PICKING_COMPLETE`                                 |
| 欠品報告       | `SHORTAGE_REPORTED`                                                 |
| 再配分        | `REALLOCATION_REQUEST`, `REALLOCATION_CONFIRM`, `REALLOCATION_FAIL` |
| 出荷確定       | `SHIPMENT_CONFIRMED`                                                |
| データ修正      | `MANUAL_UPDATE`                                                     |

---

## 5️⃣ エラー制御方針

| 区分          | ルール                                  |
| ----------- | ------------------------------------ |
| **整合性違反**   | 409 Conflict／412 Precondition Failed |
| **冪等重複**    | 200 OK（再実行結果を返却）                     |
| **在庫ロック失敗** | 503 Retry-After を返却                  |
| **状態不整合**   | 422 Unprocessable Entity（サービス層でガード）  |

---

## 6️⃣ 非機能要件（Performance & Reliability）

| 項目         | 要件                 |
| ---------- | ------------------ |
| 同時接続ユーザー   | 50（倉庫・本社合計）        |
| ピッキングリスト取得 | < 1秒（平均）           |
| 波動生成       | < 1分（1万伝票処理）       |
| 欠品確定〜反映    | < 3秒               |
| 出荷確定処理     | < 10秒（波動単位）        |
| データ保持      | 操作ログ90日以上／出荷履歴5年保存 |

---


---

## 8️⃣ セキュリティ

| 項目       | 内容                          |
| -------- | --------------------------- |
| 認証       | BoozeCoreのSSOを利用（APIトークン連携） |
| 権限       | 倉庫／コース単位のRBAC（ピッカー・管理者・営業）  |
| 通信       | HTTPS／API署名トークン             |
| データ改ざん防止 | 監査ログのハッシュ化保存（SHA256）        |

---

## 9️⃣ 運用監視

| 項目    | 内容                                                                       |
| ----- | ------------------------------------------------------------------------ |
| アプリ監視 | Laravel Horizon／Queueの失敗通知                                               |
| DB監視  | スロークエリ・ロック監視                                                             |
| 定期ジョブ | `wms:generate-waves`, `wms:reallocation-expire`, `wms:confirm-shipments` |
| 通知    | Slack／メールによる障害・完了通知                                                      |

---

## 🔟 保守・拡張ルール

| 区分      | 方針                              |
| ------- | ------------------------------- |
| スキーマ変更  | マイグレーション履歴を必ず残す（`migrations`）   |
| バージョン管理 | 仕様変更時は `README.md` のバージョン履歴を更新  |
| 外部連携    | API変更は `/v{version}/` でバージョン分離  |
| 開発環境    | Staging・Production 完全分離、データ同期なし |

---

## ✅ まとめ

本システムの信頼性を支える3つの柱：

1. **排他制御** — 同時アクセス時のデータ整合性保持
2. **冪等性** — 再送や再実行でも安全な一貫結果保証
3. **監査・ログ** — 誰が何をしたかを100%追跡可能

これらを組み合わせることで、倉庫現場・販売管理・システムの三者間で
安全・確実・再現可能な出荷プロセスを提供する。

