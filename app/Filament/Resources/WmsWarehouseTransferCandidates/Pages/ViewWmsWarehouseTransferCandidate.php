<?php

namespace App\Filament\Resources\WmsWarehouseTransferCandidates\Pages;

use App\Filament\Resources\WmsWarehouseTransferCandidates\Support\WarehouseTransferCandidateActions;
use App\Filament\Resources\WmsWarehouseTransferCandidates\WmsWarehouseTransferCandidateResource;
use App\Models\Sakemaru\DeliveryCourse;
use App\Models\Sakemaru\Warehouse;
use App\Services\WarehouseTransfer\WarehouseTransferStatusSyncService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Alignment;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;

class ViewWmsWarehouseTransferCandidate extends ViewRecord
{
    protected static string $resource = WmsWarehouseTransferCandidateResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        app(WarehouseTransferStatusSyncService::class)->sync($this->record);
        $this->refreshRecord();
    }

    public function getTitle(): string|Htmlable
    {
        return "倉庫移動候補: {$this->record->candidate_no}";
    }

    public function getMaxContentWidth(): ?string
    {
        return 'full';
    }

    public function refreshRecord(): void
    {
        $this->record = $this->record->fresh(['submittedByPicker', 'confirmedByUser', 'deliveryCourse']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editHeader')
                ->label('ヘッダー編集')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->visible(fn (): bool => $this->record->isPending() && WmsWarehouseTransferCandidateResource::canEdit($this->record))
                ->modalHeading(fn () => "ヘッダー編集: {$this->record->candidate_no}")
                ->modalWidth('2xl')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('変更を保存')->color('danger'))
                ->modalCancelActionLabel('変更せず閉じる')
                ->fillForm(fn (): array => [
                    'to_warehouse_id' => $this->record->to_warehouse_id,
                    'process_date' => $this->record->process_date?->toDateString(),
                    'delivered_date' => $this->record->delivered_date?->toDateString(),
                    'delivery_course_id' => $this->record->delivery_course_id,
                    'memo' => $this->record->memo,
                ])
                ->schema([
                    Select::make('to_warehouse_id')
                        ->label('移動先倉庫')
                        ->required()
                        ->searchable()
                        ->options(fn () => ListWmsWarehouseTransferCandidates::warehouseOptions())
                        ->getSearchResultsUsing(fn (string $search) => ListWmsWarehouseTransferCandidates::warehouseOptions($search))
                        ->rule(fn () => function (string $attribute, $value, \Closure $fail): void {
                            if ((int) $value === (int) $this->record->from_warehouse_id) {
                                $fail('移動元と移動先は同一にできません');
                            }
                        }),
                    DatePicker::make('process_date')->label('処理日')->required(),
                    DatePicker::make('delivered_date')->label('納品日')->required(),
                    Select::make('delivery_course_id')
                        ->label('配送コース')
                        ->helperText('倉庫間配送コース設定に登録がある場合はそちらが優先されます')
                        ->searchable()
                        ->options(fn () => static::deliveryCourseOptions())
                        ->getSearchResultsUsing(fn (string $search) => static::deliveryCourseOptions($search))
                        ->getOptionLabelUsing(function ($value): ?string {
                            $course = DeliveryCourse::find($value);

                            return $course ? "[{$course->code}] {$course->name}" : null;
                        }),
                    Textarea::make('memo')->label('備考')->rows(2),
                ])
                ->action(function (array $data): void {
                    if (! $this->record->isPending()) {
                        Notification::make()->title('確定済みの候補は編集できません')->danger()->send();

                        return;
                    }

                    $toWarehouse = Warehouse::find($data['to_warehouse_id']);
                    if (! $toWarehouse) {
                        Notification::make()->title('移動先倉庫が見つかりません')->danger()->send();

                        return;
                    }

                    $this->record->update([
                        'to_warehouse_id' => $toWarehouse->id,
                        'to_warehouse_code' => $toWarehouse->code,
                        'to_warehouse_name' => $toWarehouse->name,
                        'process_date' => $data['process_date'],
                        'delivered_date' => $data['delivered_date'],
                        'delivery_course_id' => $data['delivery_course_id'] ?: null,
                        'memo' => $data['memo'] ?? null,
                    ]);

                    Notification::make()->title('ヘッダーを更新しました')->success()->send();
                    $this->refreshRecord();
                }),

            WarehouseTransferCandidateActions::confirm(),
            WarehouseTransferCandidateActions::retry(),
            WarehouseTransferCandidateActions::cancel(),
        ];
    }

    public static function deliveryCourseOptions(?string $search = null): array
    {
        $search = $search !== null ? mb_convert_kana($search, 'as') : null;

        return DB::connection('sakemaru')
            ->table('delivery_courses')
            ->where('is_active', 1)
            ->when($search, fn ($q) => $q->where(fn ($qq) => $qq
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('code')
            ->limit(100)
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}] {$c->name}"])
            ->toArray();
    }
}
