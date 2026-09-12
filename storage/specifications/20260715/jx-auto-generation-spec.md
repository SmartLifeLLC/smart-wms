# JX発注データ自動生成 仕様書

作成日: 2026-07-15  
作業ブランチ: `codex/auto-jx-generation-transmission`  
前提: 伝票番号対応コミット `66f0602f Restore legacy EOS slip numbering for JX orders` 取り込み済み  
DB確認: HANA-STG MySQL（SELECTのみ）

## 1. 目的

JX発注データを、設定時刻になったら仕入先代表グループ別に自動生成する。

生成は送信しない。生成後は既存どおり `wms_order_jx_documents.status = PENDING` にし、JX送信画面から送信する。

## 2. 要件

### 2.1 初期スケジュール

| 曜日 | 生成時刻 | 対象締め時刻 |
| --- | --- | --- |
| 月-土 | 13:30 | 当日13:20まで |
| 日 | 23:30 | 当日23:00まで |

対象は `CONFIRMED`、JX未生成、FAX未生成の発注候補。

### 2.2 設定画面

現在の発注先編集画面に、JX発注データ生成の設定項目を追加する。

対象画面:

- `admin/contractors/{id}/edit`
- 実装: `app/Filament/Resources/Contractors/Schemas/ContractorForm.php`
- 保存: `app/Filament/Resources/Contractors/Pages/EditContractor.php`
- 保存先: `wms_contractor_settings`

既存の `transmission_time` は送信時刻であり、今回の「JXデータ生成時刻」とは別管理にする。

### 2.3 入荷予定日補正

JX生成前に、対象候補のうち `expected_arrival_date <= 実行日` のものだけを補正する。

補正先は、実行日の翌日以降で、対象の `contractor_id + warehouse_id` が入荷可能な最初の日付とする。倉庫休日がある場合はさらに次の日へ送る。

例:

- 水曜日にJX生成する。
- 入荷予定日が当日水曜日または過去日の候補がある。
- その倉庫・仕入先の入荷可能曜日が月水金の場合、当日水曜は採用せず、次の金曜日へ補正する。

補正時は、発注候補と未入荷の入荷予定を同じ日付に更新する。

更新対象:

- `wms_order_candidates.expected_arrival_date`
- `wms_order_incoming_schedules.expected_arrival_date`
- 必要に応じて `wms_order_incoming_schedules.expiration_date`

更新対象外:

- `wms_order_jx_document_id IS NOT NULL`
- 入荷済みまたは一部入荷済みの入荷予定
- `expected_arrival_date > 実行日`

## 3. 現行実装確認

### 3.1 JX生成画面

対象:

- `app/Filament/Resources/WmsOrderForJx/WmsOrderForJxResource.php`
- `app/Filament/Resources/WmsOrderForJx/Pages/ListWmsOrderForJx.php`
- `app/Filament/Resources/WmsOrderConfirmed/Tables/WmsOrderConfirmedTable.php`

現行画面の初期条件:

- `status = CONFIRMED`
- JX未生成: `wms_order_jx_document_id IS NULL`
- FAX未生成: `wms_order_data_files` に候補ID該当データがない
- 確定日: `modified_at` の前日から当日

今回の自動生成では、確定日の範囲を「実行日 00:00 から締め時刻まで」とする。現行DBには発注確定専用の `confirmed_at` がないため、既存画面と同じく `modified_at` を確定時刻として扱う。

### 3.2 JX生成サービス

生成は以下を使う。

- `OrderTransmissionService::generateJxFilesForCandidateIds(array $candidateIds)`

理由:

- 既存画面のJX生成と同じ経路。
- 伝票番号対応済みの `HanaOrderJXFileGenerator` を通る。
- 生成後に `wms_order_jx_documents` を `PENDING` 作成する。
- 候補へ `wms_order_jx_document_id` を紐付ける。
- 伝票番号割当を `wms_order_slip_number_assignments` に保存する。
- 入荷予定の `slip_number` も更新する。

使わない経路:

