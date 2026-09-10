<?php

namespace App\Filament\Resources\Contractors\RelationManagers;

use App\Models\Sakemaru\Supplier;
use App\Models\WmsContractorSupplier;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ContractorSuppliersRelationManager extends RelationManager
{
    protected static string $relationship = 'contractorSuppliers';

    protected static ?string $title = '仕入先';

    protected static ?string $modelLabel = '仕入先';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('supplier_id')
                    ->label('仕入先')
                    ->options(function (?WmsContractorSupplier $record): array {
                        $existingSupplierIds = WmsContractorSupplier::query()
                            ->select('supplier_id')
                            ->where('contractor_id', $this->getOwnerRecord()->id)
                            ->when(
                                $record,
                                fn ($query) => $query->where('id', '!=', $record->getKey()),
                            );

                        return Supplier::query()
                            ->with('partner')
                            ->where('client_id', $this->getOwnerRecord()->client_id)
                            ->whereHas('partner')
                            ->whereNotIn('id', $existingSupplierIds)
                            ->get()
                            ->sortBy(fn (Supplier $supplier) => (int) $supplier->partner?->code)
                            ->mapWithKeys(fn (Supplier $supplier) => [
                                $supplier->id => "[{$supplier->partner?->code}] {$supplier->partner?->name}",
                            ])
                            ->all();
                    })
                    ->searchable()
                    ->required()
                    ->unique(ignoreRecord: true, modifyRuleUsing: function ($rule) {
                        return $rule->where('contractor_id', $this->getOwnerRecord()->id);
                    }),

                Textarea::make('memo')
                    ->label('メモ')
                    ->rows(2)
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('supplier.partner.name')
            ->columns([
                TextColumn::make('supplier.partner.code')
                    ->label('仕入先コード')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('supplier.partner.name')
                    ->label('仕入先名')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('memo')
                    ->label('メモ')
                    ->limit(30)
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('登録日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('supplier_id')
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
