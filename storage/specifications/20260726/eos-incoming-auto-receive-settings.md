# EOSデータ受信設定・自動入荷確定・仕入自動連携 開発仕様

作成日: 2026-07-26

## 目的

EOS(JX)受信を、手動実行だけでなく設定時刻に自動実行できるようにする。

自動実行では、JXデータ受信、EOS取込、入荷予定照合、入荷確定、仕入データ生成までを一連の実行単位として扱う。ただし、仕入データ生成は91番倉庫を除外し、EOS送信履歴が確認できた伝票番号だけを対象にする。

伝票番号割当が見つからないEOS受信データは、倉庫・仕入先・商品・入荷数量を解決できる場合に限り、区分 `不明` の入荷完了データとして自動作成する。解決できないデータは確認用履歴として残す。

## 現在のコード状態

### JX受信

画面: `/admin/wms-jx-transmission-logs`

実装:

- `app/Filament/Resources/WmsJxTransmissionLogResource/Pages/ListWmsJxTransmissionLogs.php`
- `app/Services/JX/JxDocumentReceiver.php`

右上の `受信実行` で、有効な全JX接続設定に対して `GetDocument` を実行する。

受信時に行うこと:

- JX-FINETからドキュメント取得
- 受信原本をS3へ保存
- `ConfirmDocument` 送信
- `wms_jx_transmission_logs` へ受信履歴を保存

この時点では、入荷予定への照合、入荷確定、仕入データ生成は行わない。

### EOS取込・入荷確定

画面: `/admin/wms-jx-transmission-logs`

実装:

- `app/Filament/Resources/WmsJxTransmissionLogResource.php`
- `app/Services/JX/Eos/JxEosIncomingWorkflowService.php`
- `app/Services/AutoOrder/IncomingReceiveService.php`

各行の `EOS取込/入荷更新` で次を実行する。

1. EOS原本の正規化取込
2. `wms_incoming_received_files/slips/details` への保存
3. 入荷予定との照合
4. 照合済みデータの入荷予定への適用

現コードでは `IncomingReceiveService::applyMatched()` が入荷予定を `CONFIRMED` に更新する。そのため、現在の `EOS取込/入荷更新` は実質的に「照合 + 入荷確定」まで進める処理である。

### JX照合キー

JX受信では、受信伝票番号を直接 `wms_order_incoming_schedules.slip_number` に当てない。

照合順:

1. 受信伝票番号で `wms_order_slip_number_assignments.slip_number` を検索
2. 割当の `order_candidate_ids` から入荷予定を取得
3. 受信明細の商品コード/JANを、入荷予定の商品へ照合

このため、カナカンなどで受信側仕入先コードが送信時と異なる場合でも、送信時の伝票番号割当を正として入荷予定・仕入先へ戻す。

### 伝票番号割当なし

JX受信で `wms_order_slip_number_assignments` が見つからない伝票は、いったん伝票番号不明として記録する。その後、自動実行時に倉庫・仕入先・商品・入荷数量を解決できるものは、入荷完了データとして自動作成する。

保存する状態:

- `wms_incoming_received_slips.match_status = NO_ASSIGNMENT`
- `wms_incoming_import_errors.error_code = EOS_ASSIGNMENT_NOT_FOUND`
- 商品が解決できる場合は `matched_item_id` だけ保存
- 商品が解決できない場合は `ITEM_NOT_FOUND` も保存

確認画面:

- `/admin/wms-jx-unknown-incoming-slips`
- メニュー表示: `伝票番号不明`

自動作成できた伝票は入荷完了側に移り、区分は `不明` として表示する。自動作成できないものは伝票番号不明一覧に残す。後から手動で既存入荷予定へ紐づける導線は今回の対象外とする。

### 仕入データ生成

画面: `/admin/wms-incoming-completed`

実装:

- `app/Filament/Resources/WmsIncomingCompleted/*`
- `app/Services/AutoOrder/IncomingTransmissionService.php`

`CONFIRMED` の入荷予定から `purchase_create_queue` を作成する。

グルーピング単位:

- 倉庫コード
- 仕入先コード
- 伝票番号
- 発注日
- 入荷日
- 買掛日

伝票番号は `purchase_create_queue.slip_number` と `items` JSON内の `slip_number` の両方に保存される。

現在の不足:

- 91番倉庫除外が共通スコープに明示されていない
- EOS送信履歴がある伝票番号のみ、という仕入自動連携用の条件が共通スコープに明示されていない
- 定期実行スケジュールが未設定
- 1回の実行単位で受信、確定、仕入生成の結果をまとめて見る専用ログがない

