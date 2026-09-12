<x-filament-panels::page>
    <style>
        body:has(.wms-real-stock-iframe-page) .fi-main {
            max-width: none !important;
            padding: 0 !important;
        }

        body:has(.wms-real-stock-iframe-page) .fi-page,
        body:has(.wms-real-stock-iframe-page) .fi-page-header-main-ctn,
        body:has(.wms-real-stock-iframe-page) .fi-page-main,
        body:has(.wms-real-stock-iframe-page) .fi-page-content {
            gap: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        body:has(.wms-real-stock-iframe-page) .fi-page-header-main-ctn > .fi-header {
            display: none !important;
        }
    </style>

    <div class="wms-real-stock-iframe-shell overflow-hidden bg-white dark:bg-gray-900" style="height: calc(100dvh - 2.5rem); width: 100%;">
        <iframe
            src="{{ $this->getCoreStockUrl() }}"
            title="stocks form {{ $this->record->getKey() }}"
            class="block"
            style="height: 100%; width: 100%;"
        ></iframe>
    </div>
</x-filament-panels::page>
