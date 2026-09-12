# 新規発注登録画面 仕様整理・開発方針

作成日: 2026-08-04
作業ブランチ: `codex/purchase-order-v2-planning`

## 目的

既存の外部発注フローは「候補生成 → 承認 → 発注確定 → 必要に応じてFAX出力 → 夜間JX生成/送信」という段階管理になっている。
新フローでは、発注登録画面で発注方法を選択し、候補検索または販売履歴検索から追加した明細を最終確認時点で直接発注確定まで進める。

同時に、FAX発注書は確定時に自動生成する。ただし、EOS発注として登録したデータはFAX発注書を作ってもJX/EOS連携対象から外れないようにする。

## 実装結果サマリー

- ブランチ: `codex/purchase-order-v2-planning`
- 新規メニュー名: `（新）外部発注`
- 新規画面: `app/Filament/Pages/WmsOrderRegistration.php`
- 既存 `外部発注` / `発注確定待ち` / `発注確定済み` / `JX発注データ作成` は並行運用する。
- ページ見出しは表示せず、上部操作エリアも標準入荷予定日を持たないコンパクト構成にする。
- 新画面では単一倉庫を選択し、複数倉庫混在はさせない。
- `EOS発注` はJX/EOS対象仕入先のみ候補検索・登録可能にする。
- `FAX発注` はJX/EOS対象仕入先も選択可能だが、JX生成対象から除外する。
- 候補検索/販売履歴検索で、発注先リードタイム + JX入荷予定日補正と同じ納品可能曜日/倉庫休日判定により、行ごとの最短入荷予定日を初期値として登録する。
- 入荷予定日は登録リストの明細ごとにユーザーが変更可能にする。
- 確定時は `WmsOrderCandidate` を作成後、既存 `OrderExecutionService::confirmCandidate()` を使って `CONFIRMED` と入荷予定作成まで同期実行する。
- 確定時にPDF用の `WmsOrderDataFile` を作成するが、CSVファイルは作らない。`file_path` は既存NOT NULL制約を維持するため空文字を入れる。
- EOS控えPDFは `fax_downloaded_at` を更新しない。ユーザーが再ダウンロードしても `markAsFaxDownloaded()` 側で更新をスキップする。
- JX夜間生成は、明示 `order_channel = EOS` をFAX/PDF生成有無に関係なく対象にし、明示 `order_channel = FAX` は対象外にする。既存NULLデータは従来のFAXデータファイル存在判定を維持する。
- 既存確定済みデータの補完用に `wms:backfill-order-channels --dry-run` を追加した。実更新は運用判断後に `--dry-run` なしで実行する。

## 参照済みドキュメント

- `storage/specifications/knowledges/filament4spec.md`
- `storage/specifications/old/2025-10-13-wms-specification.md`
- `storage/specifications/old/outbound/20251115-shorage-algorithm.md`
- `storage/specifications/old/table-design-specification.md`
- `/Users/jungsinyu/.claude/design-knowledge/modal-design.md`
- `/Users/jungsinyu/.claude/design-knowledge/mega-menu.md`
- `/Users/jungsinyu/.claude/design-knowledge/table-tabs.md`
- `/Users/jungsinyu/.claude/design-knowledge/page-scroll-control.md`
- `/Users/jungsinyu/.claude/design-knowledge/table-compact-design.md`

AGENTS.md の `.Codex/design-knowledge/...` はこの環境には存在せず、実在する `.claude/design-knowledge/...` 側を確認した。

## 現状の発注フロー

### 画面・メニュー

- `app/Enums/EMenu.php`
  - `WMS_ORDER_CANDIDATES`: 外部発注
  - `WMS_ORDER_CONFIRMATION_WAITING`: 発注確定待ち
  - `WMS_ORDER_CONFIRMED`: 発注確定済み
  - `WMS_ORDER_FOR_JX`: JX発注データ作成
  - `WMS_ORDER_DATA_FILES`: 発注データファイル
  - `WMS_ORDER_DOCUMENTS`: JX送信ファイル

新規発注登録画面は、既存の発注作業導線に近い `EMenuCategory::AUTO_ORDER` 配下へ追加するのが自然。
JX生成・送信結果は既存の `WMS_ORDER_FOR_JX` / `WMS_ORDER_DOCUMENTS` を継続利用する。

### 候補生成

- `app/Filament/Resources/WmsOrderCandidates/Pages/ListWmsOrderCandidates.php`
  - `発注追加`: 手入力の外部発注候補を `WmsOrderCandidate` に `PENDING` で作成する。
  - `外部発注候補生成`: 販売履歴・仕入先・倉庫などから外部発注候補をプレビューし、`PENDING` で作成/更新する。
  - `全件承認`: 現在ユーザーの `PENDING` 候補を `APPROVED` にする。
