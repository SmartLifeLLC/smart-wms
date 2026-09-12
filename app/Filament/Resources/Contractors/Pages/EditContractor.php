<?php

namespace App\Filament\Resources\Contractors\Pages;

use App\Filament\Resources\Contractors\ContractorResource;
use App\Filament\Resources\Contractors\Schemas\ContractorForm;
use App\Models\WmsContractorSetting;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\RelationManagers\RelationManagerConfiguration;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;

class EditContractor extends EditRecord
{
    protected static string $resource = ContractorResource::class;

    protected static ?string $title = '発注先編集';

    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * フォームスキーマをオーバーライド
     * 基本情報・発注メール設定のフォームタブ + リレーションマネージャータブを
     * 一つのTabsコンポーネントにまとめる
     */
    public function form(Schema $schema): Schema
    {
        $ownerRecord = $this->getRecord();
        $managers = $this->getRelationManagers();
        $managerLivewireData = ['ownerRecord' => $ownerRecord, 'pageClass' => static::class];

        $relationManagerTabs = collect($managers)
            ->map(function ($manager) use ($managerLivewireData, $ownerRecord) {
                $normalizedManagerClass = $this->normalizeRelationManagerClass($manager);

                return $normalizedManagerClass::getTabComponent($ownerRecord, static::class)
                    ->schema(fn (): array => [
                        Livewire::make(
                            $normalizedManagerClass,
                            [
                                ...$managerLivewireData,
                                ...(($manager instanceof RelationManagerConfiguration)
                                    ? [...$manager->relationManager::getDefaultProperties(), ...$manager->getProperties()]
                                    : $manager::getDefaultProperties()),
                            ],
                        )->key($normalizedManagerClass),
                    ]);
            })
            ->all();

        return $schema
            ->columns(1)
            ->components([
                Tabs::make('contractor-tabs')
                    ->contained(false)
                    ->persistTabInQueryString('tab')
                    ->tabs([
                        Tab::make('基本情報')
                            ->icon('heroicon-o-information-circle')
                            ->schema(ContractorForm::basicInfoSchema()),

                        Tab::make('送受信設定')
                            ->icon('heroicon-o-arrows-right-left')
                            ->schema(ContractorForm::transmissionSchema()),

                        Tab::make('発注メール設定')
                            ->icon('heroicon-o-envelope')
                            ->schema(ContractorForm::mailSchema()),

                        ...$relationManagerTabs,
                    ]),
            ]);
    }

    /**
     * content()をオーバーライドしてフォームのみ表示
     * リレーションマネージャーはform()内のTabsに統合済み
     */
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('保存')
                ->action('save')
                ->keyBindings(['mod+s']),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // wms_ プレフィックスのフィールドはContractorモデルに存在しない
        // afterSave() で WmsContractorSetting に別途保存するため除外
        return collect($data)->reject(fn ($value, $key) => str_starts_with($key, 'wms_'))->all();
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $wmsSetting = $this->record->wmsSetting;

