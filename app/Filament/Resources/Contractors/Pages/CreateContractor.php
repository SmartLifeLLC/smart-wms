<?php

namespace App\Filament\Resources\Contractors\Pages;

use App\Filament\Resources\Contractors\ContractorResource;
use App\Models\Sakemaru\Client;
use App\Models\WmsContractorSetting;
use Filament\Resources\Pages\CreateRecord;

class CreateContractor extends CreateRecord
{
    protected static string $resource = ContractorResource::class;

    protected static ?string $title = '発注先作成';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = collect($data)->reject(fn ($value, $key) => str_starts_with($key, 'wms_'))->all();
        $data['client_id'] = Client::first()->id;

        return $data;
    }

    protected function afterCreate(): void
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
