# MODEL 変更
Models/Sakemaru に sakemaru database用のmodelがあるので以下のモデルはModels/Sakemaru を利用する。
[Item.php](../../app/Models/Item.php)
[Location.php](../../app/Models/Location.php)
[RealStock.php](../../app/Models/RealStock.php)
[StockAvailable.php](../../app/Models/StockAvailable.php)
[User.php](../../app/Models/User.php)
[Warehouse.php](../../app/Models/Warehouse.php)


また、該当ファイルは削除する。

wms_* テーブルは酒丸テーブルと構成が違うのでCustomModelを継承しない。






