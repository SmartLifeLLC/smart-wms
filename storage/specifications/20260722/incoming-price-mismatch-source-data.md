# EOS入荷 単価相違リスト用原本データ保存仕様

作成日: 2026-07-22

## 目的

EOS(JX)受信データを入荷予定へ照合・適用するとき、単価相違リスト作成に必要な原本データをWMS側に保存する。

単価相違リストの表示、日次確認、Excel相当の判定ロジック、担当者向け画面は `sakemaru-ai-core` 側で実装する。WMS側の責務は、後から判定ロジックが変わっても再計算できるように、送信・受信・入荷予定・マスタ由来の単価情報を重複なく保存するところまでとする。

## 参考Excel

- [★202604～最新 (1).xls](</Users/jungsinyu/Downloads/★202604～最新 (1).xls>)
- [★要修正リスト.xls](</Users/jungsinyu/Downloads/★要修正リスト.xls>)

`★要修正リスト.xls` は、3つ目のシート `クエリ★修正済` を仕入異常チェックの参考データとして扱う。

## 担当者運用の整理

現在のExcel運用では、ある期間の仕入データと、その時点のマスタ情報を比較し、単価の相違を調査している。

調査の目的は次の切り分けである。

- 実際の仕入入力での仕入単価が誤っている
- マスタ上の仕入単価が新価格に更新されていない
- 入数、ケース単価、仕入調整額などの考慮不足
- 仕入先から受信した数量・単価・金額の組み合わせ自体に異常がある

佐藤作成のクエリで相違のある仕入を抽出し、その後Excel上で `入数考慮` と `仕入調整考慮` を関数で確認している。

## 用語

| 用語 | 意味 | WMSで保存する主な項目 |
| --- | --- | --- |
| 送信単価 | WMSからJX送信したDレコード上の単価 | `sent_unit_price_raw`, `sent_unit_price`, `sent_payload` |
| 受信単価 | 仕入先からEOS(JX)で返ってきたDレコード上の単価 | `received_unit_price_raw`, `received_unit_price`, `received_payload`, `eos_payload` |
| マスタ単価 | 入荷予定に保持されている自社側の単価。照合・適用時点のマスタ由来単価として扱う | `master_unit_price`, `master_case_price`, `schedule_payload` |
| 実際の仕入先別単価 | 仕入先・商品・入荷予定に紐づく単価確認の対象 | `contractor_id`, `supplier_id`, `item_id`, `master_*`, `sent_*`, `received_*` |
| 仕入単価 | Excel上で比較対象にしている実仕入側の単価。ai-core側では受信単価、金額、数量、入数、調整額から再計算する | `received_unit_price`, `received_amount`, `received_*_quantity` |
| 単価の差 | 仕入単価とマスタ単価の差額 | `comparison_price_diff`, `calculation_payload` |
| 仕入異常チェック | 数量、単価、金額の整合が取れない受信データの検出 | `received_payload`, `eos_payload`, `received_amount`, `received_*_quantity` |

## Excel上の判定観点

### 単価の差

通常比較では次の差額を確認する。

```text
仕入単価 - マスタ単価 = 単価の差
```

### 入数考慮

仕入数量がケースで入っている場合、仕入単価にはケース単価が表示されることがある。その場合はバラ単価へ直して比較する。

```text
入数 × ケース数量 = 総バラ数量
金額 ÷ 総バラ数量 = 仕入単価
仕入単価 - マスタ単価 = 差額
```

### 仕入調整考慮

P箱代などの仕入調整額が金額に含まれる商品は、先に調整額分を除外してからバラ単価を算出する。

```text
金額 - (仕入調整額 × ケース数量) = 仕入調整額考慮済の金額
入数 × ケース数量 = 総バラ数量
仕入調整額考慮済の金額 ÷ 総バラ数量 = 仕入単価
仕入単価 - マスタ単価 = 差額
```

### 絞り込み順

Excel運用では、次の順に差額が1未満のものを除外している。

1. 単価の差
2. 入数考慮
3. 仕入調整考慮

`差異額` は、マスタ単価で計算した金額と仕入入力での金額の差額だが、現時点では必須項目としない。必要性は佐藤さんとの確認後に確定する。

## 仕入異常チェック

カナカン、まれに国分から、数量・単価・金額が一致しないEOSデータが来ることがある。旧システムではそのまま自動仕入入力されていたため、異常検出が必要だった。

新システムでは数量に応じた単価・金額へ補正された状態で自動仕入入力されている可能性があるため、WMS側では原本の数量・単価・金額を保存し、ai-core側で実データを見ながら検出要否を判断する。

