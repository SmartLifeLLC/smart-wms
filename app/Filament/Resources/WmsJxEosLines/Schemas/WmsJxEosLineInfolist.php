<?php

namespace App\Filament\Resources\WmsJxEosLines\Schemas;

use App\Models\WmsJxEosImportBatch;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WmsJxEosLineInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('取込情報')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('importBatch.status')
                                    ->label('取込状態')
                                    ->badge()
                                    ->formatStateUsing(fn (?string $state): string => $state ? (WmsJxEosImportBatch::statusLabels()[$state] ?? $state) : '-')
                                    ->color(fn (?string $state): string => match ($state) {
                                        WmsJxEosImportBatch::STATUS_SUCCEEDED => 'success',
                                        WmsJxEosImportBatch::STATUS_FAILED => 'danger',
                                        WmsJxEosImportBatch::STATUS_IMPORTING => 'warning',
                                        WmsJxEosImportBatch::STATUS_SUPERSEDED => 'gray',
                                        default => 'gray',
                                    }),
                                TextEntry::make('importBatch.import_version')
                                    ->label('取込版')
                                    ->state(fn ($record): string => 'v'.($record->importBatch?->import_version ?? '-').($record->importBatch?->is_current ? ' 現行' : '')),
                                TextEntry::make('importBatch.imported_at')
                                    ->label('取込日時')
                                    ->dateTime('Y-m-d H:i:s')
                                    ->placeholder('-'),
                                TextEntry::make('importBatch.finet_code')
                                    ->label('FINET')
                                    ->badge()
                                    ->placeholder('-'),
                            ]),
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('wms_jx_transmission_log_id')
                                    ->label('JXログID'),
                                TextEntry::make('importBatch.source_message_id')
                                    ->label('メッセージID')
                                    ->copyable()
                                    ->placeholder('-'),
                                TextEntry::make('importBatch.detected_contractor_code')
                                    ->label('JX送信先コード')
                                    ->placeholder('-'),
                                TextEntry::make('importBatch.detectedContractor.name')
                                    ->label('JX送信先')
                                    ->placeholder('-'),
                            ]),
                        TextEntry::make('importBatch.source_file_path')
                            ->label('原本ファイル')
                            ->copyable()
                            ->columnSpanFull()
                            ->placeholder('-'),
                        TextEntry::make('importBatch.file_sha256')
                            ->label('原本SHA256')
                            ->copyable()
                            ->columnSpanFull()
                            ->placeholder('-'),
                    ]),
                Section::make('伝票ヘッダー')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('slip.slip_number')
                                    ->label('伝票番号')
                                    ->copyable()
                                    ->placeholder('-'),
                                TextEntry::make('slip.order_number')
                                    ->label('発注番号')
                                    ->copyable()
                                    ->placeholder('-'),
                                TextEntry::make('slip.order_type_label')
                                    ->label('発注区分')
                                    ->placeholder('-'),
                                TextEntry::make('slip.order_type')
                                    ->label('発注区分CD')
                                    ->placeholder('-'),
                                TextEntry::make('slip.maker_direct_delivery_type')
                                    ->label('メーカー直送区分')
                                    ->placeholder('-'),
                            ]),
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('slip.order_date')
                                    ->label('発注日')
                                    ->date('Y-m-d')
                                    ->placeholder('-'),
                                TextEntry::make('slip.delivery_date')
                                    ->label('納品日')
                                    ->date('Y-m-d')
                                    ->placeholder('-'),
                                TextEntry::make('slip.warehouse_code')
                                    ->label('倉庫コード')
                                    ->placeholder('-'),
                                TextEntry::make('slip.warehouse_name')
                                    ->label('倉庫名')
                                    ->placeholder('-'),
                            ]),
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('slip.shop_code')
                                    ->label('社・店コード')
                                    ->placeholder('-'),
                                TextEntry::make('slip.category_code')
                                    ->label('分類コード')
                                    ->placeholder('-'),
                                TextEntry::make('slip.contractor_code')
                                    ->label('取引先コード')
                                    ->placeholder('-'),
                                TextEntry::make('slip.slip_type_label')
                                    ->label('伝票区分')
                                    ->placeholder('-'),
                            ]),
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('slip.slip_type')
                                    ->label('伝票区分CD')
                                    ->placeholder('-'),
                                TextEntry::make('slip.delivery_route')
                                    ->label('便')
                                    ->placeholder('-'),
                                TextEntry::make('slip.detail_count')
                                    ->label('伝票内明細数')
                                    ->numeric(),
                            ]),
                        Grid::make(2)
                            ->schema([
                                TextEntry::make('slip.shop_name')
                                    ->label('店名')
                                    ->placeholder('-'),
                                TextEntry::make('slip.delivery_place')
                                    ->label('納品場所')
                                    ->placeholder('-'),
                            ]),
                        TextEntry::make('slip.note')
                            ->label('備考')
                            ->columnSpanFull()
                            ->placeholder('-'),
                    ]),
                Section::make('明細')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('source_record_no')
                                    ->label('原本レコード番号'),
                                TextEntry::make('line_number')
                                    ->label('伝票行番号')
                                    ->numeric(),
                                TextEntry::make('data_type')
                                    ->label('データ種別'),
                                TextEntry::make('is_shortage')
                                    ->label('欠品判定')
                                    ->badge()
                                    ->formatStateUsing(fn (bool $state): string => $state ? '欠品' : '通常')
                                    ->color(fn (bool $state): string => $state ? 'danger' : 'success'),
                            ]),
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('jan_code')
                                    ->label('JANコード')
                                    ->copyable()
                                    ->placeholder('-'),
                                TextEntry::make('item_code')
                                    ->label('自社コード')
                                    ->copyable()
                                    ->placeholder('-'),
                                TextEntry::make('product_name')
                                    ->label('品名')
                                    ->placeholder('-'),
                            ]),
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('pack_quantity')
                                    ->label('入数')
                                    ->numeric(),
                                TextEntry::make('case_quantity')
                                    ->label('ケース数')
                                    ->numeric(),
                                TextEntry::make('piece_quantity')
                                    ->label('バラ数')
                                    ->numeric(),
                                TextEntry::make('total_quantity')
                                    ->label('総バラ数')
                                    ->numeric(),
                            ]),
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('unit_price_raw')
                                    ->label('納品単価(原値)')
                                    ->numeric(),
                                TextEntry::make('unit_price')
                                    ->label('納品単価')
                                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2)),
                                TextEntry::make('amount')
                                    ->label('金額')
                                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2)),
                            ]),
                    ]),
                Section::make('原本レコード')
                    ->schema([
                        TextEntry::make('raw_record_text')
                            ->label('128バイトレコード')
                            ->copyable()
                            ->columnSpanFull()
                            ->extraAttributes(['class' => 'font-mono text-xs whitespace-pre-wrap bg-gray-100 p-2 rounded']),
                        TextEntry::make('raw_record_hash')
                            ->label('レコードSHA256')
                            ->copyable()
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),
            ]);
    }
}
