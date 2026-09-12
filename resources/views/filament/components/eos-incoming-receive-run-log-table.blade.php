<div class="space-y-4 text-sm">
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        <div>
            <div class="text-xs text-gray-500">実行ID</div>
            <div class="font-medium">{{ $run->id }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500">状態</div>
            <div class="font-medium">{{ $run->statusLabel() }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500">開始</div>
            <div class="font-medium">{{ $run->started_at?->format('Y-m-d H:i:s') ?? '-' }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500">終了</div>
            <div class="font-medium">{{ $run->finished_at?->format('Y-m-d H:i:s') ?? '-' }}</div>
        </div>
    </div>

    <div class="overflow-x-auto rounded border border-gray-200 dark:border-gray-700">
        <table class="min-w-full divide-y divide-gray-200 text-xs dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="whitespace-nowrap px-3 py-2 text-left font-medium">時刻</th>
                    <th class="whitespace-nowrap px-3 py-2 text-left font-medium">レベル</th>
                    <th class="whitespace-nowrap px-3 py-2 text-left font-medium">ステップ</th>
                    <th class="min-w-[24rem] px-3 py-2 text-left font-medium">メッセージ</th>
                    <th class="whitespace-nowrap px-3 py-2 text-right font-medium">JXログ</th>
                    <th class="whitespace-nowrap px-3 py-2 text-right font-medium">入荷予定</th>
                    <th class="min-w-[18rem] px-3 py-2 text-left font-medium">詳細</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
                @forelse ($logs as $log)
                    <tr>
                        <td class="whitespace-nowrap px-3 py-2">{{ $log->created_at?->format('H:i:s') ?? '-' }}</td>
                        <td class="whitespace-nowrap px-3 py-2">
                            <span @class([
                                'rounded px-2 py-0.5 text-xs font-medium',
                                'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200' => $log->level === 'error',
                                'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-200' => $log->level === 'warning',
                                'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200' => ! in_array($log->level, ['error', 'warning'], true),
                            ])>{{ $log->level }}</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2">{{ $log->step }}</td>
                        <td class="px-3 py-2">{{ $log->message }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">{{ $log->jx_transmission_log_id ?? '-' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">{{ $log->incoming_schedule_id ?? '-' }}</td>
                        <td class="px-3 py-2">
                            @if ($log->context)
                                <pre class="max-h-32 overflow-auto whitespace-pre-wrap rounded bg-gray-50 p-2 text-[11px] dark:bg-gray-800">{{ json_encode($log->context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                            @else
                                -
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-3 py-6 text-center text-gray-500">ログはありません。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