## WMS側の保存タイミング

保存はEOS(JX)受信データが入荷予定へ照合・適用されたタイミングで行う。

対象処理:

- EOS受信取り込み
- 入荷予定との照合
- 入荷予定への適用
- 既存の入荷確定処理で、JX受信明細が既に紐づいている入荷予定を更新した場合

実装上は `IncomingReceiveService` から `IncomingPriceCheckSourceRecorder` を呼び出して保存する。

## 保存テーブル

新規テーブル: `wms_incoming_price_check_sources`

実装ファイル:

- `/Users/jungsinyu/Projects/sakemaru-wms/database/migrations/2026_07_22_000000_create_wms_incoming_price_check_sources_table.php`
- `/Users/jungsinyu/Projects/sakemaru-wms/app/Models/WmsIncomingPriceCheckSource.php`
- `/Users/jungsinyu/Projects/sakemaru-wms/app/Services/AutoOrder/IncomingPriceCheckSourceRecorder.php`
- `/Users/jungsinyu/Projects/sakemaru-wms/app/Services/AutoOrder/IncomingReceiveService.php`
- `/Users/jungsinyu/Projects/sakemaru-wms/app/Services/AutoOrder/IncomingConfirmationService.php`

## 保存単位

保存単位は、EOS受信明細1行と入荷予定1件の組み合わせで1件とする。

同じEOSファイルを再処理した場合でも、同一明細について複数行を作らない。`source_key` を一意キーにして `upsert` する。

## 重複防止

`source_key` は次の情報から生成する。

- 受信ファイルの `raw_sha256`
- `received_message_id`
- 上記がない場合は `received_file_id`
- EOS行の `raw_record_hash`
- EOS行が取れない場合は伝票番号、行番号、JAN、商品コード、数量、単価、金額

これにより、同じ受信原本の同じ明細を複数回照合・適用しても、単価相違リスト用の原本データは1件に集約される。

## 主な保存項目

| 分類 | 主な項目 | 用途 |
| --- | --- | --- |
| 原本識別 | `source_type`, `source_schema_version`, `source_key`, `source_document_key`, `source_line_key` | ai-core側の再処理、重複防止、将来のスキーマ判定 |
| 受信リンク | `received_file_id`, `received_slip_id`, `received_detail_id` | EOS受信ファイル、伝票、明細への追跡 |
| 入荷予定リンク | `incoming_schedule_id`, `order_candidate_id` | 入荷予定、発注候補との対応確認 |
| JX送信リンク | `wms_order_jx_document_id`, `wms_order_slip_number_assignment_id` | 送信時の伝票番号・送信単価への追跡 |
| EOS取り込みリンク | `wms_jx_transmission_log_id`, `wms_jx_eos_import_batch_id`, `wms_jx_eos_line_id` | JX受信履歴、EOS明細への追跡 |
| 仕入先・商品 | `contractor_id`, `contractor_code`, `contractor_name`, `supplier_id`, `item_id`, `item_code`, `item_name`, `search_code` | 仕入先別・商品別の日次確認 |
| 日付・伝票 | `slip_number`, `schedule_slip_number`, `line_number`, `order_date`, `expected_arrival_date`, `received_delivery_date`, `recorded_at` | 期間抽出、伝票追跡 |
| 数量 | `quantity_type`, `expected_quantity`, `received_total_quantity`, `received_pack_quantity`, `received_case_quantity`, `received_piece_quantity`, `shipped_quantity`, `shortage_quantity` | 入数考慮、分納、欠品確認 |
| 送信単価 | `sent_price_type`, `sent_unit_price_raw`, `sent_unit_price`, `sent_candidate_unit_price` | WMSから送信した単価との比較 |
| マスタ単価 | `master_unit_price`, `master_case_price` | 入荷予定適用時点の自社側単価 |
| 受信単価 | `received_unit_price_raw`, `received_unit_price`, `received_amount` | 仕入先から返却された単価・金額 |
| 現行比較 | `comparison_price_type`, `comparison_master_price`, `comparison_received_price`, `comparison_price_diff`, `current_price_mismatch` | WMS現行ロジックでの直接比較結果 |
| 除外 | `is_price_check_excluded`, `price_check_excluded_reason` | 送料行など、単価確認対象外の管理 |
| 原本JSON | `received_payload`, `schedule_payload`, `sent_payload`, `eos_payload`, `calculation_payload` | ai-core側でロジック変更後も再計算可能にするための原本保存 |

## 価格の保存ルール

### 送信単価

