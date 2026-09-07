<x-filament-panels::page class="overflow-hidden">
    @php
        $record = $this->record;
        $logs = $this->logs();
        $actorOptions = $this->actorOptions();
        $deviceOptions = $this->deviceOptions();
        $pageFirst = $logs->firstItem() ?? 0;
        $pageLast = $logs->lastItem() ?? 0;
        $filterInputClass = 'h-8 w-full rounded-md border border-slate-300 bg-slate-50 px-2 text-xs text-slate-900 shadow-inner outline-none transition placeholder:text-slate-400 focus:border-sky-500 focus:bg-white focus:ring-1 focus:ring-sky-500';
        $filterSelectClass = 'h-8 w-full rounded-md border border-slate-300 bg-slate-50 px-2 text-xs text-slate-900 shadow-inner outline-none transition focus:border-sky-500 focus:bg-white focus:ring-1 focus:ring-sky-500';
        $statusColors = [
            'draft' => 'bg-slate-200 text-slate-700',
            'counting' => 'bg-sky-100 text-sky-700',
            'checked' => 'bg-amber-100 text-amber-700',
            'confirmed' => 'bg-green-100 text-green-700',
            'cancelled' => 'bg-red-100 text-red-700',
        ];
    @endphp

    <div x-data="{ filtersOpen: true }" class="flex h-[calc(100vh-72px)] min-h-0 flex-col gap-2">
        <div class="relative z-20 shrink-0 overflow-visible rounded-lg border border-slate-300 bg-slate-100 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-800 px-3 py-2 text-white">
                <div class="flex min-w-0 flex-wrap items-center gap-3">
                    <span class="truncate text-xs text-slate-300">
                        {{ $record->count_no }}
                        / {{ $record->warehouse_name }}
                        / {{ $record->count_date?->format('Y/m/d') }}
                    </span>
                    <span class="rounded-full px-2 py-0.5 text-[11px] font-bold {{ $statusColors[$record->status] ?? 'bg-slate-200 text-slate-700' }}">
                        {{ $record->status_label }}
                    </span>
                    <span class="text-xs text-slate-400">
                        ログ全{{ number_format($this->totalLogCount()) }}件
                        / 表示{{ number_format($pageFirst) }}-{{ number_format($pageLast) }}件
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button"
                        wire:click="downloadExcel"
                        wire:loading.attr="disabled"
                        wire:target="downloadExcel"
                        class="inline-flex h-8 items-center gap-1 rounded-md border border-slate-500 px-2 text-xs font-semibold text-slate-100 hover:bg-slate-700 disabled:cursor-wait disabled:opacity-70">
                        <x-filament::icon wire:loading.remove wire:target="downloadExcel" icon="heroicon-m-arrow-down-tray" class="h-4 w-4" />
                        <span wire:loading wire:target="downloadExcel" class="h-4 w-4 animate-spin rounded-full border-2 border-slate-400 border-t-white"></span>
                        <span wire:loading.remove wire:target="downloadExcel">Excelダウンロード</span>
                        <span wire:loading wire:target="downloadExcel">出力中</span>
                    </button>
                    <a href="{{ \App\Filament\Resources\WmsInventoryCountResource::getUrl('view', ['record' => $record]) }}"
                        class="inline-flex h-8 items-center gap-1 rounded-md border border-slate-500 px-2 text-xs font-semibold text-slate-100 hover:bg-slate-700">
                        <x-filament::icon icon="heroicon-m-arrow-left" class="h-4 w-4" />
                        <span>棚卸し詳細</span>
                    </a>
                    <button type="button"
                        class="inline-flex h-8 items-center gap-1 rounded-md border border-slate-500 px-2 text-xs font-semibold text-slate-100 hover:bg-slate-700"
                        @click="filtersOpen = ! filtersOpen">
                        <x-filament::icon icon="heroicon-m-magnifying-glass" class="h-4 w-4" />
                        <span>検索条件</span>
                        <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4 transition" x-bind:class="{ 'rotate-180': filtersOpen }" />
                    </button>
                </div>
            </div>

            <div x-show="filtersOpen" x-collapse x-cloak class="bg-slate-100 p-2">
                <div class="grid grid-cols-2 items-end gap-2 md:grid-cols-6 xl:grid-cols-12">
                    <label class="space-y-1 md:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">作業者</span>
                        <select wire:model.live="actorFilter" class="{{ $filterSelectClass }}">
                            <option value="">すべて</option>
                            @foreach ($actorOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="space-y-1">
                        <span class="text-xs font-semibold text-slate-700">回数</span>
                        <select wire:model.live="roundFilter" class="{{ $filterSelectClass }}">
                            <option value="">すべて</option>
                            <option value="1">1回目</option>
                            <option value="2">2回目</option>
                            <option value="3">3回目</option>
                        </select>
                    </label>
                    <label class="space-y-1 md:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">端末</span>
                        <select wire:model.live="deviceFilter" class="{{ $filterSelectClass }}">
                            <option value="">すべて</option>
                            @foreach ($deviceOptions as $device)
                                <option value="{{ $device }}">{{ $device }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="space-y-1 md:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">商品CD</span>
                        <input type="text" wire:model.live.debounce.300ms="itemCodeFilter" class="{{ $filterInputClass }}" placeholder="商品CD検索">
                    </label>
                    <label class="space-y-1 md:col-span-3">
                        <span class="text-xs font-semibold text-slate-700">商品名</span>
                        <input type="text" wire:model.live.debounce.300ms="itemNameFilter" class="{{ $filterInputClass }}" placeholder="商品名検索">
                    </label>
                    <button type="button" wire:click="clearFilters"
                        class="h-8 rounded-md border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                        クリア
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 bg-green-700 px-3 py-2 text-white">
                <div class="rounded-full bg-green-900/40 px-3 py-1 text-sm font-black tabular-nums">
                    {{ number_format($pageFirst) }}-{{ number_format($pageLast) }} / {{ number_format($logs->total()) }}件
                </div>
                <div class="flex items-center gap-1 text-xs font-bold">
                    <button type="button"
                        wire:click="previousLogPage"
                        @disabled($logs->onFirstPage())
                        class="h-8 rounded-md border border-green-300 px-2 text-white disabled:cursor-not-allowed disabled:opacity-40 hover:bg-green-800">
                        前へ
                    </button>
                    <span class="px-2 tabular-nums">{{ $logs->currentPage() }} / {{ $logs->lastPage() }}</span>
                    <button type="button"
                        wire:click="nextLogPage"
                        @disabled(! $logs->hasMorePages())
                        class="h-8 rounded-md border border-green-300 px-2 text-white disabled:cursor-not-allowed disabled:opacity-40 hover:bg-green-800">
                        次へ
                    </button>
                    <div wire:loading class="px-2 text-xs">読込中...</div>
                </div>
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-auto rounded-lg border border-slate-300 bg-white shadow-sm">
            @if ($logs->count() === 0)
                <div class="p-8 text-center text-sm text-slate-500">条件に一致する作業ログはありません。</div>
            @else
                <table class="w-max min-w-full border-collapse text-xs">
                    <thead class="sticky top-0 z-10 bg-slate-100 text-slate-700">
                        <tr>
                            <th class="border border-slate-300 px-2 py-2 text-left">日時</th>
                            <th class="border border-slate-300 px-2 py-2 text-left">作業者</th>
                            <th class="border border-slate-300 px-2 py-2 text-left">端末</th>
                            <th class="border border-slate-300 px-2 py-2 text-center">回数</th>
                            <th class="border border-slate-300 px-2 py-2 text-left">商品CD</th>
                            <th class="border border-slate-300 px-2 py-2 text-left">商品名</th>
                            <th class="border border-slate-300 px-2 py-2 text-left">ロケーション</th>
                            <th class="border border-slate-300 px-2 py-2 text-left">ロット</th>
                            <th class="border border-slate-300 px-2 py-2 text-right">変更前</th>
                            <th class="border border-slate-300 px-2 py-2 text-right">入力数量</th>
                            <th class="border border-slate-300 px-2 py-2 text-right">入力差分</th>
                            <th class="border border-slate-300 px-2 py-2 text-left">リクエストID</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($logs as $log)
                            @php
                                $item = $log->countItem;
                                $difference = $log->new_quantity !== null && $log->old_quantity !== null
                                    ? (float) $log->new_quantity - (float) $log->old_quantity
                                    : null;
                            @endphp
                            <tr class="odd:bg-white even:bg-slate-50 hover:bg-sky-50">
                                <td class="whitespace-nowrap border border-slate-200 px-2 py-1.5 text-slate-700">
                                    {{ $log->created_at?->format('m/d H:i:s') ?? '-' }}
                                </td>
                                <td class="whitespace-nowrap border border-slate-200 px-2 py-1.5 font-semibold text-slate-900">
                                    {{ $log->actor_name }}
                                </td>
                                <td class="whitespace-nowrap border border-slate-200 px-2 py-1.5 text-slate-700">
                                    {{ $log->device_id ?: '-' }}
                                </td>
                                <td class="whitespace-nowrap border border-slate-200 px-2 py-1.5 text-center font-bold text-slate-900">
                                    {{ $log->count_round }}回目
                                </td>
                                <td class="whitespace-nowrap border border-slate-200 px-2 py-1.5 font-mono text-slate-900">
                                    {{ $item?->item_code ?? '-' }}
                                </td>
                                <td class="min-w-[260px] border border-slate-200 px-2 py-1.5 text-slate-900">
                                    {{ $item?->item_name ?? '-' }}
                                </td>
                                <td class="whitespace-nowrap border border-slate-200 px-2 py-1.5 font-mono text-slate-900">
                                    {{ $item?->location_no ?? '-' }}
                                </td>
                                <td class="whitespace-nowrap border border-slate-200 px-2 py-1.5 font-mono text-slate-700">
                                    {{ $item?->lot_no ?? '-' }}
                                </td>
                                <td class="whitespace-nowrap border border-slate-200 px-2 py-1.5 text-right tabular-nums text-slate-700">
                                    {{ $this->formatQuantity($log->old_quantity) }}
                                </td>
                                <td class="whitespace-nowrap border border-slate-200 px-2 py-1.5 text-right font-bold tabular-nums text-slate-900">
                                    {{ $this->formatQuantity($log->new_quantity) }}
                                </td>
                                <td class="whitespace-nowrap border border-slate-200 px-2 py-1.5 text-right font-bold tabular-nums {{ $difference === null || $difference == 0.0 ? 'text-slate-500' : ($difference > 0 ? 'text-blue-700' : 'text-red-700') }}">
                                    {{ $this->formatDifference($log) }}
                                </td>
                                <td class="whitespace-nowrap border border-slate-200 px-2 py-1.5 font-mono text-[11px] text-slate-500">
                                    {{ $log->request_uuid ?: '-' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-filament-panels::page>