## 追加する画面仕様

### メニュー

新規メニュー:

- 階層: システム > 発注送受信管理
- 画面名: EOSデータ受信設定
- 想定URL: `/admin/wms-eos-incoming-receive-settings`

カテゴリ名は `発注送受信管理` に変更し、JX発注データ作成とEOSデータ受信設定を同じカテゴリに置く。

### 設定画面

設定はシステム全体で1件を基本とする。

設定項目:

| 項目 | 内容 |
| --- | --- |
| 有効/無効 | 自動EOS受信を動かすか |
| 毎日受信時刻 | 通常実行時刻。初期値は22:00 |
| 曜日別追加受信時刻1 | 指定曜日だけ追加で実行する1回目の時刻 |
| 曜日別追加受信時刻2 | 指定曜日だけ追加で実行する2回目の時刻。未設定可 |
| 仕入自動連携 | 各時刻実行で仕入データ生成まで行う。初期実装では全枠有効固定 |
| 91番倉庫仕入連携除外 | 常に有効。91番倉庫は入荷確定まで自動、仕入データ生成は手動 |
| 自動整理 | 仕入自動連携後に、発注系は発注日が二週間前以前の入荷予定を自動整理し、移動系は予定日を1日以上過ぎた入荷予定を入荷完了扱いにする |
| Slack通知 | エラー時通知を行うか。基本はログERRORに寄せる |

確定した初期設定:

| 枠 | 対象曜日 | 受信時刻 | 仕入自動連携 |
| --- | --- | --- | --- |
| 通常実行 | 毎日 | 22:00 | あり |
| 月曜追加1 | 月 | 10:00 | あり |
| 月曜追加2 | 月 | 16:00 | あり |

月曜日も通常の22:00実行を行う。月曜10:00/16:00も、受信・入荷確定だけでなく仕入自動連携まで行う。

## 自動実行仕様

### 実行単位

設定時刻ごとに1つの実行ログを作成する。

1回の実行で行う処理:

1. 実行ログを `RUNNING` で作成
2. 全JX接続設定で `GetDocument` を実行
3. 新規受信ログをEOS取込対象として抽出
4. EOS取込、照合、入荷確定
5. 今回確定した入荷予定IDだけを仕入データ生成候補にする
6. 仕入自動連携ありの場合、91番倉庫以外かつEOS送信履歴ありの入荷予定だけ `purchase_create_queue` 登録
7. 仕入自動連携後、発注系は発注日が二週間前以前の未確定入荷予定を自動整理し、移動系は予定日を1日以上過ぎた未確定入荷予定を完了
8. 実行ログを `SUCCEEDED`、`PARTIAL_FAILED`、`FAILED` のいずれかで完了

同一枠の重複実行は `run_key` の一意制約と Queue Job の `ShouldBeUnique` で防止する。

### 入荷確定対象

入荷確定対象:

- JX受信ログが成功
- EOS取込対象
- 受信伝票番号に対応する `wms_order_slip_number_assignments` が存在する
- 割当から入荷予定が見つかる
- 商品照合ができる
- 入荷予定ステータスが `PENDING` または `PARTIAL`

入荷確定対象外:

- 伝票番号割当なし
- 商品不明
- 割当に対応する入荷予定なし
- 仕入先不一致
- 既に `APPLIED` または `SKIPPED` の受信ファイル

### 仕入データ生成対象

仕入データ生成対象:

- 今回の自動実行で `CONFIRMED` になった入荷予定
- 91番倉庫以外
- EOS送信履歴がある伝票番号
- 伝票番号割当なしから自動作成された区分 `不明` の入荷完了データ
- `purchase_queue_id` が未設定
- 仕入先コード、倉庫コード、商品コード、伝票番号、日付が解決できる
- 移動・内部連携ではない

仕入データ生成対象外:

- 91番倉庫
- 伝票番号割当なしのうち、倉庫・仕入先・商品・入荷数量を解決できず入荷完了を作成できないもの
- FAX/手動などEOS送信履歴が確認できない入荷予定
- 仕入先不明
- `purchase_queue_id` 設定済み

91番倉庫は入荷確定までは自動で進めるが、仕入データ生成は手動運用とする。

### 古い入荷予定の自動整理

仕入自動連携が完了した後に、古い未確定入荷予定を自動整理する。

発注系と移動系で完了条件と数量の扱いを分ける。

発注系:

- 発注日が基準日から14日前以前の未確定入荷予定を自動整理する
- 対象はEOS送信済みに限定しない。FAX、手動、EOSを含む全ての未確定入荷予定を対象にする
- 移動由来の入荷予定は対象外
- 対象になった入荷予定は、入荷数量に関係なく入荷確定ではなく `DELETED` にする

移動系:

- 入荷予定日を1日以上過ぎた仕入自動連携タイミングで入荷完了扱いにする
- `order_source = TRANSFER`、または `transfer_candidate_id/source_warehouse_id/stock_transfer_id` がある入荷予定を対象にする
- 欠品数量は立てず、`received_quantity` と `shipped_quantity` を予定数量に合わせ、`shortage_quantity = 0` にする
- 仕入キュー、移動キューは作成しない。入荷予定を完了状態へ更新するだけとする

月曜日のように同日に複数回実行される場合でも、既に完了済みの入荷予定はステータス条件から外れるため再処理しない。

発注系の対象候補:

- `order_date <= 実行日 - 14日`
- ステータスが `PENDING` または `PARTIAL`
- 移動由来ではない
- 取消済み、送信済みは対象外

移動系の対象候補:

- `expected_arrival_date <= 実行日 - 1日`
- ステータスが `PENDING` または `PARTIAL`
- 移動由来である
- 取消済み、確定済みは対象外

発注系の更新内容:

- `status = DELETED`
- `received_quantity = 0`、`shipped_quantity = 0`
- `shortage_quantity = expected_quantity`
- `actual_arrival_date` は自動整理処理日、または予定日
- `confirmed_by = 0`

移動系の更新内容:

- `status = CONFIRMED`
- `received_quantity = expected_quantity`
- `shipped_quantity = expected_quantity`
- `shortage_quantity = 0`
- `actual_arrival_date` は完了処理日、または予定日
- `confirmed_by = 0`

この処理は仕入自動連携後に実行するため、自動整理処理そのものでは新たに仕入キューや移動キューを作らない。

## 重複防止仕様

### 受信ログ

`JxDocumentReceiver` は `received_message_id` を `wms_jx_transmission_logs.message_id` に保存する。

同一メッセージIDの再受信が発生した場合、同じ受信原本を複数回処理しないようにする。

### 入荷受信ファイル

`JxEosIncomingWorkflowService` は既に次の順で既存取込を探す。

1. `received_message_id`
2. `raw_sha256`

既に `APPLIED` または `SKIPPED` の場合は再適用しない。

### 自動実行ログ

同じスケジュール枠を多重起動しない。

推奨キー:

```text
execution_date + scheduled_time + trigger_type
```

このキーで `RUNNING` または `SUCCESS` が存在する場合は、新規実行をスキップする。

手動再実行は別の `trigger_type = MANUAL_RETRY` とし、元実行IDを持たせる。

### 仕入キュー

仕入キュー生成は入荷予定の `purchase_queue_id` を排他条件にする。

同一実行内では、対象入荷予定IDを先に確定し、そのIDだけを `IncomingTransmissionService` に渡す。これにより過去の `CONFIRMED` や手動確定分が混ざらないようにする。

## 実行ログ仕様

新規テーブル案:

- `wms_eos_incoming_receive_settings`
- `wms_eos_incoming_receive_schedules`
- `wms_eos_incoming_receive_runs`
- `wms_eos_incoming_receive_run_logs`

### `wms_eos_incoming_receive_settings`

システム全体の有効/無効と共通オプションを保存する。

主な項目:

- `id`
- `is_enabled`
- `shortage_completion_days` default `14`
- `exclude_purchase_warehouse_code` default `91`
- `unknown_slip_policy` default `REVIEW_ONLY`
- `last_run_at`
- `created_at`
- `updated_at`

### `wms_eos_incoming_receive_schedules`

曜日別の時刻設定を保存する。

主な項目:

- `id`
- `setting_id`
- `schedule_type` `DAILY`, `WEEKLY_EXTRA`
- `day_of_week` 0〜6。`DAILY` の場合は0固定
- `slot_no` `DAILY` は1、`WEEKLY_EXTRA` は1〜2
- `receive_time`
- `auto_purchase_transmission_enabled`
- `is_enabled`
- `created_at`
- `updated_at`

制約:

- unique: `setting_id, schedule_type, day_of_week, slot_no`
- index: `setting_id, is_enabled, schedule_type, day_of_week, receive_time`

### `wms_eos_incoming_receive_runs`

1回の自動/手動実行のサマリーを保存する。

主な項目:

