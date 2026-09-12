<?php

namespace App\Filament\Resources\WmsWarehouseTransferCandidates\Support;

use App\Enums\WarehouseTransferCandidateStatus;
use App\Filament\Resources\WmsWarehouseTransferCandidates\WmsWarehouseTransferCandidateResource;
use App\Models\Sakemaru\DeliveryCourse;
use App\Models\WmsWarehouseTransferCandidate;
use App\Services\WarehouseTransfer\WarehouseTransferQueueService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Illuminate\Contracts\View\View;
use Throwable;

/**
 * 一覧 / 詳細で共通利用する候補アクション
 */
class WarehouseTransferCandidateActions
{
    /**
     * 確定（PENDING のみ）: 確認モーダル → queue 投入
     */
    public static function confirm(): Action
    {
        return Action::make('confirm')
            ->label('確定')
            ->icon('heroicon-o-check-circle')
            ->color('danger')
            ->visible(fn (WmsWarehouseTransferCandidate $record): bool => $record->isPending()
                && WmsWarehouseTransferCandidateResource::canConfirm())
            ->modalHeading(fn (WmsWarehouseTransferCandidate $record) => "倉庫移動を確定: {$record->candidate_no}")
            ->modalWidth('5xl')
            ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->modalFooterActionsAlignment(Alignment::End)
            ->modalSubmitAction(function ($action, WmsWarehouseTransferCandidate $record) {
                $validation = app(WarehouseTransferQueueService::class)->validateForConfirm($record);

                return $action->makeModalSubmitAction('submit', [])
                    ->label('移動を確定')
                    ->color('danger')
                    ->disabled(! $validation['ok']);
            })
            ->modalCancelActionLabel('確定せず閉じる')
            ->modalContent(function (WmsWarehouseTransferCandidate $record): View {
                $validation = app(WarehouseTransferQueueService::class)->validateForConfirm($record);
                $deliveryCourse = $validation['delivery_course_id']
                    ? DeliveryCourse::find($validation['delivery_course_id'])
                    : null;

                return view('filament.resources.wms-warehouse-transfer-candidates.confirm-modal', [
                    'record' => $record,
                    'validation' => $validation,
                    'deliveryCourse' => $deliveryCourse,
                    'items' => $record->items()->get(),
                ]);
            })
            ->action(function (WmsWarehouseTransferCandidate $record, $livewire): void {
                try {
                    $queueId = app(WarehouseTransferQueueService::class)->enqueue($record, auth()->id());
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('確定に失敗しました')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title("候補 {$record->candidate_no} を確定しました")
                    ->body("stock_transfer_queue ID: {$queueId}")
                    ->success()
                    ->send();

                static::refresh($livewire);
            });
    }

    /**
     * 取消（PENDING のみ）
     */
    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->label('取消')
            ->icon('heroicon-o-x-circle')
            ->color('gray')
            ->visible(fn (WmsWarehouseTransferCandidate $record): bool => $record->isPending()
                && WmsWarehouseTransferCandidateResource::canCancel())
            ->requiresConfirmation()
            ->modalHeading(fn (WmsWarehouseTransferCandidate $record) => "候補を取消: {$record->candidate_no}")
            ->modalDescription('この候補を取消します。取消後は確定できません。HANDYから再送信された場合は新しい候補として作成されます。')
            ->modalFooterActionsAlignment(Alignment::End)
            ->modalSubmitActionLabel('取消する')
            ->modalCancelActionLabel('取消せず閉じる')
            ->action(function (WmsWarehouseTransferCandidate $record, $livewire): void {
                try {
                    app(WarehouseTransferQueueService::class)->cancel($record, auth()->id());
                } catch (Throwable $e) {
                    Notification::make()->title('取消に失敗しました')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title("候補 {$record->candidate_no} を取消しました")->success()->send();

                static::refresh($livewire);
            });
    }

    /**
     * 再投入（FAILED のみ）
     */
    public static function retry(): Action
    {
        return Action::make('retry')
            ->label('再投入')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn (WmsWarehouseTransferCandidate $record): bool => $record->status === WarehouseTransferCandidateStatus::FAILED
                && WmsWarehouseTransferCandidateResource::canConfirm())
            ->requiresConfirmation()
            ->modalHeading(fn (WmsWarehouseTransferCandidate $record) => "queue を再投入: {$record->candidate_no}")
            ->modalDescription(fn (WmsWarehouseTransferCandidate $record) => '失敗した queue を再処理可能な状態に戻します。'
                .($record->queue_error_message ? "\n\n前回エラー: {$record->queue_error_message}" : ''))
            ->modalFooterActionsAlignment(Alignment::End)
            ->modalSubmitActionLabel('再投入する')
            ->modalCancelActionLabel('再投入せず閉じる')
            ->action(function (WmsWarehouseTransferCandidate $record, $livewire): void {
                try {
                    app(WarehouseTransferQueueService::class)->retry($record, auth()->id());
                } catch (Throwable $e) {
                    Notification::make()->title('再投入に失敗しました')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title("候補 {$record->candidate_no} を再投入しました")->success()->send();

                static::refresh($livewire);
            });
    }

    private static function refresh($livewire): void
    {
        if (method_exists($livewire, 'refreshRecord')) {
            $livewire->refreshRecord();
        }

        if (method_exists($livewire, 'resetTable')) {
            $livewire->resetTable();
        }
    }
}
