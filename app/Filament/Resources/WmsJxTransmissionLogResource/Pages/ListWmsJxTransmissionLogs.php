<?php

namespace App\Filament\Resources\WmsJxTransmissionLogResource\Pages;

use App\Filament\Resources\WmsJxTransmissionLogResource;
use App\Models\WmsJxTransmissionLog;
use App\Models\WmsOrderJxSetting;
use App\Services\JX\JxDocumentReceiver;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListWmsJxTransmissionLogs extends ListRecords
{
    protected static string $resource = WmsJxTransmissionLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('receiveJxDocuments')
                ->label('受信実行')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('JXドキュメント一括受信')
                ->modalDescription('有効なJX接続設定すべてでドキュメント受信を実行します。受信結果はJX送受信履歴に記録されます。')
                ->modalSubmitActionLabel('受信開始')
                ->action(fn () => $this->receiveJxDocuments()),
        ];
    }

    private function receiveJxDocuments(): void
    {
        $settings = WmsOrderJxSetting::query()
            ->active()
            ->orderBy('id')
            ->get();

        if ($settings->isEmpty()) {
            Notification::make()
                ->title('受信対象がありません')
                ->body('有効なJX接続設定がありません。')
                ->warning()
                ->send();

            return;
        }

        $receivedCount = 0;
        $errors = [];
        $receivedDetails = [];

        foreach ($settings as $setting) {
            try {
                $receiver = new JxDocumentReceiver($setting);
                $receiver->setStorageDisk('s3');
                $receiver->setEnvironment(WmsJxTransmissionLog::ENV_PRODUCTION);

                $documents = $receiver->receiveAll();
                $documentCount = $documents->count();
                $receivedCount += $documentCount;

                if ($receiver->getLastError() !== null) {
                    $errors[] = "{$setting->name}: {$receiver->getLastError()}";
                }

                if ($documentCount > 0) {
                    $receivedDetails[] = "{$setting->name}: {$documentCount}件";
                }
            } catch (\Throwable $throwable) {
                $errors[] = "{$setting->name}: {$throwable->getMessage()}";
            }
        }

        $body = "JX設定: {$settings->count()}件 / 受信: {$receivedCount}件";

        if ($receivedDetails !== []) {
            $body .= "\n".collect($receivedDetails)->take(5)->implode("\n");

            if (count($receivedDetails) > 5) {
                $body .= "\nほか ".(count($receivedDetails) - 5).'件';
            }
        }

        if ($errors !== []) {
            $body .= "\n\nエラー: ".count($errors)."件\n".collect($errors)->take(3)->implode("\n");

            Notification::make()
                ->title($receivedCount > 0 ? '一部のJX受信でエラーが発生しました' : 'JX受信に失敗しました')
                ->body($body)
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('JX受信が完了しました')
            ->body($body)
            ->success()
            ->send();
    }
}