- `AutoOrderTransmitCommand`
- `ProcessAutoSendJob`
- `transmitConfirmedOrders()`

これらは承認、確定、生成、送信が一体化しており、今回の「生成のみ」と責務が違う。

### 3.3 伝票番号ルール

JX発注データのBレコード伝票番号は、旧EOS互換の11桁番号を使う。

形式:

```text
店舗CD2桁 + 年度コード2桁 + 10固定 + 店舗年度別連番5桁
```

例:

- 倉庫CD91、2026年、連番18629: `91461018629`
- 倉庫CD1、2025年、連番18907: `01451018907`

5・6桁目は `10` 固定とする。過去問い合わせで確認していた `00` 固定ルールは採用しない。

## 4. 追加DB設計

既存の `wms_contractor_settings` にJX生成設定を追加する。

追加案:

| カラム | 型 | 目的 |
| --- | --- | --- |
| `is_jx_auto_generation_enabled` | boolean default false | JXデータ自動生成を有効化 |
| `jx_generation_time` | string(5) nullable | 月-土の生成時刻 |
| `jx_generation_cutoff_time` | string(5) nullable | 月-土の対象締め時刻 |
| `jx_generation_sunday_time` | string(5) nullable | 日曜の生成時刻 |
| `jx_generation_sunday_cutoff_time` | string(5) nullable | 日曜の対象締め時刻 |

初期値:

- JX代表親: 有効、`13:30 / 13:20 / 23:30 / 23:00`
- JX集約子: 同じ値を持つ。ただし自動生成コマンドは親だけを実行対象にする。
- 非JX: 無効、時刻NULL

設定を子にも持たせる理由:

- 画面で個別仕入先を見たときに設定内容を確認できる。
- 将来、集約を外す場合に設定値を保持できる。

実行対象を親に限定する理由:

- カナカンのような集約グループで子ごとに生成すると重複ファイルができる。
- 既存の `WmsOrderForJx` 画面も代表親タブに子仕入先を集約している。

## 5. 自動生成コマンド

新規コマンド案:

```bash
php artisan wms:generate-jx-order-documents
```

オプション:

```bash
--date=YYYY-MM-DD
--time=HH:MM
--contractor=CODE_OR_ID
--force
--dry-run
```

通常実行:

1. 現在時刻から曜日を判定する。
2. `wms_contractor_settings` からJX代表親を取得する。
3. 日曜なら `jx_generation_sunday_time/cutoff_time`、それ以外は `jx_generation_time/cutoff_time` を使う。
4. 生成時刻 `<= current HH:MM` のものだけ対象にする。
5. 当日実行済みの代表親はスキップする。
6. 対象候補をSELECTする。
7. 入荷予定日補正を短いトランザクションで実行する。
8. 補正後の候補IDを `generateJxFilesForCandidateIds()` に渡す。
9. 結果を実行ログへ保存する。

スケジュール:

```php
Schedule::command('wms:generate-jx-order-documents')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/jx-order-generation.log'));
```

本番の全スケジュール停止コメントがあるため、リリース時はスケジュール再開範囲を明示してから有効化する。

## 6. 対象候補条件

代表親 `P` の対象仕入先ID:

- `P.contractor_id`
- `transmission_contractor_id = P.contractor_id` の子仕入先

候補条件:

- `wms_order_candidates.contractor_id IN (親+子)`
- `status = CONFIRMED`
- `order_quantity > 0`
- `wms_order_jx_document_id IS NULL`
- `modified_at >= 実行日 00:00:00`
- `modified_at <= 実行日 締め時刻`
- FAX未生成:
  - `wms_order_data_files` に同じ `batch_code + warehouse_id + contractor_id + expected_arrival_date` がなく、
  - `candidate_ids` がNULL、または `candidate_ids` に対象候補IDを含むものがない

注意:

- 過去日に確定された未生成候補は自動生成の通常対象外。
- 過去分救済は `--date` または別の手動再実行モードで扱う。

## 7. 入荷予定日補正サービス

