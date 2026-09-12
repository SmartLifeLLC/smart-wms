@php
    $rows = collect($items ?? []);
@endphp

<div class="overflow-hidden rounded-md border border-slate-200 bg-white text-xs dark:border-slate-700 dark:bg-slate-950">
    <div class="border-b border-slate-200 bg-slate-50 px-2 py-1 text-[11px] font-bold leading-4 text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
        商品明細リスト
    </div>

    @if ($rows->isEmpty())
        <div class="px-2 py-2 text-center text-xs text-slate-500 dark:text-slate-400">
            商品明細はありません。
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-[720px] w-full border-collapse text-left text-xs">
                <thead>
                    <tr class="bg-slate-700 text-[11px] font-bold leading-4 text-white dark:bg-slate-800">
                        <th class="w-24 px-2 py-1.5">商品コード</th>
                        <th class="min-w-72 px-2 py-1.5">商品名</th>
                        <th class="w-20 px-2 py-1.5 text-right">受注ケース</th>
                        <th class="w-20 px-2 py-1.5 text-right">出荷ケース</th>
                        <th class="w-20 px-2 py-1.5 text-right">受注バラ</th>
                        <th class="w-20 px-2 py-1.5 text-right">出荷バラ数</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @foreach ($rows as $item)
                        <tr class="odd:bg-white even:bg-slate-50 dark:odd:bg-slate-950 dark:even:bg-slate-900">
                            <td class="px-2 py-1.5 font-mono font-semibold leading-5 text-blue-700 dark:text-blue-300">
                                {{ filled($item['item_code'] ?? null) ? $item['item_code'] : '-' }}
                            </td>
                            <td class="px-2 py-1.5 font-medium leading-5 text-slate-900 dark:text-slate-100">
                                {{ filled($item['item_name'] ?? null) ? $item['item_name'] : '-' }}
                            </td>
                            <td class="px-2 py-1.5 text-right font-mono leading-5 text-slate-800 dark:text-slate-100">
                                {{ number_format((int) ($item['ordered_case_qty'] ?? 0)) }}
                            </td>
                            <td class="px-2 py-1.5 text-right font-mono leading-5 text-slate-800 dark:text-slate-100">
                                {{ number_format((int) ($item['shipment_case_qty'] ?? 0)) }}
                            </td>
                            <td class="px-2 py-1.5 text-right font-mono leading-5 text-slate-800 dark:text-slate-100">
                                {{ number_format((int) ($item['ordered_piece_qty'] ?? 0)) }}
                            </td>
                            <td class="px-2 py-1.5 text-right font-mono font-bold leading-5 text-blue-700 dark:text-blue-300">
                                {{ number_format((int) ($item['shipment_piece_qty'] ?? 0)) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
