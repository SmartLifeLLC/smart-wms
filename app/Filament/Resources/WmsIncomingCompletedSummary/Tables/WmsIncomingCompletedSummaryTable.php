<?php

namespace App\Filament\Resources\WmsIncomingCompletedSummary\Tables;

use App\Enums\AutoOrder\OrderSource;
use App\Enums\PaginationOptions;
use App\Enums\QuantityType;
use App\Filament\Concerns\HasOptimizedFilters;
use App\Filament\Concerns\HasStockSubqueries;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\IncomingTransmissionService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class WmsIncomingCompletedSummaryTable
{
    use HasOptimizedFilters;
    use HasStockSubqueries;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'incoming-completed-summary-table sticky-actions'])
            ->checkIfRecordIsSelectableUsing(fn (WmsOrderIncomingSchedule $record): bool => self::canTransmitSummaryGroup($record))
            ->columns([
                TextColumn::make('warehouse.name')
                    ->label('入荷倉庫')
                    ->searchable()
                    ->placeholder('-')
                    ->width('160px'),

                TextColumn::make('contractor.code')
                    ->label('発注先CD')
                    ->searchable()
                    ->alignCenter()
                    ->placeholder('-')
                    ->width('90px'),

                TextColumn::make('contractor.name')
                    ->label('発注先名')
                    ->searchable()
                    ->placeholder('-')
                    ->limit(32)
                    ->tooltip(fn ($state) => $state)
                    ->grow(),

                TextColumn::make('summary_detail_count')
                    ->label('明細数')
                    ->numeric()
                    ->alignEnd()
                    ->width('80px'),

                TextColumn::make('summary_item_count')
                    ->label('商品数')
                    ->numeric()
                    ->alignEnd()
                    ->width('80px'),

                TextColumn::make('summary_slip_count')
                    ->label('伝票数')
                    ->numeric()
                    ->alignEnd()
                    ->width('80px'),

                TextColumn::make('summary_expected_period')
                    ->label('予定日')
                    ->state(fn (WmsOrderIncomingSchedule $record): string => self::formatPeriod(
                        $record->summary_expected_from,
                        $record->summary_expected_until,
                    ))
                    ->alignCenter()
                    ->width('130px'),

                TextColumn::make('summary_actual_period')
                    ->label('入荷日')
                    ->state(fn (WmsOrderIncomingSchedule $record): string => self::formatPeriod(
                        $record->summary_actual_from,
                        $record->summary_actual_until,
                    ))
                    ->alignCenter()
                    ->width('130px'),

                TextColumn::make('summary_last_confirmed_at')
                    ->label('データ連携時刻')
                    ->formatStateUsing(fn ($state): string => self::formatDateTime($state))
                    ->alignCenter()
                    ->width('130px'),

                TextColumn::make('summary_transmission_state')
                    ->label('仕入連携')
                    ->state(fn (WmsOrderIncomingSchedule $record): string => self::transmissionStateLabel($record))
                    ->badge()
                    ->color(fn (WmsOrderIncomingSchedule $record): string => self::transmissionStateColor($record))
                    ->alignCenter()
                    ->width('110px'),

                TextColumn::make('summary_untransmitted_count')
                    ->label('未連携')
                    ->numeric()
                    ->alignEnd()
                    ->width('80px'),

                TextColumn::make('summary_transmitted_count')
                    ->label('連携済')
                    ->numeric()
                    ->alignEnd()
                    ->width('80px'),
            ])
            ->filters([
                static::contractorFilter(),
                SelectFilter::make('purchase_transmission_state')
                    ->label('仕入連携')
                    ->options([
                        'untransmitted' => '未連携あり',
                        'transmitted' => '連携済みのみ',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'untransmitted' => $query->whereNull('purchase_queue_id'),
                        'transmitted' => $query->whereNotNull('purchase_queue_id'),
                        default => $query,
                    }),
                Filter::make('expected_arrival_date')
                    ->label('予定日')
                    ->form([
                        DatePicker::make('from')
                            ->label('予定日 From'),
                        DatePicker::make('until')
                            ->label('予定日 To'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('expected_arrival_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('expected_arrival_date', '<=', $date))),
                Filter::make('confirmed_at')
                    ->label('データ連携時刻')
                    ->form([
                        DatePicker::make('from')
                            ->label('連携日 From'),
                        DatePicker::make('until')
                            ->label('連携日 To'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('confirmed_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('confirmed_at', '<=', $date))),
            ])
            ->recordActionsColumnLabel('操作')
            ->recordAction('viewDetails')
            ->recordUrl(null)
            ->recordActions([
                Action::make('viewDetails')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (?WmsOrderIncomingSchedule $record): string => self::detailHeading($record))
                    ->modalWidth('7xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる')
                    ->modalContent(fn (?WmsOrderIncomingSchedule $record) => view(
                        'filament.components.incoming-completed-summary-detail',
                        [
                            'summary' => self::summaryData($record),
                            'details' => self::detailRows($record),
                        ],
                    )),
                Action::make('transmitPurchaseGroup')
                    ->label('仕入連携')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->visible(fn (WmsOrderIncomingSchedule $record): bool => self::canTransmitSummaryGroup($record))
                    ->requiresConfirmation()
                    ->modalHeading(fn (WmsOrderIncomingSchedule $record): string => self::purchaseTransmissionHeading($record))
                    ->modalDescription(fn (WmsOrderIncomingSchedule $record): string => self::purchaseTransmissionDescription($record))
                    ->modalSubmitActionLabel('仕入連携する')
                    ->modalCancelActionLabel('連携せず閉じる')
                    ->action(function (WmsOrderIncomingSchedule $record): void {
                        self::transmitSelectedPurchaseGroups(collect([$record]));
                    }),
            ], position: RecordActionsPosition::BeforeColumns)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('transmitSelectedPurchaseGroups')
                        ->label('チェックした仕入データ連携')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('チェックした仕入データ連携')
                        ->modalDescription(fn (Collection $records): string => "選択した {$records->count()} 件の発注先グループの未連携入荷完了データを基幹システムの仕入キューに登録します。同一の倉庫・仕入先・伝票番号・入荷日ごとに1伝票としてまとめられます。登録後はデータの修正ができなくなります。")
                        ->modalSubmitActionLabel('連携する')
                        ->modalCancelActionLabel('連携せず閉じる')
                        ->action(function (Collection $records): void {
                            self::transmitSelectedPurchaseGroups($records);
                        }),
                ]),
            ])
            ->defaultKeySort(false)
            ->defaultSort(
                fn (Builder $query): Builder => $query
                    ->orderByDesc('summary_last_confirmed_at')
                    ->orderBy('warehouse_id')
                    ->orderBy('contractor_id'),
                'desc'
            );
    }

    private static function detailHeading(?WmsOrderIncomingSchedule $record): string
    {
        if (! $record) {
            return '入荷完了明細';
        }

        $contractor = $record->contractor;
        $contractorName = $contractor
            ? "[{$contractor->code}]{$contractor->name}"
            : '発注先不明';

        return "入荷完了明細: {$contractorName}";
    }

    private static function summaryData(?WmsOrderIncomingSchedule $record): array
    {
        if (! $record) {
            return [
                'warehouse' => '-',
                'contractor' => '-',
                'detail_count' => 0,
                'item_count' => 0,
                'slip_count' => 0,
                'expected_period' => '-',
                'actual_period' => '-',
                'last_confirmed_at' => '-',
                'transmission_state' => '-',
            ];
        }

        return [
            'warehouse' => self::warehouseLabel($record),
            'contractor' => self::contractorLabel($record),
            'detail_count' => (int) ($record->summary_detail_count ?? 0),
            'item_count' => (int) ($record->summary_item_count ?? 0),
            'slip_count' => (int) ($record->summary_slip_count ?? 0),
            'expected_period' => self::formatPeriod($record->summary_expected_from, $record->summary_expected_until),
            'actual_period' => self::formatPeriod($record->summary_actual_from, $record->summary_actual_until),
            'last_confirmed_at' => self::formatDateTime($record->summary_last_confirmed_at),
            'transmission_state' => self::transmissionStateLabel($record),
        ];
    }

    private static function canTransmitSummaryGroup(WmsOrderIncomingSchedule $record): bool
    {
        return (int) ($record->warehouse_id ?? 0) > 0
            && (int) ($record->contractor_id ?? 0) > 0
            && (int) ($record->summary_untransmitted_count ?? 0) > 0;
    }

    private static function transmitSelectedPurchaseGroups(Collection $records): void
    {
        $queueCount = 0;
        $scheduleCount = 0;
        $errors = [];
        $skippedCount = 0;
        $service = app(IncomingTransmissionService::class);

        foreach ($records as $record) {
            if (! $record instanceof WmsOrderIncomingSchedule || ! self::canTransmitSummaryGroup($record)) {
                $skippedCount++;

                continue;
            }

            try {
                $result = $service->transmitConfirmedIncomings(
                    warehouseId: (int) $record->warehouse_id,
                    contractorId: (int) $record->contractor_id,
                );
            } catch (\Throwable $throwable) {
                $errors[] = self::summaryGroupLabel($record).': '.$throwable->getMessage();

                continue;
            }

            $queueCount += (int) ($result['queue_count'] ?? 0);
            $scheduleCount += (int) ($result['schedule_count'] ?? 0);

            foreach ($result['errors'] ?? [] as $error) {
                $errors[] = self::summaryGroupLabel($record).': '.(is_array($error)
                    ? ($error['error'] ?? json_encode($error, JSON_UNESCAPED_UNICODE))
                    : (string) $error);
            }
        }

        $notification = Notification::make()
            ->title($errors === [] ? '仕入キューに登録しました' : '一部エラーが発生しました')
            ->body(self::purchaseTransmissionResultMessage($queueCount, $scheduleCount, $skippedCount, $errors));

        ($errors === [] ? $notification->success() : $notification->warning())->send();
    }

    private static function purchaseTransmissionHeading(WmsOrderIncomingSchedule $record): string
    {
        return self::contractorLabel($record).' 仕入連携';
    }

    private static function purchaseTransmissionDescription(WmsOrderIncomingSchedule $record): string
    {
        $detailCount = number_format((int) ($record->summary_untransmitted_count ?? 0));
        $slipCount = number_format((int) ($record->summary_slip_count ?? 0));

        return self::summaryGroupLabel($record)
            ." の未連携入荷完了データ {$detailCount}件を基幹システムの仕入キューに登録します。"
            .'同一の倉庫・仕入先・伝票番号・入荷日ごとに1伝票としてまとめられます。'
            ."グループ内伝票数: {$slipCount}件。登録後はデータの修正ができなくなります。";
    }

    private static function purchaseTransmissionResultMessage(
        int $queueCount,
        int $scheduleCount,
        int $skippedCount,
        array $errors,
    ): string {
        $message = "キュー: {$queueCount}件 / 入荷データ: {$scheduleCount}件";

        if ($skippedCount > 0) {
            $message .= " / スキップ: {$skippedCount}件";
        }

        if ($errors !== []) {
            $message .= "\nエラー: ".count($errors).'件';
            $message .= "\n".collect($errors)->take(5)->implode("\n");
        }

        return $message;
    }

    private static function summaryGroupLabel(WmsOrderIncomingSchedule $record): string
    {
        return self::warehouseLabel($record).' / '.self::contractorLabel($record);
    }

    private static function detailRows(?WmsOrderIncomingSchedule $record): array
    {
        if (! $record) {
            return [];
        }

        return WmsOrderIncomingSchedule::query()
            ->confirmed()
            ->withoutTransferSource()
            ->where('warehouse_id', $record->warehouse_id)
            ->where('contractor_id', $record->contractor_id)
            ->addSelect([
                'computed_default_location' => static::defaultLocationSubquery('wms_order_incoming_schedules'),
            ])
            ->with(['warehouse', 'contractor', 'item', 'location', 'confirmedByUser', 'confirmedByPicker'])
            ->orderByDesc('confirmed_at')
            ->orderBy('slip_number')
            ->orderBy('id')
            ->get()
            ->map(fn (WmsOrderIncomingSchedule $detail): array => self::detailRow($detail))
            ->values()
            ->all();
    }

    private static function detailRow(WmsOrderIncomingSchedule $record): array
    {
        $quantities = self::quantityData($record);

        return [
            'id' => $record->id,
            'slip_number' => $record->slip_number ?: '-',
            'order_source' => self::orderSourceLabel($record),
            'order_date' => self::formatDate($record->order_date),
            'expected_arrival_date' => self::formatDate($record->expected_arrival_date),
            'actual_arrival_date' => self::formatDate($record->actual_arrival_date),
            'confirmed_at' => self::formatDateTime($record->confirmed_at),
            'item_code' => $record->item_code ?: $record->item?->code ?: '-',
            'search_code' => $record->search_code ?: '-',
            'item_name' => $record->item?->name ?: '-',
            'capacity_case' => self::numberOrDash($quantities['capacity_case']),
            'expected_case_quantity' => self::numberOrDash($quantities['expected_case_quantity']),
            'expected_piece_quantity' => self::numberOrDash($quantities['expected_piece_quantity']),
            'expected_total_piece_quantity' => self::numberOrDash($record->expected_piece_quantity),
            'received_total_piece_quantity' => self::numberOrDash($record->received_piece_quantity),
            'shortage_quantity' => self::numberOrDash($record->shortage_quantity),
            'expiration_date' => self::formatDate($record->expiration_date),
            'location' => self::locationLabel($record),
            'confirmed_by' => $record->confirmedByUser?->name ?? $record->confirmedByPicker?->name ?? '-',
            'purchase_state' => $record->purchase_queue_id ? '連携済' : '未連携',
            'purchase_slip_number' => $record->purchase_slip_number ?: '-',
        ];
    }

    private static function quantityData(WmsOrderIncomingSchedule $record): array
    {
        $capacity = max(1, (int) ($record->item?->capacity_case ?? 1));
        $quantity = (int) ($record->expected_quantity ?? 0);

        if ($record->quantity_type === QuantityType::CASE) {
            return [
                'capacity_case' => $capacity,
                'expected_case_quantity' => $quantity,
                'expected_piece_quantity' => 0,
            ];
        }

        if ($capacity <= 1) {
            return [
                'capacity_case' => $capacity,
                'expected_case_quantity' => 0,
                'expected_piece_quantity' => $quantity,
            ];
        }

        return [
            'capacity_case' => $capacity,
            'expected_case_quantity' => intdiv($quantity, $capacity),
            'expected_piece_quantity' => $quantity % $capacity,
        ];
    }

    private static function transmissionStateLabel(WmsOrderIncomingSchedule $record): string
    {
        $untransmitted = (int) ($record->summary_untransmitted_count ?? 0);
        $transmitted = (int) ($record->summary_transmitted_count ?? 0);

        if ($untransmitted > 0 && $transmitted > 0) {
            return '一部未連携';
        }

        if ($untransmitted > 0) {
            return '未連携';
        }

        return '連携済';
    }

    private static function transmissionStateColor(WmsOrderIncomingSchedule $record): string
    {
        return match (self::transmissionStateLabel($record)) {
            '未連携' => 'warning',
            '一部未連携' => 'info',
            default => 'success',
        };
    }

    private static function orderSourceLabel(WmsOrderIncomingSchedule $record): string
    {
        if ($record->isUnassignedJxReceived()) {
            return '不明';
        }

        $source = $record->order_source;

        if ($source instanceof OrderSource) {
            return match ($source) {
                OrderSource::AUTO => '発注',
                OrderSource::MANUAL => '手動',
                OrderSource::TRANSFER => '移動',
                OrderSource::RECEIVED => '受信',
                OrderSource::APP_UNPLANNED => '予定なし',
            };
        }

        return OrderSource::tryFrom((string) $source)?->label() ?? '-';
    }

    private static function warehouseLabel(WmsOrderIncomingSchedule $record): string
    {
        return $record->warehouse
            ? "[{$record->warehouse->code}]{$record->warehouse->name}"
            : '-';
    }

    private static function contractorLabel(WmsOrderIncomingSchedule $record): string
    {
        return $record->contractor
            ? "[{$record->contractor->code}]{$record->contractor->name}"
            : '-';
    }

    private static function locationLabel(WmsOrderIncomingSchedule $record): string
    {
        $defaultLocation = trim((string) $record->getAttribute('computed_default_location'));

        if ($defaultLocation !== '') {
            return $defaultLocation;
        }

        if (! $record->location) {
            return '-';
        }

        return collect([$record->location->code1, $record->location->code2, $record->location->code3])
            ->filter(fn ($code): bool => filled($code))
            ->implode('-') ?: '-';
    }

    private static function formatPeriod($from, $until): string
    {
        $fromText = self::formatDate($from);
        $untilText = self::formatDate($until);

        if ($fromText === '-' && $untilText === '-') {
            return '-';
        }

        if ($fromText === $untilText) {
            return $fromText;
        }

        return "{$fromText} - {$untilText}";
    }

    private static function formatDate($value): string
    {
        if (! $value) {
            return '-';
        }

        try {
            return $value instanceof Carbon
                ? $value->format('Y/m/d')
                : Carbon::parse($value)->format('Y/m/d');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private static function formatDateTime($value): string
    {
        if (! $value) {
            return '-';
        }

        try {
            return $value instanceof Carbon
                ? $value->format('Y/m/d H:i')
                : Carbon::parse($value)->format('Y/m/d H:i');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private static function numberOrDash($value): string
    {
        $number = (int) ($value ?? 0);

        return $number > 0 ? number_format($number) : '-';
    }
}
