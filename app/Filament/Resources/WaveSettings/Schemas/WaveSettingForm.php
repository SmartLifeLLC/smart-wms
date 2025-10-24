<?php

namespace App\Filament\Resources\WaveSettings\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class WaveSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Wave Configuration')
                    ->description('Configure wave generation settings for warehouse and delivery course combinations')
                    ->schema([
                        Select::make('warehouse_id')
                            ->label('Warehouse')
                            ->options(function () {
                                return DB::connection('sakemaru')
                                    ->table('warehouses')
                                    ->selectRaw("id, CONCAT(code, ' - ', name) as label")
                                    ->pluck('label', 'id');
                            })
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search) {
                                return DB::connection('sakemaru')
                                    ->table('warehouses')
                                    ->where(function ($query) use ($search) {
                                        $query->where('name', 'like', "%{$search}%")
                                              ->orWhere('code', 'like', "%{$search}%");
                                    })
                                    ->selectRaw("id, CONCAT(code, ' - ', name) as label")
                                    ->pluck('label', 'id')
                                    ->toArray();
                            })
                            ->live()
                            ->afterStateUpdated(function (callable $set) {
                                $set('delivery_course_id', null);
                            }),

                        Select::make('delivery_course_id')
                            ->label('Delivery Course')
                            ->options(function (callable $get) {
                                $warehouseId = $get('warehouse_id');

                                if (!$warehouseId) {
                                    return [];
                                }

                                return DB::connection('sakemaru')
                                    ->table('delivery_courses')
                                    ->where('warehouse_id', $warehouseId)
                                    ->selectRaw("id, CONCAT(code, ' - ', name) as label")
                                    ->pluck('label', 'id');
                            })
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search, callable $get) {
                                $warehouseId = $get('warehouse_id');

                                if (!$warehouseId) {
                                    return [];
                                }

                                return DB::connection('sakemaru')
                                    ->table('delivery_courses')
                                    ->where('warehouse_id', $warehouseId)
                                    ->where(function ($query) use ($search) {
                                        $query->where('name', 'like', "%{$search}%")
                                              ->orWhere('code', 'like', "%{$search}%");
                                    })
                                    ->selectRaw("id, CONCAT(code, ' - ', name) as label")
                                    ->pluck('label', 'id')
                                    ->toArray();
                            })
                            ->disabled(fn (callable $get) => !$get('warehouse_id'))
                            ->helperText('Select a warehouse first'),

                        TimePicker::make('picking_start_time')
                            ->label('Picking Start Time')
                            ->seconds(false)
                            ->nullable(),

                        TimePicker::make('picking_deadline_time')
                            ->label('Picking Deadline Time')
                            ->seconds(false)
                            ->nullable(),

                        Select::make('creator_id')
                            ->label('Creator')
                            ->options(function () {
                                return DB::connection('sakemaru')
                                    ->table('users')
                                    ->pluck('name', 'id');
                            })
                            ->default(fn () => auth()->id())
                            ->required()
                            ->disabled()
                            ->dehydrated(),

                        Select::make('last_updater_id')
                            ->label('Last Updater')
                            ->options(function () {
                                return DB::connection('sakemaru')
                                    ->table('users')
                                    ->pluck('name', 'id');
                            })
                            ->default(fn () => auth()->id())
                            ->required()
                            ->disabled()
                            ->dehydrated(),
                    ])
                    ->columns(2),
            ]);
    }
}