- `app/Filament/Resources/WmsOrderCandidates/Tables/WmsOrderCandidatesTable.php`
  - 明細編集、除外、選択承認を提供する。

現在の新規追加・候補生成ロジックはページクラス内に多く残っている。
新画面で再利用するには、販売履歴ベースの計算、商品検索、候補行組み立てをサービスへ切り出す必要がある。

### 発注確定

- `app/Filament/Resources/WmsOrderConfirmationWaiting/Pages/ListWmsOrderConfirmationWaiting.php`
  - `全件発注確定`: `ProcessOrderConfirmationJob` を dispatch する。
  - 選択確定系の処理も存在する。
- `app/Jobs/ProcessOrderConfirmationJob.php`
  - `APPROVED` 候補を対象に `OrderExecutionService::confirmBatch()` を呼ぶ。
- `app/Services/AutoOrder/OrderExecutionService.php`
  - `confirmCandidate()` が `APPROVED` / `CONFIRMED` 候補を `CONFIRMED` にし、`wms_order_incoming_schedules` を作成する。
  - 入荷予定の `order_source` は `OrderSource::AUTO`、伝票番号は `WmsOrderIncomingSchedule::generateSlipNumber()` で採番する。

現行の確定後データの実体は `wms_order_candidates.status = CONFIRMED` と、それに紐づく入荷予定。
新フローでもこの互換構造を維持するのが安全。

### FAX/発注データファイル

- `app/Services/AutoOrder/OrderDataFileService.php`
  - `generateCsvFilesForCandidates()` が確定済み候補を倉庫・仕入先・納品予定日などでまとめ、`WmsOrderDataFile` とCSVを作成する。
  - 倉庫単位で生成する場合、`PurchaseOrderPdfService::generateAndStoreFromCandidates()` でFAX用PDFも作成する。
  - 生成時点では `fax_downloaded_at` は更新しない。
- `app/Services/AutoOrder/PurchaseOrderPdfService.php`
  - FAX発注書PDFを生成する。
  - 現状は右上に発注日/発注番号を表示するが、EOS発注スタンプはない。
- `app/Filament/Resources/WmsOrderDataFiles/Tables/WmsOrderDataFilesTable.php`
  - FAXダウンロード時にPDFを再生成し、`markAsFaxDownloaded()` で `fax_downloaded_at` と `status=DOWNLOADED` を更新する。

### JX生成・送信

- `app/Console/Commands/AutoOrder/GenerateJxOrderDocumentsCommand.php`
  - 夜間/指定時刻でJX対象仕入先を拾い、対象候補からJXファイルを生成する。
  - `targetCandidates()` は `CONFIRMED`、未JX生成、数量あり、対象日時内を条件にする。
  - さらに `wms_order_data_files` が存在する候補を除外している。
- `app/Services/AutoOrder/OrderTransmissionService.php`
  - `generateJxFilesForCandidateIds()` がJX対象候補を検証し、JX文書を生成し、候補に `wms_order_jx_document_id` を紐づける。
  - 送信確認CSVとして `WmsOrderDataFile` も作成する。
- `app/Services/AutoOrder/Generators/HanaOrderJXFileGenerator.php`
  - JX対象仕入先コード判定、伝票番号割当、JXファイルレコード生成を行う。
- `app/Console/Commands/AutoOrder/TransmitJxOrderDocumentsCommand.php`
  - `PENDING` のJX文書を送信する。

## 現状の重要な問題

ユーザー要件では「FAX発行をしてもEOSデータはFAX発行済みにしない」「JXデータ送信時にはEOS発注対象として作ったものが対象」となっている。

しかし現行実装では、JX生成対象から外す判定が `fax_downloaded_at` だけではなく、`wms_order_data_files` の存在に依存している箇所がある。
新フローでEOS発注のFAX/PDFを自動作成すると、`WmsOrderDataFile` が作られた時点でJX生成対象から外れる可能性が高い。

したがって、新フローでは「FAXデータがあるか」ではなく「発注登録時にEOS発注として登録されたか」をJX対象判定の主条件にする必要がある。

## 新フロー案

1. 新規メニュー「発注登録」を開く。
2. 画面上部で以下を選択する。
   - 発注区分: `EOS発注` / `FAX発注`
   - 倉庫
