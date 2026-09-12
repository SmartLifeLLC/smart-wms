<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Contractor;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderIncomingSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class JxOrderArrivalDateAdjustmentService
{
    private const MAX_LOOKAHEAD_DAYS = 14;

    public function earliestArrivalDate(int $contractorId, int $warehouseId, ?Carbon $orderDate = null): ?Carbon
    {
        $orderDay = ($orderDate ?? Carbon::parse(ClientSetting::freshSystemDateYMD('jx_arrival_date:default')))
            ->copy()
            ->startOfDay();
        $contractor = Contractor::with('leadTime')->find($contractorId);

        if (! $contractor) {
            return $orderDay->copy()->addDay();
        }

        $arrivalDate = app(ContractorLeadTimeService::class)
            ->calculateArrivalDate($contractor, $orderDay)['arrival_date']
            ->copy()
            ->startOfDay();

        if ($arrivalDate->lte($orderDay)) {
            $arrivalDate = $orderDay->copy()->addDay();
        }

        $deliveryDays = $this->loadDeliveryDaySetting($contractorId, $warehouseId);

        if ($deliveryDays === null) {
            return $arrivalDate;
        }

        if (! in_array(true, $deliveryDays, true)) {
            return null;
        }

        $warehouseHolidays = $this->loadWarehouseHolidaysForWarehouse($warehouseId, $orderDay, $arrivalDate);

        if ($this->isArrivalDateAllowed($arrivalDate, $warehouseId, $deliveryDays, $warehouseHolidays)) {
            return $arrivalDate;
        }

        return $this->findNextArrivalDate(
            $arrivalDate->copy()->subDay(),
            $warehouseId,
            $deliveryDays,
            $warehouseHolidays
        );
    }

    /**
     * @param  Collection<int, WmsOrderCandidate>  $candidates
     * @return array{
     *     eligible_candidate_ids: array<int>,
     *     excluded_candidate_ids: array<int>,
     *     adjusted: array<int, array<string, mixed>>,
     *     errors: array<int, array<string, mixed>>,
     *     adjusted_count: int,
     *     excluded_count: int
     * }
     */
    public function adjust(Collection $candidates, Carbon $executionDate, bool $dryRun = false): array
    {
        $candidates = $candidates->values();
        $executionDay = $executionDate->copy()->startOfDay();

        if ($candidates->isEmpty()) {
            return $this->result([], [], [], []);
        }

        $candidatesWithArrivalDate = $candidates
            ->filter(fn (WmsOrderCandidate $candidate): bool => $candidate->expected_arrival_date !== null)
            ->values();

        if ($candidatesWithArrivalDate->isEmpty()) {
            return $this->result($candidates->pluck('id')->map(fn ($id) => (int) $id)->all(), [], [], []);
        }

        $deliveryDaySettings = $this->loadDeliveryDaySettings($candidatesWithArrivalDate);
        $warehouseHolidays = $this->loadWarehouseHolidays($candidatesWithArrivalDate, $executionDay);

        $adjustments = [];
        $excludedCandidateIds = [];
        $errors = [];

        foreach ($candidatesWithArrivalDate as $candidate) {
            $settingKey = $this->settingKey((int) $candidate->contractor_id, (int) $candidate->warehouse_id);
            $deliveryDays = $deliveryDaySettings[$settingKey] ?? null;

            if (! $this->requiresAdjustment($candidate, $executionDay, $deliveryDays, $warehouseHolidays)) {
                continue;
            }

            if ($deliveryDays === null || ! in_array(true, $deliveryDays, true)) {
                $excludedCandidateIds[] = (int) $candidate->id;
                $errors[] = [
                    'candidate_id' => (int) $candidate->id,
                    'contractor_id' => (int) $candidate->contractor_id,
                    'warehouse_id' => (int) $candidate->warehouse_id,
                    'reason' => '入荷可能曜日設定が未設定、または全曜日不可です',
                ];

                continue;
            }

            $nextArrivalDate = $this->findNextArrivalDate(
                $this->nextArrivalSearchBaseDay($candidate, $executionDay),
                (int) $candidate->warehouse_id,
                $deliveryDays,
                $warehouseHolidays
            );

            if ($nextArrivalDate === null) {
                $excludedCandidateIds[] = (int) $candidate->id;
                $errors[] = [
                    'candidate_id' => (int) $candidate->id,
                    'contractor_id' => (int) $candidate->contractor_id,
                    'warehouse_id' => (int) $candidate->warehouse_id,
                    'reason' => self::MAX_LOOKAHEAD_DAYS.'日以内に入荷可能日がありません',
                ];

                continue;
            }

            $adjustments[] = [
                'candidate_id' => (int) $candidate->id,
                'warehouse_id' => (int) $candidate->warehouse_id,
                'contractor_id' => (int) $candidate->contractor_id,
                'from' => $candidate->expected_arrival_date?->format('Y-m-d'),
                'to' => $nextArrivalDate->format('Y-m-d'),
                'expiration_date' => $this->calculateExpirationDate($candidate, $nextArrivalDate),
            ];
        }

        $excludedCandidateIds = array_values(array_unique($excludedCandidateIds));
        $eligibleCandidateIds = $candidates
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id): bool => in_array($id, $excludedCandidateIds, true))
            ->values()
            ->all();

        if (! $dryRun && ! empty($adjustments)) {
            $this->persistAdjustments($adjustments);
        }

        return $this->result($eligibleCandidateIds, $excludedCandidateIds, $adjustments, $errors);
    }

    /**
     * @param  array<int, bool>|null  $deliveryDays
     * @param  array<int, array<string, bool>>  $warehouseHolidays
     */
    private function requiresAdjustment(
        WmsOrderCandidate $candidate,
        Carbon $executionDay,
        ?array $deliveryDays,
        array $warehouseHolidays
    ): bool {
        if ($candidate->expected_arrival_date === null) {
            return false;
        }

        $arrivalDay = Carbon::parse($candidate->expected_arrival_date)->startOfDay();

        if ($arrivalDay->lte($executionDay)) {
            return true;
        }

        if ($deliveryDays === null) {
            return false;
        }

        if (! in_array(true, $deliveryDays, true)) {
            return true;
        }

        return ! $this->isArrivalDateAllowed($arrivalDay, (int) $candidate->warehouse_id, $deliveryDays, $warehouseHolidays);
    }

    private function nextArrivalSearchBaseDay(WmsOrderCandidate $candidate, Carbon $executionDay): Carbon
    {
        if ($candidate->expected_arrival_date === null) {
            return $executionDay;
        }

        $arrivalDay = Carbon::parse($candidate->expected_arrival_date)->startOfDay();

        return $arrivalDay->gt($executionDay) ? $arrivalDay : $executionDay;
    }

    /**
     * @param  Collection<int, WmsOrderCandidate>  $candidates
     * @return array<string, array<int, bool>>
     */
    private function loadDeliveryDaySettings(Collection $candidates): array
    {
        $contractorIds = $candidates->pluck('contractor_id')->map(fn ($id) => (int) $id)->unique()->values();
        $warehouseIds = $candidates->pluck('warehouse_id')->map(fn ($id) => (int) $id)->unique()->values();

        return DB::connection('sakemaru')
            ->table('wms_contractor_warehouse_delivery_days')
            ->whereIn('contractor_id', $contractorIds)
            ->whereIn('warehouse_id', $warehouseIds)
            ->get()
            ->mapWithKeys(fn ($row): array => [
                $this->settingKey((int) $row->contractor_id, (int) $row->warehouse_id) => [
                    0 => (bool) $row->delivery_sun,
                    1 => (bool) $row->delivery_mon,
                    2 => (bool) $row->delivery_tue,
                    3 => (bool) $row->delivery_wed,
                    4 => (bool) $row->delivery_thu,
                    5 => (bool) $row->delivery_fri,
                    6 => (bool) $row->delivery_sat,
                ],
            ])
            ->all();
    }

    /**
     * @return array<int, bool>|null
     */
    private function loadDeliveryDaySetting(int $contractorId, int $warehouseId): ?array
    {
        $row = DB::connection('sakemaru')
            ->table('wms_contractor_warehouse_delivery_days')
            ->where('contractor_id', $contractorId)
            ->where('warehouse_id', $warehouseId)
            ->first();

        if (! $row) {
            return null;
        }

        return [
            0 => (bool) $row->delivery_sun,
            1 => (bool) $row->delivery_mon,
            2 => (bool) $row->delivery_tue,
            3 => (bool) $row->delivery_wed,
            4 => (bool) $row->delivery_thu,
            5 => (bool) $row->delivery_fri,
            6 => (bool) $row->delivery_sat,
        ];
    }

    /**
     * @param  Collection<int, WmsOrderCandidate>  $candidates
     * @return array<int, array<string, bool>>
     */
    private function loadWarehouseHolidays(Collection $candidates, Carbon $executionDay): array
    {
        $warehouseIds = $candidates->pluck('warehouse_id')->map(fn ($id) => (int) $id)->unique()->values();
        $from = $executionDay->copy()->addDay()->toDateString();
        $latestBaseDay = $candidates
            ->pluck('expected_arrival_date')
            ->filter()
            ->map(fn ($date) => Carbon::parse($date)->startOfDay())
            ->filter(fn (Carbon $date): bool => $date->gt($executionDay))
            ->reduce(fn (?Carbon $latest, Carbon $date): Carbon => $latest === null || $date->gt($latest) ? $date : $latest);
        $to = ($latestBaseDay instanceof Carbon ? $latestBaseDay : $executionDay->copy())
            ->addDays(self::MAX_LOOKAHEAD_DAYS)
            ->toDateString();

        return DB::connection('sakemaru')
            ->table('wms_warehouse_calendars')
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('is_holiday', true)
            ->whereBetween('target_date', [$from, $to])
            ->get(['warehouse_id', 'target_date'])
            ->groupBy('warehouse_id')
            ->map(fn (Collection $rows): array => $rows
                ->mapWithKeys(fn ($row): array => [(string) $row->target_date => true])
                ->all()
            )
            ->all();
    }

    /**
     * @return array<int, array<string, bool>>
     */
    private function loadWarehouseHolidaysForWarehouse(int $warehouseId, Carbon $orderDay, Carbon $arrivalDate): array
    {
        $from = $orderDay->copy()->addDay()->toDateString();
        $to = $arrivalDate->copy()->addDays(self::MAX_LOOKAHEAD_DAYS)->toDateString();

        return [
            $warehouseId => DB::connection('sakemaru')
                ->table('wms_warehouse_calendars')
                ->where('warehouse_id', $warehouseId)
                ->where('is_holiday', true)
                ->whereBetween('target_date', [$from, $to])
                ->get(['target_date'])
                ->mapWithKeys(fn ($row): array => [(string) $row->target_date => true])
                ->all(),
        ];
    }

    /**
     * @param  array<int, bool>  $deliveryDays
     * @param  array<int, array<string, bool>>  $warehouseHolidays
     */
    private function findNextArrivalDate(
        Carbon $baseDay,
        int $warehouseId,
        array $deliveryDays,
        array $warehouseHolidays
    ): ?Carbon {
        $date = $baseDay->copy()->addDay();

        for ($i = 1; $i <= self::MAX_LOOKAHEAD_DAYS; $i++, $date->addDay()) {
            if (! $this->isArrivalDateAllowed($date, $warehouseId, $deliveryDays, $warehouseHolidays)) {
                continue;
            }

            return $date->copy();
        }

        return null;
    }

    /**
     * @param  array<int, bool>  $deliveryDays
     * @param  array<int, array<string, bool>>  $warehouseHolidays
     */
    private function isArrivalDateAllowed(
        Carbon $arrivalDay,
        int $warehouseId,
        array $deliveryDays,
        array $warehouseHolidays
    ): bool {
        if (! ($deliveryDays[$arrivalDay->dayOfWeek] ?? false)) {
            return false;
        }

        return ! ($warehouseHolidays[$warehouseId][$arrivalDay->toDateString()] ?? false);
    }

    private function calculateExpirationDate(WmsOrderCandidate $candidate, Carbon $arrivalDate): ?string
    {
        $days = (int) ($candidate->item?->default_expiration_days ?? 0);

        if ($days <= 0) {
            return null;
        }

        return $arrivalDate->copy()->addDays($days)->format('Y-m-d');
    }

    /**
     * @param  array<int, array<string, mixed>>  $adjustments
     */
    private function persistAdjustments(array $adjustments): void
    {
        DB::connection('sakemaru')->transaction(function () use ($adjustments): void {
            collect($adjustments)
                ->groupBy('to')
                ->each(function (Collection $group, string $arrivalDate): void {
                    WmsOrderCandidate::query()
                        ->whereIn('id', $group->pluck('candidate_id')->all())
                        ->where('status', CandidateStatus::CONFIRMED)
                        ->whereNull('wms_order_jx_document_id')
                        ->update([
                            'expected_arrival_date' => $arrivalDate,
                            'updated_at' => now(),
                        ]);
                });

            foreach ($adjustments as $adjustment) {
                $scheduleUpdate = [
                    'expected_arrival_date' => $adjustment['to'],
                    'updated_at' => now(),
                ];

                if ($adjustment['expiration_date'] !== null) {
                    $scheduleUpdate['expiration_date'] = $adjustment['expiration_date'];
                }

                WmsOrderIncomingSchedule::query()
                    ->where('order_candidate_id', $adjustment['candidate_id'])
                    ->where('status', IncomingScheduleStatus::PENDING->value)
                    ->update($scheduleUpdate);
            }
        }, 3);
    }

    /**
     * @param  array<int>  $eligibleCandidateIds
     * @param  array<int>  $excludedCandidateIds
     * @param  array<int, array<string, mixed>>  $adjustments
     * @param  array<int, array<string, mixed>>  $errors
     * @return array{
     *     eligible_candidate_ids: array<int>,
     *     excluded_candidate_ids: array<int>,
     *     adjusted: array<int, array<string, mixed>>,
     *     errors: array<int, array<string, mixed>>,
     *     adjusted_count: int,
     *     excluded_count: int
     * }
     */
    private function result(array $eligibleCandidateIds, array $excludedCandidateIds, array $adjustments, array $errors): array
    {
        return [
            'eligible_candidate_ids' => $eligibleCandidateIds,
            'excluded_candidate_ids' => $excludedCandidateIds,
            'adjusted' => $adjustments,
            'errors' => $errors,
            'adjusted_count' => count($adjustments),
            'excluded_count' => count($excludedCandidateIds),
        ];
    }

    private function settingKey(int $contractorId, int $warehouseId): string
    {
        return "{$contractorId}:{$warehouseId}";
    }
}
