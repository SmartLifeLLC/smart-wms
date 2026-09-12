<?php

namespace App\Console\Commands\AutoOrder;

use App\Models\WmsJxTransmissionLog;
use App\Services\JX\Eos\JxEosIncomingSkipService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SkipPendingEosIncomingLogsCommand extends Command
{
    protected $signature = 'wms:eos-incoming-skip-pending
                            {--before= : 対象にする送受信日時の上限。YYYY-MM-DD指定時は当日23:59:59まで}
                            {--environment=production : 対象環境}
                            {--document-type=90 : 対象文書タイプ。空文字を指定すると文書タイプで絞り込まない}
                            {--limit= : 対象件数の上限}
                            {--chunk=500 : 一括処理のチャンクサイズ}
                            {--apply : 実際にSKIPPEDの入荷受信ファイルを作成・更新する。未指定はdry-run}';

    protected $description = '未処理のEOS受信GetDocumentログを、リリース前データとして一括で取込対象外にする';

    private const RELEASE_SKIP_REASON = 'リリース前EOS受信データとして処理対象外にしました。';

    public function handle(JxEosIncomingSkipService $skipService): int
    {
        $before = $this->resolveBefore();
        $environment = trim((string) $this->option('environment'));
        $documentType = trim((string) $this->option('document-type'));
        $limit = $this->positiveIntOption('limit');
        $chunkSize = max(1, $this->positiveIntOption('chunk') ?? 500);
        $apply = (bool) $this->option('apply');

        $query = $this->targetQuery($before, $environment, $documentType);
        $targetCount = (clone $query)->count();
        $effectiveCount = $limit !== null ? min($targetCount, $limit) : $targetCount;

        $this->info(($apply ? 'APPLY' : 'DRY-RUN').' EOS受信データ一括対象外');
        $this->table(
            ['条件', '値'],
            [
                ['送受信日時 <=', $before->format('Y-m-d H:i:s')],
                ['環境', $environment !== '' ? $environment : '(未指定)'],
                ['文書タイプ', $documentType !== '' ? $documentType : '(絞り込みなし)'],
                ['対象件数', (string) $targetCount],
                ['処理予定件数', (string) $effectiveCount],
            ],
        );

        if ($effectiveCount === 0) {
            $this->line('対象の未処理EOS受信ログはありません。');

            return self::SUCCESS;
        }

        $sampleLimit = min(10, $effectiveCount);

        $sampleRows = (clone $query)
            ->orderBy('id')
            ->limit($sampleLimit)
            ->get(['id', 'jx_setting_id', 'message_id', 'document_type', 'data_size', 'transmitted_at'])
            ->map(fn (WmsJxTransmissionLog $log): array => [
                $log->id,
                $log->jx_setting_id ?? '-',
                $log->message_id ?? '-',
                $log->document_type ?? '-',
                $log->data_size ?? '-',
                $log->transmitted_at?->format('Y-m-d H:i:s') ?? '-',
            ])
            ->all();

        $this->table(['ID', 'JX設定', 'メッセージID', '文書', 'サイズ', '送受信日時'], $sampleRows);

        if (! $apply) {
            $this->warn('dry-runです。実更新する場合は --apply を付けて再実行してください。');

            return self::SUCCESS;
        }

        $attempted = 0;
        $processed = 0;
        $failed = 0;
        $errors = [];

        (clone $query)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($logs) use ($skipService, $limit, &$attempted, &$processed, &$failed, &$errors): bool {
                foreach ($logs as $log) {
                    if ($limit !== null && $attempted >= $limit) {
                        return false;
                    }

                    $attempted++;

                    try {
                        $skipService->skip($log, null, self::RELEASE_SKIP_REASON);
                        $processed++;
                    } catch (\Throwable $throwable) {
                        $failed++;
                        $errors[] = [
                            'id' => $log->id,
                            'message_id' => $log->message_id,
                            'error' => $throwable->getMessage(),
                        ];
                    }
                }

                return true;
            });

        $this->info("完了: 対象外 {$processed}件 / 失敗 {$failed}件");

        if ($errors !== []) {
            $this->table(
                ['ID', 'メッセージID', 'エラー'],
                collect($errors)->take(20)->map(fn (array $error): array => [
                    $error['id'],
                    $error['message_id'] ?? '-',
                    $error['error'],
                ])->all(),
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function targetQuery(Carbon $before, string $environment, string $documentType)
    {
        return WmsJxTransmissionLog::query()
            ->pendingEosIncomingImport()
            ->when($environment !== '', fn ($query) => $query->where('environment', $environment))
            ->when($documentType !== '', fn ($query) => $query->where('document_type', $documentType))
            ->where('transmitted_at', '<=', $before);
    }

    private function resolveBefore(): Carbon
    {
        $value = trim((string) $this->option('before'));

        if ($value === '') {
            return now();
        }

        $date = Carbon::parse($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $date->endOfDay();
        }

        return $date;
    }

    private function positiveIntOption(string $name): ?int
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }
}