新規サービス案:

- `JxOrderArrivalDateAdjustmentService`

責務:

- JX生成対象候補を受け取る。
- `expected_arrival_date <= 実行日` の候補だけを抽出する。
- `wms_contractor_warehouse_delivery_days` と `wms_warehouse_calendars` を参照する。
- 実行日の翌日から最大14日先まで、入荷可能かつ倉庫休日でない日付を探す。
- 候補と未入荷予定を短いトランザクションで更新する。
- 補正件数、補正前後日付、理由を返す。

補正不可時:

- 入荷可能曜日が全OFFの場合は補正せず、対象候補をJX生成から除外してエラーに残す。
- 14日以内に補正先がない場合も生成対象から除外する。

## 8. 初期設定migration

### 8.1 JX生成設定

JX対象の親・子に初期値を入れる。

対象コード:

- コカコーラ: `1017`
- カナカン: `1106, 1021, 1029, 1068, 1126, 1127, 1680`
- 国分: `1202`
- 三菱: `1330`

設定:

- `is_jx_auto_generation_enabled = true`
- `jx_generation_time = '13:30'`
- `jx_generation_cutoff_time = '13:20'`
- `jx_generation_sunday_time = '23:30'`
- `jx_generation_sunday_cutoff_time = '23:00'`

### 8.2 倉庫別入荷可能曜日

`wms_contractor_warehouse_delivery_days` を `updateOrInsert` する。

| グループ | 初期設定 |
| --- | --- |
| カナカン全対象 | 日曜不可。基本は月-土可。倉庫CD22 小浜店のみ月・水・日不可、火・木・金・土可 |
| 国分 | 基本は月不可、火-日可。倉庫CD91は月-土可、日不可。倉庫CD22は火・木・土のみ可 |
| 三菱 | 基本は月-土可、日不可。倉庫CD22は月・水・金のみ可 |
| コカコーラ | 全倉庫で火・木・土のみ可 |

HANA-STG確認結果:

- 国分とコカコーラは、おおむね依頼内容と一致。
- カナカンは仕入先種別ごとに既存パターンがばらついており、依頼内容とは差分がある。
- 三菱は一部倉庫で日曜可の既存設定があり、依頼内容とは差分がある。
- カナカンコード `1680` はHANA-STG上で納品可能曜日行が1件のみだった。migrationでは対象倉庫の不足行も作成する。

## 9. HANA-STG DB確認結果

調査スクリプト:

- `/Users/jungsinyu/.local/share/sakemaru-survey/work/20260715-jx-auto-generation-spec/check_hana_stg_jx_auto_generation.py`

結果JSON:

- `/Users/jungsinyu/.local/share/sakemaru-survey/work/20260715-jx-auto-generation-spec/hana_stg_jx_auto_generation_state.json`

確認時点のJX設定:

| 代表/子 | コード | 既存 auto_order_generation_time | 既存 transmission_time | 備考 |
| --- | ---: | --- | --- | --- |
| 親 | 1017 | 11:00 | 12:00 | コカコーラ |
| 子 | 1021 | 11:00 | 12:00 | カナカン、1106へ集約 |
| 子 | 1029 | 11:00 | 12:00 | カナカン、1106へ集約 |
| 子 | 1068 | 11:00 | 12:00 | カナカン、1106へ集約 |
| 親 | 1106 | 11:00 | 12:00 | カナカン代表 |
| 子 | 1126 | 11:00 | 12:00 | カナカン、1106へ集約 |
| 子 | 1127 | 11:00 | 12:00 | カナカン、1106へ集約 |
| 親 | 1202 | 11:00 | 12:00 | 国分 |
| 親 | 1330 | 11:00 | 12:00 | 三菱 |
| 子 | 1680 | NULL | NULL | カナカン、1106へ集約 |

HANA-STGのJX未生成・FAX未生成・CONFIRMED候補（調査時点）:

