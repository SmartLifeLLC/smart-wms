@php
    $queueStatus = $target['queue_status'] ?? null;
    $canPrint = (bool) ($target['can_print'] ?? false);
    $slipTypeName = filled($group['slip_type_name'] ?? null)
        ? (string) $group['slip_type_name']
        : '専用伝票';
    $statusLabel = match ($queueStatus) {
        \App\Models\SpecificSlipPrintRequestQueue::STATUS_PENDING => '印刷待ち',
        \App\Models\SpecificSlipPrintRequestQueue::STATUS_PROCESSING => '印刷処理中',
        \App\Models\SpecificSlipPrintRequestQueue::STATUS_COMPLETED => '完了',
        \App\Models\SpecificSlipPrintRequestQueue::STATUS_FAILED => '失敗',
        default => match (true) {
            ! $canPrint => '印刷不可',
            default => '未印刷',
        },
    };
    $statusClass = match ($queueStatus) {
        \App\Models\SpecificSlipPrintRequestQueue::STATUS_PENDING => 'bg-amber-100 text-amber-700 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900',
        \App\Models\SpecificSlipPrintRequestQueue::STATUS_PROCESSING => 'bg-sky-100 text-sky-700 ring-sky-200 dark:bg-sky-950/40 dark:text-sky-300 dark:ring-sky-900',
        \App\Models\SpecificSlipPrintRequestQueue::STATUS_COMPLETED => 'bg-emerald-100 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900',
        \App\Models\SpecificSlipPrintRequestQueue::STATUS_FAILED => 'bg-red-100 text-red-700 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900',
        default => $canPrint
            ? 'bg-slate-100 text-slate-600 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700'
            : 'bg-red-100 text-red-700 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900',
    };
@endphp

<div class="flex flex-wrap items-center gap-2 text-sm leading-5">
    <div class="font-bold text-slate-900 dark:text-white">{{ $slipTypeName }}</div>
    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-bold ring-1 ring-inset {{ $statusClass }}">
        {{ $statusLabel }}
    </span>
</div>
