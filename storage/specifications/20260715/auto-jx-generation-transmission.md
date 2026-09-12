# JX発注データ自動生成・一括送信 仕様整理

調査日時: 2026-07-15  
作業ブランチ: `codex/auto-jx-generation-transmission`  
ベース: `release/v1.0` (`c537d170 Stop WMS daily report auto recalculation`)

## 目的

現在は以下の手作業で発注送信している。

1. `admin/wms-order-for-jx` でJX対象仕入先ごとに発注候補を選択し、JXデータを生成する。
2. `admin/wms-order-documents` で生成済みJXデータを選択し、JX送信する。

これを段階的に自動化する。

1. まずJXデータ生成を自動化する。
2. 次にJX送信画面へ「対象JXファイルを順番に送信する」ボタンを追加する。
3. 将来、送信もスケジュール化する。

重複送信、再送信、送信漏れは業務上致命的なので、JX生成と送信は冪等性、短いDBロック、監査ログ、明示的な対象判定を必須にする。

## 現行のJX生成画面

対象画面: `admin/wms-order-for-jx`

実装:

- `app/Filament/Resources/WmsOrderForJx/WmsOrderForJxResource.php`
- `app/Filament/Resources/WmsOrderForJx/Pages/ListWmsOrderForJx.php`
- `app/Filament/Resources/WmsOrderConfirmed/Tables/WmsOrderConfirmedTable.php`
- `app/Services/AutoOrder/OrderTransmissionService.php`

現行の一覧条件:

- ベースモデルは `wms_order_candidates`。
- Resourceのベースクエリは `status IN (CONFIRMED, EXECUTED)`。
- テーブル初期フィルタは以下。
  - ステータス: `CONFIRMED`
  - 確定日: 前日から当日
  - JX生成: 未生成 (`wms_order_jx_document_id IS NULL`)
  - FAX生成: 未生成 (`wms_order_data_files` に該当候補がない)
  - 確定者: JX画面では初期指定なし
- 代表タブは `wms_contractor_settings.transmission_type = JX_FINET` のうち、`transmission_contractor_id` がNULLまたは自身の仕入先を親として作る。
- 子仕入先は `transmission_contractor_id = 親contractor_id` で同じタブにまとめる。

JX生成処理:

- 画面の一括アクション `bulkGenerateJxFiles` が選択IDを `OrderTransmissionService::generateJxFilesForCandidateIds()` に渡す。
- サービス側で改めて `status = CONFIRMED` かつ `wms_order_jx_document_id IS NULL` の候補だけを取得する。
- `HanaOrderJXFileGenerator` / `Hana2` でJXファイルを生成する。
- `wms_order_jx_documents` に `status = PENDING` で保存する。
- 対象候補に `wms_order_jx_document_id` を設定する。

注意:

- 生成時の自動入荷予定日調整は現状見つからない。
- 画面には手動の「入荷予定日変更」があり、候補と未入荷予定の `wms_order_incoming_schedules` を更新し、送信前JXドキュメントがあれば取消に戻す。
- 候補生成時の入荷予定日計算では、`wms_contractor_warehouse_delivery_days` と倉庫休日を考慮している。

## 現行のJX送信画面

対象画面: `admin/wms-order-documents`

実装:

- `app/Filament/Resources/WmsOrderDocuments/Pages/ListWmsOrderDocuments.php`
- `app/Filament/Resources/WmsOrderDocuments/Tables/WmsOrderDocumentsTable.php`
- `app/Services/AutoOrder/OrderTransmissionService.php`
- `app/Services/JX/JxClient.php`

現行挙動:

- 既定タブは `wms_order_jx_documents.status = PENDING`。
- 選択一括送信は `OrderTransmissionService::transmitSelectedDocuments()` を呼ぶ。
- 対象は選択行のうち `PENDING` のみ。
- 送信は `foreach` で順番に実行される。
- 成功時:
  - `wms_order_jx_documents.status = TRANSMITTED`
  - `transmitted_at`, `transmitted_by`, `jx_message_id`, `jx_response_data` を更新
  - 紐づく `wms_order_candidates.status = EXECUTED`
  - 紐づく候補の `transmitted_at` を更新
- 失敗時:
  - `wms_order_jx_documents.status = ERROR`
  - `error_message`, `jx_message_id`, `jx_response_data` を更新
