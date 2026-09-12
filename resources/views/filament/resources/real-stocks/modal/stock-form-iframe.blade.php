<div class="overflow-hidden rounded border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900" style="height: 82vh; min-height: 560px;">
    <iframe
        src="{{ $coreStockUrl }}"
        title="stocks form {{ $record->getKey() }}"
        class="block"
        style="height: 100%; width: 100%;"
        loading="lazy"
    ></iframe>
</div>
