<?php

namespace App\Services\Export;

use App\Enums\ExportFormat;
use App\Enums\ExportStatus;
use App\Jobs\ProcessExportJob;
use App\Models\WmsExportLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv as CsvWriter;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportService
{
    /**
     * 同期/非同期の閾値
     */
    const SYNC_THRESHOLD = 1000;

    /**
     * エクスポートを実行する
     *
     * @param  array<string, string>  $columns  ['label' => 'db_column_or_relation']
     * @return WmsExportLog|StreamedResponse 非同期の場合はWmsExportLog、同期の場合はStreamedResponse
     */
    public function export(
        Builder $query,
        array $columns,
        ExportFormat $format,
        string $resourceName,
        int $userId,
        ?array $filters = null,
        ?string $customFileName = null,
    ): WmsExportLog|StreamedResponse {
        $count = (clone $query)->count();

        $fileName = $customFileName
            ? $customFileName.'.'.$format->extension()
            : $this->generateFileName($resourceName, $format);
        $columnLabels = array_keys($columns);

        $exportLog = WmsExportLog::create([
            'resource_name' => $resourceName,
            'format' => $format,
            'status' => ExportStatus::PENDING,
            'file_name' => $fileName,
            'user_id' => $userId,
            'filters' => $filters,
            'columns' => $columnLabels,
        ]);

        if ($count > self::SYNC_THRESHOLD) {
            // 非同期処理
            ProcessExportJob::dispatch(
                $exportLog->id,
                $query->getModel()::class,
                $this->extractQueryConstraints($query),
                $columns,
                $format
            );

            return $exportLog;
        }

        // 同期処理
        return $this->executeSyncExport($query, $columns, $format, $exportLog);
    }

    /**
     * 同期エクスポートを実行してStreamedResponseを返す
     */
    public function executeSyncExport(
        Builder $query,
        array $columns,
        ExportFormat $format,
        WmsExportLog $exportLog
    ): StreamedResponse {
        $exportLog->markAsProcessing();

        $tempPath = $this->generateTempPath($exportLog->file_name);

        try {
            $rowCount = $this->generateFile($query, $columns, $format, $tempPath);

            $fileSize = filesize($tempPath);
            $s3Path = $this->uploadToS3($tempPath, $exportLog);

            $exportLog->markAsCompleted($s3Path, $fileSize, $rowCount);
            $exportLog->markAsDownloaded();

            return $this->createDownloadResponse($tempPath, $exportLog->file_name, $format);
        } catch (\Exception $e) {
            $exportLog->markAsFailed($e->getMessage());
            @unlink($tempPath);

            throw $e;
        }
    }

    /**
     * 非同期エクスポートを実行する（ジョブから呼ばれる）
     */
    public function executeAsyncExport(
        Builder $query,
        array $columns,
        ExportFormat $format,
        WmsExportLog $exportLog
    ): void {
        $exportLog->markAsProcessing();

        $tempPath = $this->generateTempPath($exportLog->file_name);

        try {
            $rowCount = $this->generateFile($query, $columns, $format, $tempPath);

            $fileSize = filesize($tempPath);
            $s3Path = $this->uploadToS3($tempPath, $exportLog);

            $exportLog->markAsCompleted($s3Path, $fileSize, $rowCount);
        } catch (\Exception $e) {
            $exportLog->markAsFailed($e->getMessage());

            throw $e;
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * ファイルを生成する
     */
    public function generateFile(Builder $query, array $columns, ExportFormat $format, string $filePath): int
    {
        return match ($format) {
            ExportFormat::CSV => $this->generateCsv($query, $columns, $filePath),
            ExportFormat::XLSX => $this->generateXlsx($query, $columns, $filePath),
        };
    }

    /**
     * CSVファイルを生成する
     */
    public function generateCsv(Builder $query, array $columns, string $filePath): int
    {
        $spreadsheet = $this->buildSpreadsheet($query, $columns);
        $writer = new CsvWriter($spreadsheet);
        $writer->setUseBOM(true);
        $writer->setDelimiter(',');
        $writer->setEnclosure('"');
        $writer->save($filePath);

        // ヘッダー行を除いた行数
        return $spreadsheet->getActiveSheet()->getHighestRow() - 1;
    }

    /**
     * XLSXファイルを生成する
     */
    public function generateXlsx(Builder $query, array $columns, string $filePath): int
    {
        $spreadsheet = $this->buildSpreadsheet($query, $columns);

        // ヘッダー行にスタイルを適用
        $sheet = $spreadsheet->getActiveSheet();
        $lastColumn = $sheet->getHighestColumn();
        $headerRange = "A1:{$lastColumn}1";
        $sheet->getStyle($headerRange)->getFont()->setBold(true);

        // オートフィルター設定
        $lastRow = $sheet->getHighestRow();
        $sheet->setAutoFilter("A1:{$lastColumn}{$lastRow}");

        $writer = new XlsxWriter($spreadsheet);
        $writer->save($filePath);

        return $lastRow - 1;
    }

    /**
     * スプレッドシートを構築する
     *
     * @param  array<string, string>  $columns  ['label' => 'db_column']
     */
    private function buildSpreadsheet(Builder $query, array $columns): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        // ヘッダー行
        $colIndex = 1;
        foreach ($columns as $label => $dbColumn) {
            $sheet->setCellValue([$colIndex, 1], $label);
            $colIndex++;
        }

        // データ行
        $rowIndex = 2;
        $query->chunk(500, function ($records) use ($sheet, $columns, &$rowIndex) {
            foreach ($records as $record) {
                $colIndex = 1;
                foreach ($columns as $label => $dbColumn) {
                    $value = $this->resolveColumnValue($record, $dbColumn);
                    $sheet->setCellValue([$colIndex, $rowIndex], $value);
                    $colIndex++;
                }
                $rowIndex++;
            }
        });

        return $spreadsheet;
    }

    /**
     * レコードからカラム値を解決する
     */
    private function resolveColumnValue(mixed $record, string $dbColumn): mixed
    {
        $value = $this->resolvePathValue($record, explode('.', $dbColumn));

        return $this->formatValueForExport($value);
    }

    /**
     * ドット記法でリレーションを辿る（例: 'warehouse.name', 'activeLots.expiration_date'）
     */
    private function resolvePathValue(mixed $value, array $parts): mixed
    {
        foreach ($parts as $index => $part) {
            if ($value === null) {
                return null;
            }

            if ($value instanceof Enumerable) {
                $remainingParts = array_slice($parts, $index);

                return $value
                    ->map(fn (mixed $item): mixed => $this->resolvePathValue($item, $remainingParts))
                    ->filter(fn (mixed $item): bool => $this->hasExportValue($item))
                    ->values();
            }

            if (is_array($value)) {
                $value = $value[$part] ?? null;

                continue;
            }

            $value = $value->{$part} ?? null;
        }

        return $value;
    }

    private function formatValueForExport(mixed $value): mixed
    {
        if ($value instanceof Enumerable) {
            return $value
                ->map(fn (mixed $item): mixed => $this->formatValueForExport($item))
                ->filter(fn (mixed $item): bool => $this->hasExportValue($item))
                ->implode("\n");
        }

        if (is_array($value)) {
            return collect($value)
                ->map(fn (mixed $item): mixed => $this->formatValueForExport($item))
                ->filter(fn (mixed $item): bool => $this->hasExportValue($item))
                ->implode("\n");
        }

        // Enum値はラベルに変換
        if ($value instanceof \BackedEnum) {
            return method_exists($value, 'label') ? $value->label() : $value->value;
        }

        // Carbon日付はフォーマット
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value ?? '';
    }

    private function hasExportValue(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    /**
     * S3にアップロード
     */
    public function uploadToS3(string $localPath, WmsExportLog $exportLog): string
    {
        $s3Path = sprintf(
            'exports/%s/%s/%s',
            date('Y/m'),
            $exportLog->user_id,
            $exportLog->file_name
        );

        Storage::disk('s3')->put($s3Path, file_get_contents($localPath));

        return $s3Path;
    }

    /**
     * S3からダウンロードURLを生成する
     */
    public function getDownloadResponse(WmsExportLog $exportLog): StreamedResponse
    {
        $exportLog->markAsDownloaded();

        $format = $exportLog->format;

        return new StreamedResponse(function () use ($exportLog) {
            echo Storage::disk('s3')->get($exportLog->file_path);
        }, 200, $this->buildContentDispositionHeaders($exportLog->file_name, $format));
    }

    /**
     * ダウンロードレスポンスを生成する
     */
    private function createDownloadResponse(string $filePath, string $fileName, ExportFormat $format): StreamedResponse
    {
        return new StreamedResponse(function () use ($filePath) {
            $stream = fopen($filePath, 'r');
            fpassthru($stream);
            fclose($stream);
            @unlink($filePath);
        }, 200, $this->buildContentDispositionHeaders($fileName, $format));
    }

    /**
     * Content-Dispositionヘッダーを構築する（日本語ファイル名対応）
     */
    private function buildContentDispositionHeaders(string $fileName, ExportFormat $format): array
    {
        $encodedFileName = rawurlencode($fileName);

        return [
            'Content-Type' => $format->mimeType(),
            'Content-Disposition' => "attachment; filename=\"{$encodedFileName}\"; filename*=UTF-8''{$encodedFileName}",
        ];
    }

    /**
     * ファイル名を生成する
     */
    private function generateFileName(string $resourceName, ExportFormat $format): string
    {
        $timestamp = date('Ymd_His');

        return "{$resourceName}_{$timestamp}.{$format->extension()}";
    }

    /**
     * テンポラリファイルパスを生成する
     */
    private function generateTempPath(string $fileName): string
    {
        $dir = storage_path('app/private/exports');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir.'/'.$fileName;
    }

    /**
     * Builderからクエリ制約を抽出する（シリアライズ用）
     */
    public function extractQueryConstraints(Builder $query): array
    {
        return [
            'wheres' => $query->getQuery()->wheres,
            'bindings' => $query->getQuery()->getBindings(),
            'orders' => $query->getQuery()->orders ?? [],
        ];
    }

    /**
     * クエリ制約を再構築する（ジョブでの復元用）
     */
    public function rebuildQuery(string $modelClass, array $constraints): Builder
    {
        $query = $modelClass::query();

        // WHERE条件を再適用
        if (! empty($constraints['wheres'])) {
            $query->getQuery()->wheres = $constraints['wheres'];
        }

        // バインディングを再設定
        if (! empty($constraints['bindings'])) {
            foreach ($constraints['bindings'] as $binding) {
                $query->getQuery()->addBinding($binding);
            }
        }

        // ORDER BY を再適用
        if (! empty($constraints['orders'])) {
            $query->getQuery()->orders = $constraints['orders'];
        }

        return $query;
    }
}