送信JX原本のDレコードを読み、伝票番号と行番号で対応する行を探す。行番号で見つからない場合は、同一伝票内のJANまたは商品コードで補助的に探す。

JX原単価は下2桁が小数部の整数として扱い、`sent_unit_price_raw` に原値、`sent_unit_price` に100で割った値を保存する。

### 受信単価

EOS受信Dレコードの `d_unit_price` を `received_unit_price_raw` として保存し、100で割った値を `received_unit_price` に保存する。

受信金額は、EOS行テーブルに金額がある場合はその値を優先し、なければ受信明細の `d_amount` を保存する。

### マスタ単価

入荷予定に保持されている `unit_price` と `case_price` を、照合・適用時点のマスタ由来単価として保存する。

この値は将来のマスタ変更で変わらないよう、単価相違リスト用の原本テーブルにスナップショットとして残す。

### 現行比較

現行WMSでは、入荷予定の `price_type` に応じて次の比較を行う。

- `CASE`: `partner_case_price - case_price`
- `PIECE`: `partner_unit_price - unit_price`

この結果は `comparison_*` と `calculation_payload` に保存する。ただし、Excel相当の `入数考慮` と `仕入調整考慮` はai-core側で再計算する前提で、WMS側では原本データを残す。

## ai-core側で行うこと

`sakemaru-ai-core` は `wms_incoming_price_check_sources` を参照し、日次または期間指定で単価相違リストを作成する。

ai-core側の主な責務:

- 仕入先別、商品別、期間別の単価相違一覧を表示する
- Excel運用と同じ順序で `単価の差`、`入数考慮`、`仕入調整考慮` を評価する
- 差額が1未満の行を除外する
- 送信単価、受信単価、マスタ単価を並べて確認できるようにする
- 仕入異常チェックとして、数量・単価・金額の不整合を検出する
- 判定ロジック変更時に、WMS側の原本JSONから再計算する

## 重要な境界

WMS側では単価相違リストの表示画面を作らない。

WMS側では、将来の判定ロジックに依存しすぎた加工済み結果を主データにしない。`comparison_*` は現行WMS上の参考値であり、ai-core側の最終判定は保存済み原本データを使って再計算する。

## 仕入先マッピング上の注意

カナカンなど一部仕入先では、受信データ上の仕入先コードだけでは最終的な仕入先を確定できないケースがある。

そのため単価相違リスト用データでは、受信伝票のコードだけでなく、送信時の伝票番号割当、発注候補、入荷予定に紐づく `contractor_id` と `supplier_id` を保存する。

特にカナカンは、JX受信時にカナカンの受信ファイルであることを判断し、伝票番号から送信時の入荷予定・仕入先へ戻すことを前提とする。

## 分納・欠品時の扱い

分納や欠品の場合も、受信明細と入荷予定の対応が作られた時点で原本データを保存する。

同一伝票番号から複数の仕入伝票を生成する将来仕様に備え、`slip_number`、`received_detail_id`、`incoming_schedule_id`、数量系項目を保存しておく。

ai-core側では、同一伝票番号内の複数明細、複数回受信、分納状態を考慮して表示・集計する。

## 除外行

JAN `9999999999996` の送料行は単価確認対象外として保存する。

除外行でも原本としては保存し、`is_price_check_excluded = true`、`price_check_excluded_reason = SHIPPING_LINE` を設定する。

## 未確定事項

- `差異額` を正式に残すかどうかは、佐藤さんとの確認後に決定する
- P箱代などの仕入調整額をどのマスタ・項目から取得するかは未確定
- 6缶パック、4缶パックなどの入数解釈は、仕入先別の受信データ調査結果を反映する必要がある
- カナカン、国分の数量・単価・金額不整合が新システムでどこまで補正済みか、実データで確認する必要がある
- 伝票番号割当が存在しないEOS明細から新規作成された入荷予定を、単価相違リストで通常行に含めるか、別カテゴリにするかはai-core側で決める

## 検証観点

- 同じEOS受信ファイルを2回適用しても `wms_incoming_price_check_sources` が重複しない
- 送信JX Dレコードの単価が `sent_unit_price_raw` と `sent_unit_price` に残る
- EOS受信Dレコードの単価が `received_unit_price_raw` と `received_unit_price` に残る
- 入荷予定適用時点の `unit_price`、`case_price` が `master_unit_price`、`master_case_price` に残る
- 送料行は除外扱いになるが、原本行としては残る
- 伝票番号割当、発注候補、入荷予定のリンクから、カナカンのような仕入先でも送信時の仕入先へ戻れる