3. `販売履歴より生成` の場合は、販売期間・仕入先・カテゴリなどを指定して候補を検索する。
4. `候補検索から生成` の場合は、商品/発注先をモーダルで検索し、数量・納品予定日などを指定してリストへ追加する。
5. 追加された候補は画面内の登録リストに保持し、数量・納品予定日・発注コード・メモを編集できる。
6. 確定ボタンで、登録リストの全明細を発注確定データとして作成する。
7. 確定と同一処理内で、入荷予定、発注データファイル、FAX PDFを作成する。
8. `EOS発注` として登録した明細は、FAX PDFを作ってもJX生成対象として残す。
9. `FAX発注` として登録した明細は、JX生成対象にしない。

## データ設計方針

既存の候補・確定・JX・入荷予定の仕組みを利用するため、`wms_order_candidates` は新フローでも作成する。
ただし、利用者に見せる `PENDING` / `APPROVED` の候補状態は作らず、確定処理内で直接 `CONFIRMED` まで進める。

追加候補:

- `wms_order_candidates.order_channel`
  - 値: `EOS`, `FAX`
  - JX生成対象判定の主条件。
- `wms_order_candidates.entry_source`
  - 値: `SALES_HISTORY`, `SEARCH`
  - 新画面で何を起点に追加したかを保持する。
- `wms_order_data_files.order_channel`
  - 値: `EOS`, `FAX`, `JX_CONFIRMATION`
  - FAX/PDF用データとJX確認CSVを区別する。
- `wms_order_data_files.show_eos_stamp`
  - PDF右上の `EOS発注` スタンプ表示用。

`wms_order_incoming_schedules` にも `order_channel` を追加し、確定時に候補からミラーする。

## サービス設計方針

### 新規サービス

`App\Services\AutoOrder\OrderRegistrationService`

責務:

- 新画面の登録リストを検証する。
- 候補データを `CONFIRMED` として作成する。
- 既存の `OrderExecutionService` 相当の入荷予定作成を実行する。
- `OrderDataFileService` を呼び、発注データファイル/FAX PDFを自動作成する。
- EOS/FAXの発注区分を候補・データファイルへ記録する。
- 確定処理をトランザクションでまとめる。

### 既存ロジックの切り出し

新画面向けに `App\Services\AutoOrder\OrderRegistrationSearchService` を追加する。

- 販売履歴ベースの候補計算
- 商品検索/発注先検索
- 手入力候補行の組み立て
- EOS/FAX別の仕入先絞り込み

既存画面の大規模なリファクタは今回のスコープ外とし、既存画面はそのまま残す。

### JX生成条件の変更

`GenerateJxOrderDocumentsCommand::targetCandidates()` と、画面側のJX対象表示条件を変更する。

推奨条件:

- 新フローのデータ: `order_channel = EOS` をJX対象にする。
- `order_channel = FAX` はJX対象外にする。
- 既存データ: `order_channel` がNULLの間は、従来条件を維持するか、初回リリース前にバックフィルする。
- 新フローで自動生成されたFAX/PDF用 `WmsOrderDataFile` は、EOS発注をJX対象外にしない。

## 画面設計方針

### 新規リソース/ページ

実装:

- `app/Filament/Pages/WmsOrderRegistration.php`
- `resources/views/filament/pages/wms-order-registration.blade.php`

メニュー:

- `EMenu::WMS_ORDER_REGISTRATION`
- ラベル: `（新）外部発注`
- カテゴリ: `EMenuCategory::AUTO_ORDER`
- ソート: `WMS_ORDER_CANDIDATES` より前、または直後
- アイコン: `heroicon-o-shopping-bag` または `heroicon-o-document-plus`

### UI構成

- 上部操作エリア
  - 発注区分のセグメント: `EOS発注` / `FAX発注`
  - 倉庫
  - 候補検索、販売履歴検索、確定ボタン
- 登録リスト
  - 発注先CD、発注先名、商品CD、商品名、入数、単位、発注数、総バラ数、入荷予定日、生成元
  - 入荷予定日は、商品/発注先/倉庫ごとの最短入荷予定日を初期値にして明細単位で編集可能
  - 商品名は `grow()`、コードと名前は別カラム
  - 操作列は `sticky-actions`
- モーダル
  - `incoming-detail-modal` を利用
  - 大量候補選択は `ViewField + Alpine.js` パターン
  - フッター右寄せ
  - 確定系ボタンは `danger`
  - キャンセルラベルは `発注せず閉じる` / `追加せず閉じる`

## FAX PDF仕様

