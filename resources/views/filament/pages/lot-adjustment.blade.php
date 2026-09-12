<x-filament-panels::page>
    @php
        $log = \App\Models\WmsLotAdjustmentLog::class;
        $summary = $result['summary'] ?? null;
        $details = $result['details'] ?? [];
        $maxRows = 300;
    @endphp

    <x-filament::section>
        <x-slot name="heading">対象倉庫</x-slot>
        <div class="text-sm text-gray-700 dark:text-gray-300">
            <span class="font-semibold">{{ $warehouseName ?? '未選択' }}</span>
            <span class="text-gray-400">（ID: {{ $warehouseId }}）</span>
        </div>
        <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            上部の倉庫セレクタで対象倉庫を切り替えられます。<br>
            「プレビュー」で変更内容を確認 → 「調節を実行」で適用します。<strong>棚番（floor/location）は変更しません。</strong>
            real_stocks 同期は <strong>単一 ACTIVE LOT のときのみ</strong>親在庫に合わせます（複数/0個は「合わせ要手動」として検出のみ）。
            複数棚番・空棚番は検出のみで自動統一はしません。
            <strong>RSLE再利用リスクは検出のみです。実行しても RSLE を CANCELLED にしません（CANCELは別操作）。</strong>
        </div>
    </x-filament::section>

    @if ($result === null)
        <x-filament::section>
            <div class="py-8 text-center text-gray-500 dark:text-gray-400">
                「プレビュー（変更しない）」を押すと、相殺・再ACTIVE化・STLA修正の候補を表示します（在庫は変更しません）。
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">
                結果サマリ
                @if ($resultMode === 'DRY_RUN')
                    <span class="ml-2 rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-700">プレビュー（未適用）</span>
                @else
                    <span class="ml-2 rounded bg-green-100 px-2 py-0.5 text-xs text-green-700">実行済み</span>
                @endif
            </x-slot>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
                @foreach (['offset' => '相殺', 'reactivate' => '再ACTIVE化', 'zero_residual' => '残数0化', 'sync_applied' => '在庫数合わせ', 'sync_manual' => '合わせ要手動', 'repoint' => 'STLA修正', 'multi_shelf' => '複数棚番', 'blank_location' => '空棚番', 'reserved_reuse_risk' => 'RSLE再利用', 'reserved_reuse_wms_exists' => 'RSLE(WMS行)', 'skipped' => 'スキップ'] as $key => $jp)
                    <div class="rounded-lg border border-gray-200 p-3 text-center dark:border-gray-700">
                        <div class="text-xs text-gray-500">{{ $jp }}</div>
                        <div class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ $summary[$key] ?? 0 }}</div>
                    </div>
                @endforeach
            </div>

            <div class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                {{ $resultMode === 'DRY_RUN' ? '適用されるはずの件数' : '適用件数' }}:
                <span class="font-semibold">{{ $result['affected_count'] ?? 0 }}</span>
                <span class="ml-2 text-xs text-gray-400">run: {{ \Illuminate\Support\Str::limit($result['run_uuid'] ?? '', 8, '') }}</span>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">明細（{{ count($details) }} 件）</x-slot>

            @if (count($details) > $maxRows)
                <div class="mb-2 text-xs text-amber-600">表示は先頭 {{ $maxRows }} 件です。全件は「ロット調節履歴」で確認できます。</div>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-gray-200 text-xs text-gray-500 dark:border-gray-700">
                        <tr>
                            <th class="px-2 py-1">種別</th>
                            <th class="px-2 py-1">real_stock</th>
                            <th class="px-2 py-1">LOT / STLA</th>
                            <th class="px-2 py-1">current 前→後</th>
                            <th class="px-2 py-1">status 前→後</th>
                            <th class="px-2 py-1">棚番ID</th>
                            <th class="px-2 py-1">理由</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (array_slice($details, 0, $maxRows) as $d)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-2 py-1">
                                    <span class="rounded px-2 py-0.5 text-xs {{ $log::typeBadgeClass($d['type'] ?? null) }}">{{ $log::typeLabel($d['type'] ?? null) }}</span>
                                </td>
                                <td class="px-2 py-1">{{ $d['real_stock_id'] ?? '-' }}</td>
                                <td class="px-2 py-1">
                                    @if (!empty($d['stla_id']))
                                        stla:{{ $d['stla_id'] }}
                                        @if (!empty($d['old_lot_id'])) <span class="text-gray-400">({{ $d['old_lot_id'] }}→{{ $d['new_lot_id'] ?? '?' }})</span>@endif
                                    @else
                                        {{ $d['lot_id'] ?? '-' }}
                                    @endif
                                </td>
                                <td class="px-2 py-1">
                                    @if (array_key_exists('current_before', $d))
                                        {{ $d['current_before'] }} → {{ $d['current_after'] }}
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-2 py-1 text-xs text-gray-500">
                                    {{ $d['status_before'] ?? '' }}{{ isset($d['status_after']) ? ' → '.$d['status_after'] : '' }}
                                </td>
                                <td class="px-2 py-1 text-xs">{{ $d['location_id'] ?? '' }}</td>
                                <td class="px-2 py-1 text-xs text-gray-500">{{ $log::reasonLabel($d['reason'] ?? null) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
