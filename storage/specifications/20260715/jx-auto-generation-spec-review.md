# JX発注データ自動生成 仕様レビュー

作成日: 2026-07-15  
対象:

- `storage/specifications/20260715/jx-auto-generation-spec.md`
- `storage/specifications/20260715/jx-auto-generation-test-plan.md`

## レビュー結果

結論: 実装に進める仕様として概ね妥当。ただし、下記の指摘を実装時に反映すること。

## 指摘事項

### R1. 既存画面の「自動生成」ラベルが実態とずれている

`ContractorForm::transmissionSchema()` のヘッダーにある `wms_is_auto_transmission` は、DB上は `is_auto_transmission` へ保存される。これは送信自動化のフラグであり、今回のJXデータ生成自動化とは別物。

対応:

- 既存ラベルは「自動送信」に改める。
- 新規項目は「JXデータ自動生成」として別セクション化する。

### R2. `modified_at` を確定時刻として使う制約

現行のJX画面も確定日フィルタで `modified_at` を使っているが、入荷予定日変更などでも更新される。今回の締め時刻判定に使うと、厳密な「確定時刻」ではない。

対応:

- 初期実装は既存画面互換として `modified_at` を使う。
- 仕様書に制約を明記済み。
- 将来の改善として `confirmed_at` 追加を検討する。

### R3. データ更新migrationのrollback

倉庫別入荷可能曜日の初期設定は既存データ更新を伴う。`down()` で元値を完全復元するには事前バックアップが必要。

対応:

- migrationは対象コード・対象倉庫限定の `updateOrInsert` にする。
- 既存全削除は禁止。
- 適用前のSELECT結果を保存し、必要なら別rollback SQLを生成する。

### R4. 子仕入先のスケジュール設定と実行対象の扱い

カナカン子仕入先にも同じ設定を入れる要件があるが、実行まで子単位にすると重複生成になる。

対応:

- 設定値は親・子に持たせる。
- 自動生成コマンドは `transmission_contractor_id IS NULL OR transmission_contractor_id = contractor_id` の代表親だけ実行対象にする。
- 子候補は親代表の対象候補として集約する。

### R5. 古い未生成候補の扱い

HANA-STGには2026年5月以降の古いJX未生成候補が残っている。通常自動生成で拾うと想定外の大量生成になる。

対応:

- 通常自動生成は実行日当日の締め時刻までに限定する。
- 古い候補は手動生成または別の救済モードで扱う。

### R6. スケジュール有効化のタイミング

`routes/console.php` では多くのWMSスケジュールが停止中。新規スケジュールだけを有効化すると、運用意図とずれる可能性がある。

対応:

- コマンド実装とスケジュール定義は行う。
- 本番でのスケジュール有効化はリリース手順に明示する。
- 初回は `--dry-run` で対象件数を確認する。

## 実装前チェック

- [ ] 既存ラベル `自動生成` を `自動送信` に修正する。
- [ ] JX生成設定フィールドの保存・復元を `EditContractor` に追加する。
- [ ] `wms_contractor_settings` 追加カラムはnullable/default付きにする。
- [ ] run管理テーブルはユニークキー `(representative_contractor_id, target_date)` を持つ。
- [ ] 入荷予定日補正サービスは実行日当日を補正先にしない。
- [ ] 生成コマンドは送信しない。
- [ ] 伝票番号対応済みの `generateJxFilesForCandidateIds()` 経由に限定する。
- [ ] 初期設定migrationは対象コード限定で、既存全削除をしない。
