<div class="space-y-3 -mt-2">
    {{-- 上段: 商品ヘッダー --}}
    <div class="flex items-center gap-3 px-3 py-2 bg-slate-700 dark:bg-slate-800 rounded-lg">
        <span class="px-2 py-0.5 rounded text-xs font-bold shrink-0 bg-blue-500/20 text-blue-300">発注</span>
        <span class="text-white font-mono text-sm">{{ $itemCode }}</span>
        @if(($searchCode ?? '-') !== '-')
            <span class="text-slate-400 font-mono text-xs">[{{ $searchCode }}]</span>
        @endif
        <span class="text-white font-medium text-sm truncate">{{ $itemName }}</span>
        @if($packaging && $packaging !== '-')
            <span class="text-slate-400 text-xs shrink-0">{{ $packaging }}</span>
        @endif
        @if($capacityText && $capacityText !== '-')
            <span class="text-slate-400 text-xs shrink-0">（{{ $capacityText }}）</span>
        @endif
        <span @class([
            'ml-auto px-2 py-0.5 rounded text-xs font-medium shrink-0',
            'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300' => $statusLabel === '承認済',
            'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' => $statusLabel === '確定済',
            'bg-gray-100 text-gray-700 dark:bg-gray-900/40 dark:text-gray-300' => !in_array($statusLabel, ['承認済', '確定済']),
        ])>{{ $statusLabel }}</span>
    </div>

    {{-- 中段: 2カラム情報 --}}
    <div class="grid grid-cols-2 gap-3">
        {{-- 左: 基本情報 --}}
        <div class="bg-gray-50 dark:bg-white/5 rounded-lg p-3">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    <tr>
                        <td class="py-1.5 text-gray-500 dark:text-gray-400 w-28">倉庫</td>
                        <td class="py-1.5 font-medium text-gray-900 dark:text-white">{{ $warehouseName }}</td>
                    </tr>
                    <tr>
                        <td class="py-1.5 text-gray-500 dark:text-gray-400">発注先</td>
                        <td class="py-1.5 font-medium text-gray-900 dark:text-white">{{ $contractorName }}</td>
                    </tr>
                    <tr>
                        <td class="py-1.5 text-gray-500 dark:text-gray-400">入荷予定日</td>
                        <td class="py-1.5">
                            <div class="font-bold text-primary-600 dark:text-primary-400">{{ $expectedArrivalDate }}</div>
                            @if(($hasCalculationLog ?? false) && ($leadTimeDays ?? 0) > 0)
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
                </tbody>
            </table>
        </div>

        {{-- 右: 数量・計算情報 --}}
        <div class="space-y-2">
            {{-- 数量カード --}}
            <div class="grid grid-cols-3 gap-2">
                <div class="text-center bg-gray-50 dark:bg-white/5 rounded-lg py-2 px-1">
                    <div class="text-xs text-gray-500 dark:text-gray-400">理論在庫</div>
                    <div class="text-lg font-bold text-gray-900 dark:text-white">{{ number_format($currentEffectiveStock) }}</div>
                    @if(isset($snapshotEffectiveStock) && (int) $snapshotEffectiveStock !== (int) $currentEffectiveStock)
                        <div class="text-[10px] text-amber-600 dark:text-amber-400">算出時: {{ number_format($snapshotEffectiveStock) }}</div>
                    @endif
                </div>
                <div class="text-center bg-gray-50 dark:bg-white/5 rounded-lg py-2 px-1">
                    <div class="text-xs text-gray-500 dark:text-gray-400">算出数</div>
                    <div class="text-lg font-bold text-gray-900 dark:text-white">{{ number_format($suggestedQuantity) }}</div>
                </div>
                <div class="text-center bg-primary-50 dark:bg-primary-900/20 rounded-lg py-2 px-1">
                    <div class="text-xs text-primary-600 dark:text-primary-400">発注数</div>
                    <div class="text-lg font-bold text-primary-700 dark:text-primary-300">{{ number_format($orderQuantity) }}</div>
                </div>
            </div>

            {{-- 計算情報 --}}
            @if($hasCalculationLog)
            <div class="bg-slate-50 dark:bg-white/5 rounded-lg p-3 text-sm">
                <div class="font-medium text-gray-700 dark:text-gray-300 mb-2">計算情報</div>
                <div class="font-mono text-gray-700 dark:text-gray-300 mb-2 bg-white dark:bg-white/5 rounded px-2 py-1">{{ $formula }}</div>
                <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-gray-600 dark:text-gray-400">
                    <div class="flex justify-between"><span>有効在庫(算出時)</span><span class="font-semibold text-gray-900 dark:text-white">{{ number_format($effectiveStock) }}</span></div>
                    <div class="flex justify-between"><span>入荷予定</span><span class="font-semibold text-gray-900 dark:text-white">{{ number_format($incomingStock) }}</span></div>
                    @if(($transferIncoming ?? 0) > 0 || ($transferOutgoing ?? 0) > 0)
                    <div class="flex justify-between"><span>移動入庫</span><span class="font-semibold text-gray-900 dark:text-white">{{ number_format($transferIncoming ?? 0) }}</span></div>
                    <div class="flex justify-between"><span>移動出庫</span><span class="font-semibold text-gray-900 dark:text-white">{{ number_format($transferOutgoing ?? 0) }}</span></div>
                    @endif
                    <div class="flex justify-between"><span>発注点</span><span class="font-semibold text-gray-900 dark:text-white">{{ number_format($safetyStock) }}</span></div>
                    <div class="flex justify-between"><span>最大発注点</span><span class="font-semibold text-gray-900 dark:text-white">{{ number_format($maxStock ?? 0) }}</span></div>
                    <div class="flex justify-between"><span>最低在庫数</span><span class="font-semibold text-gray-900 dark:text-white">{{ number_format($minStock ?? 0) }}</span></div>
                    <div class="flex justify-between"><span>自動発注</span><span class="font-semibold {{ ($isAutoOrder ?? false) ? 'text-green-700 dark:text-green-300' : 'text-gray-500 dark:text-gray-400' }}">{{ ($isAutoOrder ?? false) ? 'ON' : 'OFF' }}</span></div>
                    <div class="flex justify-between"><span>自動発注数</span><span class="font-semibold text-gray-900 dark:text-white">{{ number_format($settingAutoOrderQuantity ?? $autoOrderQuantity ?? 0) }}</span></div>
                    @if(($maxOrderQuantity ?? null) !== null)
                    <div class="col-span-2 text-xs text-gray-500 dark:text-gray-400">
                        最大発注点まで発注可能: {{ number_format($maxOrderQuantity) }}バラ
                    </div>
                    @endif
                    @if($isEditable ?? false)
                    <div class="col-span-2 mt-1 rounded-lg border border-blue-200 bg-blue-50/80 p-2.5 dark:border-blue-800 dark:bg-blue-950/30">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="text-xs font-semibold text-blue-700 dark:text-blue-300">発注点変更</div>
                                <div class="text-[11px] text-blue-600/80 dark:text-blue-400/80">必要に応じて発注点を手動で上書きします</div>
                            </div>
                            <input type="number"
                                   wire:model.blur="mountedActionsData.0.safety_stock"
                                   class="w-24 rounded-md border border-blue-300 bg-white px-2.5 py-1 text-right text-sm font-semibold text-gray-900 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-blue-700 dark:bg-gray-800 dark:text-white"
                                   min="0" step="1" />
                        </div>
                    </div>
                    @endif
                    <div class="flex justify-between"><span class="text-red-600 dark:text-red-400">不足</span><span class="font-semibold text-red-600 dark:text-red-400">{{ number_format($shortageQty) }}</span></div>
                    @if(($autoOrderQuantity ?? 0) > 0)
                    <div class="flex justify-between"><span>旧自動発注数</span><span class="font-semibold text-gray-900 dark:text-white">{{ number_format($autoOrderQuantity) }}</span></div>
                    <div class="col-span-2 text-xs text-gray-500 dark:text-gray-400">
                        発注数量は{{ $orderQuantitySource ?? '不足数' }}{{ number_format($orderQuantitySourceQty ?? 0) }}バラを基準に計算
                    </div>
                    @endif
                </div>
                @if(isset($purchaseUnit) && $purchaseUnit > 1)
                <div class="flex items-center justify-between mt-2 pt-2 border-t border-gray-200 dark:border-white/10">
                    <div class="text-gray-500 dark:text-gray-400">
                        <span>最小仕入単位: {{ number_format($purchaseUnit) }}</span>
                        @if($purchaseUnitAdjustment) <span class="ml-2 text-xs">{{ $purchaseUnitAdjustment }}</span> @endif
                    </div>
                </div>
                @endif
            </div>
            @else
            <div class="bg-gray-50 dark:bg-white/5 rounded-lg p-3 text-sm text-gray-400">
                計算ログが見つかりません
            </div>
            @endif
        </div>
    </div>
</div>
