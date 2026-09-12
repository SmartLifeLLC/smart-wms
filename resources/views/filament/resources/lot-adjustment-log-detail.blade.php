@php
    $log = \App\Models\WmsLotAdjustmentLog::class;
    $details = $record->details ?? [];
    $summary = $record->summary ?? [];
    $maxRows = 500;
@endphp

<div class="space-y-3 text-sm">
    <div class="text-xs text-gray-500">
        run: {{ $record->run_uuid }} ／ {{ $record->mode === 'APPLIED' ? '実行' : 'プレビュー' }}
        ／ 倉庫ID {{ $record->warehouse_id }} ／ 適用 {{ $record->affected_count }} 件
    </div>

    <div class="grid grid-cols-4 gap-2 sm:grid-cols-8">
        @foreach (['offset' => '相殺', 'reactivate' => '再ACTIVE化', 'zero_residual' => '残数0化', 'sync_applied' => '在庫数合わせ', 'sync_manual' => '合わせ要手動', 'repoint' => 'STLA修正', 'multi_shelf' => '複数棚番', 'blank_location' => '空棚番', 'reserved_reuse_risk' => 'RSLE再利用', 'reserved_reuse_wms_exists' => 'RSLE(WMS行)', 'skipped' => 'スキップ'] as $key => $jp)
            <div class="rounded border border-gray-200 p-2 text-center dark:border-gray-700">
                <div class="text-xs text-gray-500">{{ $jp }}</div>
                <div class="text-lg font-bold">{{ $summary[$key] ?? 0 }}</div>
            </div>
        @endforeach
    </div>

    @if (count($details) > $maxRows)
        <div class="text-xs text-amber-600">表示は先頭 {{ $maxRows }} 件です。</div>
    @endif

    <div class="max-h-[60vh] overflow-auto">
        <table class="w-full text-left text-xs">
            <thead class="sticky top-0 border-b border-gray-200 bg-white text-gray-500 dark:border-gray-700 dark:bg-gray-900">
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
                        <td class="px-2 py-1"><span class="rounded px-1.5 py-0.5 {{ $log::typeBadgeClass($d['type'] ?? null) }}">{{ $log::typeLabel($d['type'] ?? null) }}</span></td>
                        <td class="px-2 py-1">{{ $d['real_stock_id'] ?? '-' }}</td>
                        <td class="px-2 py-1">
                            @if (!empty($d['stla_id']))
                                stla:{{ $d['stla_id'] }}<span class="text-gray-400"> ({{ $d['old_lot_id'] ?? '?' }}→{{ $d['new_lot_id'] ?? '?' }})</span>
                            @else
                                {{ $d['lot_id'] ?? '-' }}
                            @endif
                        </td>
                        <td class="px-2 py-1">{{ array_key_exists('current_before', $d) ? ($d['current_before'].' → '.$d['current_after']) : '-' }}</td>
                        <td class="px-2 py-1 text-gray-500">{{ $d['status_before'] ?? '' }}{{ isset($d['status_after']) ? ' → '.$d['status_after'] : '' }}</td>
                        <td class="px-2 py-1">{{ $d['location_id'] ?? '' }}</td>
                        <td class="px-2 py-1 text-gray-500">{{ $log::reasonLabel($d['reason'] ?? null) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
