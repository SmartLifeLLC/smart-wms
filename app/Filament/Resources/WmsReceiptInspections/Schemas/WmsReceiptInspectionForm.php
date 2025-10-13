<?php

namespace App\Filament\Resources\WmsReceiptInspections\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class WmsReceiptInspectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('基本情報')
                    ->schema([
                        TextInput::make('inspection_no')
                            ->label('検品番号')
                            ->required()
                            ->maxLength(255),

                        Select::make('purchase_id')
                            ->label('入荷予定')
                            ->relationship('purchase', 'id')
                            ->required()
                            ->searchable()
                            ->preload(),

                        Select::make('warehouse_id')
                            ->label('倉庫')
                            ->relationship('warehouse', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),

                        Select::make('status')
                            ->label('ステータス')
                            ->options([
                                'PENDING' => '未着手',
                                'IN_PROGRESS' => '進行中',
                                'COMPLETED' => '完了',
                                'CANCELLED' => 'キャンセル',
                            ])
                            ->required()
                            ->default('PENDING'),

                        Select::make('inspected_by')
                            ->label('作業者')
                            ->relationship('inspector', 'name')
                            ->searchable()
                            ->preload(),

                        DateTimePicker::make('inspected_at')
                            ->label('検品日時'),

                        Textarea::make('notes')
                            ->label('備考')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
