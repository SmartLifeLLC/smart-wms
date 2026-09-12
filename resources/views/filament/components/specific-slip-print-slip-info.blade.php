<div class="grid gap-x-4 gap-y-1 text-sm md:grid-cols-[7rem_9rem_1fr_3rem] md:items-center">
    <div>
        <div class="text-[11px] font-medium leading-4 text-slate-500 dark:text-slate-400">識別ID</div>
        <div class="font-mono font-bold leading-5 text-slate-900 dark:text-white">
            {{ filled($slip['serial_id'] ?? null) ? $slip['serial_id'] : '-' }}
        </div>
    </div>
    <div>
        <div class="text-[11px] font-medium leading-4 text-slate-500 dark:text-slate-400">得意先CD</div>
        <div class="font-mono leading-5 text-slate-800 dark:text-slate-100">
            {{ filled($slip['buyer_code'] ?? null) ? $slip['buyer_code'] : '-' }}
        </div>
    </div>
    <div class="min-w-0">
        <div class="text-[11px] font-medium leading-4 text-slate-500 dark:text-slate-400">得意先名</div>
        <div class="truncate font-semibold leading-5 text-slate-900 dark:text-white">
            {{ filled($slip['buyer_name'] ?? null) ? $slip['buyer_name'] : '-' }}
        </div>
    </div>
    <div class="text-left md:text-right">
        <div class="text-[11px] font-medium leading-4 text-slate-500 dark:text-slate-400">明細</div>
        <div class="font-mono leading-5 text-slate-800 dark:text-slate-100">
            {{ number_format((int) ($slip['line_count'] ?? 0)) }}
        </div>
    </div>
</div>
