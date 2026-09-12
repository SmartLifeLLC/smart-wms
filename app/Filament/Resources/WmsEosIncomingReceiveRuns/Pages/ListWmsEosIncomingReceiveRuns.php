<?php

namespace App\Filament\Resources\WmsEosIncomingReceiveRuns\Pages;

use App\Filament\Resources\WmsEosIncomingReceiveRuns\WmsEosIncomingReceiveRunResource;
use App\Jobs\ProcessEosIncomingReceiveRunJob;
use App\Models\WmsEosIncomingReceiveRun;
use App\Models\WmsEosIncomingReceiveSchedule;
use App\Models\WmsEosIncomingReceiveSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Str;

class ListWmsEosIncomingReceiveRuns extends ListRecords
{
    protected static string $resource = WmsEosIncomingReceiveRunResource::class;

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('EOSデータ受信設定')
                    ->persistTabInQueryString('receive_section')
                    ->tabs([
                        Tab::make('受信履歴')
                            ->schema([
                                $this->getTabsContentComponent(),
                                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                                EmbeddedTable::make(),
                                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
                            ]),
                        Tab::make('自動連携仕様')
                            ->schema([
                                View::make('filament.components.eos-incoming-auto-transmission-spec'),
                            ]),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editSettings')
                ->label('設定編集')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->modalHeading('EOSデータ受信設定')
                ->modalWidth('5xl')
                ->schema($this->settingsSchema())
                ->fillForm(fn (): array => WmsEosIncomingReceiveSetting::ensureDefault()->settingsFormData())
                ->modalSubmitActionLabel('保存')
                ->modalCancelActionLabel('保存せず閉じる')
                ->action(function (array $data): void {
                    WmsEosIncomingReceiveSetting::ensureDefault()->saveSettingsFormData($data);

                    Notification::make()
                        ->title('EOSデータ受信設定を保存しました')
                        ->success()
                        ->send();
                }),
            Action::make('runNow')
                ->label('今すぐ実行')
                ->icon('heroicon-o-play')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('JXデータ受信を今すぐ実行')
                ->modalDescription('有効なJX接続設定すべてでGetDocument受信し、今回受信したEOSログの取込までキューで実行します。入荷予定照合、入荷確定、仕入データ生成は実行しません。')
                ->modalSubmitActionLabel('実行')
                ->action(function (): void {
                    WmsEosIncomingReceiveSetting::ensureDefault();
                    $runKey = 'manual:'.Str::uuid()->toString();

                    ProcessEosIncomingReceiveRunJob::dispatch(
                        $runKey,
                        null,
                        WmsEosIncomingReceiveRun::TRIGGER_MANUAL,
                        false,
                        null,
                        true,
                    );

                    Notification::make()
                        ->title('JXデータ受信とEOSログ取込をキューに登録しました')
                        ->body("実行キー: {$runKey}")
                        ->success()
                        ->send();
                }),
        ];
    }

    private function settingsSchema(): array
    {
        return [
            Section::make('基本設定')
                ->schema([
                    Grid::make(3)
                        ->schema([
                            Toggle::make('is_enabled')
                                ->label('定期実行を有効にする')
                                ->inline(false),
                            TimePicker::make('daily_receive_time')
                                ->label('毎日の受信時刻')
                                ->seconds(false)
                                ->required(),
                            TextInput::make('exclude_purchase_warehouse_code')
                                ->label('仕入自動生成除外倉庫CD')
                                ->default('91')
                                ->maxLength(16),
                            TextInput::make('shortage_completion_days')
                                ->label('自動整理日数')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(60)
                                ->default(14),
                        ]),
                ]),
            Section::make('曜日別の追加受信時刻')
                ->schema([
                    Grid::make(2)
                        ->schema($this->weekdayTimeFields()),
                ]),
        ];
    }

    private function weekdayTimeFields(): array
    {
        $fields = [];

        foreach (WmsEosIncomingReceiveSchedule::dayLabels() as $day => $label) {
            for ($slot = 1; $slot <= 2; $slot++) {
                $fields[] = TimePicker::make("weekday_{$day}_slot_{$slot}_time")
                    ->label("{$label}曜 追加{$slot}")
                    ->seconds(false)
                    ->nullable();
            }
        }

        return $fields;
    }
}
