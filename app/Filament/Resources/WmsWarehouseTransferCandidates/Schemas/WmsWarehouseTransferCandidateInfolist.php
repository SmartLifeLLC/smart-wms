<?php

namespace App\Filament\Resources\WmsWarehouseTransferCandidates\Schemas;

use App\Enums\WarehouseTransferCandidateStatus;
use App\Filament\Resources\WmsWarehouseTransferCandidates\Tables\WmsWarehouseTransferCandidatesTable;
use App\Models\WmsWarehouseTransferCandidate;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WmsWarehouseTransferCandidateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('候補情報')
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('candidate_no')->label('候補番号'),
                        TextEntry::make('status')
                            ->label('状態')
                            ->badge()
                            ->formatStateUsing(fn (WarehouseTransferCandidateStatus $state) => $state->label())
                            ->color(fn (WarehouseTransferCandidateStatus $state) => $state->color()),
                        TextEntry::make('source_type')->label('起点')->badge()->color('gray'),
                        TextEntry::make('submitted_at')->label('受信日時')->dateTime('Y/m/d H:i')->placeholder('-'),

                        TextEntry::make('from_warehouse_code')->label('移動元倉庫CD'),
                        TextEntry::make('from_warehouse_name')->label('移動元倉庫名'),
                        TextEntry::make('to_warehouse_code')->label('移動先倉庫CD'),
                        TextEntry::make('to_warehouse_name')->label('移動先倉庫名'),

                        TextEntry::make('process_date')->label('処理日')->date('Y/m/d'),
                        TextEntry::make('delivered_date')->label('納品日')->date('Y/m/d'),
                        TextEntry::make('deliveryCourse.name')
                            ->label('配送コース')
                            ->formatStateUsing(fn ($state, WmsWarehouseTransferCandidate $record) => $record->deliveryCourse
                                ? "[{$record->deliveryCourse->code}] {$record->deliveryCourse->name}"
                                : $state)
                            ->placeholder('未設定'),
                        TextEntry::make('submitter')
                            ->label('送信者 / 端末')
                            ->state(fn (WmsWarehouseTransferCandidate $record) => trim(implode(' / ', array_filter([
                                $record->submittedByPicker?->name,
                                $record->submitted_device_id,
                            ]))) ?: '-'),

                        TextEntry::make('memo')->label('備考')->placeholder('-')->columnSpan(4),
                    ]),
                ]),

            Section::make('基幹連携')
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('queue_status')
                            ->label('queue状態')
                            ->badge()
                            ->state(fn (WmsWarehouseTransferCandidate $record) => WmsWarehouseTransferCandidatesTable::queueLabel($record))
                            ->color(fn (string $state) => match ($state) {
                                '伝票作成済' => 'success',
                                '伝票作成失敗' => 'danger',
                                '処理中', 'queue待ち' => 'info',
                                default => 'gray',
                            }),
                        TextEntry::make('stock_transfer_queue_id')->label('queue ID')->placeholder('-'),
                        TextEntry::make('stock_transfer_id')->label('基幹移動ID')->placeholder('-'),
                        TextEntry::make('confirmedByUser.name')->label('確定者')->placeholder('-'),
                        TextEntry::make('confirmed_at')->label('確定日時')->dateTime('Y/m/d H:i')->placeholder('-'),
                        TextEntry::make('queue_request_id')->label('request_id')->placeholder('-')->columnSpan(2),
                        TextEntry::make('queue_error_message')
                            ->label('queueエラー')
                            ->placeholder('-')
                            ->color('danger')
                            ->columnSpan(4)
                            ->visible(fn (WmsWarehouseTransferCandidate $record) => filled($record->queue_error_message)),
                    ]),
                ])
                ->collapsible()
                ->collapsed(fn (WmsWarehouseTransferCandidate $record) => $record->isPending()),
        ]);
    }
}
