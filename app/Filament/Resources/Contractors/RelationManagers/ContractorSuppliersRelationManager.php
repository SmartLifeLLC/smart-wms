<?php

namespace App\Filament\Resources\Contractors\RelationManagers;

use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Supplier;
use App\Models\WmsContractorSupplier;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
                    ->disabled(fn (?WmsContractorSupplier $record): bool => $record ? $this->isDefaultSupplier($record) : false)
                    ->helperText(fn (?WmsContractorSupplier $record): ?string => $record && $this->isDefaultSupplier($record)
                        ? 'デフォルト仕入先を変更する場合は、先にデフォルトをOFFにしてください。'
                        : null)
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
                ToggleColumn::make('is_default')
                    ->label('デフォルト')
                    ->state(fn (WmsContractorSupplier $record): bool => $this->isDefaultSupplier($record))
                    ->updateStateUsing(function (WmsContractorSupplier $record, ?bool $state): bool {
                        $result = $this->updateDefaultSupplier($record, (bool) $state);

                        if (! $result['success']) {
                            Notification::make()
                                ->danger()
                                ->title('デフォルト仕入先を変更できませんでした')
                                ->body('仕入先の紐付けまたは有効状態を確認してください。')
                                ->send();

                            return $result['is_default'];
                        }

                        Notification::make()
                            ->success()
                            ->title($result['is_default']
                                ? 'デフォルト仕入先を設定しました'
                                : 'デフォルト仕入先を解除しました')
                            ->send();

                        return $result['is_default'];
                    })
                    ->onColor('success')
                    ->offColor('gray')
                    ->onIcon('heroicon-o-check')
                    ->offIcon('heroicon-o-minus')
                    ->alignCenter()
                    ->extraAttributes(fn (WmsContractorSupplier $record): array => [
                        'wire:key' => 'contractor-default-supplier-'.$record->getKey().'-'.((int) $this->getOwnerRecord()->supplier_id),
                    ]),

                TextColumn::make('supplier.partner.code')
                    ->label('仕入先CD')
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
                CreateAction::make()
                    ->label('仕入先を追加')
                    ->modalHeading('仕入先を追加')
                    ->modalWidth('lg')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('追加する')->color('danger'))
                    ->modalCancelActionLabel('追加せず閉じる')
                    ->createAnother(false)
                    ->using(fn (array $data): WmsContractorSupplier => $this->createSupplierMapping($data)),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->disabled(fn (WmsContractorSupplier $record): bool => $this->isDefaultSupplier($record))
                    ->tooltip(fn (WmsContractorSupplier $record): ?string => $this->isDefaultSupplier($record)
                        ? '削除する場合は、先にデフォルトをOFFにしてください。'
                        : null),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->before(function (DeleteBulkAction $action, $records): void {
                            if (! $records->contains(fn (WmsContractorSupplier $record): bool => $this->isDefaultSupplier($record))) {
                                return;
                            }

                            Notification::make()
                                ->danger()
                                ->title('デフォルト仕入先は削除できません')
                                ->body('先にデフォルトをOFFにしてから削除してください。')
                                ->send();

                            $action->halt();
                        }),
                ]),
            ]);
    }

    private function isDefaultSupplier(WmsContractorSupplier $record): bool
    {
        return (int) $this->getOwnerRecord()->supplier_id === (int) $record->supplier_id;
    }

    /**
     * @param  array{supplier_id: int|string, memo?: string|null}  $data
     */
    private function createSupplierMapping(array $data): WmsContractorSupplier
    {
        $ownerRecord = $this->getOwnerRecord();
        $contractorId = (int) $ownerRecord->getKey();
        $clientId = (int) $ownerRecord->client_id;
        $supplierId = (int) $data['supplier_id'];

        $record = DB::connection('sakemaru')->transaction(function () use ($clientId, $contractorId, $data, $supplierId): WmsContractorSupplier {
            $contractor = Contractor::query()
                ->whereKey($contractorId)
                ->where('client_id', $clientId)
                ->lockForUpdate()
                ->first();

            if (! $contractor || ! $this->isSelectableSupplier($supplierId, $clientId)) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'この発注先に登録できる仕入先ではありません。',
                ]);
            }

            if (WmsContractorSupplier::query()
                ->where('contractor_id', $contractorId)
                ->where('supplier_id', $supplierId)
                ->exists()) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'この仕入先は既に登録されています。',
                ]);
            }

            $record = WmsContractorSupplier::create([
                'contractor_id' => $contractorId,
                'supplier_id' => $supplierId,
                'memo' => $data['memo'] ?? null,
            ]);

            $this->persistDefaultSupplier($contractor, $supplierId);

            return $record;
        });

        $this->syncOwnerDefaultSupplier($supplierId);

        return $record;
    }

    /**
     * @return array{success: bool, is_default: bool}
     */
    private function updateDefaultSupplier(WmsContractorSupplier $record, bool $state): array
    {
        $ownerRecord = $this->getOwnerRecord();
        $contractorId = (int) $ownerRecord->getKey();
        $clientId = (int) $ownerRecord->client_id;

        if ((int) $record->contractor_id !== $contractorId) {
            return ['success' => false, 'is_default' => $this->isDefaultSupplier($record)];
        }

        $result = DB::connection('sakemaru')->transaction(function () use ($clientId, $contractorId, $record, $state): array {
            $contractor = Contractor::query()
                ->whereKey($contractorId)
                ->where('client_id', $clientId)
                ->lockForUpdate()
                ->first();

            if (! $contractor) {
                return ['success' => false, 'supplier_id' => null];
            }

            $mapping = WmsContractorSupplier::query()
                ->whereKey($record->getKey())
                ->where('contractor_id', $contractorId)
                ->where('supplier_id', $record->supplier_id)
                ->lockForUpdate()
                ->first();

            if (! $mapping) {
                return ['success' => false, 'supplier_id' => $contractor->supplier_id];
            }

            if ($state && ! $this->isSelectableSupplier((int) $mapping->supplier_id, $clientId)) {
                return ['success' => false, 'supplier_id' => $contractor->supplier_id];
            }

            $supplierId = $state
                ? (int) $mapping->supplier_id
                : ((int) $contractor->supplier_id === (int) $mapping->supplier_id ? null : $contractor->supplier_id);

            $this->persistDefaultSupplier($contractor, $supplierId);

            return ['success' => true, 'supplier_id' => $supplierId];
        });

        if ($result['success']) {
            $this->syncOwnerDefaultSupplier($result['supplier_id']);
        }

        return [
            'success' => $result['success'],
            'is_default' => $result['success']
                ? (int) $result['supplier_id'] === (int) $record->supplier_id
                : $this->isDefaultSupplier($record),
        ];
    }

    private function isSelectableSupplier(int $supplierId, int $clientId): bool
    {
        return Supplier::query()
            ->whereKey($supplierId)
            ->where('client_id', $clientId)
            ->whereHas('partner')
            ->exists();
    }

    private function persistDefaultSupplier(Contractor $contractor, ?int $supplierId): void
    {
        $contractor->supplier_id = $supplierId;

        if (auth()->id()) {
            $contractor->last_updater_id = auth()->id();
        }

        $contractor->save();
    }

    private function syncOwnerDefaultSupplier(?int $supplierId): void
    {
        $this->getOwnerRecord()->setAttribute('supplier_id', $supplierId);
        $this->getOwnerRecord()->unsetRelation('supplier');
    }
}