- 送信前取消:
  - `cancelPendingJxDocumentAndRestoreCandidates()` で `PENDING` のみ取消可能。
  - 候補の `wms_order_jx_document_id` をNULLに戻し、ドキュメントは `CANCELLED` にする。

現行の問題:

- HTTP送信中の排他状態がない。
- `PENDING` のまま別リクエストから同じドキュメントを送れる余地がある。
- `SENDING` のような中間状態がない。
- 送信対象の絞り込みが `PENDING` 中心で、JX設定が有効かどうかの強制条件が弱い。
- 本番では `PENDING` の中にJX対象外ドキュメントが残っているため、全件送信ボタンで単純に `PENDING` 全件を送るのは危険。

## 既存の自動送信

実装:

- `app/Console/Commands/AutoOrder/AutoOrderTransmitCommand.php`
- `app/Jobs/ProcessAutoSendJob.php`
- `routes/console.php`

現状:

- `wms:auto-order-transmit` は存在する。
- `routes/console.php` ではスケジュールがコメントアウトされている。
- このジョブは `PENDING/APPROVED` 候補の承認、確定、JX生成、JX送信まで一気通貫で実行する。
- 今回の要件は「まず生成だけ自動化」「送信は画面ボタンで順次」なので、この既存ジョブをそのまま再利用しない。

## 入荷可能曜日設定

設定テーブル:

- `wms_contractor_warehouse_delivery_days`
- 仕入先×倉庫ごとに `delivery_mon` から `delivery_sun` を持つ。
- モデル: `App\Models\WmsContractorWarehouseDeliveryDay`
- Oracle「M3仕入先店舗納品曜日」からの同期先。

候補生成時の計算:

- `OrderCandidateCalculationService::calculateArrivalDate()`
- `SalesBasedOrderCandidateService::calculateArrivalDate()`
- リードタイム加算後、仕入先×倉庫の納品可能曜日へ最大14日先まで送る。
- その後、倉庫休日も最大14日先まで送る。

今回追加する生成前調整:

- JX生成実行日を `D` とする。
- `expected_arrival_date <= D` の候補だけを対象にする。
- 候補ごとに `contractor_id + warehouse_id` の入荷可能曜日を確認し、`D` 以降で最初に入荷可能な日へ補正する。
- 倉庫休日も既存ロジックと同様に避ける。
- 調整後は候補の `expected_arrival_date` と未入荷予定の `wms_order_incoming_schedules.expected_arrival_date` を同じ値へ更新する。
- 既にJX生成済みの候補は自動調整対象外にする。

## 本番DB状態

取得方法:

- 読み取り専用SELECT。
- 出力先: `~/.local/share/sakemaru-survey/work/20260715-auto-jx-generation-transmission/prod_jx_state.json`
- DB時刻: `2026-07-15 12:23:51`
- DBタイムゾーン: `Asia/Tokyo`

JX代表グループ:

| 親CD | 親仕入先 | 子仕入先 | JX設定 | 送信文書 |
| --- | --- | --- | --- | --- |
| 1017 | 北陸コカ・コーラ | なし | active / hana2 | 91 |
| 1106 | カナカン食品 福井営業所 | 1021, 1029, 1068, 1126, 1127, 1680 | active / hana | 91 |
| 1202 | 国分中部 第二支社 福井支店 | なし | active / hana | 91 |
| 1330 | 三菱食品 | なし | active / hana | 91 |

本番設定上の自動送信設定:

- 上記4グループは `is_auto_transmission = true`。
- `transmission_time = 12:00`。
- 送信曜日設定は全て `日月火水木金土`。
- 現場運用の「13:30生成」とDB設定の `12:00` は不一致。自動生成時刻は運用に合わせて別設定にするか、既存 `transmission_time` を使うかを決める必要がある。

入荷可能曜日の実データ:

- コカコーラ(1017): 32倉庫すべて `火木土`。
- カナカン食品(1106): 12倉庫で複数パターン。
  - 本店/光陽店/プラザ店: `月水金`
  - 二の宮/サンドーム前/敦賀/越前/江守: `月火水木金土`
  - 坂井/ヴィオ: `火木土`
  - 小浜: `火木金土`
  - 華むすびの蔵センター: `火水木金土`
- カナカン酒類(1021): 多くは `月火水木金土`、小浜のみ `火木金土`。
- カナカン菓子(1127): 32倉庫すべて `火木土`。
- カナカン日配(1126): 多くは `火金`。
- 国分(1202): 多くは `日火水木金土`、小浜は `火木土`、華むすびの蔵センターは `月火水木金土`。
- 三菱食品(1330): 多くは `月火水木金土`、小浜は `月水金`、営業部石川(金沢)は `日火水木金土`。

