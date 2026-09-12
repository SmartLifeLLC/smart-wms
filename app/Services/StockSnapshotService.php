<?php

namespace App\Services;

use App\Support\DbMutex;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class StockSnapshotService
{
    private const CONNECTION = 'sakemaru';

    private const SNAPSHOT_LOCK_TIMEOUT = 10;

    private const PARTITION_LOCK = 'wms:snapshot:partition-maintenance';

    private const TABLES = [
        'wms_stock_snapshots',
        'wms_stock_snapshot_lots',
        'wms_stock_snapshot_verifications',
    ];

    public function capture(string $time = 'morning'): array
    {
        $this->assertSnapshotTime($time);

        $date = now()->toDateString();
        $capturedAt = now();
        $lockKey = "wms:snapshot:{$date}:{$time}";

        $this->ensureFuturePartitions();

        if (! DbMutex::acquire($lockKey, self::SNAPSHOT_LOCK_TIMEOUT, self::CONNECTION)) {
            throw new RuntimeException("Stock snapshot is already running: {$date} {$time}");
        }

        $committed = false;

        try {
            $connection = DB::connection(self::CONNECTION);
            // Avoid shared next-key locks on source tables during INSERT ... SELECT.
            $connection->statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $connection->beginTransaction();

            try {
                $capturedAtValue = $capturedAt->format('Y-m-d H:i:s.u');

                $connection->statement($this->summaryInsertSql(), [
                    $date,
                    $time,
                    $capturedAtValue,
                ]);

                $connection->statement($this->lotInsertSql(), [
                    $date,
                    $time,
                    $capturedAtValue,
                ]);

                $connection->commit();
                $committed = true;
            } catch (\Throwable $e) {
                if (! $committed && $connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }

                throw $e;
            }

            $summaryRows = $this->countSummaryRows($date, $time);
            $lotRows = $this->countLotRows($date, $time);
            $verification = $this->verify($date, $time, $summaryRows, $lotRows, $capturedAt);

            return [
                'summary_rows' => $summaryRows,
                'lot_rows' => $lotRows,
                'verification' => $verification,
            ];
        } finally {
            DbMutex::release($lockKey, self::CONNECTION);
        }
    }

    public function archiveAndCleanup(string $disk = 's3', bool $dryRun = false): array
    {
        $this->ensureFuturePartitions();

        $lotCutoff = CarbonImmutable::now()->subMonths(6)->startOfMonth();
        $summaryCutoff = CarbonImmutable::now()->subMonths(15)->startOfMonth();
        $months = $this->archiveTargetMonths($lotCutoff);

        $result = [
            'dry_run' => $dryRun,
            'disk' => $disk,
            'lot_cutoff' => $lotCutoff->toDateString(),
            'summary_cutoff' => $summaryCutoff->toDateString(),
            'archived_months' => [],
            'dropped_partitions' => [],
        ];

        foreach ($months as $month) {
            $monthResult = $this->archiveLotMonth($month, $disk, $dryRun);
            $result['archived_months'][] = $monthResult;

            if (! $dryRun && ($monthResult['verified'] ?? false)) {
                $partition = 'p'.$month;
                if ($this->dropPartitionIfExists('wms_stock_snapshot_lots', $partition)) {
                    $result['dropped_partitions'][] = "wms_stock_snapshot_lots.{$partition}";
                }
            }
        }

        foreach (['wms_stock_snapshots', 'wms_stock_snapshot_verifications'] as $table) {
            foreach ($this->expiredPartitions($summaryCutoff) as $partition) {
                if (! $dryRun && $this->dropPartitionIfExists($table, $partition)) {
                    $result['dropped_partitions'][] = "{$table}.{$partition}";
                }
            }
        }

        return $result;
    }

    public function ensureFuturePartitions(int $monthsAhead = 16): void
    {
        if (! DbMutex::acquire(self::PARTITION_LOCK, 10, self::CONNECTION)) {
            throw new RuntimeException('Failed to acquire stock snapshot partition maintenance lock');
        }

        try {
            foreach (self::TABLES as $table) {
                if (! $this->tableExists($table)) {
                    continue;
                }

                $existing = $this->partitionNames($table);
                if ($existing->isEmpty()) {
                    continue;
                }

                $maxExistingMonth = $existing
                    ->filter(fn (string $name) => preg_match('/^p\d{6}$/', $name) === 1)
                    ->map(fn (string $name) => substr($name, 1))
                    ->sort()
                    ->last();

                $start = CarbonImmutable::now()->startOfMonth();
                for ($i = 0; $i <= $monthsAhead; $i++) {
                    $month = $start->addMonths($i);
                    $partitionName = 'p'.$month->format('Ym');

                    if ($existing->contains($partitionName)) {
                        continue;
                    }

                    if ($maxExistingMonth !== null && $month->format('Ym') < $maxExistingMonth) {
                        throw new RuntimeException("Missing non-tail partition {$partitionName} on {$table}");
                    }

                    $next = $month->addMonth();
                    DB::connection(self::CONNECTION)->statement(sprintf(
                        "ALTER TABLE %s ADD PARTITION (PARTITION %s VALUES LESS THAN (TO_DAYS('%s')))",
                        $table,
                        $partitionName,
                        $next->format('Y-m-d')
                    ));

                    $existing->push($partitionName);
                    $maxExistingMonth = max($maxExistingMonth ?? '', $month->format('Ym'));
                }
            }
        } finally {
            DbMutex::release(self::PARTITION_LOCK, self::CONNECTION);
        }
    }

    private function summaryInsertSql(): string
    {
        return <<<'SQL'
INSERT INTO wms_stock_snapshots
    (snapshot_date, snapshot_time, warehouse_id, item_id,
     current_quantity, reserved_quantity, available_quantity,
     incoming_quantity, stock_count, captured_at, created_at)
SELECT
    ?,
    ?,
    k.warehouse_id,
    k.item_id,
    COALESCE(stock.current_quantity, 0),
    COALESCE(stock.reserved_quantity, 0),
    COALESCE(stock.available_quantity, 0),
    COALESCE(inc.total_incoming, 0),
    COALESCE(stock.stock_count, 0),
    ?,
    NOW()
FROM (
    SELECT warehouse_id, item_id
    FROM real_stocks
    WHERE current_quantity > 0 OR reserved_quantity > 0
    GROUP BY warehouse_id, item_id
    UNION
    SELECT warehouse_id, item_id
    FROM wms_order_incoming_schedules
    WHERE status IN ('PENDING', 'PARTIAL')
      AND expected_quantity > received_quantity
    GROUP BY warehouse_id, item_id
) k
LEFT JOIN (
    SELECT warehouse_id, item_id,
           SUM(current_quantity) AS current_quantity,
           SUM(reserved_quantity) AS reserved_quantity,
           SUM(available_quantity) AS available_quantity,
           COUNT(id) AS stock_count
    FROM real_stocks
    WHERE current_quantity > 0 OR reserved_quantity > 0
    GROUP BY warehouse_id, item_id
) stock ON stock.warehouse_id = k.warehouse_id
    AND stock.item_id = k.item_id
LEFT JOIN (
    SELECT warehouse_id, item_id,
           SUM(expected_quantity - received_quantity) AS total_incoming
    FROM wms_order_incoming_schedules
    WHERE status IN ('PENDING', 'PARTIAL')
      AND expected_quantity > received_quantity
    GROUP BY warehouse_id, item_id
) inc ON inc.warehouse_id = k.warehouse_id
    AND inc.item_id = k.item_id
WHERE COALESCE(stock.current_quantity, 0) > 0
   OR COALESCE(stock.reserved_quantity, 0) > 0
   OR COALESCE(inc.total_incoming, 0) > 0
ON DUPLICATE KEY UPDATE
    current_quantity = VALUES(current_quantity),
    reserved_quantity = VALUES(reserved_quantity),
    available_quantity = VALUES(available_quantity),
    incoming_quantity = VALUES(incoming_quantity),
    stock_count = VALUES(stock_count),
    captured_at = VALUES(captured_at)
SQL;
    }

    private function lotInsertSql(): string
    {
        $realStockReceivedAt = DB::connection(self::CONNECTION)
            ->getSchemaBuilder()
            ->hasColumn('real_stocks', 'received_at')
            ? 'rs.received_at'
            : 'NULL';

        return <<<SQL
INSERT INTO wms_stock_snapshot_lots
    (snapshot_date, snapshot_time, warehouse_id, item_id,
     real_stock_id, lot_id, location_id, floor_id, expiration_date, purchase_id,
     current_quantity, reserved_quantity, price,
     real_stock_received_at, lot_created_at, captured_at, created_at)
SELECT
    ?, ?,
    rs.warehouse_id,
    rs.item_id,
    rs.id,
    rsl.id,
    rsl.location_id,
    rsl.floor_id,
    rsl.expiration_date,
    rsl.purchase_id,
    rsl.current_quantity,
    rsl.reserved_quantity,
    rsl.price,
    {$realStockReceivedAt},
    rsl.created_at,
    ?,
    NOW()
FROM real_stock_lots rsl
INNER JOIN real_stocks rs ON rs.id = rsl.real_stock_id
WHERE rsl.status = 'ACTIVE'
  AND (rsl.current_quantity > 0 OR rsl.reserved_quantity > 0)
ON DUPLICATE KEY UPDATE
    warehouse_id = VALUES(warehouse_id),
    item_id = VALUES(item_id),
    real_stock_id = VALUES(real_stock_id),
    location_id = VALUES(location_id),
    floor_id = VALUES(floor_id),
    expiration_date = VALUES(expiration_date),
    purchase_id = VALUES(purchase_id),
    current_quantity = VALUES(current_quantity),
    reserved_quantity = VALUES(reserved_quantity),
    price = VALUES(price),
    real_stock_received_at = VALUES(real_stock_received_at),
    lot_created_at = VALUES(lot_created_at),
    captured_at = VALUES(captured_at)
SQL;
    }

    private function verify(string $date, string $time, int $summaryRows, int $lotRows, \DateTimeInterface $capturedAt): array
    {
        $summaryLotDetails = $this->summaryLotMismatches($date, $time);
        $realtime = $this->realtimeDrift($date, $time);
        $rowCountRatio = $this->rowCountRatio($date, $time, $summaryRows);

        $summaryLotMismatchCount = $summaryLotDetails['count'];
        $isRowCountHealthy = $rowCountRatio === null || ($rowCountRatio >= 0.8 && $rowCountRatio <= 1.2);
        $isHealthy = $summaryLotMismatchCount === 0 && $realtime['mismatch_count'] === 0 && $isRowCountHealthy;

        $details = [
            'summary_lot' => $summaryLotDetails['samples'],
            'realtime' => $realtime['samples'],
        ];

        DB::connection(self::CONNECTION)
            ->table('wms_stock_snapshot_verifications')
            ->updateOrInsert(
                [
                    'snapshot_date' => $date,
                    'snapshot_time' => $time,
                ],
                [
                    'summary_rows' => $summaryRows,
                    'lot_rows' => $lotRows,
                    'summary_lot_mismatches' => $summaryLotMismatchCount,
                    'realtime_mismatches' => $realtime['mismatch_count'],
                    'realtime_total_diff' => $realtime['total_diff'],
                    'row_count_ratio' => $rowCountRatio,
                    'is_healthy' => $isHealthy,
                    'details' => json_encode($details, JSON_UNESCAPED_UNICODE),
                    'captured_at' => $capturedAt->format('Y-m-d H:i:s.u'),
                    'created_at' => now(),
                ]
            );

        return [
            'summary_lot_mismatches' => $summaryLotMismatchCount,
            'realtime_mismatches' => $realtime['mismatch_count'],
            'realtime_total_diff' => $realtime['total_diff'],
            'row_count_ratio' => $rowCountRatio,
            'is_healthy' => $isHealthy,
            'details' => $details,
        ];
    }

    private function summaryLotMismatches(string $date, string $time): array
    {
        $rows = DB::connection(self::CONNECTION)->select(<<<'SQL'
SELECT *
FROM (
    SELECT
        s.warehouse_id,
        s.item_id,
        s.current_quantity AS summary_current,
        COALESCE(l.lot_current, 0) AS lot_current,
        s.current_quantity - COALESCE(l.lot_current, 0) AS diff
    FROM wms_stock_snapshots s
    LEFT JOIN (
        SELECT warehouse_id, item_id, SUM(current_quantity) AS lot_current
        FROM wms_stock_snapshot_lots
        WHERE snapshot_date = ? AND snapshot_time = ?
        GROUP BY warehouse_id, item_id
    ) l ON s.warehouse_id = l.warehouse_id
        AND s.item_id = l.item_id
    WHERE s.snapshot_date = ? AND s.snapshot_time = ?
    UNION ALL
    SELECT
        l.warehouse_id,
        l.item_id,
        0 AS summary_current,
        l.lot_current,
        0 - l.lot_current AS diff
    FROM (
        SELECT warehouse_id, item_id, SUM(current_quantity) AS lot_current
        FROM wms_stock_snapshot_lots
        WHERE snapshot_date = ? AND snapshot_time = ?
        GROUP BY warehouse_id, item_id
    ) l
    LEFT JOIN wms_stock_snapshots s ON s.snapshot_date = ?
        AND s.snapshot_time = ?
        AND s.warehouse_id = l.warehouse_id
        AND s.item_id = l.item_id
    WHERE s.item_id IS NULL
) mismatches
WHERE diff != 0
LIMIT 101
SQL, [$date, $time, $date, $time, $date, $time, $date, $time]);

        return [
            'count' => $this->countSummaryLotMismatches($date, $time),
            'samples' => array_slice(array_map(fn ($row) => (array) $row, $rows), 0, 100),
        ];
    }

    private function countSummaryLotMismatches(string $date, string $time): int
    {
        $row = DB::connection(self::CONNECTION)->selectOne(<<<'SQL'
SELECT COUNT(*) AS mismatch_count
FROM (
    SELECT s.warehouse_id, s.item_id,
           s.current_quantity - COALESCE(l.lot_current, 0) AS diff
    FROM wms_stock_snapshots s
    LEFT JOIN (
        SELECT warehouse_id, item_id, SUM(current_quantity) AS lot_current
        FROM wms_stock_snapshot_lots
        WHERE snapshot_date = ? AND snapshot_time = ?
        GROUP BY warehouse_id, item_id
    ) l ON s.warehouse_id = l.warehouse_id
        AND s.item_id = l.item_id
    WHERE s.snapshot_date = ? AND s.snapshot_time = ?
    UNION ALL
    SELECT l.warehouse_id, l.item_id, 0 - l.lot_current AS diff
    FROM (
        SELECT warehouse_id, item_id, SUM(current_quantity) AS lot_current
        FROM wms_stock_snapshot_lots
        WHERE snapshot_date = ? AND snapshot_time = ?
        GROUP BY warehouse_id, item_id
    ) l
    LEFT JOIN wms_stock_snapshots s ON s.snapshot_date = ?
        AND s.snapshot_time = ?
        AND s.warehouse_id = l.warehouse_id
        AND s.item_id = l.item_id
    WHERE s.item_id IS NULL
) x
WHERE diff != 0
SQL, [$date, $time, $date, $time, $date, $time, $date, $time]);

        return (int) ($row->mismatch_count ?? 0);
    }

    private function realtimeDrift(string $date, string $time): array
    {
        $summary = DB::connection(self::CONNECTION)->selectOne(<<<'SQL'
SELECT COUNT(*) AS mismatch_count, COALESCE(SUM(ABS(diff)), 0) AS total_diff
FROM (
    SELECT s.warehouse_id, s.item_id,
           MAX(s.current_quantity) - COALESCE(SUM(rs.current_quantity), 0) AS diff
    FROM wms_stock_snapshots s
    LEFT JOIN real_stocks rs ON s.warehouse_id = rs.warehouse_id
        AND s.item_id = rs.item_id
    WHERE s.snapshot_date = ? AND s.snapshot_time = ?
    GROUP BY s.warehouse_id, s.item_id
) sub
WHERE diff != 0
SQL, [$date, $time]);

        $samples = DB::connection(self::CONNECTION)->select(<<<'SQL'
SELECT *
FROM (
    SELECT s.warehouse_id, s.item_id,
           MAX(s.current_quantity) AS snapshot_current,
           COALESCE(SUM(rs.current_quantity), 0) AS realtime_current,
           MAX(s.current_quantity) - COALESCE(SUM(rs.current_quantity), 0) AS diff
    FROM wms_stock_snapshots s
    LEFT JOIN real_stocks rs ON s.warehouse_id = rs.warehouse_id
        AND s.item_id = rs.item_id
    WHERE s.snapshot_date = ? AND s.snapshot_time = ?
    GROUP BY s.warehouse_id, s.item_id
) sub
WHERE diff != 0
LIMIT 100
SQL, [$date, $time]);

        return [
            'mismatch_count' => (int) ($summary->mismatch_count ?? 0),
            'total_diff' => (int) ($summary->total_diff ?? 0),
            'samples' => array_map(fn ($row) => (array) $row, $samples),
        ];
    }

    private function rowCountRatio(string $date, string $time, int $summaryRows): ?float
    {
        $previousDate = DB::connection(self::CONNECTION)
            ->table('wms_stock_snapshots')
            ->where('snapshot_time', $time)
            ->where('snapshot_date', '<', $date)
            ->max('snapshot_date');

        if ($previousDate === null) {
            return null;
        }

        $previousCount = DB::connection(self::CONNECTION)
            ->table('wms_stock_snapshots')
            ->where('snapshot_date', $previousDate)
            ->where('snapshot_time', $time)
            ->count();

        if ($previousCount === 0) {
            return null;
        }

        return round($summaryRows / $previousCount, 2);
    }

    private function countSummaryRows(string $date, string $time): int
    {
        return DB::connection(self::CONNECTION)
            ->table('wms_stock_snapshots')
            ->where('snapshot_date', $date)
            ->where('snapshot_time', $time)
            ->count();
    }

    private function countLotRows(string $date, string $time): int
    {
        return DB::connection(self::CONNECTION)
            ->table('wms_stock_snapshot_lots')
            ->where('snapshot_date', $date)
            ->where('snapshot_time', $time)
            ->count();
    }

    private function archiveTargetMonths(CarbonImmutable $cutoff): Collection
    {
        return DB::connection(self::CONNECTION)
            ->table('wms_stock_snapshot_lots')
            ->selectRaw("DATE_FORMAT(snapshot_date, '%Y%m') AS archive_month")
            ->where('snapshot_date', '<', $cutoff->toDateString())
            ->groupBy('archive_month')
            ->orderBy('archive_month')
            ->pluck('archive_month');
    }

    private function archiveLotMonth(string $month, string $disk, bool $dryRun): array
    {
        $start = CarbonImmutable::createFromFormat('Ym', $month)->startOfMonth();
        $end = $start->addMonth();
        $dbRows = DB::connection(self::CONNECTION)
            ->table('wms_stock_snapshot_lots')
            ->where('snapshot_date', '>=', $start->toDateString())
            ->where('snapshot_date', '<', $end->toDateString())
            ->count();

        $result = [
            'month' => $month,
            'db_rows' => $dbRows,
            'files' => [],
            'manifest_path' => "wms-snapshots/lots/{$start->format('Y')}/{$start->format('m')}/manifest_{$month}.json",
            'verified' => false,
        ];

        if ($dryRun || $dbRows === 0) {
            return $result;
        }

        $snapshots = DB::connection(self::CONNECTION)
            ->table('wms_stock_snapshot_lots')
            ->select('snapshot_date', 'snapshot_time')
            ->where('snapshot_date', '>=', $start->toDateString())
            ->where('snapshot_date', '<', $end->toDateString())
            ->groupBy('snapshot_date', 'snapshot_time')
            ->orderBy('snapshot_date')
            ->orderBy('snapshot_time')
            ->get();

        $totalCsvRows = 0;
        $manifestFiles = [];

        foreach ($snapshots as $snapshot) {
            $file = $this->writeSnapshotLotCsv((string) $snapshot->snapshot_date, $snapshot->snapshot_time, $disk);
            $totalCsvRows += $file['rows'];
            $manifestFiles[] = $file;
        }

        $manifest = [
            'month' => $month,
            'db_rows' => $dbRows,
            'csv_rows' => $totalCsvRows,
            'files' => $manifestFiles,
            'created_at' => now()->toIso8601String(),
        ];

        Storage::disk($disk)->put(
            $result['manifest_path'],
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        $result['files'] = $manifestFiles;
        $result['verified'] = $dbRows === $totalCsvRows
            && Storage::disk($disk)->exists($result['manifest_path']);

        if (! $result['verified']) {
            Log::warning('Stock snapshot archive verification failed', $result);
        }

        return $result;
    }

    private function writeSnapshotLotCsv(string $date, string $time, string $disk): array
    {
        $snapshotDate = CarbonImmutable::parse($date);
        $path = sprintf(
            'wms-snapshots/lots/%s/%s/snapshot_lots_%s_%s.csv.gz',
            $snapshotDate->format('Y'),
            $snapshotDate->format('m'),
            $snapshotDate->format('Ymd'),
            $time
        );

        $tmpPath = tempnam(sys_get_temp_dir(), 'wms_snapshot_lots_');
        $gzipPath = $tmpPath.'.gz';
        rename($tmpPath, $gzipPath);

        $handle = fopen('compress.zlib://'.$gzipPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Failed to open gzip archive for stock snapshot lots');
        }

        $headers = [
            'snapshot_date',
            'snapshot_time',
            'warehouse_id',
            'item_id',
            'real_stock_id',
            'lot_id',
            'location_id',
            'floor_id',
            'expiration_date',
            'purchase_id',
            'current_quantity',
            'reserved_quantity',
            'price',
            'real_stock_received_at',
            'lot_created_at',
            'captured_at',
        ];

        fputcsv($handle, $headers);
        $rows = 0;
        $lastId = 0;

        do {
            $chunk = DB::connection(self::CONNECTION)
                ->table('wms_stock_snapshot_lots')
                ->where('snapshot_date', $date)
                ->where('snapshot_time', $time)
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(10000)
                ->get();

            foreach ($chunk as $row) {
                fputcsv($handle, [
                    $row->snapshot_date,
                    $row->snapshot_time,
                    $row->warehouse_id,
                    $row->item_id,
                    $row->real_stock_id,
                    $row->lot_id,
                    $row->location_id,
                    $row->floor_id,
                    $row->expiration_date,
                    $row->purchase_id,
                    $row->current_quantity,
                    $row->reserved_quantity,
                    $row->price,
                    $row->real_stock_received_at,
                    $row->lot_created_at,
                    $row->captured_at,
                ]);
                $rows++;
                $lastId = (int) $row->id;
            }
        } while ($chunk->isNotEmpty());

        fclose($handle);

        $size = filesize($gzipPath) ?: 0;
        $checksum = hash_file('sha256', $gzipPath);
        $stream = fopen($gzipPath, 'rb');
        Storage::disk($disk)->put($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        $exists = Storage::disk($disk)->exists($path);
        $storedSize = $exists ? Storage::disk($disk)->size($path) : 0;
        @unlink($gzipPath);

        if (! $exists || $storedSize !== $size) {
            throw new RuntimeException("Failed to verify archived stock snapshot file: {$path}");
        }

        return [
            'path' => $path,
            'rows' => $rows,
            'bytes' => $size,
            'sha256' => $checksum,
        ];
    }

    private function expiredPartitions(CarbonImmutable $cutoff): array
    {
        $partitions = [];
        $cursor = CarbonImmutable::create(2020, 1, 1)->startOfMonth();

        while ($cursor < $cutoff) {
            $partitions[] = 'p'.$cursor->format('Ym');
            $cursor = $cursor->addMonth();
        }

        return $partitions;
    }

    private function dropPartitionIfExists(string $table, string $partition): bool
    {
        if (! $this->partitionNames($table)->contains($partition)) {
            return false;
        }

        DB::connection(self::CONNECTION)->statement("ALTER TABLE {$table} DROP PARTITION {$partition}");

        return true;
    }

    private function tableExists(string $table): bool
    {
        return DB::connection(self::CONNECTION)
            ->getSchemaBuilder()
            ->hasTable($table);
    }

    private function partitionNames(string $table): Collection
    {
        $database = DB::connection(self::CONNECTION)->getDatabaseName();

        return collect(DB::connection(self::CONNECTION)->select(
            <<<'SQL'
SELECT PARTITION_NAME
FROM INFORMATION_SCHEMA.PARTITIONS
WHERE TABLE_SCHEMA = ?
  AND TABLE_NAME = ?
  AND PARTITION_NAME IS NOT NULL
ORDER BY PARTITION_ORDINAL_POSITION
SQL,
            [$database, $table]
        ))->pluck('PARTITION_NAME');
    }

    private function assertSnapshotTime(string $time): void
    {
        if (! in_array($time, ['morning', 'evening'], true)) {
            throw new RuntimeException("Invalid snapshot time: {$time}");
        }
    }
}
