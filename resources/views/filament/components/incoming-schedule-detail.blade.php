<div class="space-y-3 -mt-2">
    {{-- 上段: 商品ヘッダー --}}
    <div class="flex items-center gap-3 px-3 py-2 bg-slate-700 dark:bg-slate-800 rounded-lg">
        <span @class([
            'px-2 py-0.5 rounded text-xs font-bold shrink-0',
            'bg-blue-500/20 text-blue-300' => $orderSource === '発注',
            'bg-gray-500/20 text-gray-300' => $orderSource === '手動',
            'bg-amber-500/20 text-amber-300' => $orderSource === '移動',
            'bg-emerald-500/20 text-emerald-300' => $orderSource === '受信',
        ])>{{ $orderSource }}</span>
        <span class="text-white font-mono text-sm">{{ $itemCode }}</span>
        <span class="text-white font-medium text-sm truncate">{{ $itemName }}</span>
        @if($packaging && $packaging !== '-')
            <span class="text-slate-400 text-xs shrink-0">{{ $packaging }}</span>
        @endif
        @if($capacityText && $capacityText !== '-')
            <span class="text-slate-400 text-xs shrink-0">（{{ $capacityText }}）</span>
        @endif
        <span class="ml-auto px-2 py-0.5 rounded text-xs font-medium shrink-0 bg-{{ $statusColor }}-100 text-{{ $statusColor }}-700 dark:bg-{{ $statusColor }}-900/40 dark:text-{{ $statusColor }}-300">{{ $status }}</span>
    </div>

    {{-- 中段: 2カラム情報 --}}
    <div class="grid grid-cols-2 gap-3">
        {{-- 左: 基本情報 --}}
        <div class="bg-gray-50 dark:bg-white/5 rounded-lg p-3">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    <tr>
                        <td class="py-1.5 text-gray-500 dark:text-gray-400 w-24">商品CD</td>
                        <td class="py-1.5 font-mono text-gray-900 dark:text-white">{{ $itemCode ?? '-' }}</td>
                        <td class="py-1.5 text-gray-500 dark:text-gray-400 w-24 pl-4">検索CD</td>
                        <td class="py-1.5 font-mono text-gray-900 dark:text-white">{{ $searchCode ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td class="py-1.5 text-gray-500 dark:text-gray-400">倉庫</td>
                        <td class="py-1.5 font-medium text-gray-900 dark:text-white" colspan="3">{{ $warehouseName }}</td>
                    </tr>
                    <tr>
                        <td class="py-1.5 text-gray-500 dark:text-gray-400">ロケーション</td>
                        <td class="py-1.5" colspan="3">
                            @if($locationText !== '-')
                                <span class="font-mono text-xs bg-gray-200 dark:bg-white/10 px-1.5 py-0.5 rounded">{{ $locationText }}</span>
                            @else
                                <span class="text-gray-400 text-xs">未設定</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="py-1.5 text-gray-500 dark:text-gray-400">発注先</td>
                        <td class="py-1.5 font-medium text-gray-900 dark:text-white" colspan="3">{{ $contractorName }}</td>
                    </tr>
                    <tr>
                        <td class="py-1.5 text-gray-500 dark:text-gray-400">発注日</td>
                        <td class="py-1.5 text-gray-900 dark:text-white" colspan="3">{{ $orderDate }}</td>
                    </tr>
                    <tr>
                        <td class="py-1.5 text-gray-500 dark:text-gray-400">予定日</td>
                        <td class="py-1.5" colspan="3">
                            <div class="font-bold text-primary-600 dark:text-primary-400">{{ $expectedArrivalDate }}</div>
                            @if(($hasOrderCandidate ?? false) && ($hasCalculationLog ?? false) && ($leadTimeDays ?? 0) > 0)
                                <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                                    @if(($shiftedDays ?? 0) > 0)
                                        {{ $orderDate }} + LT {{ $leadTimeDays }}日 → {{ $originalArrivalDate ?? '?' }}
                                        → {{ $shiftReasons }}
                                        → {{ $calculatedDate ?? $expectedArrivalDate }}
                                    @else
                                        {{ $orderDate }} + LT {{ $leadTimeDays }}日（調整なし）
                                    @endif
                                </div>
                                @if($isDateManuallyChanged ?? false)
                                    <div class="text-xs text-blue-600 dark:text-blue-400 mt-0.5">※ 手動で {{ $calculatedDate ?? '-' }} → {{ $expectedArrivalDate }} に変更済</div>
                                @endif
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="py-1.5 text-gray-500 dark:text-gray-400">入荷日時</td>
                        <td class="py-1.5 text-gray-900 dark:text-white" colspan="3">{{ $actualArrivalDateTime ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td class="py-1.5 text-gray-500 dark:text-gray-400">担当者</td>
                        <td class="py-1.5 text-gray-900 dark:text-white" colspan="3">{{ $confirmedByName ?? '-' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- 右: 数量・在庫 --}}
        <div class="space-y-2">
            {{-- 数量カード --}}
            @php
                $capacityCase = $capacityCase ?? 0;
                $quantityType = $quantityType ?? null;
                $orderCases = 0;
                $orderLoose = 0;
                $totalPieces = $expectedQuantity;

                if ($quantityType === 'CASE') {
                    $orderCases = $expectedQuantity;
                    $totalPieces = $capacityCase > 0 ? $expectedQuantity * $capacityCase : $expectedQuantity;
                } elseif ($capacityCase > 1) {
                    $orderCases = (int) ($expectedQuantity / $capacityCase);
                    $orderLoose = $expectedQuantity % $capacityCase;
                } else {
                    $orderLoose = $expectedQuantity;
                }
            @endphp
            <div class="grid grid-cols-4 gap-2">
                <div class="text-center bg-gray-50 dark:bg-white/5 rounded-lg py-2 px-1">
                    <div class="text-xs text-gray-500 dark:text-gray-400">発注ケース</div>
                    <div class="text-lg font-bold text-gray-900 dark:text-white">{{ $capacityCase > 1 ? number_format($orderCases) : '-' }}</div>
                </div>
                <div class="text-center bg-gray-50 dark:bg-white/5 rounded-lg py-2 px-1">
                    <div class="text-xs text-gray-500 dark:text-gray-400">発注バラ</div>
                    <div class="text-lg font-bold text-gray-900 dark:text-white">{{ number_format($orderLoose) }}</div>
                </div>
                <div class="text-center bg-gray-50 dark:bg-white/5 rounded-lg py-2 px-1">
                    <div class="text-xs text-gray-500 dark:text-gray-400">発注総バラ</div>
                    <div class="text-lg font-bold text-gray-900 dark:text-white">{{ number_format($totalPieces) }}</div>
                </div>
                <div class="text-center rounded-lg py-2 px-1 {{ $receivedQuantity > 0 ? 'bg-emerald-50 dark:bg-emerald-900/20' : 'bg-gray-50 dark:bg-white/5' }}">
                    <div class="text-xs {{ $receivedQuantity > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500 dark:text-gray-400' }}">入荷総バラ</div>
                    <div class="text-lg font-bold {{ $receivedQuantity > 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-900 dark:text-white' }}">{{ number_format($receivedQuantity) }}</div>
                </div>
            </div>

            {{-- 在庫カード --}}
            <div class="grid grid-cols-2 gap-2">
                <div class="text-center bg-gray-50 dark:bg-white/5 rounded-lg py-2">
                    <div class="text-xs text-gray-500 dark:text-gray-400">現在庫</div>
                    <div class="text-base font-semibold text-gray-900 dark:text-white">{{ number_format($currentStock) }}</div>
                </div>
                <div class="text-center bg-blue-50 dark:bg-blue-900/20 rounded-lg py-2">
                    <div class="text-xs text-blue-600 dark:text-blue-400">有効在庫</div>
                    <div class="text-base font-semibold text-blue-700 dark:text-blue-300">{{ number_format($availableStock) }}</div>
                </div>
            </div>

            {{-- 計算情報 --}}
            @if($hasOrderCandidate && $hasCalculationLog)
            <div class="bg-slate-50 dark:bg-white/5 rounded-lg p-3 text-sm">
                <div class="flex items-center gap-2 text-gray-500 dark:text-gray-400 mb-2">
                    <span class="font-medium text-gray-700 dark:text-gray-300">計算情報</span>
                    <span class="text-xs">ID:{{ $orderCandidateId }} / {{ $batchCodeFormatted }}</span>
                </div>
                <div class="font-mono text-gray-700 dark:text-gray-300 mb-2 bg-white dark:bg-white/5 rounded px-2 py-1">{{ $formula }}</div>
                <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-gray-600 dark:text-gray-400">
                    <div class="flex justify-between"><span>有効在庫</span><span class="font-semibold text-gray-900 dark:text-white">{{ number_format($effectiveStock) }}</span></div>
                    <div class="flex justify-between"><span>入荷予定</span><span class="font-semibold text-gray-900 dark:text-white">{{ number_format($incomingStock) }}</span></div>
                    @if(($transferIncoming ?? 0) > 0 || ($transferOutgoing ?? 0) > 0)
                    <div class="flex justify-between"><span>移動入庫</span><span class="font-semibold text-gray-900 dark:text-white">{{ number_format($transferIncoming ?? 0) }}</span></div>
                    <div class="flex justify-between"><span>移動出庫</span><span class="font-semibold text-gray-900 dark:text-white">{{ number_format($transferOutgoing ?? 0) }}</span></div>
                    @endif
                    <div class="flex justify-between"><span>発注点</span><span class="font-semibold text-gray-900 dark:text-white">{{ number_format($safetyStock) }}</span></div>
                    <div class="flex justify-between"><span class="text-red-600 dark:text-red-400">不足</span><span class="font-semibold text-red-600 dark:text-red-400">{{ number_format($shortageQty) }}</span></div>
                </div>
                <div class="flex items-center justify-between mt-2 pt-2 border-t border-gray-200 dark:border-white/10">
                    @if($purchaseUnit > 1)
                    <div class="text-gray-500 dark:text-gray-400">
                        <span>最小仕入単位: {{ number_format($purchaseUnit) }}</span>
                        @if($unitAdjustmentNote) <span class="ml-2 text-xs">{{ $unitAdjustmentNote }}</span> @endif
                    </div>
                    @else
                    <div></div>
                    @endif
                    <span class="font-bold text-base text-primary-600 dark:text-primary-400">発注数: {{ number_format($orderQuantity) }}</span>
                </div>
            </div>
            @elseif(!$hasOrderCandidate)
            <div class="bg-gray-50 dark:bg-white/5 rounded-lg p-3 text-sm text-gray-400">
                手動登録または移動からの入荷予定（発注計算情報なし）
            </div>
            @endif
        </div>
    </div>
</div>