`wms-order-for-jx` 初期条件相当の未生成候補数:

| 親CD | 件数 | 倉庫数 | 入荷予定日数 | 入荷予定日 |
| --- | ---: | ---: | ---: | --- |
| 1017 | 12 | 7 | 1 | 2026-07-16 |
| 1106 | 263 | 12 | 2 | 2026-07-16 から 2026-07-17 |
| 1202 | 73 | 11 | 2 | 2026-07-15 から 2026-07-16 |
| 1330 | 97 | 11 | 3 | 2026-07-15 から 2026-07-17 |

JXドキュメント状態:

- 2026-07-15作成分:
  - `PENDING`: 16件 / 発注111件 / レコード187件
  - `TRANSMITTED`: 3件 / 発注26件 / レコード41件
- 直近14日:
  - `CANCELLED`: 2件 / 発注147件
  - `PENDING`: 37件 / 発注198件
  - `TRANSMITTED`: 402件 / 発注7593件
- 直近14日のJX通信ログ:
  - `send / PutDocument / success`: 419件

全PENDING分類:

- 有効なJX設定に紐づくPENDING: 19件 / 発注114件
- JX対象外PENDING: 66件 / 発注300件

このため、送信画面の「全対象送信」は `PENDING` 全件ではなく、必ず有効JX設定に紐づくドキュメントだけを対象にする。

## ローカルJX受信テストサーバ

実装:

- `routes/web.php`
- `app/Http/Controllers/JxServerController.php`
- `app/Services/JX/JxClient.php`
- `tests/Feature/JX/JxServerTest.php`

ローカル環境:

- `app()->environment()` は `local`。
- `/jx-server` ルートは登録されている。
- ローカルDBの有効JX設定は4件。
- 初回調査時点では、endpoint URL が `/jx-server` を向いている設定は0件。
- 2026-07-15 追記: ローカルDB `hana_local` の有効JX設定4件を `https://wms.sakemaru.test/jx-server` へ変更済み。`ssl_certification_file` はローカル送信に不要なためNULLにした。
- 2026-07-15 追記: 上記4件のBasic認証も、ローカルJX受信サーバ `config('jx.server.*')` の設定値に更新済み。認証値そのものは仕様書・ログへ出力しない。

既存テスト結果:

- `php artisan test tests/Feature/JX/JxServerTest.php`
- 3件成功、1件失敗。
- 失敗理由: `JxServerController` は受信XMLを `Storage::disk('s3')` に保存するが、テストは `Storage::fake('local')` して `local` を確認している。

ローカル送信疎通結果:

- 実施日時: 2026-07-15 12:39 JST
- 実行内容: `wms:generate-jx-test-files --pattern=empty --transmit` を有効JX設定4件に対して実行。
- 4件すべて `wms_jx_transmission_logs` に `direction = send`, `operation_type = PutDocument`, `status = success`, `http_code = 200` で記録された。
- ただし、既存実装のデフォルトにより、ローカル送信でも `wms_jx_transmission_logs.environment` は `production` と記録されている。疎通結果としては成功だが、テストログ識別のため改善対象にする。
- Message ID:
  - setting 1: `put_6a5700fd095bf_20260715123941@lw-hana.co.jp`
  - setting 2: `put_6a570108380e3_20260715123952@lw-hana.co.jp`
  - setting 3: `put_6a57010935d28_20260715123953@lw-hana.co.jp`
  - setting 4: `put_6a57010a3581c_20260715123954@lw-hana.co.jp`
- 受信側 `Storage::disk('s3')` で、上記4件の `jx-server/received/2026-07-15/*_request.xml`、`jx-server/documents/2026-07-15/91_{message_id}.txt`、`jx-server/pending/91_{message_id}.txt` を確認済み。

現時点の判定:

- `/jx-server` の受信処理自体は実装されている。
- ローカルDBの送信先とBasic認証は `/jx-server` 向けに変更済み。
- コマンド経由のローカルPutDocument疎通は4設定すべて成功した。
- ただし、既存Featureテストは保存先ディスク不一致で1件失敗しており、E2Eテストの整備はまだ必要。
- E2E可能にするには、以下が必要。
  - 受信サーバの保存ディスクをテスト可能にする、またはテスト側を `s3` fake に修正する。
  - 本番JX設定を誤ってローカルテストに使わないガードを入れる。

