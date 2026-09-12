<?php

namespace App\Filament\Resources\Contractors\RelationManagers;

use App\Enums\AutoOrder\TransmissionType;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderFtpSetting;
use App\Models\WmsOrderJxSetting;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WmsSettingRelationManager extends RelationManager
{
    protected static string $relationship = 'wmsSetting';

    protected static ?string $title = '発注データ送信設定';

    protected static ?string $modelLabel = '送信設定';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('transmission_type')
                    ->label('送信方式')
                    ->options(collect(TransmissionType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()]))
                    ->required()
                    ->live()
                    ->default(TransmissionType::MANUAL_CSV->value),

                Select::make('transmission_contractor_id')
                    ->label('発注データ集約先')
                    ->options(fn ($livewire) => Contractor::where('is_active', true)
                        ->where('id', '!=', $livewire->getOwnerRecord()->id)
                        ->get()
                        ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}] {$c->name}"])
                    )
                    ->searchable()
                    ->nullable()
                    ->live()
                    ->helperText('指定した発注先の送信データに本発注先の発注データを集約する'),

                Select::make('wms_order_jx_setting_id')
                    ->label('JX設定')
                    ->options(fn () => WmsOrderJxSetting::where('is_active', true)->pluck('name', 'id'))
                    ->searchable()
                    ->nullable()
                    ->visible(fn (Get $get) => $get('transmission_type') === TransmissionType::JX_FINET->value)
                    ->required(fn (Get $get) => $get('transmission_type') === TransmissionType::JX_FINET->value),

                Select::make('wms_order_ftp_setting_id')
                    ->label('FTP設定')
                    ->options(fn () => WmsOrderFtpSetting::where('is_active', true)->pluck('name', 'id'))
                    ->searchable()
                    ->nullable()
                    ->visible(fn (Get $get) => $get('transmission_type') === TransmissionType::FTP->value)
                    ->required(fn (Get $get) => $get('transmission_type') === TransmissionType::FTP->value),

                Select::make('supply_warehouse_id')
                    ->label('供給倉庫')
                    ->options(fn () => Warehouse::pluck('name', 'id'))
                    ->searchable()
                    ->nullable()
                    ->visible(fn (Get $get) => $get('transmission_type') === TransmissionType::INTERNAL->value)
                    ->required(fn (Get $get) => $get('transmission_type') === TransmissionType::INTERNAL->value)
                    ->helperText('倉庫間移動の場合、供給元の倉庫を選択'),

                TextInput::make('aggregation_note')
                    ->label('スケジュール')
                    ->disabled()
                    ->default('集約先の設定にしたがいます')
                    ->dehydrated(false)
                    ->visible(fn (Get $get) => filled($get('transmission_contractor_id'))),

                TextInput::make('transmission_time')
                    ->label('送信時刻')
                    ->type('time')
                    ->nullable()
                    ->visible(fn (Get $get) => blank($get('transmission_contractor_id')))
                    ->helperText('指定しない場合は手動送信'),

                TextInput::make('jx_transmission_time')
                    ->label('JX送信時刻（月-土）')
                    ->type('time')
                    ->nullable()
                    ->visible(fn (Get $get) => blank($get('transmission_contractor_id')) && $get('transmission_type') === TransmissionType::JX_FINET->value)
                    ->helperText('通常運用: 13:40'),

                TextInput::make('jx_transmission_sunday_time')
                    ->label('JX送信時刻（日）')
                    ->type('time')
                    ->nullable()
                    ->visible(fn (Get $get) => blank($get('transmission_contractor_id')) && $get('transmission_type') === TransmissionType::JX_FINET->value)
                    ->helperText('通常運用: 23:40'),

                Fieldset::make('送信曜日')
                    ->visible(fn (Get $get) => blank($get('transmission_contractor_id')))
                    ->schema([
                        Toggle::make('is_transmission_mon')->label('月')->inline(false),
                        Toggle::make('is_transmission_tue')->label('火')->inline(false),
                        Toggle::make('is_transmission_wed')->label('水')->inline(false),
                        Toggle::make('is_transmission_thu')->label('木')->inline(false),
                        Toggle::make('is_transmission_fri')->label('金')->inline(false),
                        Toggle::make('is_transmission_sat')->label('土')->inline(false),
                        Toggle::make('is_transmission_sun')->label('日')->inline(false),
                    ])
                    ->columns(7),

                Toggle::make('is_auto_transmission')
                    ->label('自動送信')
                    ->default(false)
                    ->visible(fn (Get $get) => blank($get('transmission_contractor_id')))
                    ->helperText('ONにすると指定時刻・曜日に自動送信されます'),

                TextInput::make('format_strategy_class')
                    ->label('フォーマット戦略クラス')
                    ->visible(false),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('transmission_type')
            ->columns([
                TextColumn::make('transmission_type')
                    ->label('送信方式')
                    ->badge()
                    ->formatStateUsing(fn (TransmissionType $state): string => $state->label())
                    ->color(fn (TransmissionType $state): string => match ($state) {
                        TransmissionType::JX_FINET => 'info',
                        TransmissionType::FTP => 'success',
                        TransmissionType::MANUAL_CSV => 'gray',
                        TransmissionType::INTERNAL => 'warning',
                    }),

                TextColumn::make('jxSetting.name')
                    ->label('JX設定')
                    ->placeholder('-'),

                TextColumn::make('supplyWarehouse.name')
                    ->label('供給倉庫')
                    ->placeholder('-'),

                TextColumn::make('transmission_time')
                    ->label('送信時刻')
                    ->placeholder('手動'),

                TextColumn::make('transmission_days_label')
                    ->label('送信曜日')
                    ->placeholder('-'),

                IconColumn::make('is_auto_transmission')
                    ->label('自動送信')
                    ->boolean(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['contractor_id'] = $this->getOwnerRecord()->id;

                        return $data;
                    })
                    ->visible(fn () => ! $this->getOwnerRecord()->wmsSetting()->exists()),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