- 確定時に自動生成する。
- `EOS発注` の場合は右上に `EOS発注` スタンプを表示する。
- `FAX発注` の場合はスタンプを表示しない。
- FAX PDFを生成しても、EOS発注のJX対象判定には影響させない。
- FAX発注PDFの手動ダウンロードは従来通り `fax_downloaded_at` を更新する。
- EOS控えPDFの手動ダウンロードは `fax_downloaded_at` を更新しない。

## 実装計画

### 1. DB項目追加

- 内容
  - `wms_order_candidates` に `order_channel`, `entry_source` を追加。
  - `wms_order_data_files` に `order_channel`, `show_eos_stamp` を追加。
  - 必要なら `wms_order_incoming_schedules` に `order_channel` を追加。
- 検証
  - `php artisan migrate:status`
  - 対象migrationの単体確認
  - `php artisan test --filter=...`
- ロールバック
  - 追加カラムのみを削除するdown migration。
- リスク
  - 中。JX対象判定に直結するため、NULL既存データの扱いを事前決定する必要がある。

### 2. 候補検索/生成ロジックのサービス化

- 内容
  - 現行ページ内ロジックをサービスへ切り出す。
  - 既存画面は切り出し後サービスを呼ぶだけにして、挙動を変えない。
- 検証
  - 外部発注候補生成の既存画面でプレビュー件数と作成結果を比較。
  - 関連テストがあれば追加/更新。
- ロールバック
  - ページクラスの呼び出しを元のロジックへ戻せるよう小さく分割する。
- リスク
  - 中。既存候補生成の副作用が多いため、先に振る舞い固定のテストが必要。

### 3. 新規発注登録画面の追加

- 内容
  - `発注登録` メニューを追加。
  - 画面上部に倉庫/発注区分/検索ボタン/確定ボタンを配置。
  - 候補検索モーダルと登録リストを実装。
- 検証
  - Filament画面表示。
  - モーダル検索、追加、数量編集、削除。
  - モバイル/通常幅でテキストはみ出しなし。
- ロールバック
  - 新規メニュー/Resourceを無効化すれば既存画面へ戻せる。
- リスク
  - 中。Livewire state と候補リスト編集の整合性に注意。

### 4. 直接確定サービスの実装

- 内容
  - 登録リストから `CONFIRMED` 候補と入荷予定を作成。
  - 発注データファイル/FAX PDFを自動生成。
  - すべてトランザクション化し、失敗時はロールバック。
- 検証
  - EOS/FAXそれぞれで候補、入荷予定、データファイル、PDFが期待通り作成されること。
  - 数量0、重複、納品予定日未入力、仕入先未設定のバリデーション。
- ロールバック
  - 新サービス経由の作成データを対象に削除できるよう `entry_source` / `order_channel` / `batch_code` で追跡可能にする。
- リスク
  - 高。確定済みデータ・入荷予定を作るため、DBトランザクションと冪等性が必要。

### 5. JX生成条件の変更

- 内容
  - EOS発注は、FAX/PDF用 `WmsOrderDataFile` があってもJX対象にする。
  - FAX発注はJX対象外にする。
  - 既存データのNULL扱いを決め、画面表示・夜間コマンドを揃える。
- 検証
  - `GenerateJxOrderDocumentsCommand` の対象抽出テスト。
  - `WMS_ORDER_FOR_JX` 画面の対象表示確認。
  - JX生成後に `wms_order_jx_document_id` が候補へ紐づくこと。
- ロールバック
  - 条件を従来の `wms_order_data_files` 存在判定へ戻せるよう差分を限定する。
- リスク
  - 高。誤るとEOS送信漏れまたはFAX発注の誤送信が起きる。

### 6. PDFスタンプ追加

- 内容
  - `PurchaseOrderPdfService` に `EOS発注` スタンプ描画を追加。
  - `WmsOrderDataFile` または候補の `order_channel` から表示判定する。
- 検証
  - EOS発注PDFのみ右上にスタンプ表示。
  - FAX発注PDFには表示なし。
  - 既存PDFレイアウト崩れなし。
- ロールバック
  - スタンプ描画ブロックを外す。
- リスク
  - 低。PDFレイアウトのみ。ただし業務上の誤認防止のため表示条件は厳密にする。

## 確認が必要な質問

