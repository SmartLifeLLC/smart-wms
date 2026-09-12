<?php

namespace App\Console\Commands\AutoOrder;

use App\Jobs\ProcessEosIncomingReceiveRunJob;
use App\Models\WmsEosIncomingReceiveRun;
use App\Models\WmsEosIncomingReceiveSchedule;
use App\Models\WmsEosIncomingReceiveSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class EosIncomingReceiveScheduledCommand extends Command
{
    protected $signature = 'wms:eos-incoming-receive-scheduled
                            {--run-now : スケジュール時刻に関係なく即時実行する}
                            {--schedule-id= : 指定スケジュールだけ実行する}
                            {--sync : キューへ投入せず同期実行する}';

    protected $description = 'EOSデータ受信設定に基づきJX受信、入荷確定、仕入データ自動生成をキュー投入する';

    public function handle(): int
    {
        $setting = WmsEosIncomingReceiveSetting::ensureDefault();

        if ($this->option('run-now')) {
            $runKey = 'manual:'.Str::uuid()->toString();
            $this->dispatchRun($runKey, null, WmsEosIncomingReceiveRun::TRIGGER_MANUAL, true);
            $this->info("EOSデータ受信の即時実行を投入しました: {$runKey}");

            return self::SUCCESS;
        }

        if (! $setting->is_enabled) {
            $this->line('EOSデータ受信設定が無効です。');

            return self::SUCCESS;
        }

        $schedules = $this->dueSchedules($setting);

        if ($schedules->isEmpty()) {
            $this->line('現在時刻に実行するEOSデータ受信スケジュールはありません。');

            return self::SUCCESS;
        }

        foreach ($schedules as $schedule) {
            $runKey = sprintf(
                'scheduled:%s:%d:%s',
                now()->toDateString(),
                $schedule->id,
                substr((string) $schedule->receive_time, 0, 5),
            );

            $this->dispatchRun(
                $runKey,
                $schedule->id,
                WmsEosIncomingReceiveRun::TRIGGER_SCHEDULED,
                (bool) $schedule->auto_purchase_transmission_enabled,
            );

            $this->info("EOSデータ受信スケジュールを投入しました: {$schedule->label()} / {$runKey}");
        }

        return self::SUCCESS;
    }

    private function dueSchedules(WmsEosIncomingReceiveSetting $setting)
    {
        $query = WmsEosIncomingReceiveSchedule::query()
            ->where('setting_id', $setting->id)
            ->dueAt(now())
            ->orderBy('schedule_type')
            ->orderBy('day_of_week')
            ->orderBy('slot_no');

        $scheduleId = $this->option('schedule-id');
        if ($scheduleId !== null && $scheduleId !== '') {
            $query->whereKey((int) $scheduleId);
        }

        return $query->get();
    }

    private function dispatchRun(
        string $runKey,
        ?int $scheduleId,
        string $triggerType,
        bool $autoPurchaseTransmission,
    ): void {
        if ($this->option('sync')) {
            ProcessEosIncomingReceiveRunJob::dispatchSync(
                $runKey,
                $scheduleId,
                $triggerType,
                $autoPurchaseTransmission,
            );

            return;
        }

        ProcessEosIncomingReceiveRunJob::dispatch(
            $runKey,
            $scheduleId,
            $triggerType,
            $autoPurchaseTransmission,
        );
    }
}
