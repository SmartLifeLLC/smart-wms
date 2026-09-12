<?php

namespace App\Console\Commands\AutoOrder;

use App\Enums\AutoOrder\TransmissionType;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegisterReceivedIncomingPurchasesCommand extends Command
{
    protected $signature = 'wms:incoming-register-received-purchases
                            {--since= : 対象の更新日時開始（例: 2026-05-11 04:30:00）}
                            {--until= : 対象の更新日時終了}
                            {--limit= : 対象入荷予定数の上限}
                            {--dry-run : 対象確認のみで更新しない}
                            {--yes : 確認なしで実行}';

    protected $description = 'JX受信で反映済みの入荷予定を仕入キューへ登録する';

    public function handle(): int
    {
        $schedules = $this->resolveSchedules();

        if ($schedules->isEmpty()) {
            $this->warn('仕入登録対象のJX受信入荷予定がありません。');

            return self::SUCCESS;
        }

        [$groups, $skipped] = $this->groupSchedules($schedules);

        $this->table(
            ['対象入荷予定', '登録可能', 'スキップ', '仕入キュー予定'],
            [[
                $schedules->count(),
                $groups->flatten(1)->count(),
                count($skipped),
                $groups->sum(fn (Collection $rows): int => (int) ceil($rows->count() / 100)),
            ]]
        );

        if (! empty($skipped)) {
            $this->warn('マスタまたは日付不足によりスキップされる入荷予定があります。');
            $this->table(
                ['ID', '倉庫CD', '仕入先CD', '伝票番号', '商品CD', '発注日', '入荷日'],
                collect($skipped)->take(20)->map(fn (array $row): array => [
                    $row['id'],
                    $row['warehouse_code'] ?: '-',
                    $row['supplier_code'] ?: '-',
                    $row['slip_number'] ?: '-',
                    $row['item_code'] ?: '-',
                    $row['process_date'] ?: '-',
                    $row['delivered_date'] ?: '-',
                ])->all()
            );
        }

        if ($this->option('dry-run')) {
            $this->warn('--dry-run のため、仕入キュー登録は行いません。');

            return self::SUCCESS;
        }

        if ($groups->isEmpty()) {
            $this->error('登録可能な入荷予定がありません。');

            return self::FAILURE;
        }

        if (! $this->option('yes') && ! $this->confirm('表示したJX受信入荷予定を仕入キューへ登録しますか？')) {
            $this->info('キャンセルしました。');

            return self::SUCCESS;
        }

        $result = DB::connection('sakemaru')->transaction(fn (): array => $this->registerPurchaseQueues($groups));

        $this->info("完了: 仕入キュー {$result['queue_count']}件 / 入荷予定 {$result['schedule_count']}件");
        $this->line('仕入キューID: '.implode(', ', $result['queue_ids']));

        return self::SUCCESS;
    }

    private function resolveSchedules(): Collection
    {
        $query = DB::connection('sakemaru')
            ->table('wms_order_incoming_schedules as s')
            ->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->join('items as i', 'i.id', '=', 's.item_id')
            ->leftJoin('contractors as ct', 'ct.id', '=', 's.contractor_id')
            ->leftJoin('suppliers as contractor_sup', 'contractor_sup.id', '=', 'ct.supplier_id')
            ->leftJoin('partners as contractor_supplier_partner', 'contractor_supplier_partner.id', '=', 'contractor_sup.partner_id')
            ->leftJoin('suppliers as sup', 'sup.id', '=', 's.supplier_id')
            ->leftJoin('partners as supplier_partner', 'supplier_partner.id', '=', 'sup.partner_id')
            ->where('s.order_source', 'RECEIVED')
            ->where('s.is_receive_matched', true)
            ->where('s.status', 'PENDING')
            ->where('s.received_quantity', '>', 0)
            ->whereNull('s.transfer_candidate_id')
            ->whereNull('s.source_warehouse_id')
            ->whereNull('s.stock_transfer_id')
            ->whereNotExists(function ($subQuery) {
                $subQuery
                    ->selectRaw('1')
                    ->from('wms_contractor_settings as purchase_transmission_settings')
                    ->whereColumn('purchase_transmission_settings.contractor_id', 's.contractor_id')
                    ->where('purchase_transmission_settings.transmission_type', TransmissionType::INTERNAL->value);
            })
            ->select([
                's.id',
                's.warehouse_id',
                's.contractor_id',
                's.supplier_id',
                's.item_id',
                's.item_code',
                's.slip_number',
                's.received_quantity',
                's.shortage_quantity',
                's.quantity_type',
                's.order_date',
                's.expected_arrival_date',
                's.actual_arrival_date',
                's.confirmed_at',
                's.expiration_date',
                'w.code as warehouse_code',
                'i.code as master_item_code',
                'ct.code as contractor_code',
                DB::raw('COALESCE(contractor_supplier_partner.code, supplier_partner.code) as supplier_code'),
            ])
            ->orderBy('s.id');

        if ($this->option('since')) {
            $query->where('s.updated_at', '>=', $this->option('since'));
        }

        if ($this->option('until')) {
            $query->where('s.updated_at', '<=', $this->option('until'));
        }

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        return $query->get();
    }

    private function groupSchedules(Collection $schedules): array
    {
        $skipped = [];
        $rows = $schedules->map(function (object $schedule) use (&$skipped): ?array {
            $supplierCode = trim((string) $schedule->supplier_code);
            $warehouseCode = trim((string) $schedule->warehouse_code);
            $itemCode = trim((string) ($schedule->master_item_code ?: $schedule->item_code));
            $slipNumber = trim((string) $schedule->slip_number);
            $processDate = $this->dateOnly($schedule->order_date);
            $deliveredDate = $this->dateOnly($schedule->actual_arrival_date) ?? $this->dateOnly($schedule->confirmed_at);
            $accountDate = $deliveredDate;

            if (
                ! $schedule->supplier_id
                || $supplierCode === ''
                || $warehouseCode === ''
                || $itemCode === ''
                || $slipNumber === ''
                || $processDate === null
                || $deliveredDate === null
                || $accountDate === null
            ) {
                $skipped[] = [
                    'id' => $schedule->id,
                    'warehouse_code' => $warehouseCode,
                    'supplier_code' => $supplierCode,
                    'slip_number' => $slipNumber,
                    'item_code' => $itemCode,
                    'process_date' => $processDate,
                    'delivered_date' => $deliveredDate,
                ];

                return null;
            }

            return [
                'schedule' => $schedule,
                'warehouse_code' => $warehouseCode,
                'supplier_code' => $supplierCode,
                'slip_number' => $slipNumber,
                'process_date' => $processDate,
                'delivered_date' => $deliveredDate,
                'account_date' => $accountDate,
                'item_code' => $itemCode,
            ];
        })->filter();

        return [
            $rows->groupBy(fn (array $row): string => "{$row['warehouse_code']}|{$row['supplier_code']}|{$row['slip_number']}|{$row['process_date']}|{$row['delivered_date']}|{$row['account_date']}"),
            $skipped,
        ];
    }

    private function dateOnly(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr((string) $value, 0, 10);
    }

    private function registerPurchaseQueues(Collection $groups): array
    {
        $queueIds = [];
        $scheduleCount = 0;
        $now = now();

        foreach ($groups as $rows) {
            foreach ($rows->chunk(100) as $chunk) {
                $first = $chunk->first();
                $queueId = DB::connection('sakemaru')->table('purchase_create_queue')->insertGetId([
                    'request_uuid' => Str::uuid()->toString(),
                    'slip_number' => $first['slip_number'],
                    'delivered_date' => $first['delivered_date'],
                    'items' => json_encode($this->buildPurchaseData($chunk), JSON_UNESCAPED_UNICODE),
                    'status' => 'BEFORE',
                    'retry_count' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $ids = $chunk->map(fn (array $row): int => (int) $row['schedule']->id)->all();
                $updated = DB::connection('sakemaru')
                    ->table('wms_order_incoming_schedules')
                    ->whereIn('id', $ids)
                    ->where('status', 'PENDING')
                    ->update([
                        'status' => 'TRANSMITTED',
                        'actual_arrival_date' => $first['delivered_date'],
                        'purchase_queue_id' => $queueId,
                        'updated_at' => $now,
                    ]);

                $queueIds[] = $queueId;
                $scheduleCount += $updated;
            }
        }

        return [
            'queue_count' => count($queueIds),
            'schedule_count' => $scheduleCount,
            'queue_ids' => $queueIds,
        ];
    }

    private function buildPurchaseData(Collection $rows): array
    {
        $first = $rows->first();

        return [
            'process_date' => $first['process_date'],
            'delivered_date' => $first['delivered_date'],
            'account_date' => $first['account_date'],
            'supplier_code' => $first['supplier_code'],
            'warehouse_code' => $first['warehouse_code'],
            'slip_number' => $first['slip_number'],
            'note' => 'JX受信データ一括登録 / '.now()->format('Y-m-d H:i:s'),
            'details' => $rows->map(fn (array $row): array => $this->buildDetail($row))->values()->all(),
        ];
    }

    private function buildDetail(array $row): array
    {
        $schedule = $row['schedule'];
        $detail = [
            'item_code' => $row['item_code'],
            'quantity' => (int) $schedule->received_quantity,
            'quantity_type' => $schedule->quantity_type ?: 'PIECE',
            'shortage_quantity' => (int) ($schedule->shortage_quantity ?? 0),
        ];

        if ($schedule->expiration_date) {
            $detail['expiration_date'] = substr((string) $schedule->expiration_date, 0, 10);
        }

        return $detail;
    }
}
