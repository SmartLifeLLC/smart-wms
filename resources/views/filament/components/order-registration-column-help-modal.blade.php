<div
    x-show="columnHelpLabel"
    x-cloak
    x-on:keydown.escape.window="closeColumnHelp()"
    class="fixed inset-0 flex items-center justify-center bg-slate-950/40 p-4"
    style="z-index: 10050;"
>
    <div
        x-on:click="closeColumnHelp()"
        class="absolute inset-0"
    ></div>
    <div class="relative w-full max-w-lg overflow-hidden rounded-lg bg-white shadow-xl dark:bg-gray-900">
        <div class="flex items-center justify-between bg-slate-800 px-4 py-3 text-white">
            <div class="flex items-center gap-2 text-sm font-semibold">
                <x-heroicon-o-question-mark-circle class="h-5 w-5" />
                <span x-text="columnHelpLabel"></span>
            </div>
            <button type="button" x-on:click="closeColumnHelp()" class="rounded p-1 text-white hover:bg-white/10">
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>
        <div class="p-4 text-sm leading-6 text-slate-700 dark:text-gray-200">
            <p x-text="columnHelpDescriptions[columnHelpLabel] || '説明はありません。'"></p>
        </div>
        <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
            <button type="button" x-on:click="closeColumnHelp()" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">閉じる</button>
        </div>
    </div>
</div>