- `id`
- `run_key`
- `setting_id`
- `schedule_id`
- `execution_date`
- `scheduled_time`
- `trigger_type` `scheduled`, `manual`
- `status` `QUEUED`, `RUNNING`, `SUCCEEDED`, `PARTIAL_FAILED`, `FAILED`, `SKIPPED`
- `started_at`
- `finished_at`
- `active_jx_setting_count`
- `received_jx_document_count`
- `target_jx_log_count`
- `eos_imported_count`
- `incoming_matched_count`
- `incoming_unmatched_count`
- `incoming_confirmed_schedule_count`
- `purchase_queue_count`
- `purchase_transmitted_schedule_count`
- `purchase_skipped_warehouse91_count`
- `purchase_skipped_not_eos_sent_count`
- `unknown_slip_count`
- `shortage_completed_count`
- `error_count`
- `error_summary`
- `metadata`
- `unknown_slip_count`
- `item_not_found_count`
- `old_shortage_completed_count`
- `error_count`
- `error_summary`
- `metadata` JSON
- timestamps

推奨インデックス:

- unique: `execution_date, scheduled_time, trigger_type`
- index: `status, started_at`
- index: `execution_date, scheduled_time`

### `wms_eos_incoming_receive_run_logs`

実行内の詳細イベントを保存する。

主な項目:

- `id`
- `run_id`
- `level` `INFO`, `WARNING`, `ERROR`
- `step` `RECEIVE`, `IMPORT`, `MATCH_APPLY`, `PURCHASE_TRANSMIT`, `OLD_SHORTAGE_COMPLETE`
- `message`
- `jx_setting_id`
- `jx_transmission_log_id`
- `incoming_received_file_id`
- `incoming_received_slip_id`
- `incoming_schedule_id`
- `purchase_queue_id`
- `context` JSON
- timestamps

推奨インデックス:

- index: `run_id, step`
- index: `level, created_at`
- index: `jx_transmission_log_id`
- index: `incoming_received_file_id`
- index: `incoming_schedule_id`

## ログ画面仕様

`EOSデータ受信設定` 画面内に、設定フォームと実行履歴一覧を配置する。

一覧カラム:

- 実行日時
- 予定時刻
- 起動種別
- 状態
- 受信件数
- EOS取込件数
- 入荷確定件数
- 仕入キュー件数
- 91番倉庫除外件数
- 伝票番号不明件数
- 商品不明件数
- エラー件数

行クリックまたは詳細ボタンで詳細モーダルを表示する。

詳細表示:

- 実行サマリー
- JX受信結果
- EOS取込結果
- 入荷確定結果
- 仕入データ生成結果
- 伝票番号不明一覧へのリンク
- エラー詳細

## エラー通知

自動実行で例外または処理エラーが発生した場合は `Log::error()` を必ず出す。

既存のログ監視でSlack送信される前提のため、個別にSlack APIを直接呼ぶのではなく、まずはLaravelログのERRORに集約する。

ログには次を含める。

- 実行ログID
- ステップ
- JX設定ID
- JX受信ログID
- 受信ファイルID
- 伝票番号
- 入荷予定ID
- 例外メッセージ

## 入荷確定後の修正仕様

### 必要な理由

自動確定後でも、担当者が伝票番号や数量を修正できる必要がある。

修正対象:

- 伝票番号
- 入荷数量
- 欠品数量
- 必要に応じて賞味期限、入荷日

### 修正可能条件

修正可能:

- 入荷予定ステータスが `CONFIRMED`
- `purchase_queue_id` が未設定
- 仕入データ未生成
- Web画面を利用できるユーザであれば修正可能

修正不可:

- `purchase_queue_id` が設定済み
- ステータスが `TRANSMITTED`
- 取消済み

修正した場合は、仕入データ生成時に修正後の伝票番号・数量を使用する。

この修正機能は、主に91番倉庫やFAXデータなど、仕入自動連携されない確定済み入荷データの調整で使用する。

仕入キュー作成後の修正は完全不可とする。キュー削除や取消後の再修正導線は今回の対象外とする。

## 手動入荷確定バグ修正仕様

### 現象

入荷予定詳細で数量を変更して入荷確定しても、発注数量のまま確定されるケースがある。

### 修正方針

手動確定時は、画面入力された数量を必ず `received_quantity` に反映する。

既存の `IncomingConfirmationService::resolveConfirmedReceivedQuantity()` は、現在の入荷済み数量に今回入力数量を足して累計数量を返す。手動確定フォームではこの値を `confirmIncoming()` に渡す。

### 一部欠品時

