<?php


namespace App\Traits;

use Illuminate\Console\Command;
use Lorisleiva\Actions\Concerns\AsCommand;

trait AsCustomActionCommand
{
    use AsCommand;
    public function asCommand(Command $command): void
    {
        $start_time = microtime(true);

        $result = static::make()->_executeWithTransaction($command);

        if ($result->isSuccess()) {
            $command->info('完了');
        } else {
            $result->logError();
            $command->info('失敗');
        }

        $memory = getPeakMegaMemory();
        $time = round(microtime(true) - $start_time, 3);
        dump("実行時間: {$time}秒, メモリ使用量：{$memory}M");
    }
}
