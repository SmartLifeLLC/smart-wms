# JX発注データ自動生成 テスト計画書

作成日: 2026-07-15  
対象仕様: `storage/specifications/20260715/jx-auto-generation-spec.md`

## 1. テスト方針

目的は、JX発注データ自動生成で以下を保証すること。

- 指定時刻・締め時刻に従って対象候補だけを生成する。
- 仕入先代表グループ単位で生成し、集約子を重複生成しない。
- FAX生成済み、JX生成済み、締め時刻後、当日以外の候補を除外する。
- 入荷予定日が当日・過去日の候補だけを、倉庫別入荷可能曜日へ補正する。
- 伝票番号対応済みの生成経路を使い、`wms_order_slip_number_assignments` と入荷予定の `slip_number` が正しく更新される。
- 送信は行わず、JXドキュメントは `PENDING` に止まる。
- 同日・同仕入先代表の二重実行を防ぐ。

## 2. テスト対象

主対象:

- 新規コマンド: `wms:generate-jx-order-documents`
- 新規サービス: `JxOrderArrivalDateAdjustmentService`
- 既存サービス: `OrderTransmissionService::generateJxFilesForCandidateIds()`
- 設定画面: `ContractorForm::transmissionSchema()`
- 保存処理: `EditContractor`
- migration:
  - JX生成設定カラム
  - JX生成runテーブル
  - 初期設定upsert

既存影響範囲:

- `wms_order_candidates`
- `wms_order_incoming_schedules`
- `wms_order_jx_documents`
- `wms_order_data_files`
- `wms_order_slip_number_sequences`
- `wms_order_slip_number_assignments`
- `wms_contractor_settings`
- `wms_contractor_warehouse_delivery_days`

## 3. 単体テスト

### 3.1 入荷予定日補正

| No | 実行日 | 元入荷予定日 | 入荷可能曜日 | 倉庫休日 | 期待値 |
| --- | --- | --- | --- | --- | --- |
| A1 | 水 | 水 | 月水金 | なし | 金 |
| A2 | 水 | 火 | 月水金 | なし | 金 |
| A3 | 水 | 木 | 月水金 | なし | 補正しない |
| A4 | 水 | 水 | 火木土 | なし | 木 |
| A5 | 土 | 土 | 月火水木金土 | なし | 月 |
| A6 | 土 | 金 | 火木土 | なし | 火 |
| A7 | 日 | 日 | 月火水木金土 | なし | 月 |
| A8 | 日 | 日 | 火木土 | なし | 火 |
| A9 | 水 | 水 | 木 | 木が倉庫休日 | 翌週木、または次の入荷可能日 |
| A10 | 水 | 水 | 全OFF | なし | 補正不可として生成除外 |

確認項目:

- 実行日当日は補正先候補に含めない。
- 未来日の `expected_arrival_date` は補正しない。
- 候補と `PENDING` 入荷予定だけを更新する。
- `CONFIRMED` / `TRANSMITTED` / `PARTIAL` 入荷予定は更新しない。
- 補正結果の件数と理由が返る。

### 3.2 仕入先別ルール

| No | 仕入先 | 倉庫 | 期待入荷可能曜日 |
| --- | --- | --- | --- |
| B1 | カナカン1106 | 通常倉庫 | 月-土 |
| B2 | カナカン1021 | 通常倉庫 | 月-土 |
| B3 | カナカン1126 | 通常倉庫 | 月-土 |
| B4 | カナカン1127 | 通常倉庫 | 月-土 |
| B5 | カナカン全対象 | 倉庫CD22 | 火木金土 |
| B6 | 国分1202 | 通常倉庫 | 火-日 |
| B7 | 国分1202 | 倉庫CD91 | 月-土 |
| B8 | 国分1202 | 倉庫CD22 | 火木土 |
| B9 | 三菱1330 | 通常倉庫 | 月-土 |
| B10 | 三菱1330 | 倉庫CD22 | 月水金 |
| B11 | コカコーラ1017 | 全倉庫 | 火木土 |

### 3.3 スケジュール判定

| No | 曜日 | 現在時刻 | 設定 | 期待値 |
| --- | --- | --- | --- | --- |
| C1 | 月 | 13:29 | 13:30 | 未実行 |
| C2 | 月 | 13:30 | 13:30 | 実行 |
| C3 | 土 | 13:35 | 13:30 | 実行 |
| C4 | 日 | 13:35 | 23:30 | 未実行 |
| C5 | 日 | 23:30 | 23:30 | 実行 |
| C6 | 日 | 23:29 | 23:30 | 未実行 |
| C7 | 月 | 13:30 | 自動生成OFF | 未実行 |

### 3.4 対象締め時刻

| No | 曜日 | 締め時刻 | 候補 modified_at | 期待値 |
| --- | --- | --- | --- | --- |
| D1 | 月 | 13:20 | 当日13:19:59 | 対象 |
| D2 | 月 | 13:20 | 当日13:20:00 | 対象 |
| D3 | 月 | 13:20 | 当日13:20:01 | 対象外 |
| D4 | 日 | 23:00 | 当日23:00:00 | 対象 |
| D5 | 日 | 23:00 | 当日23:00:01 | 対象外 |
| D6 | 月 | 13:20 | 前日12:00:00 | 通常対象外 |

