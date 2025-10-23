<div class="bg-white dark:bg-gray-800 rounded-lg shadow h-full flex flex-col">
    @if($item)
        {{-- Header --}}
        <div class="p-4 border-b border-gray-200 dark:border-gray-700">
            <div class="flex justify-between items-start">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                        {{ $item->name }}
                    </h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        商品コード: {{ $item->code }}
                    </p>
                </div>
                <button
                    type="button"
                    wire:click="clear"
                    class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>

        {{-- Content --}}
        <div class="flex-1 overflow-y-auto p-4 space-y-6">
            {{-- Basic Info --}}
            <div>
                <h4 class="font-semibold text-gray-900 dark:text-white mb-3">基本情報</h4>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <dt class="text-gray-500 dark:text-gray-400">規格</dt>
                    <dd class="text-gray-900 dark:text-white">{{ $item->standard ?? '-' }}</dd>

                    <dt class="text-gray-500 dark:text-gray-400">JANコード</dt>
                    <dd class="text-gray-900 dark:text-white">{{ $item->jancode ?? '-' }}</dd>

                    <dt class="text-gray-500 dark:text-gray-400">入数</dt>
                    <dd class="text-gray-900 dark:text-white">{{ $item->case_quantity ?? '-' }}</dd>

                    <dt class="text-gray-500 dark:text-gray-400">倉庫</dt>
                    <dd class="text-gray-900 dark:text-white">{{ $item->warehouse?->name ?? '-' }}</dd>
                </dl>
            </div>

            {{-- Stock Summary --}}
            <div>
                <h4 class="font-semibold text-gray-900 dark:text-white mb-3">在庫サマリー</h4>
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3">
                        <div class="text-xs text-gray-500 dark:text-gray-400">現在庫</div>
                        <div class="text-2xl font-bold text-gray-900 dark:text-white">
                            {{ number_format($stockInfo['total_qty'] ?? 0) }}
                        </div>
                    </div>
                    <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3">
                        <div class="text-xs text-gray-500 dark:text-gray-400">引当済</div>
                        <div class="text-2xl font-bold text-orange-600 dark:text-orange-400">
                            {{ number_format($stockInfo['reserved_qty'] ?? 0) }}
                        </div>
                    </div>
                    <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3">
                        <div class="text-xs text-gray-500 dark:text-gray-400">ピッキング中</div>
                        <div class="text-2xl font-bold text-yellow-600 dark:text-yellow-400">
                            {{ number_format($stockInfo['picking_qty'] ?? 0) }}
                        </div>
                    </div>
                    <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3">
                        <div class="text-xs text-gray-500 dark:text-gray-400">引当可能</div>
                        <div class="text-2xl font-bold text-green-600 dark:text-green-400">
                            {{ number_format($stockInfo['available_qty'] ?? 0) }}
                        </div>
                    </div>
                </div>
            </div>

            {{-- Location Details --}}
            @if(!empty($stockInfo['locations']))
                <div>
                    <h4 class="font-semibold text-gray-900 dark:text-white mb-3">ロケーション詳細</h4>
                    <div class="space-y-2">
                        @foreach($stockInfo['locations'] as $location)
                            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3 text-sm">
                                <div class="flex justify-between items-start mb-2">
                                    <div>
                                        <div class="font-medium text-gray-900 dark:text-white">
                                            {{ $location['warehouse_name'] }} - {{ $location['location_name'] }}
                                        </div>
                                        @if($location['lot_no'])
                                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                                ロット: {{ $location['lot_no'] }}
                                            </div>
                                        @endif
                                        @if($location['expiration_date'])
                                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                                賞味期限: {{ $location['expiration_date'] }}
                                            </div>
                                        @endif
                                    </div>
                                    <div class="text-right">
                                        <div class="font-bold text-gray-900 dark:text-white">
                                            {{ number_format($location['current_qty']) }}
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">
                                            引当可能: {{ number_format($location['available_qty']) }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @else
        {{-- Empty State --}}
        <div class="flex-1 flex items-center justify-center p-8">
            <div class="text-center text-gray-500 dark:text-gray-400">
                <svg class="w-16 h-16 mx-auto mb-4 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                </svg>
                <p class="text-sm">商品を選択してください</p>
            </div>
        </div>
    @endif
</div>
