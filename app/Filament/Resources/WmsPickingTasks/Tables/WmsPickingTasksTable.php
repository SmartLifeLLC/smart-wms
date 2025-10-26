<?php

namespace App\Filament\Resources\WmsPickingTasks\Tables;

use App\Filament\Resources\WmsPickingTasks\WmsPickingTaskResource;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class WmsPickingTasksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'PENDING' => 'warning',
                        'PICKING' => 'info',
                        'COMPLETED' => 'success',
                        'CANCELLED' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'PENDING' => '未着手',
                        'PICKING' => 'ピッキング中',
                        'COMPLETED' => '完了',
                        'CANCELLED' => 'キャンセル',
                        default => $state,
                    })
                    ->sortable(),

                TextColumn::make('trade.serial_id')
                    ->label('伝票番号')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('picker.display_name')
                    ->label('ピッカー')
                    ->default('未割当')
                    ->badge()
                    ->color(fn($state) => $state !== '未割当' ? 'success' : 'danger')
                    ->sortable(),

                TextColumn::make('warehouse.code')
                    ->label('倉庫コード')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('warehouse.name')
                    ->label('倉庫名')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('earning.delivery_course.code')
                    ->label('配送コースコード')
                    ->default('-')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('earning.delivery_course.name')
                    ->label('配送コース名')
                    ->default('-')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('started_at')
                    ->label('ピッキング日時')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('created_at')
                    ->label('生成日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('updated_at')
                    ->label('更新日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('wms_picking_area_id')
                    ->label('ピッキングエリア')
                    ->relationship('pickingArea', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        'PENDING' => '未着手',
                        'PICKING' => 'ピッキング中',
                        'COMPLETED' => '完了',
                        'CANCELLED' => 'キャンセル',
                    ]),

                SelectFilter::make('picker_assigned')
                    ->label('担当者割当状況')
                    ->options([
                        'assigned' => '割当済み',
                        'unassigned' => '未割当',
                    ])
                    ->query(function ($query, $state) {
                        return match ($state['value'] ?? null) {
                            'assigned' => $query->whereNotNull('picker_id'),
                            'unassigned' => $query->whereNull('picker_id'),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                Action::make('execute')
                    ->label('ピッキング開始')
                    ->icon('heroicon-o-play')
                    ->color('primary')
                    ->url(fn ($record) => WmsPickingTaskResource::getUrl('execute', ['record' => $record->id]))
                    ->visible(fn ($record) => in_array($record->status, ['PENDING', 'PICKING'])),
            ], position: RecordActionsPosition::BeforeColumns)
            ->defaultSort('created_at', 'desc')
            ->bulkActions([
                BulkAction::make('assignPicker')
                    ->label('担当者を割り当てる')
                    ->icon('heroicon-o-user-plus')
                    ->color('primary')
                    ->form([
                        Select::make('picker_id')
                            ->label('ピッカー')
                            ->required()
                            ->searchable()
                            ->options(function () {
                                return \App\Models\WmsPicker::active()
                                    ->orderBy('code')
                                    ->get()
                                    ->pluck('display_name', 'id');
                            })
                            ->helperText('担当するピッカーを選択してください'),
                    ])
                    ->action(function (Collection $records, array $data) {
                        $pickerId = $data['picker_id'];
                        $picker = \App\Models\WmsPicker::find($pickerId);
                        $count = 0;

                        DB::connection('sakemaru')->transaction(function () use ($records, $pickerId, &$count) {
                            foreach ($records as $task) {
                                // Only assign if not already assigned
                                if ($task->picker_id === null) {
                                    $task->update([
                                        'picker_id' => $pickerId,
                                        'status' => 'PICKING',
                                    ]);
                                    $count++;
                                }
                            }
                        });

                        Notification::make()
                            ->title('担当者を割り当てました')
                            ->body("{$count}件のタスクを{$picker->display_name}に割り当てました")
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation(),

                BulkAction::make('unassignPicker')
                    ->label('担当者割当を解除')
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->action(function (Collection $records) {
                        $count = 0;

                        DB::connection('sakemaru')->transaction(function () use ($records, &$count) {
                            foreach ($records as $task) {
                                if ($task->picker_id !== null && $task->status !== 'COMPLETED') {
                                    $task->update([
                                        'picker_id' => null,
                                        'status' => 'PENDING',
                                    ]);
                                    $count++;
                                }
                            }
                        });

                        Notification::make()
                            ->title('担当者割当を解除しました')
                            ->body("{$count}件のタスクの担当者を解除しました")
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation(),
            ]);
    }
}
