@php
    /** @var \App\Models\WmsWarehouseTransferCandidate $record */
    $shortageByItem = collect($validation['shortages'])->keyBy('item_id');
@endphp

<div class="space-y-4 text-sm">
    @if ($validation['errors'] !== [])
        <div class="rounded-md border border-red-300 bg-red-50 p-3 text-red-800 dark:border-red-700 dark:bg-red-950 dark:text-red-200">
            <div class="font-semibold">確定できません</div>
            <ul class="mt-1 list-disc pl-5">
                @foreach ($validation['errors'] as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($validation['warnings'] !== [])
        <div class="rounded-md border border-orange-300 bg-orange-50 p-3 text-orange-800 dark:border-orange-700 dark:bg-orange-950 dark:text-orange-200">
            <div class="font-semibold">警告</div>
            <ul class="mt-1 list-disc pl-5">
                @foreach ($validation['warnings'] as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid grid-cols-2 gap-x-6 gap-y-2 md:grid-cols-4">
        <div>
            <div class="text-xs text-gray-500">移動元倉庫</div>
            <div class="font-medium">[{{ $record->from_warehouse_code }}] {{ $record->from_warehouse_name }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500">移動先倉庫</div>
            <div class="font-medium">[{{ $record->to_warehouse_code }}] {{ $record->to_warehouse_name }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500">配送コース</div>
            <div class="font-medium">{{ $deliveryCourse ? "[{$deliveryCourse->code}] {$deliveryCourse->name}" : '未解決' }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500">処理日 / 納品日</div>
            <div class="font-medium">{{ $record->process_date?->format('Y/m/d') }} / {{ $record->delivered_date?->format('Y/m/d') }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500">明細数</div>
            <div class="font-medium">{{ number_format($validation['item_count']) }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500">総バラ</div>
            <div class="font-medium">{{ number_format($validation['total_quantity']) }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500">在庫不足明細</div>
            <div class="font-medium {{ count($validation['shortages']) > 0 ? 'text-orange-600' : '' }}">{{ count($validation['shortages']) }} 件</div>
        </div>
    </div>

    <div class="overflow-x-auto rounded-md border border-gray-200 dark:border-gray-700">
        <table class="min-w-full text-xs">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-2 py-1 text-left">商品CD</th>
                    <th class="px-2 py-1 text-left">商品名</th>
                    <th class="px-2 py-1 text-left">ロケーション</th>
                    <th class="px-2 py-1 text-right">ケース</th>
                    <th class="px-2 py-1 text-right">バラ</th>
                    <th class="px-2 py-1 text-right">総バラ</th>
                    <th class="px-2 py-1 text-right">利用可能在庫</th>
                    <th class="px-2 py-1 text-left">在庫区分CD</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $item)
                    @php $shortage = $shortageByItem->get((int) $item->item_id); @endphp
                    <tr class="{{ $shortage ? 'bg-orange-50 dark:bg-orange-950' : ($loop->odd ? 'bg-white dark:bg-gray-900' : 'bg-gray-50 dark:bg-gray-800') }}">
                        <td class="px-2 py-1 whitespace-nowrap">{{ $item->item_code }}</td>
                        <td class="px-2 py-1">{{ $item->item_name }}</td>
                        <td class="px-2 py-1 whitespace-nowrap">{{ $item->location_no ?: '-' }}</td>
                        <td class="px-2 py-1 text-right">{{ number_format((float) $item->case_quantity) }}</td>
                        <td class="px-2 py-1 text-right">{{ number_format((float) $item->piece_quantity) }}</td>
                        <td class="px-2 py-1 text-right font-medium">{{ number_format((float) $item->transfer_quantity) }}</td>
                        <td class="px-2 py-1 text-right {{ $shortage ? 'font-semibold text-orange-700 dark:text-orange-300' : '' }}">
                            {{ $shortage ? number_format($shortage['available_quantity']) : '-' }}
                        </td>
                        <td class="px-2 py-1">{{ $item->stock_allocation_code }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="text-xs text-gray-500">
        確定すると基幹の stock_transfer_queue に倉庫移動伝票の作成依頼（CREATE）が登録され、以降は明細を編集できません。
    </p>
</div>