## 実装方針

### 1. 前提コミット

このブランチは `release/v1.0` から作成されており、2026-07-15に伝票番号修正コミット `66f0602f Restore legacy EOS slip numbering for JX orders` を取り込み済み。

JX自動生成は、取り込み済みの伝票番号生成ロジックを通すため、既存画面と同じ `OrderTransmissionService::generateJxFilesForCandidateIds()` 経由に限定する。

伝票番号は旧EOS互換11桁とし、形式は `店舗CD2桁 + 年度コード2桁 + 10固定 + 店舗年度別連番5桁` とする。5・6桁目は `10` 固定であり、`00` 固定ではない。

### 2. JX自動生成

新規コマンド案:

- `php artisan wms:generate-jx-orders`
- 本番スケジュールは運用時刻に合わせて `13:30` を候補にする。
- 初期実装では送信しない。生成だけ行い、結果を `wms_order_jx_documents.status = PENDING` にする。

対象:

- JX代表親4グループ。
- `wms_contractor_settings.transmission_type = JX_FINET`
- 親: `transmission_contractor_id IS NULL OR transmission_contractor_id = contractor_id`
- 子: `transmission_contractor_id = 親contractor_id`
- 候補: `status = CONFIRMED`
- 候補: `wms_order_jx_document_id IS NULL`
- 候補: `order_quantity > 0`
- 候補: FAX/MAIL/CSV生成済みは除外するかどうかを現行画面と同じにする場合、`wms_order_data_files` 未生成を条件にする。
- 確定日範囲は現行画面に合わせるなら前日から当日。ただし自動生成では送信漏れ防止のため、未生成の古いCONFIRMEDをどう扱うか明示する必要がある。

生成単位:

- 代表仕入先単位。
- Generator側は `contractor_id + warehouse_id + expected_arrival_date` でBレコードを分割する。
- カナカンは親1106に子仕入先を集約する。

入荷予定日調整:

- JX生成前に、対象候補のうち `expected_arrival_date <= 実行日` を補正する。
- 補正日は `contractor_id + warehouse_id` の入荷可能曜日と倉庫休日から算出する。
- 更新は短いトランザクションで行う。
- `wms_order_incoming_schedules` が未入荷状態なら同じ日付へ更新する。
- 既にPENDING/TRANSMITTEDのJXドキュメントに紐づく候補は対象外。

冪等性:

- 新規実行管理テーブル案: `wms_jx_generation_runs`
  - `run_date`
  - `representative_contractor_id`
  - `status` (`RUNNING`, `SUCCESS`, `FAILED`, `CANCELLED`)
  - `started_at`, `finished_at`
  - `candidate_count`, `document_count`
  - `created_by`, `created_by_name`
  - `error_message`
  - unique key: `(run_date, representative_contractor_id)`
- 実行開始時にrun行を作る。既に `RUNNING` または `SUCCESS` がある場合は二重実行しない。
- 実際の候補紐付けは `WHERE wms_order_jx_document_id IS NULL` を付けた更新にする。
- run行のロックは短くし、JXファイル生成・S3保存中に多数候補を長時間ロックしない。

### 3. JX一括送信ボタン

追加先:

- `admin/wms-order-documents`
- Header actionまたはToolbar action。

名称案:

- `対象JXを順番に送信`

対象条件:

- `wms_order_jx_documents.status = PENDING`
- `document_type = PURCHASE`
- `wms_order_jx_setting_id` が有効な `wms_order_jx_settings` を指す。
- または `contractor_id` から有効なJX設定が解決できる。
- `file_path` が存在する。
- `order_count > 0` の通常JXを対象にする。空ファイル送信が必要な場合は別途明示する。
- `not_jx_target` は対象外。

送信順:

- `created_at ASC`, `id ASC`。
- 並列送信しない。

排他:

- `TransmissionDocumentStatus` に `SENDING` を追加する。
- 送信前に短いトランザクションで対象ドキュメントを `PENDING -> SENDING` に更新する。
- 更新条件は `WHERE id = ? AND status = PENDING`。
- 更新件数が0なら他処理が取得済みとみなしてスキップする。
- HTTP通信はトランザクション外で行う。
- 成功/失敗後に短いトランザクションで `TRANSMITTED` または `ERROR` に更新する。

送信後更新:

- 成功時は既存どおり、ドキュメントを `TRANSMITTED`、候補を `EXECUTED` にする。
- 候補更新は `wms_order_jx_document_id = document_id AND status = CONFIRMED` を条件にする。
- 送信ログ `wms_jx_transmission_logs` の `message_id` とドキュメントIDの紐づきを監査可能にする。

失敗時:

- HTTP失敗または例外は `ERROR`。
- `jx_message_id` が発番済みなら保存する。
- 明示的な再送は既存の再送信操作だけに限定する。
- `ERROR -> PENDING` に戻す操作は管理者確認付きにする。

中断時:

- `SENDING` のまま一定時間を超えたものは、手動で `PENDING` または `ERROR` に戻す管理者操作を用意する。
- 自動復旧は、実際に相手先へ送信された可能性が判定できないため初期実装では避ける。

### 4. JX対象外の完了処理

現行:

- 非JX向けの発注データは `wms_order_data_files` でCSV/FAX/メールとして生成される。
- CSV/FAXダウンロード時は `status = DOWNLOADED` になる。
- メール送信時は `mail_sent_at` が入る。
- この処理だけでは `wms_order_candidates.status = EXECUTED` にする明確な完了処理は見つからない。

方針:

- JX一括送信ボタンでは非JXを処理しない。
- 非JX完了は別アクションまたは別コマンドに分ける。
- 完了条件は送信方式ごとに分ける。
  - `MANUAL_CSV`: CSVダウンロード済みをもって完了にするか、管理者の明示完了ボタンにするか決定。
  - メール対象: `mail_sent_at IS NOT NULL` を完了条件にできる。
  - FAX対象: `fax_downloaded_at IS NOT NULL` を完了条件にできる。
- 完了時は候補を `EXECUTED` にするが、送信ログとは分けて監査ログを残す。
- 非JX完了対象をJX送信対象へ混入させない。

## ロックとデッドロック方針

避けること:

- 候補大量行をロックしたままS3保存やHTTP送信を行う。
- `PENDING` 全件を1トランザクションで送る。
- 送信対象を画面の表示状態だけに依存する。

採用すること:

- 実行run行またはドキュメント単位の短い排他取得。
- HTTP通信前に `PENDING -> SENDING` だけをコミット。
- HTTP通信後に結果更新だけを短くコミット。
- 候補更新は主キーまたは `wms_order_jx_document_id` で対象限定。
- 失敗時は再実行できるが、自動再送はしない。

## レビュー結果

コード状態:

- ブランチは作成済み。
- 前回の伝票番号修正は `66f0602f` として取り込み済み。
- 取り込みはfast-forwardで完了し、merge conflictは発生していない。
- 今後のJX自動生成実装では `app/Services/AutoOrder/OrderTransmissionService.php`、`app/Services/AutoOrder/Generators/HanaOrderJXFileGenerator.php`、`app/Filament/Resources/WmsOrderConfirmed/Tables/WmsOrderConfirmedTable.php` の伝票番号処理を壊さないようにする。
- 既存の `wms:auto-order-transmit` は要件に対して粒度が大きすぎるため、そのまま再開しない。
- `transmitSelectedDocuments()` は順次送信だが、送信中排他がない。
- `JxServerTest` は保存先ディスク不一致で1件失敗する。

本番DB状態:

- JX代表グループは4つ。
- 入荷可能曜日は仕入先×倉庫で実データあり。
- `PENDING` のうちJX対象外が66件残っているため、送信対象判定の厳密化は必須。
- JX通信ログは直近14日でPutDocument成功419件。失敗ログは今回の集計条件では見つからない。

仕様上の未決事項:

- 自動生成時刻を運用の13:30固定にするか、DBの `transmission_time` を使うか。
- 生成対象の確定日範囲を「前日から当日」に固定するか、古い未生成も拾うか。
- JX空ファイルを自動生成・送信対象に含めるか。
- 非JXの完了条件を、CSV/FAXダウンロード・メール送信・管理者完了のどれにするか。
- `SENDING` 追加に伴う既存画面表示、フィルタ、復旧操作のUI。

## 次の実装順

1. ローカルJX受信テストを `s3` fake または設定可能ディスクへ修正する。
2. JX自動生成コマンドを追加し、生成前入荷予定日調整とrun管理を実装する。
3. `SENDING` ステータスと送信排他を追加する。
4. JX一括送信ボタンを実装し、対象を有効JX設定つきPENDINGに限定する。
5. 非JX完了処理をJX送信とは別に実装する。