1. 新画面の正式メニュー名は `発注登録` でよいか。
=>（ 新）外部発注　にする。
2. 新画面は既存の `外部発注` / `発注確定待ち` と並行運用するか、将来的に置き換えるか。
＝＞　並行運用。既存システムの発注データ生成とJX送信で一緒に送信できるようにする必要がある。。既存システムのEOS送信対象は1. 商品が（発注先が）EOS対象であるもの。2. FAX生成されてないものの条件になるので、ここに寄せる必要がある。EOSかFAXかの追加はするが、JX送信時には旧システムとの互換性が必要。また、今回はの新システムは発注確定データを生成する部分までになるが、その際にFAXを自動生成するのが必要。ここの整合性必要。EOSがFAX生成済みになってしまうと非常にまずい。
3. `EOS発注` は「JX連携対象仕入先のみ選択可能」にするか、非対応仕入先も選べるが確定時にエラーにするか。
=> 発注候補登録時に登録がそもそもできないようにしたい。
4. `FAX発注` でもJX対応仕入先を選べるようにするか。
これは可能。
5. `EOS発注` で自動生成するFAX PDFは、実際にFAX送信/ダウンロードする業務用途なのか、控え・確認用なのか。
=> 控え・確認用。ここが誤ってJX送信対象外にすることを必ず防ぐ必要がある。
6. `EOS発注` スタンプはPDFだけでよいか。画面の確定済み一覧・発注データファイル一覧にもバッジ表示が必要か。
=> まずはPDFだけでよい。
7. 確定時に自動生成するFAX PDFは、`fax_downloaded_at` を更新しない方針でよいか。
更新しない方針でよい。ユーザが再ダウンロードした時にも更新しない方針がよい。
8. `WmsOrderDataFile` のCSVも確定時に必ず作成するか、PDFのみ自動作成でCSVは任意か。
CSVは作成しない。
9. `販売履歴より生成` の計算条件は、現行の `外部発注候補生成` と完全同一でよいか。
同一でよい。
10. `候補検索から生成` は商品検索ベースか、既存候補検索ベースか。検索対象に `PENDING/APPROVED` の既存候補を含めるか。
商品検索ベース。また、既存候補は含めない。

11. 同一商品・同一仕入先・同一納品予定日の重複追加は、数量加算にするか、別明細として許可するか。
別明細を許可する。

12. 確定後の修正・取消は既存の発注確定済み画面で行うか、新画面にも編集/取消導線が必要か。
発注確定済みで実施する。発注確定済みで取り消した場合、取り消しの履歴は残すか完全にキャンセル状態にする。つまり、発注候補でキャンセルした時とおなじ。

13. 既存の `CONFIRMED` データに `order_channel` をバックフィルするか。バックフィルする場合、判定基準は現行のJX対象仕入先/発注データファイル有無でよいか。
それでよい。


14. 夜間JX生成は「EOS発注として登録されたものだけ」に完全移行するか、既存フロー由来のNULLデータも当面対象に残すか。
ここは既存機能をそのまま利用する。

15. EOS発注でJX生成済みになった後、FAX PDFの再生成・メール送信を許可するか。
許可する。

16. 発注確定時に複数仕入先/複数倉庫が混在した場合、1回の確定操作で複数の発注データファイルに分割してよいか。
分割でよい。また、複数倉庫を混在することをしない。UI側でもないようにする。


17. 新フローの確定処理は同期処理でよいか、件数が多い場合はジョブ化して進捗表示が必要か。
同期処理でよい。



## 現時点の推奨方針

- 既存の `wms_order_candidates` / `wms_order_incoming_schedules` / `wms_order_data_files` / `wms_order_jx_documents` は維持する。
- 新画面では候補状態を利用者に見せず、確定時に直接 `CONFIRMED` を作る。
- JX対象は `wms_order_data_files` の有無ではなく、明示的な `order_channel = EOS` で判定する。
- EOS発注のFAX PDFは自動生成するが、JX対象除外フラグとして扱わない。
- 既存画面は初期リリースでは残し、新画面の運用安定後に置き換え判断を行う。

## 実装後の確認結果

- `php -l` 対象変更PHPファイル: OK
- `./vendor/bin/pint --test` 対象変更PHPファイル: OK
- `php artisan view:cache && php artisan view:clear`: OK
- `php artisan route:list --path=wms-order-registration`: `admin/wms-order-registration` を確認
- `php artisan test tests/Unit/Services/AutoOrder/OrderDataFileServiceTest.php tests/Unit/Services/AutoOrder/OrderExecutionServiceTest.php`: 9 tests / 24 assertions OK
- `php artisan migrate:status`: `2026_08_04_000000_add_order_registration_channels` は未適用

## リリース時の実行順序

1. `php artisan migrate`
2. `php artisan wms:backfill-order-channels --dry-run`
3. dry-run結果確認後、必要な場合のみ `php artisan wms:backfill-order-channels`
4. 新画面でEOS/FAXそれぞれ1件ずつ登録し、PDF・入荷予定・JX対象表示を確認する。
