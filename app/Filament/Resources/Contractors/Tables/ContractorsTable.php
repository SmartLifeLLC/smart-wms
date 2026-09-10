<?php

namespace App\Filament\Resources\Contractors\Tables;

use App\Enums\AutoOrder\TransmissionType;
use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContractorsTable
{
    use HasExportAction;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'supplier.partner',
                'contractorSuppliers.supplier.partner',
                'wmsSetting.transmissionContractor',
            ]))
            ->columns([
                TextColumn::make('code')
                    ->label('コード')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('発注先名')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('nickname')
                    ->label('略称')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('contractorSuppliers.supplier.partner.code')
                    ->label('仕入先CD')
                    ->searchable()
                    ->listWithLineBreaks()
                    ->distinctList()
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->placeholder('-'),

                TextColumn::make('contractorSuppliers.supplier.partner.name')
                    ->label('仕入先名')
                    ->searchable()
                    ->listWithLineBreaks()
                    ->distinctList()
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->placeholder('-'),

                TextColumn::make('supplier.partner.code')
                    ->label('デフォルト仕入先CD')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('supplier.partner.name')
                    ->label('デフォルト仕入先名')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('tel')
                    ->label('電話番号')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('fax')
                    ->label('FAX')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_auto_change_order')
                    ->label('自動発注')
                    ->boolean()
                    ->sortable()
                    ->alignCenter(),

                IconColumn::make('is_active')
                    ->label('有効')
                    ->boolean()
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('wmsSetting.transmission_type')
                    ->label('送信方式')
                    ->badge()
                    ->color(fn (?TransmissionType $state): string => match ($state) {
                        TransmissionType::JX_FINET => 'success',
                        TransmissionType::FTP => 'info',
                        TransmissionType::MANUAL_CSV => 'gray',
                        TransmissionType::INTERNAL => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('wmsSetting.transmissionContractor.name')
                    ->label('集約先')
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('wmsSetting.transmission_days_label')
                    ->label('送信曜日')
                    ->placeholder('-')
                    ->toggleable(),

                IconColumn::make('wmsSetting.is_auto_transmission')
                    ->label('自動送信')
                    ->boolean()
                    ->alignCenter()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('登録日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('有効/無効')
                    ->placeholder('すべて')
                    ->trueLabel('有効のみ')
                    ->falseLabel('無効のみ'),

                TernaryFilter::make('is_auto_change_order')
                    ->label('自動発注')
                    ->placeholder('すべて')
                    ->trueLabel('自動発注のみ')
                    ->falseLabel('手動発注のみ'),

                SelectFilter::make('transmission_type')
                    ->label('送信方式')
                    ->options(collect(TransmissionType::cases())
                        ->mapWithKeys(fn (TransmissionType $type) => [$type->value => $type->label()])
                        ->toArray())
                    ->modifyQueryUsing(fn (Builder $query, array $data) => $data['value']
                        ? $query->whereHas('wmsSetting', fn (Builder $q) => $q->where('transmission_type', $data['value']))
                        : $query),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                static::getExportAction(),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('code', 'asc');
    }
}
