<?php

namespace App\Console\Commands\AutoOrder;

use App\Enums\AutoOrder\OrderSource;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SyncOrderDatesToCreatedDateCommand extends Command
{
    protected $signature = 'wms:sync-order-dates-to-created-date
        {--table=* : 対象: incoming, data-files, jx-documents。未指定時は全対象}
        {--source=* : incoming の order_source。未指定時は AUTO, TRANSFER}
        {--status=* : incoming の status。未指定時は全ステータス}
        {--created-date= : 対象作成日（YYYY-MM-DD）。DATE(created_at) で判定}
        {--since= : 対象 created_at 開始（例: 2026-06-15 00:00:00）}
        {--until= : 対象 created_at 終了}
        {--limit= : 各テーブルの最大更新件数}
        {--execute : 実更新する。未指定時は dry-run}
        {--yes : 確認なしで実行}';

    protected $description = 'WMS発注系データの order_date を DATE(created_at) に同期する';

    private const DEFAULT_TABLES = ['incoming', 'data-files', 'jx-documents'];

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $tables = $this->resolveTargetTables();

        if ($tables->isEmpty()) {
            $this->error('--table は incoming, data-files, jx-documents のいずれかを指定してください。');

            return self::FAILURE;
        }

        if ($execute && ! $this->hasDateGuard()) {
            $this->error('--execute には --created-date / --since / --until のいずれかが必要です。');

            return self::FAILURE;
        }

        $targets = $this->collectTargets($tables);
        $total = $targets->sum(fn (array $target): int => $target['rows']->count());

        $this->info(($execute ? '実更新' : 'dry-run').' 対象: '.$total.' 件');

        if ($total === 0) {
            return self::SUCCESS;
        }

        $this->printSummary($targets);
        $this->printSample($targets);

        if (! $execute) {
            $this->warn('更新はしていません。実行する場合は --execute を付けてください。');

            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm('表示した order_date を DATE(created_at) に同期しますか？')) {
            $this->info('キャンセルしました。');

            return self::SUCCESS;
        }

        $updated = 0;
        foreach ($targets as $target) {
            $updated += $this->syncTarget($target);
        }

        $this->info("完了: {$updated} 件を更新しました。");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, string>
     */
    private function resolveTargetTables(): Collection
    {
        $requested = collect($this->option('table'))
            ->filter()
            ->map(fn (string $table): string => Str::of($table)->lower()->toString());

        if ($requested->isEmpty()) {
            return collect(self::DEFAULT_TABLES);
        }

        $allowed = collect(self::DEFAULT_TABLES);

        return $requested
            ->filter(fn (string $table): bool => $allowed->contains($table))
            ->unique()
            ->values();
    }

    private function hasDateGuard(): bool
    {
        return filled($this->option('created-date'))
            || filled($this->option('since'))
            || filled($this->option('until'));
    }

    /**
     * @param  Collection<int, string>  $tables
     * @return Collection<int, array{name: string, label: string, table: string, rows: Collection<int, object>}>
     */
    private function collectTargets(Collection $tables): Collection
    {
        return $tables
            ->map(fn (string $name): ?array => $this->targetDefinition($name))
            ->filter()
            ->filter(function (array $definition): bool {
                if (Schema::connection('sakemaru')->hasTable($definition['table'])) {
                    return true;
                }

                $this->warn("skip: {$definition['table']} が存在しません。");

                return false;
            })
            ->map(function (array $definition): array {
                $definition['rows'] = $this->targetRows($definition);

                return $definition;
            })
            ->values();
    }

    private function targetDefinition(string $name): ?array
    {
        return match ($name) {
            'incoming' => [
                'name' => 'incoming',
                'label' => '入荷予定',
                'table' => 'wms_order_incoming_schedules',
            ],
            'data-files' => [
                'name' => 'data-files',
                'label' => '発注データ',
                'table' => 'wms_order_data_files',
            ],
            'jx-documents' => [
                'name' => 'jx-documents',
                'label' => 'JX発注文書',
                'table' => 'wms_order_jx_documents',
            ],
            default => null,
        };
    }

    /**
     * @param  array{name: string, label: string, table: string}  $definition
     * @return Collection<int, object>
     */
    private function targetRows(array $definition): Collection
    {
        $query = $this->baseTargetQuery($definition)
            ->orderBy('id')
            ->select($this->selectColumns($definition));

        if ($limit = $this->limit()) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * @param  array{name: string, table: string}  $definition
     */
    private function baseTargetQuery(array $definition): Builder
    {
        $query = DB::connection('sakemaru')
            ->table($definition['table'])
            ->whereNotNull('created_at')
            ->whereNotNull('order_date')
            ->whereRaw('DATE(created_at) <> order_date');

        if ($this->option('created-date')) {
            $query->whereDate('created_at', $this->option('created-date'));
        }

        if ($this->option('since')) {
            $query->where('created_at', '>=', $this->option('since'));
        }

        if ($this->option('until')) {
            $query->where('created_at', '<=', $this->option('until'));
        }

        if ($definition['name'] === 'incoming') {
            $query->whereIn('order_source', $this->incomingSources());

            $statuses = $this->incomingStatuses();
            if ($statuses->isNotEmpty()) {
                $query->whereIn('status', $statuses->all());
            }
        }

        return $query;
    }

    /**
     * @return array<int, mixed>
     */
    private function selectColumns(array $definition): array
    {
        $columns = [
            'id',
            'order_date',
            'created_at',
            DB::raw('DATE(created_at) as created_date'),
        ];

        if ($definition['name'] === 'incoming') {
            $columns[] = 'order_source';
            $columns[] = 'status';
            $columns[] = 'slip_number';
        }

        if ($definition['name'] === 'data-files') {
            $columns[] = 'batch_code';
            $columns[] = 'status';
        }

        if ($definition['name'] === 'jx-documents') {
            $columns[] = 'batch_code';
            $columns[] = 'status';
            $columns[] = 'file_path';
        }

        return $columns;
    }

    /**
     * @return array<int, string>
     */
    private function incomingSources(): array
    {
        $sources = collect($this->option('source'))
            ->filter()
            ->map(fn (string $source): string => strtoupper($source))
            ->unique()
            ->values();

        if ($sources->isEmpty()) {
            return [
                OrderSource::AUTO->value,
                OrderSource::TRANSFER->value,
            ];
        }

        return $sources->all();
    }

    /**
     * @return Collection<int, string>
     */
    private function incomingStatuses(): Collection
    {
        return collect($this->option('status'))
            ->filter()
            ->map(fn (string $status): string => strtoupper($status))
            ->unique()
            ->values();
    }

    private function limit(): ?int
    {
        $limit = $this->option('limit');

        if (! filled($limit)) {
            return null;
        }

        return max(1, (int) $limit);
    }

    /**
     * @param  Collection<int, array{name: string, label: string, table: string, rows: Collection<int, object>}>  $targets
     */
    private function printSummary(Collection $targets): void
    {
        $summary = $targets->flatMap(function (array $target): Collection {
            return $target['rows']
                ->groupBy(fn (object $row): string => implode('|', [
                    $row->order_source ?? '-',
                    $row->status ?? '-',
                    $row->order_date,
                    $row->created_date,
                ]))
                ->map(function (Collection $rows) use ($target): array {
                    $first = $rows->first();

                    return [
                        'target' => $target['label'],
                        'source' => $first->order_source ?? '-',
                        'status' => $first->status ?? '-',
                        'order_date' => $first->order_date,
                        'sync_to' => $first->created_date,
                        'count' => $rows->count(),
                        'min_id' => $rows->min('id'),
                        'max_id' => $rows->max('id'),
                    ];
                })
                ->values();
        });

        $this->table(
            ['対象', 'source', 'status', '現在order_date', '同期先', '件数', 'min ID', 'max ID'],
            $summary->map(fn (array $row): array => [
                $row['target'],
                $row['source'],
                $row['status'],
                $row['order_date'],
                $row['sync_to'],
                $row['count'],
                $row['min_id'],
                $row['max_id'],
            ])->all()
        );
    }

    /**
     * @param  Collection<int, array{name: string, label: string, table: string, rows: Collection<int, object>}>  $targets
     */
    private function printSample(Collection $targets): void
    {
        $sample = $targets->flatMap(fn (array $target): Collection => $target['rows']
            ->take(10)
            ->map(fn (object $row): array => [
                'target' => $target['label'],
                'id' => $row->id,
                'source' => $row->order_source ?? '-',
                'status' => $row->status ?? '-',
                'batch' => $row->batch_code ?? '-',
                'slip' => $row->slip_number ?? '-',
                'order_date' => $row->order_date,
                'created_at' => $row->created_at,
                'sync_to' => $row->created_date,
            ]));

        $this->table(
            ['対象', 'ID', 'source', 'status', 'batch', 'slip', '現在order_date', 'created_at', '同期先'],
            $sample->all()
        );
    }

    /**
     * @param  array{name: string, label: string, table: string, rows: Collection<int, object>}  $target
     */
    private function syncTarget(array $target): int
    {
        $updated = 0;

        foreach ($target['rows']->pluck('id')->chunk(500) as $ids) {
            $updated += DB::connection('sakemaru')->transaction(function () use ($target, $ids): int {
                return DB::connection('sakemaru')
                    ->table($target['table'])
                    ->whereIn('id', $ids->all())
                    ->whereRaw('DATE(created_at) <> order_date')
                    ->update([
                        'order_date' => DB::raw('DATE(created_at)'),
                    ]);
            });
        }

        $this->info("{$target['label']}: {$updated} 件更新");

        return $updated;
    }
}
