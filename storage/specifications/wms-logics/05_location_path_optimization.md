# 05. ロケーション動線最適化仕様書

了解しました。
以下が **`05_location_path_optimization.md`** の完全内容です。
既存の `locations` テーブルを拡張し、倉庫内ピッキング動線の最適化を行うための詳細仕様です。

---

````markdown
# 05. ロケーション動線最適化仕様書

## 🎯 目的
倉庫内のロケーション情報（`locations`）を拡張し、  
ピッカーの移動距離を最小化するために最適なピッキング順序を算出する。

---

## 1️⃣ 背景
販売管理システムには既に `locations` テーブルが存在し、  
棚番号・通路番号などの基本的なロケーション管理が行われている。  

しかし、WMSとして効率的にピッキング順序を提示するには、  
倉庫内の**ゾーン情報・動線順序・階層・座標**などを保持する必要がある。

---

## 2️⃣ 対象テーブル構成

### 既存: `locations`
| カラム | 内容 |
|--------|------|
| `warehouse_id` | 倉庫 |
| `code1` | 主ロケコード（通路番号など） |
| `code2`, `code3` | 補助ロケコード |
| `name` | ロケーション名（例: “1F-A-05”） |

---

### 新設: `wms_locations`
```sql
CREATE TABLE wms_locations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    location_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    zone_code VARCHAR(20) NULL COMMENT 'ゾーン（例: 常温、冷蔵、高頻度）',
    floor INT DEFAULT 1 COMMENT '階層（1F,2F等）',
    aisle INT NULL COMMENT '通路番号（縦方向）',
    rack INT NULL COMMENT '棚番号（横方向）',
    level INT NULL COMMENT '段（高さ）',
    x_position DECIMAL(8,2) NULL COMMENT '倉庫内X座標（m）',
    y_position DECIMAL(8,2) NULL COMMENT '倉庫内Y座標（m）',
    picking_priority INT DEFAULT 9999 COMMENT 'ゾーン内優先順序（数値が小さいほど先）',
    walking_order INT DEFAULT 9999 COMMENT '倉庫全体の動線順序',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
````

---

## 3️⃣ 動線最適化アルゴリズム概要

### 並び順の優先度

| 順位 | ソートキー               | 説明                         |
| -- | ------------------- | -------------------------- |
| ①  | `zone_code ASC`     | 温度帯・エリア別に分ける（常温 → 冷蔵 → 冷凍） |
| ②  | `walking_order ASC` | 倉庫全体の巡回順（マネージャ設定値）         |
| ③  | `earning_id ASC`    | 同一ロケの場合、伝票順（FIFO）          |
| ④  | `item_id ASC`       | 同一伝票・同一ロケ内での商品順            |

---

### 伝票またぎピッキングの取り扱い

* 複数伝票に同一商品が存在する場合、**同一ロケーションで伝票昇順**にピックする。
* 例:

    * 伝票 #1001, #1002 ともに「アサヒスーパードライ」
    * ロケーション順では

      ```
      1F-A-05 → アサヒ（伝票1001）
               → アサヒ（伝票1002）
      ```

---

## 4️⃣ ピッキングリスト生成ロジック

### SQL例

```sql
SELECT
  pr.item_id,
  pt.earning_id,
  l.name AS location_name,
  wl.zone_code,
  wl.walking_order,
  pr.planned_qty,
  pr.shortage_qty,
  pt.picker_id
FROM wms_picking_results pr
JOIN wms_picking_tasks pt ON pt.id = pr.picking_task_id
JOIN real_stocks rs ON rs.item_id = pr.item_id AND rs.warehouse_id = pt.warehouse_id
JOIN locations l ON l.id = rs.location_id
JOIN wms_locations wl ON wl.location_id = l.id
WHERE pt.wave_id = :wave_id
ORDER BY wl.zone_code, wl.walking_order, pt.earning_id;
```

---

## 5️⃣ ピッキング順序の決定例

| 順序 | ロケーション  | ゾーン | 通路 | 商品    | 伝票   | 数量 |
| -- | ------- | --- | -- | ----- | ---- | -- |
| 1  | 1F-A-01 | 常温  | 1  | アサヒ   | 1001 | 10 |
| 2  | 1F-A-01 | 常温  | 1  | アサヒ   | 1002 | 8  |
| 3  | 1F-A-02 | 常温  | 1  | キリン   | 1001 | 5  |
| 4  | 1F-B-05 | 常温  | 2  | サントリー | 1001 | 6  |

---

## 6️⃣ 初期動線順序設定

### 自動採番例

```sql
UPDATE wms_locations
SET walking_order = (aisle * 1000 + rack * 10 + level);
```

### 設定方針

* 倉庫マネージャがゾーン／通路単位で初期化。
* 一度設定すれば、再配置まで固定。
* UI（Filamentなど）からも更新可能。

---

## 7️⃣ 座標によるルート最適化（将来拡張）

| 機能             | 内容                                             |
| -------------- | ---------------------------------------------- |
| **座標計算**       | `x_position`, `y_position` によるTSP（巡回最短経路）計算。   |
| **ゾーン単位ルート**   | 温度帯別にルート分割（常温エリア→冷蔵エリア）                        |
| **ピッカー別ルート割当** | ピッカーIDごとに部分経路を割り当てて分担可能。                       |
| **アルゴリズム**     | Greedy／A*／Dijkstra／Genetic Algorithmなど柔軟に選択可能。 |

---

## 8️⃣ 再配分ピックへの適用

* `wms_picking_tasks.task_type='REALLOCATION'` も同じ `walking_order` を使用。
* 再配分ピックリストは波動とは別セクションとして表示されるが、
  倉庫全体では同一動線基準で統一。

---

## 9️⃣ テーブル運用ルール

| 項目   | 内容                                    |
| ---- | ------------------------------------- |
| 編集権限 | 倉庫管理者のみ                               |
| 更新頻度 | 倉庫レイアウト変更時                            |
| 一貫性  | `locations.id` と1対1で紐づく               |
| 保守性  | 変更履歴は `wms_op_logs` に記録（before/after） |

---

## 🔟 Android/Web出力形式（例）

```json
{
  "wave_id": 1007,
  "warehouse_id": 3,
  "picking_sequence": [
    {
      "zone": "常温",
      "location": "1F-A-05",
      "item_id": 110,
      "item_name": "アサヒスーパードライ 350ml",
      "earning_id": 501,
      "planned_qty": 10,
      "picking_order": 1
    },
    {
      "zone": "常温",
      "location": "1F-A-05",
      "item_id": 110,
      "earning_id": 502,
      "planned_qty": 8,
      "picking_order": 2
    }
  ]
}
```

---

## ✅ 備考

* 倉庫動線順序はピッキング生産性の根幹。定期的にレビュー推奨。
* 欠品再配分フェーズでも同ロジックで順序を維持することで一貫性を保つ。
* 将来的に倉庫内マップと連携して動的ナビゲーションを提供可能。

```

---

この内容をファイルとして  
`/mnt/data/wms_specifications_v1_2025-10-22_md/05_location_path_optimization.md`  
に保存しますか？
```
