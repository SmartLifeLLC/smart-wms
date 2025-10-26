<div class="bg-white dark:bg-gray-800 rounded-lg shadow h-full flex flex-col">
    {{-- Header --}}
    <div class="p-4 border-b border-gray-200 dark:border-gray-700">
        <div class="flex justify-between items-center">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                検索結果 ({{ $totalCount }} 件)
            </h3>
        </div>
    </div>

    {{-- Results List with Infinite Scroll --}}
    <div class="flex-1 overflow-y-auto" wire:scroll="loadMore">
        <table class="w-full text-sm">
            <thead class="sticky top-0 bg-gray-50 dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-800" wire:click="sort('item_code')">
                        商品コード
                        @if($sortBy === 'item_code')
                            <span>{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>
                        @endif
                    </th>
                    <th class="px-4 py-3 text-left cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-800" wire:click="sort('item_name')">
                        商品名
                        @if($sortBy === 'item_name')
                            <span>{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>
                        @endif
                    </th>
                    <th class="px-4 py-3 text-right cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-800" wire:click="sort('available_qty')">
                        在庫数
                        @if($sortBy === 'available_qty')
                            <span>{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>
                        @endif
                    </th>
                    <th class="px-4 py-3 text-left">倉庫</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse($results as $result)
                    <tr
                        class="hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer {{ $selectedItemId === $result->item_id ? 'bg-blue-50 dark:bg-blue-900' : '' }}"
                        wire:click="selectItem({{ $result->item_id }})"
                    >
                        <td class="px-4 py-3">{{ $result->item->code ?? 'N/A' }}</td>
                        <td class="px-4 py-3">{{ $result->item->name ?? 'N/A' }}</td>
                        <td class="px-4 py-3 text-right">{{ number_format($result->available_for_wms ?? 0) }}</td>
                        <td class="px-4 py-3">{{ $result->warehouse->name ?? 'N/A' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                            検索結果がありません
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if($hasMorePages)
            <div class="p-4 text-center">
                <button
                    type="button"
                    wire:click="loadMore"
                    class="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                >
                    さらに読み込む...
                </button>
            </div>
        @endif
    </div>
</div>
