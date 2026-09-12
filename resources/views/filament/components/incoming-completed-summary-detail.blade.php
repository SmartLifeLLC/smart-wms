<div class="space-y-4">
    <div class="overflow-auto" style="max-height: calc(100vh - 260px); scrollbar-gutter: stable; border-top: 1px solid #d1d5db; border-bottom: 1px solid #d1d5db;">
        <table class="w-full border-collapse border border-gray-300 text-xs dark:border-gray-600" style="min-width: 1048px; table-layout: fixed; border: 1px solid #d1d5db;">
            <colgroup>
                <col style="width: 54px;">
                <col style="width: 96px;">
                <col style="width: 44px;">
                <col style="width: 86px;">
                <col style="width: 86px;">
                <col style="width: 100px;">
                <col>
                <col style="width: 48px;">
                <col style="width: 82px;">
                <col style="width: 82px;">
                <col style="width: 82px;">
            </colgroup>
            <thead>
                <tr>
                    <th class="border border-gray-300 bg-gray-50 px-2 py-1 text-center font-semibold text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" style="position: sticky; top: 0; z-index: 1;">ID</th>
                    <th class="border border-gray-300 bg-gray-50 px-2 py-1 text-center font-semibold text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" style="position: sticky; top: 0; z-index: 1;">伝票番号</th>
                    <th class="border border-gray-300 bg-gray-50 px-2 py-1 text-center font-semibold text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" style="position: sticky; top: 0; z-index: 1;">区分</th>
                    <th class="border border-gray-300 bg-gray-50 px-2 py-1 text-center font-semibold text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" style="position: sticky; top: 0; z-index: 1;">予定日</th>
                    <th class="border border-gray-300 bg-gray-50 px-2 py-1 text-center font-semibold text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" style="position: sticky; top: 0; z-index: 1;">入荷日</th>
                    <th class="border border-gray-300 bg-gray-50 px-2 py-1 text-center font-semibold text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" style="position: sticky; top: 0; z-index: 1;">商品CD<br><span class="text-[10px] font-normal text-gray-500 dark:text-gray-400">検索CD</span></th>
                    <th class="border border-gray-300 bg-gray-50 px-2 py-1 text-left font-semibold text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" style="position: sticky; top: 0; z-index: 1;">商品名</th>
                    <th class="border border-gray-300 bg-gray-50 px-2 py-1 text-right font-semibold text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" style="position: sticky; top: 0; z-index: 1;">入数</th>
                    <th class="border border-gray-300 bg-gray-50 px-2 py-1 text-right font-semibold text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" style="position: sticky; top: 0; z-index: 1;">発注総バラ</th>
                    <th class="border border-gray-300 bg-gray-50 px-2 py-1 text-right font-semibold text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" style="position: sticky; top: 0; z-index: 1;">入荷総バラ</th>
                    <th class="border border-gray-300 bg-gray-50 px-2 py-1 text-center font-semibold text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" style="position: sticky; top: 0; z-index: 1;">ロケ</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($details as $detail)
                    <tr class="bg-white even:bg-gray-50 dark:bg-gray-900 dark:even:bg-gray-800">
                        <td class="border border-gray-300 px-1.5 py-1 text-center text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $detail['id'] }}</td>
                        <td class="border border-gray-300 px-1.5 py-1 text-center font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">{{ $detail['slip_number'] }}</td>
                        <td class="border border-gray-300 px-1.5 py-1 text-center text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $detail['order_source'] }}</td>
                        <td class="border border-gray-300 px-1.5 py-1 text-center text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $detail['expected_arrival_date'] }}</td>
                        <td class="border border-gray-300 px-1.5 py-1 text-center text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $detail['actual_arrival_date'] }}</td>
                        <td class="border border-gray-300 px-1.5 py-1 text-center text-gray-700 dark:border-gray-600 dark:text-gray-300">
                            <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $detail['item_code'] }}</div>
                            <div class="text-[10px] leading-tight text-gray-500 dark:text-gray-400">{{ $detail['search_code'] }}</div>
                        </td>
                        <td class="border border-gray-300 px-1.5 py-1 text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $detail['item_name'] }}</td>
                        <td class="border border-gray-300 px-1.5 py-1 text-right text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $detail['capacity_case'] }}</td>
                        <td class="border border-gray-300 px-1.5 py-1 text-right font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">{{ $detail['expected_total_piece_quantity'] }}</td>
                        <td class="border border-gray-300 px-1.5 py-1 text-right font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">{{ $detail['received_total_piece_quantity'] }}</td>
                        <td class="border border-gray-300 px-1.5 py-1 text-center text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $detail['location'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="border border-gray-300 px-2 py-4 text-center text-gray-500 dark:border-gray-600 dark:text-gray-400">
                            明細データなし
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