| 代表コード | 件数 | 倉庫数 | modified_at範囲 | 入荷予定日範囲 |
| ---: | ---: | ---: | --- | --- |
| 1017 | 63 | 11 | 2026-05-07 17:31:00 - 2026-07-11 14:34:41 | 2026-05-09 - 2026-07-13 |
| 1106 | 215 | 11 | 2026-05-13 18:26:26 - 2026-07-11 16:24:22 | 2026-05-14 - 2026-07-14 |
| 1202 | 13 | 4 | 2026-05-18 15:46:09 - 2026-07-11 16:19:53 | 2026-05-22 - 2026-07-14 |
| 1330 | 44 | 9 | 2026-05-13 17:41:12 - 2026-07-11 16:24:05 | 2026-05-15 - 2026-07-14 |

## 10. ロック・冪等性

生成単位:

- `representative_contractor_id + target_date`

追加ログ案:

- `wms_jx_order_generation_runs`

カラム案:

- `id`
- `representative_contractor_id`
- `target_date`
- `generation_time`
- `cutoff_time`
- `status` (`RUNNING`, `SUCCESS`, `FAILED`, `SKIPPED`)
- `candidate_count`
- `adjusted_candidate_count`
- `document_count`
- `error_message`
- `started_at`
- `finished_at`
- timestamps

ユニークキー:

- `(representative_contractor_id, target_date)`

制御:

- 実行開始時にrun行を作る。
- 既に `RUNNING` または `SUCCESS` があればスキップする。
- `--force` の場合のみ `FAILED` または `SKIPPED` の再実行を許可する。
- 候補行をロックしたままS3保存やファイル生成をしない。
- 入荷予定日補正は候補ID限定の短いトランザクションにする。
- JXドキュメント作成・候補紐付け・伝票番号割当は既存 `OrderTransmissionService` の短いトランザクションに任せる。

## 11. 仕様レビュー

### 指摘1: `modified_at` を確定時刻として使うリスク

現行画面も確定日フィルタに `modified_at` を使っているが、入荷予定日変更などでも更新される。厳密には `confirmed_at` が必要。

初期対応では既存仕様に合わせる。将来改善として `wms_order_candidates.confirmed_at` 追加を検討する。

### 指摘2: `wms_contractor_settings` に時刻カラムを増やす設計

カラム追加は最小変更だが、曜日ごとに任意時刻を持ちたい場合は拡張しづらい。

今回要件は「月-土同一、日曜だけ別」なのでカラム追加で実装する。将来、曜日ごとに別時刻が必要になった場合は、`wms_jx_generation_schedules` へ移行する。

### 指摘3: 入荷可能曜日の初期設定migrationはデータ更新を伴う

本番データ更新になるため、migrationは `updateOrInsert` で対象コードと対象倉庫に限定する。既存全削除は禁止。

rollbackは、更新前の値を復元できないため、`down()` では追加カラムの削除だけにし、曜日データのrollback SQLは別途調査成果物として保存する方針が安全。

### 指摘4: 送信との混同

今回のコマンドは送信しない。JX送信の自動化・一括送信は別仕様として扱う。

### 指摘5: 古い未生成候補

HANA-STGには5月以降の古い未生成候補が残っている。通常自動生成では当日締めのみ対象にするため、古い候補は別途手動または救済コマンドで扱う。

## 12. 実装順

1. 追加カラムmigrationを作る。
2. `WmsContractorSetting` のfillable/castsへ追加する。
3. `ContractorForm::transmissionSchema()` にJX生成設定を追加する。
4. `EditContractor::mutateFormDataBeforeFill()` / `afterSave()` に追加フィールドを入れる。
5. 入荷予定日補正サービスを作る。
6. 自動生成runモデル/テーブルを作る。
7. `wms:generate-jx-order-documents` コマンドを作る。
8. `routes/console.php` に5分間隔スケジュールを追加する。ただしリリース時に明示的に有効化する。
9. 初期設定migrationでJX生成設定と倉庫別入荷可能曜日をupsertする。
10. テスト計画に沿って単体・Feature・ローカルJX生成確認を実施する。
