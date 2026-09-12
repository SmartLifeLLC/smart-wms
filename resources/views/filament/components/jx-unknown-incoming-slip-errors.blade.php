<div class="overflow-x-auto -my-2">
    <table class="w-full border-collapse border border-gray-300 text-sm dark:border-gray-600">
        <thead>
            <tr class="bg-gray-50 dark:bg-gray-800">
                <th class="border border-gray-300 px-2 py-1 text-left font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">内容</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($errors as $error)
                <tr class="bg-white dark:bg-gray-900">
                    <td class="border border-gray-300 px-2 py-1 text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $error['message'] }}</td>
                </tr>
            @empty
                <tr>
                    <td class="border border-gray-300 px-2 py-4 text-center text-gray-500 dark:border-gray-600 dark:text-gray-400">
                        確認内容なし
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
