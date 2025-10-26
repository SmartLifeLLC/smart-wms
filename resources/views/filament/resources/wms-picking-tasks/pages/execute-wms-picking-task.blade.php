<x-filament-panels::page>
    <div class="space-y-6">
        {{-- タスク情報ヘッダー --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">波動番号</div>
                    <div class="font-semibold">{{ $record->wave->wave_code ?? '-' }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">倉庫</div>
                    <div class="font-semibold">{{ $record->warehouse->name ?? '-' }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">ピッキングエリア</div>
                    <div class="font-semibold">{{ $record->pickingArea->name ?? '-' }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">担当者</div>
                    <div class="font-semibold">{{ $record->picker->display_name ?? '未割当' }}</div>
                </div>
            </div>
            <div class="mt-1 border-t-1 border-t-gray-300 grid grid-cols-3 gap-4 text-center">
                <div>
                    <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                        {{ collect($items)->where('status', 'PICKING')->count() }}
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">作業中</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-green-600 dark:text-green-400">
                        {{ collect($items)->where('status', 'COMPLETED')->count() }}
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">完了</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-yellow-600 dark:text-yellow-400">
                        {{ collect($items)->where('status', 'SHORTAGE')->count() }}
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">欠品</div>
                </div>
            </div>
        </div>

        {{-- ピッキング商品リスト --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden -mt-4">
            <div class="px-6 py-2 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-sm font-semibold">ピッキング商品一覧</h3>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 dark:bg-gray-900">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                伝票番号
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                ロケーション
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                商品名
                            </th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                単位
                            </th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                予定数
                            </th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                ピック数
                            </th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                欠品数
                            </th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                ステータス
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($items as $item)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                                {{ $record->trade->serial_id ?? 'N/A' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                {{ $item['location'] }}
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                {{ $item['item_name'] }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-900 dark:text-gray-100">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">
                                    {{ $item['planned_qty_type_display'] }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-900 dark:text-gray-100">
                                {{ $item['planned_qty'] }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                <input
                                    type="number"
                                    wire:model.blur="items.{{ $loop->index }}.picked_qty"
                                    wire:change="updateItem({{ $item['id'] }}, 'picked_qty', $event.target.value)"
                                    min="0"
                                    max="{{ $item['planned_qty'] }}"
                                    step="1"
                                    inputmode="numeric"
                                    pattern="[0-9]*"
                                    oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                                    class="w-20 text-center border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 border focus:border-primary-500 focus:ring-primary-500"
                                >
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                <input
                                    type="number"
                                    value="{{ $item['shortage_qty'] }}"
                                    min="0"
                                    step="1"
                                    readonly
                                    class="w-20 text-center border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 bg-gray-100 border cursor-not-allowed"
                                >
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                @if($item['status'] === 'COMPLETED')
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-800 dark:text-green-100">
                                        完了
                                    </span>
                                @elseif($item['status'] === 'SHORTAGE')
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-800 dark:text-yellow-100">
                                        欠品あり
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-800 dark:text-blue-100">
                                        作業中
                                    </span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>


    </div>

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            focusFirstInput();
        });

        if (typeof Livewire !== 'undefined') {
            Livewire.hook('commit', ({ component, commit, respond, succeed, fail }) => {
                succeed(({ snapshot, effect }) => {
                    setTimeout(() => {
                        const inputs = document.querySelectorAll('input[type="number"]:not([readonly])');
                        if (inputs.length > 0 && !document.activeElement.matches('input[type="number"]')) {
                            inputs[0].focus();
                        }
                    }, 100);
                });
            });
        }

        function focusFirstInput() {
            const firstInput = document.querySelector('input[type="number"]:not([readonly])');
            if (firstInput) {
                firstInput.focus();
            }
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Enter' && event.target.matches('input[type="number"]:not([readonly])')) {
                event.preventDefault();
                const inputs = Array.from(document.querySelectorAll('input[type="number"]:not([readonly])'));
                const currentIndex = inputs.indexOf(event.target);

                if (currentIndex > -1 && currentIndex < inputs.length - 1) {
                    inputs[currentIndex + 1].focus();
                    inputs[currentIndex + 1].select();
                } else if (currentIndex === inputs.length - 1) {
                    event.target.blur();
                }
            }
        });
    </script>
    @endpush
</x-filament-panels::page>
