<x-filament-panels::page>
    @if (! empty($completionResult))
        <div class="space-y-3">
            <div class="rounded-md border border-emerald-200 bg-white p-4 shadow-sm dark:border-emerald-800 dark:bg-gray-900">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                            <x-heroicon-o-check-circle class="h-7 w-7" />
                        </div>
                        <div>
                            <div class="text-lg font-bold text-emerald-700 dark:text-emerald-300">発注確定しました。</div>
                            <div class="mt-0.5 text-sm font-semibold text-emerald-700 dark:text-emerald-300">FAXデータをダウンロードしてください。</div>
                            <div class="mt-1 text-sm text-slate-600 dark:text-gray-300">
                                実行CD:
                                <span class="font-mono font-semibold text-slate-900 dark:text-white">{{ $completionResult['batch_code'] ?? '-' }}</span>
                            </div>
                        </div>
                    </div>
                    <button
                        type="button"
                        wire:click="startNewRegistration"
                        class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800"
                    >
                        <x-heroicon-o-plus class="h-4 w-4" />
                        続けて発注登録
                    </button>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-4">
                    <div class="rounded-md bg-slate-50 px-3 py-2 dark:bg-gray-800">
                        <div class="text-xs font-semibold text-slate-500 dark:text-gray-400">発注明細</div>
                        <div class="font-mono text-xl font-bold text-slate-900 dark:text-white">{{ number_format((int) ($completionResult['candidate_count'] ?? 0)) }}</div>
                    </div>
                    <div class="rounded-md bg-slate-50 px-3 py-2 dark:bg-gray-800">
                        <div class="text-xs font-semibold text-slate-500 dark:text-gray-400">入荷予定</div>
                        <div class="font-mono text-xl font-bold text-slate-900 dark:text-white">{{ number_format((int) ($completionResult['incoming_schedule_count'] ?? 0)) }}</div>
                    </div>
                    <div class="rounded-md bg-slate-50 px-3 py-2 dark:bg-gray-800">
                        <div class="text-xs font-semibold text-slate-500 dark:text-gray-400">生成PDF</div>
                        <div class="font-mono text-xl font-bold text-slate-900 dark:text-white">{{ number_format((int) ($completionResult['file_count'] ?? 0)) }}</div>
                    </div>
                    <div class="rounded-md bg-slate-50 px-3 py-2 dark:bg-gray-800">
                        <div class="text-xs font-semibold text-slate-500 dark:text-gray-400">倉庫</div>
                        <div class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $this->selectedWarehouseLabel() }}</div>
                    </div>
                </div>
            </div>

            <div class="overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="flex items-center justify-between border-b border-slate-200 px-3 py-2 dark:border-gray-700">
                    <div class="text-sm font-semibold text-slate-800 dark:text-gray-100">今回生成したFAX/PDF</div>
                    <div class="text-xs text-slate-500 dark:text-gray-400">発注先・入荷予定日ごと</div>
                </div>

                <div class="overflow-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-gray-700">
                        <thead class="bg-slate-50 text-xs text-slate-600 dark:bg-gray-800 dark:text-gray-300">
                            <tr>
                                <th class="whitespace-nowrap px-3 py-2 text-left">種別</th>
                                <th class="whitespace-nowrap px-3 py-2 text-left">発注先</th>
                                <th class="whitespace-nowrap px-3 py-2 text-center">入荷予定日</th>
                                <th class="whitespace-nowrap px-3 py-2 text-right">明細数</th>
                                <th class="whitespace-nowrap px-3 py-2 text-right">総バラ数</th>
                                <th class="whitespace-nowrap px-3 py-2 text-right">金額</th>
                                <th class="whitespace-nowrap px-3 py-2 text-left">状態</th>
                                <th class="whitespace-nowrap px-3 py-2 text-center">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-gray-800">
                            @forelse (($completionResult['files'] ?? []) as $file)
                                @php
                                    $fileChannelLabel = (string) ($file['order_channel_label'] ?? 'FAX発注');
                                    $fileChannelBadgeClass = (($file['order_channel'] ?? null) === 'FAX' || $fileChannelLabel === 'FAX発注')
                                        ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-200'
                                        : 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200';
                                @endphp
                                <tr class="odd:bg-[#f5f9ff] even:bg-white dark:odd:bg-[#1e2a3b] dark:even:bg-gray-900">
                                    <td class="whitespace-nowrap px-3 py-2">
                                        <span class="rounded px-2 py-1 text-xs font-semibold {{ $fileChannelBadgeClass }}">{{ $fileChannelLabel }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 font-semibold text-slate-800 dark:text-gray-100">{{ $file['contractor_name'] ?? '-' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-center font-mono">{{ $file['expected_arrival_date'] ?? '-' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono">{{ number_format((int) ($file['order_count'] ?? 0)) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono font-bold text-sky-700 dark:text-sky-300">{{ number_format((int) ($file['total_piece_quantity'] ?? $file['total_quantity'] ?? 0)) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono font-bold text-emerald-700 dark:text-emerald-300">¥{{ number_format((float) ($file['total_amount'] ?? 0), 0) }}</td>
                                    <td class="px-3 py-2">
                                        @if (filled($file['fax_error'] ?? null))
                                            <span class="text-xs font-semibold text-danger-700 dark:text-danger-300">{{ $file['fax_error'] }}</span>
                                        @elseif (filled($file['fax_file_path'] ?? null))
                                            <span class="text-xs font-semibold text-emerald-700 dark:text-emerald-300">生成済み</span>
                                        @else
                                            <span class="text-xs font-semibold text-amber-700 dark:text-amber-300">PDF未生成</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <button
                                                type="button"
                                                wire:click="openCompletionDetailModal({{ (int) ($file['id'] ?? 0) }})"
                                                class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800"
                                            >
                                                <x-heroicon-o-list-bullet class="h-4 w-4" />
                                                詳細
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="openCompletionFaxDownloadModal({{ (int) ($file['id'] ?? 0) }})"
                                                @disabled(blank($file['fax_file_path'] ?? null))
                                                class="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-emerald-500 disabled:cursor-not-allowed disabled:bg-slate-300 dark:disabled:bg-gray-700"
                                            >
                                                <x-heroicon-o-arrow-down-tray class="h-4 w-4" />
                                                FAXダウンロード
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-3 py-12 text-center text-sm text-slate-400 dark:text-gray-500">生成されたPDFはありません</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($showCompletionDetailModal)
                @php
                    $detailTargetFile = collect($completionResult['files'] ?? [])
                        ->firstWhere('id', $completionDetailDataFileId);
                    $detailLines = collect($detailTargetFile['lines'] ?? []);
                    $detailTotalPieces = $detailLines->sum(fn ($line) => (int) ($line['total_piece_quantity'] ?? 0));
                    $detailTotalCases = $detailLines->sum(function ($line) {
                        $capacityCase = max(1, (int) ($line['capacity_case'] ?? 1));

                        return (int) ($line['total_piece_quantity'] ?? 0) / $capacityCase;
                    });
                    $detailTotalCasesLabel = abs($detailTotalCases - round($detailTotalCases)) < 0.0001
                        ? number_format($detailTotalCases, 0)
                        : rtrim(rtrim(number_format($detailTotalCases, 2), '0'), '.');
                    $detailTotalAmount = $detailLines->sum(fn ($line) => (float) ($line['total_amount'] ?? 0));
                @endphp
                <div class="fixed inset-0 flex items-center justify-center bg-slate-950/50 p-4" style="z-index: 10000;">
                    <div class="flex h-[86vh] max-h-[86vh] w-full max-w-[calc(100vw-2rem)] flex-col overflow-hidden rounded-lg bg-white shadow-xl dark:bg-gray-900">
                        <div class="flex items-center justify-between bg-slate-800 px-4 py-3 text-white">
                            <div class="flex items-center gap-2 text-sm font-semibold">
                                <x-heroicon-o-list-bullet class="h-5 w-5" />
                                発注明細詳細
                            </div>
                            <button type="button" wire:click="closeCompletionDetailModal" class="rounded p-1 text-white hover:bg-white/10">
                                <x-heroicon-o-x-mark class="h-5 w-5" />
                            </button>
                        </div>

                        <div class="flex min-h-0 flex-1 flex-col gap-3 overflow-hidden p-4">
                            @if ($detailTargetFile)
                                <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                                    <div class="grid grid-cols-1 gap-x-6 gap-y-2 md:grid-cols-2">
                                        <div class="grid grid-cols-[6rem_1fr] gap-x-3 gap-y-1">
                                            <div class="text-xs font-semibold text-slate-500 dark:text-gray-400">種別</div>
                                            <div class="font-semibold text-slate-900 dark:text-white">{{ $detailTargetFile['order_channel_label'] ?? 'FAX発注' }}</div>
                                            <div class="text-xs font-semibold text-slate-500 dark:text-gray-400">入荷予定日</div>
                                            <div class="font-mono font-semibold text-slate-900 dark:text-white">{{ $detailTargetFile['expected_arrival_date'] ?? '-' }}</div>
                                        </div>
                                        <div class="grid grid-cols-[6rem_1fr] gap-x-3 gap-y-1">
                                            <div class="text-xs font-semibold text-slate-500 dark:text-gray-400">発注先</div>
                                            <div class="font-semibold text-slate-900 dark:text-white">{{ $detailTargetFile['contractor_name'] ?? '-' }}</div>
                                            <div class="text-xs font-semibold text-slate-500 dark:text-gray-400">総バラ数</div>
                                            <div class="font-mono font-bold text-sky-700 dark:text-sky-300">{{ number_format($detailTotalPieces) }} バラ</div>
                                            <div class="text-xs font-semibold text-slate-500 dark:text-gray-400">総ケース数</div>
                                            <div class="font-mono font-bold text-indigo-700 dark:text-indigo-300">{{ $detailTotalCasesLabel }} ケース</div>
                                            <div class="text-xs font-semibold text-slate-500 dark:text-gray-400">合計金額</div>
                                            <div class="font-mono font-bold text-emerald-700 dark:text-emerald-300">¥{{ number_format($detailTotalAmount, 0) }}</div>
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <div class="min-h-0 flex-1 overflow-auto rounded-md border border-slate-200 dark:border-gray-700">
                                <table class="min-w-[1420px] divide-y divide-slate-200 text-sm dark:divide-gray-700">
                                    <thead class="sticky top-0 bg-slate-50 text-xs text-slate-600 dark:bg-gray-800 dark:text-gray-300" style="z-index: 1;">
                                        <tr>
                                            <th class="whitespace-nowrap px-3 py-2 text-center">発注区分</th>
                                            <th class="whitespace-nowrap px-3 py-2 text-center">入荷予定日</th>
                                            <th class="whitespace-nowrap px-3 py-2 text-left">発注先CD</th>
                                            <th class="whitespace-nowrap px-3 py-2 text-left">発注先名</th>
                                            <th class="whitespace-nowrap px-3 py-2 text-left">仕入先</th>
                                            <th class="whitespace-nowrap px-3 py-2 text-left">商品CD</th>
                                            <th class="min-w-72 px-3 py-2 text-left">商品名</th>
                                            <th class="whitespace-nowrap px-3 py-2 text-right">入数</th>
                                            <th class="whitespace-nowrap px-3 py-2 text-center">単位</th>
                                            <th class="whitespace-nowrap px-3 py-2 text-right">発注数</th>
                                            <th class="whitespace-nowrap px-3 py-2 text-right">総バラ数</th>
                                            <th class="whitespace-nowrap px-3 py-2 text-right">仕入原価</th>
                                            <th class="whitespace-nowrap px-3 py-2 text-right">合計金額</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 dark:divide-gray-800">
                                        @forelse ($detailLines as $line)
                                            @php
                                                $lineChannelLabel = (string) ($line['order_channel_label'] ?? '-');
                                                $lineChannelBadgeClass = $lineChannelLabel === 'FAX発注'
                                                    ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-200'
                                                    : 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200';
                                            @endphp
                                            <tr class="odd:bg-[#f5f9ff] even:bg-white dark:odd:bg-[#1e2a3b] dark:even:bg-gray-900">
                                                <td class="whitespace-nowrap px-3 py-2 text-center">
                                                    <span class="rounded px-2 py-1 text-xs font-semibold {{ $lineChannelBadgeClass }}">{{ $lineChannelLabel }}</span>
                                                </td>
                                                <td class="whitespace-nowrap px-3 py-2 text-center font-mono">{{ $line['expected_arrival_date'] ?? '-' }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 font-mono text-xs">{{ $line['contractor_code'] ?? '' }}</td>
                                                <td class="whitespace-nowrap px-3 py-2">{{ $line['contractor_name'] ?? '-' }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 font-semibold text-slate-800 dark:text-gray-100">{{ $line['supplier_name'] ?? '-' }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 font-mono text-xs">{{ $line['item_code'] ?? '' }}</td>
                                                <td class="px-3 py-2 text-slate-800 dark:text-gray-100">{{ $line['item_name'] ?? '-' }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-right font-mono">{{ number_format((int) ($line['capacity_case'] ?? 1)) }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-center">{{ $line['quantity_type_label'] ?? '-' }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-right font-mono">{{ number_format((int) ($line['order_quantity'] ?? 0)) }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-right font-mono font-bold text-sky-700 dark:text-sky-300">{{ number_format((int) ($line['total_piece_quantity'] ?? 0)) }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-right font-mono">¥{{ number_format((float) ($line['purchase_unit_price'] ?? 0), 0) }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-right font-mono font-bold text-emerald-700 dark:text-emerald-300">¥{{ number_format((float) ($line['total_amount'] ?? 0), 0) }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="13" class="px-3 py-12 text-center text-sm text-slate-400 dark:text-gray-500">明細はありません</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
                            <button type="button" wire:click="closeCompletionDetailModal" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">閉じる</button>
                        </div>
                    </div>
                </div>
            @endif

            @if ($showCompletionFaxDownloadModal)
                @php
                    $downloadTargetFile = collect($completionResult['files'] ?? [])
                        ->firstWhere('id', $completionFaxDownloadDataFileId);
                @endphp
                <div class="fixed inset-0 flex items-center justify-center bg-slate-950/50 p-4" style="z-index: 10000;">
                    <div class="flex w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white shadow-xl dark:bg-gray-900">
                        <div class="flex items-center justify-between bg-slate-800 px-4 py-3 text-white">
                            <div class="flex items-center gap-2 text-sm font-semibold">
                                <x-heroicon-o-arrow-down-tray class="h-5 w-5" />
                                FAX/PDFダウンロード
                            </div>
                            <button type="button" wire:click="closeCompletionFaxDownloadModal" class="rounded p-1 text-white hover:bg-white/10">
                                <x-heroicon-o-x-mark class="h-5 w-5" />
                            </button>
                        </div>

                        <div class="space-y-3 p-4">
                            @if ($downloadTargetFile)
                                <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                                        <div>
                                            <div class="text-xs font-semibold text-slate-500 dark:text-gray-400">種別</div>
                                            <div class="font-semibold text-slate-900 dark:text-white">{{ $downloadTargetFile['order_channel_label'] ?? 'FAX発注' }}</div>
                                        </div>
                                        <div>
                                            <div class="text-xs font-semibold text-slate-500 dark:text-gray-400">発注先</div>
                                            <div class="font-semibold text-slate-900 dark:text-white">{{ $downloadTargetFile['contractor_name'] ?? '-' }}</div>
                                        </div>
                                        <div>
                                            <div class="text-xs font-semibold text-slate-500 dark:text-gray-400">入荷予定日</div>
                                            <div class="font-mono font-semibold text-slate-900 dark:text-white">{{ $downloadTargetFile['expected_arrival_date'] ?? '-' }}</div>
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <label class="block">
                                <span class="mb-1 block text-xs font-semibold text-slate-600 dark:text-gray-300">連絡事項（PDF通信欄）</span>
                                <textarea
                                    wire:model.live.debounce.300ms="completionFaxCommunicationNotes"
                                    maxlength="500"
                                    rows="5"
                                    placeholder="発注書の通信欄に表示する内容を入力"
                                    class="w-full rounded-md border border-slate-400 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-200 dark:border-gray-500 dark:bg-gray-950 dark:text-gray-100 dark:placeholder:text-gray-500 dark:focus:border-blue-400 dark:focus:ring-blue-900/60"
                                ></textarea>
                                <span class="mt-1 block text-right text-[11px] font-semibold text-slate-400 dark:text-gray-500">{{ mb_strlen($completionFaxCommunicationNotes) }}/500</span>
                            </label>
                        </div>

                        <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
                            <button type="button" wire:click="closeCompletionFaxDownloadModal" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">ダウンロードせず閉じる</button>
                            <button
                                type="button"
                                wire:click="downloadCompletionFaxWithNotes"
                                wire:loading.attr="disabled"
                                class="inline-flex items-center gap-1 rounded-md bg-danger-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-danger-500 disabled:cursor-not-allowed disabled:bg-slate-300 dark:disabled:bg-gray-700"
                            >
                                <x-heroicon-o-arrow-down-tray class="h-4 w-4" />
                                FAXダウンロード
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @else
    @php
        $lineContractorFilter = (string) ($this->lineContractorFilter ?? '');
        $contractorFilterOptions = collect($lines)
            ->map(fn ($line) => [
                'id' => (string) ($line['contractor_id'] ?? ''),
                'code' => (string) ($line['contractor_code'] ?? ''),
                'name' => (string) ($line['contractor_name'] ?? '-'),
            ])
            ->filter(fn ($contractor) => $contractor['id'] !== '')
            ->unique('id')
            ->sortBy('name')
            ->values();
        $visibleLines = collect($lines)
            ->filter(fn ($line) => $lineContractorFilter === '' || (string) ($line['contractor_id'] ?? '') === $lineContractorFilter);
        $visibleLineIndexes = $visibleLines->keys()->map(fn ($index) => (int) $index)->values();
        $selectedRegistrationLineIndexes = collect($this->selectedRegistrationLineIndexes ?? [])
            ->map(fn ($index) => (int) $index)
            ->unique()
            ->values();
        $selectedVisibleLineCount = $selectedRegistrationLineIndexes
            ->intersect($visibleLineIndexes)
            ->count();
        $allVisibleLinesSelected = $visibleLines->isNotEmpty() && $selectedVisibleLineCount === $visibleLines->count();
        $visibleLineTotalAmount = $visibleLines->sum(function ($line) {
            return (float) ($line['purchase_unit_price'] ?? 0) * (int) ($line['order_quantity'] ?? 0);
        });
        $visibleLineTotalPieces = $visibleLines->sum(function ($line) {
            $orderQuantity = (int) ($line['order_quantity'] ?? 0);
            $capacityCase = max(1, (int) ($line['capacity_case'] ?? 1));

            return ($line['quantity_type'] ?? '') === \App\Enums\QuantityType::CASE->value
                ? $orderQuantity * $capacityCase
                : $orderQuantity;
        });
        $visibleLineTotalCases = $visibleLines->sum(function ($line) {
            $orderQuantity = (int) ($line['order_quantity'] ?? 0);
            $capacityCase = max(1, (int) ($line['capacity_case'] ?? 1));

            return ($line['quantity_type'] ?? '') === \App\Enums\QuantityType::CASE->value
                ? $orderQuantity
                : $orderQuantity / $capacityCase;
        });
        $visibleLineTotalCasesLabel = abs($visibleLineTotalCases - round($visibleLineTotalCases)) < 0.0001
            ? number_format($visibleLineTotalCases, 0)
            : rtrim(rtrim(number_format($visibleLineTotalCases, 2), '0'), '.');
        $warehouseOptionsById = collect($this->warehouses)->keyBy('id');
        $formatWarehouseCode = function ($warehouseId) use ($warehouseOptionsById): string {
            $warehouse = $warehouseOptionsById->get((int) $warehouseId);
            $code = (string) ($warehouse['code'] ?? $warehouseId ?? '');

            return $code === '' ? '-' : str_pad($code, 2, '0', STR_PAD_LEFT);
        };
        $warehouseNameForLine = function ($line) use ($warehouseOptionsById): string {
            $warehouse = $warehouseOptionsById->get((int) ($line['warehouse_id'] ?? 0));

            return (string) ($warehouse['name'] ?? $line['warehouse_name'] ?? '-');
        };
    @endphp
    <div class="flex h-[calc(100vh-5.75rem)] min-h-0 flex-col gap-3">
        <div class="shrink-0 rounded-md border border-slate-200 bg-white p-2 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-72 flex-1">
                    <div class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xl font-black text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                        {{ $this->selectedWarehouseLabel() }}
                    </div>
                </div>

                <div class="ml-auto flex flex-wrap items-end justify-end gap-2">
                    <button
                        type="button"
                        wire:click="openCandidateSearchModal"
                        class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800"
                    >
                        <x-heroicon-o-magnifying-glass class="h-4 w-4" />
                        発注候補検索
                    </button>
                    <button
                        type="button"
                        wire:click="openSalesHistoryModal"
                        wire:loading.attr="disabled"
                        wire:target="openSalesHistoryModal"
                        class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 disabled:cursor-wait disabled:bg-slate-100 disabled:text-slate-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800 dark:disabled:bg-gray-800 dark:disabled:text-gray-400"
                    >
                        <x-heroicon-o-chart-bar wire:loading.remove wire:target="openSalesHistoryModal" class="h-4 w-4" />
                        <span wire:loading wire:target="openSalesHistoryModal" class="h-4 w-4 animate-spin rounded-full border-2 border-slate-300 border-t-slate-700 dark:border-gray-600 dark:border-t-gray-200"></span>
                        <span wire:loading.remove wire:target="openSalesHistoryModal">販売履歴から生成</span>
                        <span wire:loading wire:target="openSalesHistoryModal">読み込み中</span>
                    </button>
                    <button
                        type="button"
                        wire:click="confirmOrders"
                        wire:loading.attr="disabled"
                        @disabled(empty($lines))
                        class="inline-flex items-center gap-1 rounded-md bg-danger-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-danger-500 disabled:cursor-not-allowed disabled:bg-slate-300 dark:disabled:bg-gray-700"
                    >
                        <x-heroicon-o-check-circle class="h-4 w-4" />
                        確定
                    </button>
                </div>
            </div>
        </div>

        <div class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-3 py-2 dark:border-gray-700">
                <div>
                    <div class="text-sm font-semibold text-slate-800 dark:text-gray-100">登録リスト</div>
                    <div class="text-xs text-slate-500 dark:text-gray-400">確定時に発注確定済みデータ・入荷予定・PDFを作成</div>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-3">
                    <div class="flex flex-wrap items-center gap-2 rounded-md bg-slate-50 px-3 py-2 text-sm font-bold text-slate-700 dark:bg-gray-800 dark:text-gray-200">
                        <span>
                            表示
                            <span class="font-mono text-base text-slate-950 dark:text-white">{{ number_format($visibleLines->count()) }}</span>
                            件 / 全
                            <span class="font-mono text-base text-slate-950 dark:text-white">{{ number_format(count($lines)) }}</span>
                            件
                        </span>
                        <span class="inline-flex items-center gap-1 rounded-md border border-indigo-200 bg-indigo-50 px-2 py-1 text-indigo-800 dark:border-indigo-800 dark:bg-indigo-950/40 dark:text-indigo-200">
                            総ケース数
                            <span class="font-mono text-lg font-bold text-indigo-950 dark:text-indigo-100">{{ $visibleLineTotalCasesLabel }}</span>
                            ケース
                        </span>
                        <span class="inline-flex items-center gap-1 rounded-md border border-sky-200 bg-sky-50 px-2 py-1 text-sky-800 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-200">
                            表示合計数量（総バラ合計）
                            <span class="font-mono text-lg font-bold text-sky-950 dark:text-sky-100">{{ number_format($visibleLineTotalPieces) }}</span>
                            バラ
                        </span>
                        <span class="inline-flex items-center gap-1 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-1 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200">
                            表示合計
                            <span class="font-mono text-lg font-bold text-emerald-950 dark:text-emerald-100">¥{{ number_format($visibleLineTotalAmount, 0) }}</span>
                        </span>
                    </div>
                    <form wire:submit.prevent="applyBulkExpectedArrivalDate" class="flex items-center gap-2 rounded-md border border-slate-200 bg-white px-2 py-1.5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                        <span class="whitespace-nowrap text-xs font-semibold text-slate-600 dark:text-gray-300">
                            選択 {{ number_format($selectedVisibleLineCount) }}件
                        </span>
                        <input
                            type="date"
                            min="{{ now()->toDateString() }}"
                            wire:model.live="bulkExpectedArrivalDate"
                            class="w-36 rounded-md border-slate-300 text-sm font-semibold dark:border-gray-600 dark:bg-gray-900"
                        >
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="applyBulkExpectedArrivalDate"
                            @disabled($selectedVisibleLineCount === 0 || blank($this->bulkExpectedArrivalDate ?? ''))
                            class="inline-flex items-center gap-1 rounded-md bg-slate-800 px-3 py-2 text-xs font-semibold text-white shadow-sm hover:bg-slate-700 disabled:cursor-not-allowed disabled:bg-slate-300 dark:disabled:bg-gray-700"
                        >
                            <span wire:loading.remove wire:target="applyBulkExpectedArrivalDate">入荷予定日反映</span>
                            <span wire:loading wire:target="applyBulkExpectedArrivalDate">反映中</span>
                        </button>
                    </form>
                    <button
                        type="button"
                        wire:click="downloadRegistrationListPdf"
                        wire:loading.attr="disabled"
                        wire:target="downloadRegistrationListPdf"
                        @disabled($visibleLines->isEmpty())
                        class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800 dark:disabled:bg-gray-800 dark:disabled:text-gray-500"
                    >
                        <x-heroicon-o-arrow-down-tray wire:loading.remove wire:target="downloadRegistrationListPdf" class="h-4 w-4" />
                        <span wire:loading wire:target="downloadRegistrationListPdf" class="h-4 w-4 animate-spin rounded-full border-2 border-slate-300 border-t-slate-700 dark:border-gray-600 dark:border-t-gray-200"></span>
                        <span wire:loading.remove wire:target="downloadRegistrationListPdf">PDF出力</span>
                        <span wire:loading wire:target="downloadRegistrationListPdf">出力中</span>
                    </button>
                    <div>
                        <select
                            wire:model.live="lineContractorFilter"
                            class="w-72 rounded-md border-slate-300 px-3 py-2 text-sm font-semibold shadow-sm dark:border-gray-600 dark:bg-gray-900"
                        >
                            <option value="">発注先すべて</option>
                            @foreach ($contractorFilterOptions as $contractor)
                                <option value="{{ $contractor['id'] }}">[{{ $contractor['code'] }}]{{ $contractor['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-gray-700">
                    <thead class="sticky top-0 bg-slate-50 text-xs text-slate-600 dark:bg-gray-800 dark:text-gray-300" style="z-index: 1;">
                        <tr>
                            <th class="whitespace-nowrap px-3 py-2 text-center">
                                <input
                                    type="checkbox"
                                    @checked($allVisibleLinesSelected)
                                    @disabled($visibleLines->isEmpty())
                                    wire:click="toggleVisibleRegistrationLineSelection"
                                    class="rounded border-slate-300 text-primary-600 shadow-sm focus:ring-primary-500 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-600 dark:bg-gray-900"
                                >
                            </th>
                            <th class="whitespace-nowrap px-3 py-2 text-center">行</th>
                            <th class="whitespace-nowrap px-3 py-2 text-center">発注区分</th>
                            <th class="whitespace-nowrap px-3 py-2 text-center">入荷予定日</th>
                            <th class="whitespace-nowrap px-3 py-2 text-left">発注先CD</th>
                            <th class="whitespace-nowrap px-3 py-2 text-left">発注先名</th>
                            <th class="whitespace-nowrap px-3 py-2 text-left">商品CD</th>
                            <th class="min-w-72 px-3 py-2 text-left">商品名</th>
                            <th class="whitespace-nowrap px-3 py-2 text-right">入数</th>
                            <th class="whitespace-nowrap border-l-2 border-slate-300 bg-amber-100 px-3 py-2 text-right text-amber-900 dark:border-slate-600 dark:bg-amber-900/40 dark:text-amber-100">ケース</th>
                            <th class="whitespace-nowrap bg-amber-100 px-3 py-2 text-right text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">バラ</th>
                            <th class="whitespace-nowrap px-3 py-2 text-right">総ケース数</th>
                            <th class="whitespace-nowrap px-3 py-2 text-right">総バラ数</th>
                            <th class="whitespace-nowrap px-3 py-2 text-right">仕入原価</th>
                            <th class="whitespace-nowrap px-3 py-2 text-right">合計金額</th>
                            <th class="whitespace-nowrap px-3 py-2 text-center">納入先</th>
                            <th class="sticky right-0 bg-slate-50 px-3 py-2 text-center dark:bg-gray-800">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-gray-800">
                        @forelse ($visibleLines as $index => $line)
                            @php
                                $orderQuantity = (int) ($line['order_quantity'] ?? 0);
                                $capacityCase = max(1, (int) ($line['capacity_case'] ?? 1));
                                $totalPieces = ($line['quantity_type'] ?? '') === \App\Enums\QuantityType::CASE->value
                                    ? $orderQuantity * $capacityCase
                                    : $orderQuantity;
                                $totalCases = $totalPieces / $capacityCase;
                                $totalCasesLabel = abs($totalCases - round($totalCases)) < 0.0001
                                    ? number_format($totalCases, 0)
                                    : rtrim(rtrim(number_format($totalCases, 2), '0'), '.');
                                $isEosAvailable = (bool) ($line['is_eos_available'] ?? false);
                                $purchaseUnitPrice = (float) ($line['purchase_unit_price'] ?? 0);
                                $lineTotalAmount = $purchaseUnitPrice * $orderQuantity;
                                $warehouseCode = $formatWarehouseCode($line['warehouse_id'] ?? null);
                                $warehouseName = $warehouseNameForLine($line);
                                $orderChannelValue = (string) ($line['order_channel'] ?? \App\Enums\AutoOrder\OrderChannel::EOS->value);
                                $lineCaseQuantity = ($line['quantity_type'] ?? '') === \App\Enums\QuantityType::CASE->value && $orderQuantity > 0
                                    ? $orderQuantity
                                    : '';
                                $linePieceQuantity = ($line['quantity_type'] ?? '') === \App\Enums\QuantityType::PIECE->value && $orderQuantity > 0
                                    ? $orderQuantity
                                    : '';
                                $orderChannelSelectClass = $orderChannelValue === \App\Enums\AutoOrder\OrderChannel::FAX->value
                                    ? 'border-green-300 bg-green-100 text-green-700 dark:border-green-700 dark:bg-green-900/40 dark:text-green-200'
                                    : 'border-blue-300 bg-blue-100 text-blue-700 dark:border-blue-700 dark:bg-blue-900/40 dark:text-blue-200';
                            @endphp
                            <tr wire:key="registration-line-{{ $index }}" class="odd:bg-[#f5f9ff] even:bg-white dark:odd:bg-[#1e2a3b] dark:even:bg-gray-900">
                                <td class="whitespace-nowrap px-3 py-2 text-center">
                                    <input
                                        type="checkbox"
                                        value="{{ $index }}"
                                        wire:model.live="selectedRegistrationLineIndexes"
                                        class="rounded border-slate-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900"
                                    >
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-center font-mono font-semibold text-slate-500 dark:text-gray-400">{{ $loop->iteration }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-center">
                                    @if ($isEosAvailable)
                                        <select
                                            wire:model.live="lines.{{ $index }}.order_channel"
                                            class="w-28 rounded-md text-xs font-semibold {{ $orderChannelSelectClass }}"
                                        >
                                            <option value="{{ \App\Enums\AutoOrder\OrderChannel::EOS->value }}">EOS発注</option>
                                            <option value="{{ \App\Enums\AutoOrder\OrderChannel::FAX->value }}">FAX発注</option>
                                        </select>
                                    @else
                                        <span class="inline-flex items-center rounded bg-green-100 px-2 py-1 text-xs font-semibold text-green-700 dark:bg-green-900/40 dark:text-green-200">FAX固定</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-center">
                                    <input
                                        type="date"
                                        min="{{ now()->toDateString() }}"
                                        wire:model.live="lines.{{ $index }}.expected_arrival_date"
                                        class="w-36 rounded-md border-slate-300 text-sm dark:border-gray-600 dark:bg-gray-900"
                                    >
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 font-mono text-xs">{{ $line['contractor_code'] }}</td>
                                <td class="whitespace-nowrap px-3 py-2">
                                    <div class="flex items-center gap-2">
                                        <div>
                                            <div class="font-semibold text-slate-800 dark:text-gray-100">{{ $line['contractor_name'] }}</div>
                                            @if (! ($line['item_contractor_linked'] ?? true))
                                                <div class="mt-0.5 text-[11px] font-semibold text-danger-700 dark:text-danger-300">商品未設定 / FAX固定</div>
                                            @endif
                                        </div>
                                        <button
                                            type="button"
                                            wire:click="openContractorChangeModal({{ $index }})"
                                            class="inline-flex items-center rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800"
                                        >
                                            変更
                                        </button>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 font-mono text-xs">{{ $line['item_code'] }}</td>
                                <td class="px-3 py-2">{{ $line['item_name'] }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-right">{{ number_format($capacityCase) }}</td>
                                <td class="whitespace-nowrap border-l-2 border-slate-300 bg-amber-50 px-2 py-2 text-center dark:border-slate-600 dark:bg-amber-950/30">
                                    <input
                                        type="text"
                                        inputmode="numeric"
                                        pattern="[0-9]*"
                                        autocomplete="off"
                                        value="{{ $lineCaseQuantity }}"
                                        wire:change="setLineOrderQuantity({{ $index }}, 'CASE', $event.target.value)"
                                        class="w-[58px] rounded-md border-2 border-amber-300 bg-white px-1 py-0.5 text-right text-sm font-semibold text-slate-900 shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-200 dark:border-amber-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-amber-500 dark:focus:ring-amber-900"
                                    >
                                </td>
                                <td class="whitespace-nowrap bg-amber-50 px-2 py-2 text-center dark:bg-amber-950/30">
                                    <input
                                        type="text"
                                        inputmode="numeric"
                                        pattern="[0-9]*"
                                        autocomplete="off"
                                        value="{{ $linePieceQuantity }}"
                                        wire:change="setLineOrderQuantity({{ $index }}, 'PIECE', $event.target.value)"
                                        class="w-[58px] rounded-md border-2 border-amber-300 bg-white px-1 py-0.5 text-right text-sm font-semibold text-slate-900 shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-200 dark:border-amber-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-amber-500 dark:focus:ring-amber-900"
                                    >
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-right font-mono font-bold text-indigo-700 dark:text-indigo-300">{{ $totalCasesLabel }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-right font-mono font-bold text-sky-700 dark:text-sky-300">{{ number_format($totalPieces) }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-right font-mono">¥{{ number_format($purchaseUnitPrice, 0) }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-right font-mono font-bold text-emerald-700 dark:text-emerald-300">¥{{ number_format($lineTotalAmount, 0) }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-center">
                                    <div class="font-mono text-sm font-bold text-slate-800 dark:text-gray-100">{{ $warehouseCode }}</div>
                                    <div class="text-[11px] text-slate-500 dark:text-gray-400">{{ $warehouseName }}</div>
                                </td>
                                <td class="sticky right-0 bg-inherit px-3 py-2 text-center">
                                    <button
                                        type="button"
                                        wire:click="openLineDuplicateModal({{ $index }})"
                                        class="mr-1 inline-flex items-center rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"
                                    >
                                        複製
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="removeLine({{ $index }})"
                                        class="inline-flex items-center rounded-md border border-danger-200 bg-white px-2 py-1 text-xs font-semibold text-danger-700 hover:bg-danger-50 dark:border-danger-700 dark:bg-gray-900 dark:text-danger-300"
                                    >
                                        <x-heroicon-o-trash class="h-4 w-4" />
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="17" class="px-3 py-12 text-center text-sm text-slate-400 dark:text-gray-500">登録リストは空です</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if ($showCandidateSearchModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-3">
            <div class="flex h-[96vh] max-h-[96vh] flex-col overflow-hidden rounded-lg bg-white shadow-xl dark:bg-gray-900" style="width: min(1680px, 98vw);">
                <div class="flex items-center justify-between bg-slate-800 px-4 py-3 text-white">
                    <div class="flex items-center gap-2 text-sm font-semibold">
                        <x-heroicon-o-magnifying-glass class="h-5 w-5" />
                        発注候補検索
                    </div>
                    <button type="button" wire:click="closeCandidateSearchModal" class="rounded p-1 text-white hover:bg-white/10">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                <div class="min-h-0 flex-1 overflow-hidden p-4">
                    @include('filament.components.order-registration-candidate-create-items', ['lw' => $this])
                </div>
            </div>
        </div>
    @endif

    @if ($showContractorChangeModal)
        @php
            $targetLine = $contractorChangeLineIndex !== null && isset($lines[$contractorChangeLineIndex])
                ? $lines[$contractorChangeLineIndex]
                : null;
        @endphp
        <div class="fixed inset-0 flex items-center justify-center bg-slate-950/50 p-4" style="z-index: 10000;">
            <div class="flex h-[92vh] max-h-[92vh] flex-col overflow-hidden rounded-lg bg-white shadow-xl dark:bg-gray-900" style="width: min(1120px, 96vw);">
                <div class="flex items-center justify-between bg-slate-800 px-4 py-3 text-white">
                    <div class="flex items-center gap-2 text-sm font-semibold">
                        <x-heroicon-o-building-storefront class="h-5 w-5" />
                        発注先変更
                    </div>
                    <button type="button" wire:click="closeContractorChangeModal" class="rounded p-1 text-white hover:bg-white/10">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                <div class="flex min-h-0 flex-1 flex-col gap-3 overflow-hidden p-4">
                    @if ($targetLine)
                        <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                            <div class="flex flex-wrap gap-x-6 gap-y-1">
                                <div>
                                    <span class="text-xs font-semibold text-slate-500 dark:text-gray-400">商品</span>
                                    <span class="ml-1 font-semibold text-slate-900 dark:text-white">[{{ $targetLine['item_code'] ?? '-' }}] {{ $targetLine['item_name'] ?? '-' }}</span>
                                </div>
                                <div>
                                    <span class="text-xs font-semibold text-slate-500 dark:text-gray-400">現在</span>
                                    <span class="ml-1 font-semibold text-slate-900 dark:text-white">{{ $targetLine['contractor_name'] ?? '-' }}</span>
                                </div>
                            </div>
                        </div>
                    @endif

                    <form wire:submit.prevent="searchContractorChangeCandidates" class="rounded-md border border-slate-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                        <div class="flex flex-wrap items-end gap-2">
                            <label class="min-w-80 flex-1">
                                <span class="mb-1 block text-xs font-semibold text-slate-600 dark:text-gray-300">発注先検索</span>
                                <input
                                    type="text"
                                    wire:model.defer="contractorChangeSearch"
                                    placeholder="発注先CD・名前で検索"
                                    class="w-full rounded-md border-slate-300 text-sm dark:border-gray-600 dark:bg-gray-900"
                                >
                            </label>
                            <button type="submit" class="inline-flex items-center gap-1 rounded-md bg-slate-800 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-700">
                                <x-heroicon-o-magnifying-glass class="h-4 w-4" />
                                検索
                            </button>
                        </div>
                    </form>

                    <div class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-md border border-slate-200 dark:border-gray-700">
                        <div class="min-h-0 flex-1 overflow-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-gray-700">
                                <thead class="sticky top-0 z-10 bg-slate-50 text-xs text-slate-600 dark:bg-gray-800 dark:text-gray-300">
                                    <tr>
                                        <th class="whitespace-nowrap px-3 py-2 text-left">発注先CD</th>
                                        <th class="min-w-72 px-3 py-2 text-left">発注先名</th>
                                        <th class="whitespace-nowrap px-3 py-2 text-left">仕入先</th>
                                        <th class="whitespace-nowrap px-3 py-2 text-center">判定</th>
                                        <th class="whitespace-nowrap px-3 py-2 text-center">操作</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-gray-800">
                                    @forelse ($contractorChangeRows as $row)
                                        <tr class="odd:bg-[#f5f9ff] even:bg-white dark:odd:bg-[#1e2a3b] dark:even:bg-gray-900">
                                            <td class="whitespace-nowrap px-3 py-2 font-mono text-xs">{{ $row['contractor_code'] ?: $row['contractor_id'] }}</td>
                                            <td class="px-3 py-2 font-semibold text-slate-800 dark:text-gray-100">{{ $row['contractor_name'] }}</td>
                                            <td class="whitespace-nowrap px-3 py-2">
                                                <span class="font-mono text-xs">{{ $row['supplier_code'] ?: $row['supplier_id'] }}</span>
                                                <span class="ml-1">{{ $row['supplier_name'] }}</span>
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-2 text-center">
                                                @if (! ($row['is_selectable'] ?? false))
                                                    <span class="rounded bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-500 dark:bg-gray-800 dark:text-gray-400">選択不可</span>
                                                @elseif ($row['is_eos_available'] ?? false)
                                                    <span class="rounded bg-blue-100 px-2 py-1 text-xs font-semibold text-blue-700 dark:bg-blue-900/40 dark:text-blue-200">EOS可</span>
                                                @elseif ($row['will_force_fax'] ?? false)
                                                    <span class="rounded bg-danger-100 px-2 py-1 text-xs font-semibold text-danger-700 dark:bg-danger-900/40 dark:text-danger-200">商品未設定 / FAX固定</span>
                                                @else
                                                    <span class="rounded bg-green-100 px-2 py-1 text-xs font-semibold text-green-700 dark:bg-green-900/40 dark:text-green-200">FAX発注</span>
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-2 text-center">
                                                <button
                                                    type="button"
                                                    wire:click="applyContractorChange({{ (int) $row['contractor_id'] }}, {{ (int) ($row['supplier_id'] ?? 0) }}, {{ (int) ($row['item_contractor_warehouse_id'] ?? 0) }})"
                                                    @disabled(! ($row['is_selectable'] ?? false))
                                                    class="rounded-md bg-danger-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-danger-500 disabled:cursor-not-allowed disabled:bg-slate-300 dark:disabled:bg-gray-700"
                                                >
                                                    選択
                                                </button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="px-3 py-10 text-center text-sm text-slate-400 dark:text-gray-500">発注先が見つかりません</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
                    <button type="button" wire:click="closeContractorChangeModal" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">変更せず閉じる</button>
                </div>
            </div>
        </div>
    @endif

    @if ($showLineDuplicateModal)
        @php
            $duplicateSourceLine = $duplicateLineIndex !== null && isset($lines[$duplicateLineIndex])
                ? $lines[$duplicateLineIndex]
                : null;
            $duplicateCurrentWarehouseId = (int) ($duplicateSourceLine['warehouse_id'] ?? 0);
            $duplicateWarehouseOptions = $this->duplicateWarehouseOptions($duplicateCurrentWarehouseId);
        @endphp
        <div class="fixed inset-0 flex items-center justify-center bg-slate-950/50 p-4" style="z-index: 10000;">
            <div class="flex max-h-[88vh] w-full max-w-3xl flex-col overflow-hidden rounded-lg bg-white shadow-xl dark:bg-gray-900">
                <div class="flex items-center justify-between bg-slate-800 px-4 py-3 text-white">
                    <div class="flex items-center gap-2 text-sm font-semibold">
                        <x-heroicon-o-squares-plus class="h-5 w-5" />
                        発注データ複製
                    </div>
                    <button type="button" wire:click="closeLineDuplicateModal" class="rounded p-1 text-white hover:bg-white/10">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                <div class="flex min-h-0 flex-1 flex-col gap-3 overflow-hidden p-4">
                    @if ($duplicateSourceLine)
                        <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                            <div class="font-semibold text-slate-900 dark:text-white">[{{ $duplicateSourceLine['item_code'] ?? '-' }}] {{ $duplicateSourceLine['item_name'] ?? '-' }}</div>
                            <div class="mt-1 text-xs text-slate-500 dark:text-gray-400">選択した納入先へ同じ発注数で複製します。</div>
                        </div>
                    @endif

                    <div class="min-h-0 flex-1 overflow-auto rounded-md border border-slate-200 dark:border-gray-700">
                        <div class="grid grid-cols-1 divide-y divide-slate-100 text-sm dark:divide-gray-800 md:grid-cols-2 md:divide-x md:divide-y-0">
                            @forelse ($duplicateWarehouseOptions as $warehouse)
                                @php
                                    $warehouseId = (int) ($warehouse['id'] ?? 0);
                                    $warehouseCode = str_pad((string) ($warehouse['code'] ?? $warehouseId), 2, '0', STR_PAD_LEFT);
                                @endphp
                                <label class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-slate-50 dark:hover:bg-gray-800">
                                    <input
                                        type="checkbox"
                                        wire:model.live="duplicateWarehouseIds"
                                        value="{{ $warehouseId }}"
                                        class="rounded border-slate-300 text-danger-600 focus:ring-danger-500 dark:border-gray-600"
                                    >
                                    <span>
                                        <span class="font-mono font-bold text-slate-800 dark:text-gray-100">{{ $warehouseCode }}</span>
                                        <span class="ml-2 font-semibold text-slate-800 dark:text-gray-100">{{ $warehouse['name'] ?? '-' }}</span>
                                    </span>
                                </label>
                            @empty
                                <div class="px-3 py-8 text-center text-sm text-slate-400 dark:text-gray-500 md:col-span-2">複製できる納入先がありません</div>
                            @endforelse
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
                    <button type="button" wire:click="closeLineDuplicateModal" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">複製せず閉じる</button>
                    <button type="button" wire:click="duplicateLineToWarehouses" class="rounded-md bg-danger-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-danger-500">複製する</button>
                </div>
            </div>
        </div>
    @endif

    @if ($showSalesHistoryModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
            <div class="flex max-h-[92vh] flex-col overflow-hidden rounded-lg bg-white shadow-xl dark:bg-gray-900" style="width: min(1280px, 96vw);">
                <div class="flex items-center justify-between bg-slate-800 px-4 py-3 text-white">
                    <div class="flex items-center gap-2 text-sm font-semibold">
                        <x-heroicon-o-chart-bar class="h-5 w-5" />
                        外部発注候補生成（{{ $this->selectedWarehouseLabel() }}）
                    </div>
                    <button type="button" wire:click="closeSalesHistoryModal" class="rounded p-1 text-white hover:bg-white/10">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                <div class="overflow-auto p-4">
                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3 external-order-generation-layout">
                        <div class="space-y-3 lg:col-span-1">
                            <div class="grid grid-cols-2 gap-2">
                                <label class="block">
                                    <span class="mb-1 block text-xs font-semibold text-slate-600 dark:text-gray-300">販売実績 開始日</span>
                                    <input type="date" wire:model.live="salesStartDate" class="w-full rounded-md border-slate-300 text-sm dark:border-gray-600 dark:bg-gray-900">
                                </label>
                                <label class="block">
                                    <span class="mb-1 block text-xs font-semibold text-slate-600 dark:text-gray-300">販売実績 終了日</span>
                                    <input type="date" wire:model.live="salesEndDate" class="w-full rounded-md border-slate-300 text-sm dark:border-gray-600 dark:bg-gray-900">
                                </label>
                            </div>

                            @include('filament.components.order-registration-category-selection', [
                                'lw' => $this,
                                'categoriesProperty' => 'externalOrderCategory2Data',
                                'fallbackMethod' => 'getExternalOrderCategory2ForSalesBasedGeneration',
                                'selectedProperty' => 'selectedExternalOrderCategory2Ids',
                                'label' => '中分類',
                                'compactListHeight' => true,
                                'layout' => 'externalOrderGeneration',
                            ])
                        </div>

                        <div class="lg:col-span-2">
                            @include('filament.components.order-registration-contractor-selection', [
                                'lw' => $this,
                                'grouped' => true,
                                'primaryContractorsProperty' => 'externalOrderJxContractorsData',
                                'primaryFallbackMethod' => 'getExternalOrderJxContractorsForSalesBasedGeneration',
                                'secondaryContractorsProperty' => 'externalOrderOtherContractorsData',
                                'secondaryFallbackMethod' => 'getExternalOrderOtherContractorsForSalesBasedGeneration',
                                'selectedProperty' => 'selectedExternalOrderContractorIds',
                                'primaryLabel' => 'EOS発注先',
                                'secondaryLabel' => 'FAX発注先',
                                'compactListHeight' => true,
                                'layout' => 'externalOrderGeneration',
                            ])
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
                    <button type="button" wire:click="closeSalesHistoryModal" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">表示せず閉じる</button>
                    <button
                        type="button"
                        wire:click="calculateSalesBasedExternalOrderPreview"
                        wire:loading.attr="disabled"
                        wire:target="calculateSalesBasedExternalOrderPreview"
                        class="inline-flex items-center gap-2 rounded-md bg-danger-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-danger-500 disabled:cursor-not-allowed disabled:opacity-70"
                    >
                        <span wire:loading wire:target="calculateSalesBasedExternalOrderPreview" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                        <span wire:loading.remove wire:target="calculateSalesBasedExternalOrderPreview">候補表示</span>
                        <span wire:loading wire:target="calculateSalesBasedExternalOrderPreview">表示中</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if ($showSalesBasedExternalOrderPreviewModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-3">
            <div class="flex max-h-[96vh] flex-col overflow-hidden rounded-lg bg-white shadow-xl dark:bg-gray-900" style="width: min(1680px, 98vw);">
                <div class="flex items-center justify-between bg-slate-800 px-4 py-3 text-white">
                    <div class="flex items-center gap-2 text-sm font-semibold">
                        <x-heroicon-o-chart-bar class="h-5 w-5" />
                        外部発注候補リスト
                    </div>
                    <button
                        type="button"
                        x-data
                        x-on:click="if (confirm('外部発注候補リストを閉じますか？入力中の内容は破棄されます。')) { $wire.closeSalesBasedExternalOrderPreviewModal() }"
                        class="rounded p-1 text-white hover:bg-white/10"
                    >
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                <div wire:ignore class="overflow-auto p-4">
                    @include('filament.components.order-registration-sales-preview-edit', ['lw' => $this])
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
                    <button
                        type="button"
                        x-data
                        x-on:click="if (confirm('外部発注候補リストを閉じますか？入力中の内容は破棄されます。')) { $wire.closeSalesBasedExternalOrderPreviewModal() }"
                        class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"
                    >
                        閉じる
                    </button>
                    <button type="button" wire:click="addSalesBasedExternalOrderPreviewRowsToRegistration" class="rounded-md bg-danger-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-danger-500">
                        登録リストに追加
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if ($showWarehouseStockModal)
        @php
            $warehouseStockPairs = collect($warehouseStockRows)->chunk(2);
        @endphp
        <div class="fixed inset-0 flex items-center justify-center bg-slate-950/50 p-4" style="z-index: 10020;">
            <div class="flex max-h-[82vh] w-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white shadow-xl dark:bg-gray-900">
                <div class="flex items-center justify-between bg-slate-800 px-4 py-3 text-white">
                    <div class="flex items-center gap-2 text-sm font-semibold">
                        <x-heroicon-o-building-storefront class="h-5 w-5" />
                        他倉庫在庫
                    </div>
                    <button type="button" wire:click="closeWarehouseStockModal" class="rounded p-1 text-white hover:bg-white/10">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                <div class="flex min-h-0 flex-1 flex-col gap-3 overflow-hidden p-4">
                    <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                        <div class="flex flex-wrap gap-x-6 gap-y-1">
                            <div>
                                <span class="text-xs font-semibold text-slate-500 dark:text-gray-400">商品</span>
                                <span class="ml-1 font-semibold text-slate-900 dark:text-white">[{{ $warehouseStockItemCode ?: '-' }}] {{ $warehouseStockItemName ?: '-' }}</span>
                            </div>
                            <div>
                                <span class="text-xs font-semibold text-slate-500 dark:text-gray-400">現在倉庫</span>
                                <span class="ml-1 font-semibold text-slate-900 dark:text-white">{{ $this->selectedWarehouseLabel() }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="min-h-0 flex-1 overflow-auto rounded-md border border-slate-200 dark:border-gray-700">
                        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-gray-700">
                            <thead class="sticky top-0 z-10 bg-slate-50 text-xs text-slate-600 dark:bg-gray-800 dark:text-gray-300">
                                <tr>
                                    <th class="whitespace-nowrap px-3 py-2 text-left">倉庫CD</th>
                                    <th class="min-w-56 px-3 py-2 text-left">倉庫名</th>
                                    <th class="whitespace-nowrap px-3 py-2 text-right">理論在庫</th>
                                    <th class="whitespace-nowrap border-l border-slate-200 px-3 py-2 text-left dark:border-gray-700">倉庫CD</th>
                                    <th class="min-w-56 px-3 py-2 text-left">倉庫名</th>
                                    <th class="whitespace-nowrap px-3 py-2 text-right">理論在庫</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-gray-800">
                                @forelse ($warehouseStockPairs as $pair)
                                    @php
                                        $stockPairRows = $pair->values();
                                        $leftStockRow = $stockPairRows->get(0);
                                        $rightStockRow = $stockPairRows->get(1);
                                    @endphp
                                    <tr class="odd:bg-[#f5f9ff] even:bg-white dark:odd:bg-[#1e2a3b] dark:even:bg-gray-900">
                                        <td class="whitespace-nowrap px-3 py-2 font-mono text-xs">{{ $leftStockRow['warehouse_code'] ?: $leftStockRow['warehouse_id'] }}</td>
                                        <td class="px-3 py-2 font-semibold text-slate-800 dark:text-gray-100">{{ $leftStockRow['warehouse_name'] }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right font-mono font-bold text-slate-900 dark:text-white">{{ number_format((int) ($leftStockRow['theoretical_stock'] ?? 0)) }}</td>
                                        @if ($rightStockRow)
                                            <td class="whitespace-nowrap border-l border-slate-200 px-3 py-2 font-mono text-xs dark:border-gray-700">{{ $rightStockRow['warehouse_code'] ?: $rightStockRow['warehouse_id'] }}</td>
                                            <td class="px-3 py-2 font-semibold text-slate-800 dark:text-gray-100">{{ $rightStockRow['warehouse_name'] }}</td>
                                            <td class="whitespace-nowrap px-3 py-2 text-right font-mono font-bold text-slate-900 dark:text-white">{{ number_format((int) ($rightStockRow['theoretical_stock'] ?? 0)) }}</td>
                                        @else
                                            <td colspan="3" class="border-l border-slate-200 px-3 py-2 dark:border-gray-700"></td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-3 py-10 text-center text-sm text-slate-400 dark:text-gray-500">他倉庫の在庫はありません</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
                    <button type="button" wire:click="closeWarehouseStockModal" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">閉じる</button>
                </div>
            </div>
        </div>
    @endif
    @endif
</x-filament-panels::page>