ユーザ要件では、一部欠品として入荷確定し、追加入荷があった場合はユーザが新規入荷予定を生成する。

そのため手動確定では、入力数量が予定数量未満でも、元の入荷予定を `PARTIAL` に残さず `CONFIRMED` にする。

更新内容:

- `received_quantity = 入力数量`
- `shortage_quantity = expected_quantity - received_quantity`
- `status = CONFIRMED`
- `confirmed_at` 設定
- `confirmed_by` または `confirmed_picker_id` 設定

追加入荷分は、別途ユーザが新規入荷予定を作成する。

## 確定事項

- 月曜日も通常の22:00実行を行う
- 月曜10:00/16:00でも仕入自動連携まで行う
- 発注日二週間前自動整理の対象はEOS送信済みに限定せず、全ての未確定入荷予定を対象にする
- 91番倉庫は入荷確定まで自動、仕入データ生成は手動
- 伝票番号不明データは表示のみで、手動紐付けは今回の対象外
- 自動確定後の数量・伝票番号修正はWeb画面を利用できるユーザ全員が可能
- 仕入連携済み、つまり `purchase_queue_id` 設定済みまたは `TRANSMITTED` の入荷予定は修正不可
- 仕入キュー作成後の修正は完全不可

## 実装確定事項

- 設定画面に「今すぐ実行」を用意する。
- 実行ログは削除せず保存する。保持期間の自動削除は今回対象外。
- 初期データは毎日22:00、月曜10:00、月曜16:00を作成する。ただし `is_enabled` は初期値 false とし、画面で有効化してから定期実行する。


## 実装ステップ

1. 設定・ログ用テーブル追加
   リスク: 中
   検証: migration実行、index確認、モデル作成確認
   ロールバック: 追加テーブルのみdrop

2. EOSデータ受信設定Resource追加
   リスク: 低
   検証: `/admin/wms-eos-incoming-receive-settings` 表示、曜日別時刻が保存できる
   ロールバック: Resourceとメニュー追加を戻す

3. 自動実行サービス追加
   リスク: 中
   検証: dry-run相当で対象JXログ、確定対象、仕入連携対象を集計できる
   ロールバック: 新サービス呼び出しを止める

4. スケジューラ追加
   リスク: 中
   検証: 設定時刻だけ実行され、同一枠が二重起動しない
   ロールバック: `routes/console.php` のスケジュールを無効化

5. 仕入自動連携対象制限追加
   リスク: 高
   検証: 91番倉庫、EOS送信履歴なし、解決不可の伝票番号不明が仕入キューに入らず、解決可能な伝票番号不明は区分 `不明` の入荷完了として仕入キュー対象になる
   ロールバック: 対象制限の新メソッド呼び出しを戻す

6. 自動確定後修正UI追加
   リスク: 中
   検証: 仕入キュー未生成の確定済みデータだけ伝票番号・数量を修正できる
   ロールバック: 修正アクションを非表示化

7. 手動入荷確定の数量修正バグ対応
   リスク: 中
   検証: 入力数量が `received_quantity` に反映され、予定未満でも `CONFIRMED` になる
   ロールバック: 手動確定分岐のみ戻す

8. 二週間前自動整理処理追加
   リスク: 高
   検証: 対象発注日以前の未確定だけ整理され、入荷数量に関係なく削除済みになり、対象外ステータスは変わらない
   ロールバック: 設定で無効化し、必要なら更新前ログから復旧

9. 結合テスト
   リスク: 高
   検証: JX受信、EOS取込、入荷確定、仕入キュー生成、伝票番号不明履歴、エラー通知を通しで確認
   ロールバック: スケジューラ無効化、手動運用へ戻す

## 検証観点

- 同一JXメッセージIDを複数回処理しても受信ファイル・入荷確定・仕入キューが重複しない
- 伝票番号割当なしは、解決可能なら区分 `不明` の入荷完了として作成され、解決不可なら `伝票番号不明` に残る
- 91番倉庫は入荷確定されても仕入キューに入らない
- EOS送信履歴がない伝票番号は仕入キューに入らない
- 今回の自動実行で確定した入荷予定だけ仕入自動連携対象になる
- 手動確定で入力数量が正しく `received_quantity` に反映される
- 一部欠品の手動確定が `CONFIRMED` になり、欠品数量が残る
- 仕入キュー未生成の確定済み入荷は伝票番号・数量を修正できる
- 仕入キュー生成済みは修正できない
- エラー時に実行ログへ詳細が残り、`Log::error()` が出る