## 4. Feature / DBテスト

### 4.1 設定画面

確認対象:

- `admin/contractors/{id}/edit`
- 送受信設定タブ

ケース:

- JX-FINETのときだけJX生成設定を表示する。
- 集約子でも設定値は表示/保存できる。
- `HH:MM` 以外の値は保存できない。
- 保存後に `wms_contractor_settings` の追加カラムが更新される。
- 既存の送信時刻・受信時刻の保存を壊さない。

### 4.2 コマンド dry-run

コマンド:

```bash
php artisan wms:generate-jx-order-documents --date=2026-07-15 --time=13:30 --dry-run
```

確認:

- 対象代表仕入先が表示される。
- 対象候補数、補正予定数、除外数が出る。
- DB更新、S3保存、伝票番号採番は発生しない。

### 4.3 コマンド通常実行

準備:

- JX未生成・FAX未生成の `CONFIRMED` 候補を親+子に複数作る。
- 入荷予定日が当日・過去・未来の3種類になるようにする。

確認:

- `wms_order_jx_documents.status = PENDING` が作られる。
- `wms_order_candidates.wms_order_jx_document_id` が紐づく。
- `wms_order_slip_number_assignments.status = ACTIVE` が作られる。
- `wms_order_incoming_schedules.slip_number` が入る。
- `transmitted_at` は入らない。
- JX送信ログは作られない。

### 4.4 冪等性

ケース:

- 同じ代表仕入先・同じ日で2回実行。
- 1回目成功後、2回目はスキップ。
- `--force` なしでは再生成しない。
- `FAILED` のrunだけ `--force` で再実行できる。

確認:

- JXドキュメントが重複しない。
- 伝票番号採番が重複しない。
- 候補の `wms_order_jx_document_id` が上書きされない。

### 4.5 FAX/JX生成済み除外

ケース:

- `wms_order_data_files.candidate_ids` に対象候補IDがある。
- `candidate_ids` がNULLの同一 `batch_code + warehouse_id + contractor_id + expected_arrival_date` がある。
- `wms_order_jx_document_id` が入っている。

期待:

- すべて自動生成対象外。

## 5. ローカルJXファイル検証

生成されたJXファイルを確認する。

確認項目:

- ファイルはS3 disk上の `jx-orders/YYYY-MM-DD/` に保存される。
- CSV確認ファイルも保存される。
- Bレコードの伝票番号が旧EOS互換11桁体系になっている。
- 伝票番号は `店舗CD2桁 + 年度コード2桁 + 10固定 + 店舗年度別連番5桁` になっている。
- 伝票番号の5・6桁目は `10` 固定であり、`00` 固定ではない。
- Bレコードは `contractor_id + warehouse_id + expected_arrival_date` で分かれる。
- Dレコードは1伝票6明細まで。
- 7明細目は新しい伝票番号になる。
- 入荷予定日補正後の納品日がBレコードに反映される。

## 6. マイグレーション検証

禁止:

- `migrate:fresh`
- `migrate:refresh`
- `migrate:reset`
- `db:wipe`

確認:

- `php artisan migrate:status`
- 通常の `php artisan migrate`
- 追加カラムがnullable/default付きで追加される。
- `wms_jx_order_generation_runs` のユニークキーが効く。
- 初期設定migrationが対象コードだけをupsertする。
- `wms_contractor_warehouse_delivery_days` の既存全削除がない。

## 7. HANA-STGデータとの差分検証

HANA-STG確認結果をもとに、migration適用後に以下を確認する。

| 対象 | 確認 |
| --- | --- |
| カナカン全対象 | 全対象コードで通常倉庫が月-土、22が火木金土 |
| 国分 | 通常倉庫が火-日、91が月-土、22が火木土 |
| 三菱 | 通常倉庫が月-土、22が月水金 |
| コカコーラ | 全倉庫が火木土 |
| JX生成設定 | JX親・子に初期時刻が入る |
| 非JX | 自動生成OFF |

## 8. 回帰テスト

最低限:

```bash
php artisan test tests/Unit/Services/AutoOrder/LegacyEosSlipNumberServiceTest.php
php artisan test tests/Unit/Services/AutoOrder/HanaOrderJXFileGeneratorLegacySlipNumberTest.php
php artisan test tests/Unit/Services/AutoOrder/OrderTransmissionServiceTest.php
php artisan test tests/Unit/Services/AutoOrder/HanaOrderFileGeneratorTest.php
```

追加作成予定:

- `JxOrderArrivalDateAdjustmentServiceTest`
- `GenerateJxOrderDocumentsCommandTest`
- `ContractorJxGenerationSettingFormTest`
- `JxDeliveryDayInitialSettingsMigrationTest` またはDB確認スクリプト

## 9. リリース前確認

- HANA-STGでmigrationを通常適用し、初期設定差分を確認する。
- スケジュール有効化前に `--dry-run` を実行する。
- 生成のみで送信されないことを確認する。
- JX送信画面にPENDINGとして出ることを確認する。
- 本番投入時はスケジュールを有効化するタイミングを運用と合わせる。
