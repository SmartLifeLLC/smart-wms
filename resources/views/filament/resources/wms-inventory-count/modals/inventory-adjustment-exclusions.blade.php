@php
    $prefixes = implode(', ', $summary['prefixes'] ?? []);
    $detailCount = (int) ($summary['detail_count'] ?? 0);
    $itemCount = (int) ($summary['item_count'] ?? 0);
    $items = $summary['items'] ?? [];
@endphp

<div class="space-y-3 text-sm">
    <div class="rounded-md border border-amber-300 bg-amber-50 p-3 text-amber-900">
        <div class="font-bold">{{ $itemCount > 0 ? '実棚反映除外商品があります' : '実棚反映除外ルール' }}</div>
        <div class="mt-1 text-xs leading-5">
            商品CDの先頭が {{ $prefixes }} の商品は実棚反映しません。
            対象は明細 {{ number_format($detailCount) }} 件 / 商品 {{ number_format($itemCount) }} 件です。
            表示された商品は固定ルールにより除外選択済みです。
        </div>
    </div>

    @if ($itemCount > 0)
        <div class="max-h-64 overflow-auto rounded-md border border-slate-200">
            <table class="min-w-full border-collapse text-xs">
                <thead class="sticky top-0 bg-slate-100 text-slate-700">
                    <tr>
                        <th class="border-b border-slate-200 px-2 py-1.5 text-left">除外</th>
                        <th class="border-b border-slate-200 px-2 py-1.5 text-left">商品CD</th>
                        <th class="border-b border-slate-200 px-2 py-1.5 text-left">商品名</th>
                        <th class="border-b border-slate-200 px-2 py-1.5 text-right">明細</th>
                        <th class="border-b border-slate-200 px-2 py-1.5 text-right">差異数量</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr class="odd:bg-white even:bg-slate-50">
                            <td class="border-b border-slate-100 px-2 py-1.5">
                                <input type="checkbox" checked disabled class="rounded border-slate-300">
                            </td>
                            <td class="whitespace-nowrap border-b border-slate-100 px-2 py-1.5 font-mono font-semibold">
                                {{ $item['item_code'] }}
                            </td>
                            <td class="border-b border-slate-100 px-2 py-1.5">
                                {{ $item['item_name'] }}
                            </td>
                            <td class="whitespace-nowrap border-b border-slate-100 px-2 py-1.5 text-right tabular-nums">
                                {{ number_format($item['detail_count']) }}
                            </td>
                            <td class="whitespace-nowrap border-b border-slate-100 px-2 py-1.5 text-right tabular-nums">
                                {{ number_format($item['difference_quantity']) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($summary['has_more'] ?? false)
            <div class="text-xs text-slate-500">表示は先頭 {{ number_format(count($items)) }} 商品までです。</div>
        @endif
    @else
        <div class="rounded-md border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
            今回の差異明細に実棚反映除外商品はありません。
        </div>
    @endif
</div>
