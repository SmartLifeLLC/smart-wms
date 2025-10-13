# メニュー画面構成
WMSの各種メニューを先に作成する。

メインメニュー
入荷 / 出荷 / 在庫 / 棚卸し / 配送管理 / 


サブメニュー
入荷 : sakemaru db のpurchasesテーブルがメイン
- 入荷予定 (入荷予定が確認できる。入荷予定はsakemaru db のpurchasesテーブルのdelivered_dateが予定日になる。)
- 入荷検品（入荷予定データに対して、管理画面上で入荷の確定ができる。入荷の実績値入力と欠品、返品、分納があった場合の対応ができる。）
- 入荷履歴（入荷検品の履歴が確認できる。）
上記は日付別・仕入先別・商品別・倉庫別・伝票番号(trades.serial_id)で検索ができる。
また、入荷予定の伝票の出力機能がある。

出荷 : sakemaru db のearningsテーブルがメイン
- 出荷予定 (出荷予定が確認できる。出荷予定はsakemaru db のearningsテーブルのdelivered_date が予定日になる。)
- 出荷検品（出荷予定データに対して、管理画面上で出荷の確定ができる。出荷の実績値入力と欠品の対応ができる。）
- 出荷履歴（入荷検品の履歴が確認できる。）

在庫 : sakemaru db のreal_stocksテーブルがメイン
- 在庫確認
- ロケ移動
- 棚卸し

管理 :
- 入・出荷作業者管理
  - 新規wms_usersテーブルを sakemaru dbに生成が必要。migrationはかならずconnection='sakemaru'をいれる
    - 今後android Handy アプリによる入手か管理時のログインIDになる。
    - id, code ,name, password (暗号化) , default_warehouse_id(nullable), created_at, updated_at,
- wave管理
  - 波動ピッキング管理するもの。このシステムはwave pickingとオーダーピッキングを実装する。
  - オーダーピッキング：伝票別ピッキング
  - waveピッキングは出荷対象（ルート）についていピッキングの開始時刻と詰め込みし見切り時間を設定できる。開始時刻を過ぎるとピッキングリストに入れない。
　| 波動名    | 出荷対象            | ピッキング開始 | 積込締切  | 配送便  |
    | ------ | --------------- | ------- | ----- | ---- |
    | Wave A | AMルート（得意先1〜15）  | 7:00    | 8:30  | 午前便  |
    | Wave B | PMルート（得意先16〜30） | 11:00   | 12:30 | 午後便  |
    | Wave C | 特急・店舗受取         | 随時      |       | 店頭渡し |
　これらを管理できるようにするwms_wavesテーブルが必要

　次のテーブルの設計が必要
wms_waves
- id
- wave_no (例: 20251013-A01)
- warehouse_id
- start_time
- end_time
- route_code
- status (planned / picking / completed)

wms_wave_shipments
- wave_id
- shipment_id

wms_picking_tasks
- wave_id
- item_id
- location_id
- total_qty
- assigned_worker_id
  受注伝票A  ┐
  受注伝票B  ├── Wave A（AM便）──> トータルピッキング → 仕分け → 出荷
  受注伝票C  ┘

受注伝票D  ┐
受注伝票E  ├── Wave B（PM便）──> ピッキング → 出荷
受注伝票F  ┘


