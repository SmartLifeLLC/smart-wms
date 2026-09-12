<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Contractors\Pages\EditContractor;
use App\Filament\Resources\Contractors\RelationManagers\ContractorSuppliersRelationManager;
use App\Filament\Resources\Contractors\Tables\ContractorsTable;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\User;
use App\Models\WmsContractorSupplier;
use Filament\Forms\Components\Select;
use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\Livewire;
use Sakemaru\Auth\Services\PermissionService;
use Tests\TestCase;

class ContractorResourceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    private ?User $user = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(PermissionService::class, function ($mock): void {
            $mock->shouldReceive('check')->andReturnTrue();
        });

        $this->user = User::query()->first();

        if (! $this->user) {
            $this->markTestSkipped('No user found in database for authentication');
        }
    }

    public function test_list_displays_wms_supplier_mapping_when_default_supplier_is_missing(): void
    {
        $clientId = (int) $this->user->client_id;
        $contractorCode = random_int(700000000, 799999999);
        $supplierCode = random_int(800000000, 899999999);
        $supplierName = '一覧表示用仕入先'.uniqid();
        $supplierId = $this->createSupplier($clientId, $supplierCode, $supplierName);
        $contractor = $this->createContractor($clientId, $contractorCode);

        WmsContractorSupplier::create([
            'contractor_id' => $contractor->id,
            'supplier_id' => $supplierId,
        ]);

        $record = $contractor->fresh()->load([
            'supplier.partner',
            'contractorSuppliers.supplier.partner',
        ]);
        $component = new ContractorTableTestComponent;
        $table = ContractorsTable::configure(Table::make($component));
        $component->setConfiguredTable($table);
        $supplierCodeColumn = $table->getColumn('contractorSuppliers.supplier.partner.code');
        $supplierNameColumn = $table->getColumn('contractorSuppliers.supplier.partner.name');
        $defaultSupplierCodeColumn = $table->getColumn('supplier.partner.code');
        $defaultSupplierNameColumn = $table->getColumn('supplier.partner.name');

        $this->assertNotNull($supplierCodeColumn);
        $this->assertNotNull($supplierNameColumn);
        $this->assertNotNull($defaultSupplierCodeColumn);
        $this->assertNotNull($defaultSupplierNameColumn);
        $this->assertSame([$supplierCode], $supplierCodeColumn->record($record)->getState());
        $this->assertSame([$supplierName], $supplierNameColumn->record($record)->getState());
        $this->assertTrue($defaultSupplierCodeColumn->isToggledHiddenByDefault());
        $this->assertTrue($defaultSupplierNameColumn->isToggledHiddenByDefault());

        $isFirstSearchConstraint = true;
        $searchQuery = Contractor::query();
        $supplierNameColumn->applySearchConstraint($searchQuery, $supplierName, $isFirstSearchConstraint);
        $this->assertTrue($searchQuery->whereKey($contractor->id)->exists());
    }

    public function test_edit_options_keep_current_supplier_and_exclude_other_assignments_and_invalid_clients(): void
    {
        $clientId = (int) $this->user->client_id;
        $otherClientId = $clientId + 1000000;
        $contractor = $this->createContractor($clientId, random_int(700000000, 799999999));
        $currentSupplierId = $this->createSupplier($clientId, random_int(800000000, 819999999), '現在値仕入先'.uniqid());
        $assignedSupplierId = $this->createSupplier($clientId, random_int(820000000, 839999999), '登録済仕入先'.uniqid());
        $availableSupplierId = $this->createSupplier($clientId, random_int(840000000, 859999999), '選択可能仕入先'.uniqid());
        $otherClientSupplierId = $this->createSupplier($otherClientId, random_int(860000000, 879999999), '別クライアント仕入先'.uniqid());
        $inactiveSupplierId = $this->createSupplier($clientId, random_int(880000000, 899999999), '無効仕入先'.uniqid(), false);

        $currentMapping = WmsContractorSupplier::create([
            'contractor_id' => $contractor->id,
            'supplier_id' => $currentSupplierId,
        ]);
        WmsContractorSupplier::create([
            'contractor_id' => $contractor->id,
            'supplier_id' => $assignedSupplierId,
        ]);

        Livewire::actingAs($this->user)
            ->test(ContractorSuppliersRelationManager::class, [
                'ownerRecord' => $contractor,
                'pageClass' => EditContractor::class,
            ])
            ->mountTableAction('edit', $currentMapping)
            ->assertTableActionDataSet(['supplier_id' => $currentSupplierId])
            ->assertSchemaComponentExists(
                'supplier_id',
                checkComponentUsing: function (Select $component) use (
                    $currentSupplierId,
                    $assignedSupplierId,
                    $availableSupplierId,
                    $otherClientSupplierId,
                    $inactiveSupplierId,
                ): bool {
                    $options = $component->getOptions();

                    return array_key_exists($currentSupplierId, $options)
                        && ! array_key_exists($assignedSupplierId, $options)
                        && array_key_exists($availableSupplierId, $options)
                        && ! array_key_exists($otherClientSupplierId, $options)
                        && ! array_key_exists($inactiveSupplierId, $options);
                },
            );

        Livewire::actingAs($this->user)
            ->test(ContractorSuppliersRelationManager::class, [
                'ownerRecord' => $contractor,
                'pageClass' => EditContractor::class,
            ])
            ->mountTableAction('create')
            ->assertSchemaComponentExists(
                'supplier_id',
                checkComponentUsing: function (Select $component) use (
                    $currentSupplierId,
                    $assignedSupplierId,
                    $availableSupplierId,
                ): bool {
                    $options = $component->getOptions();

                    return ! array_key_exists($currentSupplierId, $options)
                        && ! array_key_exists($assignedSupplierId, $options)
                        && array_key_exists($availableSupplierId, $options);
                },
            );
    }

    public function test_new_supplier_mapping_is_automatically_set_as_default(): void
    {
        $clientId = (int) $this->user->client_id;
        $contractor = $this->createContractor($clientId, random_int(700000000, 799999999));
        $oldSupplierId = $this->createSupplier($clientId, random_int(800000000, 819999999), '旧デフォルト仕入先'.uniqid());
        $newSupplierId = $this->createSupplier($clientId, random_int(820000000, 839999999), '新規デフォルト仕入先'.uniqid());

        WmsContractorSupplier::create([
            'contractor_id' => $contractor->id,
            'supplier_id' => $oldSupplierId,
        ]);
        $contractor->update(['supplier_id' => $oldSupplierId]);

        Livewire::actingAs($this->user)
            ->test(ContractorSuppliersRelationManager::class, [
                'ownerRecord' => $contractor,
                'pageClass' => EditContractor::class,
            ])
            ->callTableAction('create', data: [
                'supplier_id' => $newSupplierId,
                'memo' => '新規登録時にデフォルトへ切替',
            ]);

        $this->assertDatabaseHas('wms_contractor_suppliers', [
            'contractor_id' => $contractor->id,
            'supplier_id' => $newSupplierId,
            'memo' => '新規登録時にデフォルトへ切替',
        ], 'sakemaru');
        $this->assertSame($newSupplierId, (int) $contractor->refresh()->supplier_id);
    }

    public function test_default_supplier_toggle_can_switch_on_and_off(): void
    {
        $clientId = (int) $this->user->client_id;
        $contractor = $this->createContractor($clientId, random_int(700000000, 799999999));
        $firstSupplierId = $this->createSupplier($clientId, random_int(800000000, 819999999), '第一仕入先'.uniqid());
        $secondSupplierId = $this->createSupplier($clientId, random_int(820000000, 839999999), '第二仕入先'.uniqid());
        $firstMapping = WmsContractorSupplier::create([
            'contractor_id' => $contractor->id,
            'supplier_id' => $firstSupplierId,
        ]);
        $secondMapping = WmsContractorSupplier::create([
            'contractor_id' => $contractor->id,
            'supplier_id' => $secondSupplierId,
        ]);
        $contractor->update(['supplier_id' => $firstSupplierId]);

        $component = Livewire::actingAs($this->user)
            ->test(ContractorSuppliersRelationManager::class, [
                'ownerRecord' => $contractor,
                'pageClass' => EditContractor::class,
            ])
            ->assertTableColumnStateSet('is_default', true, $firstMapping)
            ->assertTableColumnStateSet('is_default', false, $secondMapping)
            ->assertTableActionDisabled('delete', $firstMapping)
            ->assertTableActionEnabled('delete', $secondMapping)
            ->call('updateTableColumnState', 'is_default', (string) $secondMapping->getKey(), true)
            ->assertTableColumnStateSet('is_default', false, $firstMapping)
            ->assertTableColumnStateSet('is_default', true, $secondMapping)
            ->assertTableActionEnabled('delete', $firstMapping)
            ->assertTableActionDisabled('delete', $secondMapping);

        $this->assertSame($secondSupplierId, (int) $contractor->refresh()->supplier_id);

        $component
            ->call('updateTableColumnState', 'is_default', (string) $secondMapping->getKey(), false)
            ->assertTableColumnStateSet('is_default', false, $secondMapping);

        $this->assertNull($contractor->refresh()->supplier_id);
    }

    private function createSupplier(int $clientId, int $code, string $name, bool $isActive = true): int
    {
        $partnerId = (int) DB::connection('sakemaru')->table('partners')->insertGetId([
            'client_id' => $clientId,
            'code' => $code,
            'name_main' => $name,
            'is_supplier' => true,
            'is_active' => $isActive,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::connection('sakemaru')->table('suppliers')->insertGetId([
            'client_id' => $clientId,
            'partner_id' => $partnerId,
            'delivery_price_payer' => 'CLIENT',
            'payee_bank_type' => 'OTHER_BANK',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createContractor(int $clientId, int $code): Contractor
    {
        $contractorId = (int) DB::connection('sakemaru')->table('contractors')->insertGetId([
            'client_id' => $clientId,
            'code' => $code,
            'name' => '発注先'.uniqid(),
            'supplier_id' => null,
            'delivery_type' => 'DIRECT',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Contractor::query()->findOrFail($contractorId);
    }
}

class ContractorTableTestComponent extends Component implements HasTable
{
    use InteractsWithTable;

    public function setConfiguredTable(Table $table): void
    {
        $this->table = $table;
    }

    public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
    {
        return null;
    }
}