        if ($wmsSetting) {
            $data['wms_order_mail'] = $wmsSetting->order_mail;
            $data['wms_order_mail_from'] = $wmsSetting->order_mail_from;
            $data['wms_order_mail_title'] = $wmsSetting->order_mail_title;
            $data['wms_order_mail_content'] = $wmsSetting->order_mail_content;
            $data['wms_transmission_type'] = $wmsSetting->transmission_type?->value;
            $data['wms_order_jx_setting_id'] = $wmsSetting->wms_order_jx_setting_id;
            $data['wms_order_ftp_setting_id'] = $wmsSetting->wms_order_ftp_setting_id;
            $data['wms_supply_warehouse_id'] = $wmsSetting->supply_warehouse_id;
            $data['wms_auto_order_generation_time'] = $wmsSetting->auto_order_generation_time;
            $data['wms_transmission_time'] = $wmsSetting->transmission_time;
            $data['wms_is_transmission_mon'] = $wmsSetting->is_transmission_mon;
            $data['wms_is_transmission_tue'] = $wmsSetting->is_transmission_tue;
            $data['wms_is_transmission_wed'] = $wmsSetting->is_transmission_wed;
            $data['wms_is_transmission_thu'] = $wmsSetting->is_transmission_thu;
            $data['wms_is_transmission_fri'] = $wmsSetting->is_transmission_fri;
            $data['wms_is_transmission_sat'] = $wmsSetting->is_transmission_sat;
            $data['wms_is_transmission_sun'] = $wmsSetting->is_transmission_sun;
            $data['wms_is_auto_transmission'] = $wmsSetting->is_auto_transmission;
            $data['wms_is_jx_auto_generation_enabled'] = $wmsSetting->is_jx_auto_generation_enabled;
            $data['wms_jx_generation_time'] = $wmsSetting->jx_generation_time;
            $data['wms_jx_generation_cutoff_time'] = $wmsSetting->jx_generation_cutoff_time;
            $data['wms_jx_generation_sunday_time'] = $wmsSetting->jx_generation_sunday_time;
            $data['wms_jx_generation_sunday_cutoff_time'] = $wmsSetting->jx_generation_sunday_cutoff_time;
            $data['wms_jx_transmission_time'] = $wmsSetting->jx_transmission_time;
            $data['wms_jx_transmission_sunday_time'] = $wmsSetting->jx_transmission_sunday_time;
            $data['wms_transmission_contractor_id'] = $wmsSetting->transmission_contractor_id;
            $data['wms_format_strategy_class'] = $wmsSetting->format_strategy_class;
            $data['wms_is_receive_enabled'] = $wmsSetting->is_receive_enabled;
            $data['wms_receive_format'] = $wmsSetting->receive_format;
            $data['wms_receive_time'] = $wmsSetting->receive_time;
            $data['wms_is_receive_mon'] = $wmsSetting->is_receive_mon;
            $data['wms_is_receive_tue'] = $wmsSetting->is_receive_tue;
            $data['wms_is_receive_wed'] = $wmsSetting->is_receive_wed;
            $data['wms_is_receive_thu'] = $wmsSetting->is_receive_thu;
            $data['wms_is_receive_fri'] = $wmsSetting->is_receive_fri;
            $data['wms_is_receive_sat'] = $wmsSetting->is_receive_sat;
            $data['wms_is_receive_sun'] = $wmsSetting->is_receive_sun;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $data = $this->form->getState();

        $wmsFields = [
            'order_mail' => $data['wms_order_mail'] ?? null,
            'order_mail_from' => $data['wms_order_mail_from'] ?? null,
            'order_mail_title' => $data['wms_order_mail_title'] ?? null,
            'order_mail_content' => $data['wms_order_mail_content'] ?? null,
            'transmission_type' => $data['wms_transmission_type'] ?? null,
            'wms_order_jx_setting_id' => $data['wms_order_jx_setting_id'] ?? null,
            'wms_order_ftp_setting_id' => $data['wms_order_ftp_setting_id'] ?? null,
            'supply_warehouse_id' => $data['wms_supply_warehouse_id'] ?? null,
            'auto_order_generation_time' => $data['wms_auto_order_generation_time'] ?? null,
            'transmission_time' => $data['wms_transmission_time'] ?? null,
            'is_transmission_mon' => $data['wms_is_transmission_mon'] ?? false,
            'is_transmission_tue' => $data['wms_is_transmission_tue'] ?? false,
            'is_transmission_wed' => $data['wms_is_transmission_wed'] ?? false,
            'is_transmission_thu' => $data['wms_is_transmission_thu'] ?? false,
            'is_transmission_fri' => $data['wms_is_transmission_fri'] ?? false,
            'is_transmission_sat' => $data['wms_is_transmission_sat'] ?? false,
            'is_transmission_sun' => $data['wms_is_transmission_sun'] ?? false,
            'is_auto_transmission' => $data['wms_is_auto_transmission'] ?? false,
            'is_jx_auto_generation_enabled' => $data['wms_is_jx_auto_generation_enabled'] ?? false,
            'jx_generation_time' => $data['wms_jx_generation_time'] ?? null,
            'jx_generation_cutoff_time' => $data['wms_jx_generation_cutoff_time'] ?? null,
            'jx_generation_sunday_time' => $data['wms_jx_generation_sunday_time'] ?? null,
            'jx_generation_sunday_cutoff_time' => $data['wms_jx_generation_sunday_cutoff_time'] ?? null,
            'jx_transmission_time' => $data['wms_jx_transmission_time'] ?? null,
            'jx_transmission_sunday_time' => $data['wms_jx_transmission_sunday_time'] ?? null,
            'transmission_contractor_id' => $data['wms_transmission_contractor_id'] ?? null,
            'format_strategy_class' => $data['wms_format_strategy_class'] ?? null,
            'is_receive_enabled' => $data['wms_is_receive_enabled'] ?? false,
            'receive_format' => $data['wms_receive_format'] ?? 'JX',
            'receive_time' => $data['wms_receive_time'] ?? null,
            'is_receive_mon' => $data['wms_is_receive_mon'] ?? false,
            'is_receive_tue' => $data['wms_is_receive_tue'] ?? false,
            'is_receive_wed' => $data['wms_is_receive_wed'] ?? false,
            'is_receive_thu' => $data['wms_is_receive_thu'] ?? false,
            'is_receive_fri' => $data['wms_is_receive_fri'] ?? false,
            'is_receive_sat' => $data['wms_is_receive_sat'] ?? false,
            'is_receive_sun' => $data['wms_is_receive_sun'] ?? false,
        ];

        $wmsSetting = WmsContractorSetting::findOrCreateByContractor($this->record->id);
        $wmsSetting->fill($wmsFields);
        $wmsSetting->save();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
