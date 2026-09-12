<div class="overflow-x-auto -my-2">
    <table class="w-full border-collapse border border-gray-300 text-sm dark:border-gray-600">
        <thead>
            <tr class="bg-gray-50 dark:bg-gray-800">
                <th class="border border-gray-300 px-2 py-1 text-center font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">行</th>
                <th class="border border-gray-300 px-2 py-1 text-center font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">商品CD</th>
                <th class="border border-gray-300 px-2 py-1 text-left font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">商品名</th>
                <th class="border border-gray-300 px-2 py-1 text-center font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">JAN</th>
                <th class="border border-gray-300 px-2 py-1 text-right font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">入数</th>
                <th class="border border-gray-300 px-2 py-1 text-right font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">ケース数</th>
                <th class="border border-gray-300 px-2 py-1 text-right font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">バラ数</th>
                <th class="border border-gray-300 px-2 py-1 text-right font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">総バラ</th>
                <th class="border border-gray-300 px-2 py-1 text-center font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">照合</th>
                <th class="border border-gray-300 px-2 py-1 text-center font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">操作</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($details as $detail)
                <tr class="bg-white dark:bg-gray-900">
                    <td class="border border-gray-300 px-2 py-1 text-center text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $detail['line'] }}</td>
                    <td class="border border-gray-300 px-2 py-1 text-center text-gray-700 dark:border-gray-600 dark:text-gray-300">
                        @if ($detail['can_adjust_item'])
                            <button
                                type="button"
                                wire:click="replaceMountedAction('resolveMissingItem', { detail_id: {{ (int) $detail['id'] }} }, { table: true, recordKey: '{{ $recordKey }}' })"
                                class="inline-flex items-center justify-center rounded border border-amber-300 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-700 hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200"
                            >
                                商品検索
                            </button>
                        @else
                            {{ $detail['matched_item_code'] }}
                        @endif
                    </td>
                    <td class="min-w-[260px] border border-gray-300 px-2 py-1 text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $detail['product_name'] }}</td>
                    <td class="border border-gray-300 px-2 py-1 text-center text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $detail['jan_code'] }}</td>
                    <td class="border border-gray-300 px-2 py-1 text-right text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $detail['pack_quantity'] }}</td>
                    <td class="border border-gray-300 px-2 py-1 text-right text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $detail['case_quantity'] }}</td>
                    <td class="border border-gray-300 px-2 py-1 text-right text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $detail['piece_quantity'] }}</td>
                    <td class="border border-gray-300 px-2 py-1 text-right font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">{{ $detail['total_quantity'] }}</td>
                    <td class="border border-gray-300 px-2 py-1 text-center text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $detail['match_status'] }} {{ $detail['is_shortage'] }}</td>
                    <td class="border border-gray-300 px-2 py-1 text-center text-gray-700 dark:border-gray-600 dark:text-gray-300">
                        @if ($detail['can_adjust_item'])
                            <button
                                type="button"
                                wire:click="replaceMountedAction('ignoreMissingItem', { detail_id: {{ (int) $detail['id'] }} }, { table: true, recordKey: '{{ $recordKey }}' })"
                                class="inline-flex items-center justify-center rounded border border-red-300 bg-red-50 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-100 dark:border-red-700 dark:bg-red-950 dark:text-red-200"
                            >
                                削除
                            </button>
                        @else
                            -
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="border border-gray-300 px-2 py-4 text-center text-gray-500 dark:border-gray-600 dark:text-gray-400">
                        明細データなし
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
