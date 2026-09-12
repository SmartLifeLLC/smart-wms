<?php

namespace App\Services\Navigation;

use App\Enums\EMenuCategory;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use ReflectionClass;
use UnitEnum;

class MegaMenuBuilder
{
    public function build(): array
    {
        return $this->assembleStructure($this->collectItemsFromNavigation());
    }

    public function buildCatalog(): array
    {
        return $this->assembleStructure($this->collectAllItemsFromPanel());
    }

    /**
     * @param  array<string, array<int, NavigationItem>>  $itemsByGroupLabel
     */
    protected function assembleStructure(array $itemsByGroupLabel): array
    {
        $registry = $this->buildNavigationRegistry();
        $structure = [];

        foreach ($this->tabs() as $tabSort => $tab) {
            $groupsForTab = [];

            foreach ($tab['categories'] as $groupSort => $category) {
                $categoryLabel = $category->label();

                $items = collect($itemsByGroupLabel[$categoryLabel] ?? [])
                    ->flatMap(function (NavigationItem $item) {
                        $children = collect($item->getChildItems() ?? []);

                        return $children->isNotEmpty() ? $children : collect([$item]);
                    })
                    ->values();

                if ($items->isEmpty()) {
                    continue;
                }

                $groupsForTab[] = [
                    'key' => $category->value,
                    'label' => $categoryLabel,
                    'icon' => $category->icon(),
                    'sort' => $groupSort + 1,
                    'items' => $items
                        ->values()
                        ->map(fn (NavigationItem $item, int $itemSort) => $this->buildMenuItem(
                            item: $item,
                            registry: $registry,
                            itemSort: $itemSort + 1,
                        ))
                        ->toArray(),
                ];
            }

            if (count($groupsForTab) === 0) {
                continue;
            }

            $structure[] = [
                'id' => $tab['id'],
                'label' => $tab['label'],
                'icon' => $tab['icon'],
                'sort' => $tabSort + 1,
                'groups' => $groupsForTab,
            ];
        }

        $structure[] = $this->buildSakemaruSeriesTab();

        return $structure;
    }

    /**
     * @return array<string, array<int, NavigationItem>>
     */
    protected function collectItemsFromNavigation(): array
    {
        $itemsByLabel = [];

        foreach (Filament::getNavigation() as $navGroup) {
            if (! $navGroup instanceof NavigationGroup) {
                continue;
            }

            $label = $navGroup->getLabel();
            if (blank($label)) {
                continue;
            }

            $items = $navGroup->getItems();
            if ($items instanceof \Illuminate\Contracts\Support\Arrayable) {
                $items = $items->toArray();
            }

            $itemsByLabel[$label] = array_merge(
                $itemsByLabel[$label] ?? [],
                $items,
            );
        }

        return $itemsByLabel;
    }

    /**
     * @return array<string, array<int, NavigationItem>>
     */
    protected function collectAllItemsFromPanel(): array
    {
        $panel = Filament::getPanel('admin');
        $itemsByLabel = [];

        $append = function (array $items) use (&$itemsByLabel): void {
            foreach ($items as $item) {
                $label = $this->resolveGroupLabel($item->getGroup());
                if (blank($label)) {
                    continue;
                }

                $itemsByLabel[$label][] = $item;
            }
        };

        foreach ($panel->getResources() as $resourceClass) {
            $append($resourceClass::getNavigationItems());
        }

        foreach ($panel->getPages() as $pageClass) {
            if (! method_exists($pageClass, 'getNavigationItems')) {
                continue;
            }

            $append($pageClass::getNavigationItems());
        }

        $append($panel->getNavigationItems());

        foreach ($itemsByLabel as $label => $items) {
            usort(
                $items,
                fn (NavigationItem $a, NavigationItem $b) => $a->getSort() <=> $b->getSort(),
            );
            $itemsByLabel[$label] = $items;
        }

        return $itemsByLabel;
    }

    protected function resolveGroupLabel(string|UnitEnum|null $group): ?string
    {
        if ($group === null) {
            return null;
        }

        if (is_string($group)) {
            return $group;
        }

        if ($group instanceof HasLabel) {
            return $group->getLabel();
        }

        return $group->name;
    }

    public function buildCatalogRows(): array
    {
        $now = now();
        $rows = [];

        foreach ($this->buildCatalog() as $tabIndex => $tab) {
            foreach ($tab['groups'] as $groupIndex => $group) {
                foreach ($group['items'] as $itemIndex => $item) {
                    $rows[] = [
                        'system' => config('sakemaru.system', 'wms'),
                        'panel' => 'admin',
                        'item_key' => $item['itemKey'] ?? $this->makeItemKey(
                            tabId: $tab['id'],
                            groupKey: $group['key'] ?? null,
                            label: $item['label'],
                            url: $item['url'] ?? null,
                        ),
                        'permission_resource' => $item['permissionResource'] ?? null,
                        'target_system' => $item['targetSystem'] ?? $this->resolveTargetSystem($item['url'] ?? null),
                        'tab_key' => $tab['id'],
                        'tab_label' => $tab['label'],
                        'group_key' => $group['key'] ?? null,
                        'group_label' => $group['label'],
                        'item_label' => $item['label'],
                        'url' => $item['url'] ?? null,
                        'source_type' => $item['sourceType'] ?? (($item['permissionResource'] ?? null) ? 'navigation' : 'external'),
                        'is_external' => $item['isExternal'] ?? (($item['permissionResource'] ?? null) === null),
                        'opens_in_new_tab' => (bool) ($item['openInSplitView'] ?? false),
                        'tab_sort' => $tabIndex + 1,
                        'group_sort' => $groupIndex + 1,
                        'item_sort' => $itemIndex + 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        return $rows;
    }

    protected function buildNavigationRegistry(): array
    {
        $panel = Filament::getPanel('admin');
        $registry = [];

        foreach ($panel->getResources() as $resourceClass) {
            $permissionResource = $this->resolvePermissionResource($resourceClass, 'Resource');

            foreach ($resourceClass::getNavigationItems() as $item) {
                $this->registerNavigationItem($registry, $item, $permissionResource, 'resource');
            }
        }

        foreach ($panel->getPages() as $pageClass) {
            if (! method_exists($pageClass, 'getNavigationUrl')) {
                continue;
            }

            $url = $this->normalizeUrl($pageClass::getNavigationUrl());
            if ($url === null) {
                continue;
            }

            $registry[$url] = [
                'permissionResource' => $this->resolvePermissionResource($pageClass, 'Page'),
                'sourceType' => 'page',
            ];
        }

        foreach ($this->customNavigationRegistry() as $item) {
            $registry[$this->normalizeUrl($item['url'])] = [
                'permissionResource' => $item['permissionResource'],
                'sourceType' => 'custom',
            ];
        }

        return $registry;
    }

    protected function registerNavigationItem(
        array &$registry,
        NavigationItem $item,
        string $permissionResource,
        string $sourceType,
    ): void {
        $url = $this->normalizeUrl($item->getUrl());
        if ($url !== null) {
            $registry[$url] = [
                'permissionResource' => $permissionResource,
                'sourceType' => $sourceType,
            ];
        }

        foreach ($item->getChildItems() ?? [] as $childItem) {
            $this->registerNavigationItem($registry, $childItem, $permissionResource, $sourceType);
        }
    }

    protected function buildMenuItem(NavigationItem $item, array $registry, int $itemSort): array
    {
        $url = $this->normalizeUrl($item->getUrl());
        $meta = $url !== null ? ($registry[$url] ?? []) : [];

        return [
            'label' => $item->getLabel(),
            'url' => $url,
            'isActive' => $item->isActive(),
            'icon' => $this->getIconString($item->getIcon()),
            'openInSplitView' => $item->shouldOpenUrlInNewTab(),
            'permissionResource' => $meta['permissionResource'] ?? null,
            'sourceType' => $meta['sourceType'] ?? 'navigation',
            'targetSystem' => $this->resolveTargetSystem($url),
            'itemKey' => $this->makeItemKey(
                tabId: config('sakemaru.system', 'wms'),
                groupKey: null,
                label: $item->getLabel(),
                url: $url,
                permissionResource: $meta['permissionResource'] ?? null,
            ),
            'sort' => $itemSort,
        ];
    }

    protected function buildSakemaruSeriesTab(): array
    {
        $appUrl = config('app.url');
        $parsed = parse_url($appUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? 'localhost';
        $baseDomain = preg_replace('/^[^.]+\./', '', $host);

        $systems = [
            ['label' => '酒丸（基幹システム）', 'subdomain' => null, 'desc' => '基幹業務システム', 'targetSystem' => 'sakemaru'],
            ['label' => '酒丸千里眼', 'subdomain' => 'search', 'desc' => '高度検索システム', 'targetSystem' => 'search'],
            ['label' => '酒丸乃蓮', 'subdomain' => 'trade', 'desc' => '取引管理システム', 'targetSystem' => 'trade'],
            ['label' => '酒丸帳場', 'subdomain' => 'documents', 'desc' => '帳票管理システム', 'targetSystem' => 'documents'],
            ['label' => '酒丸飛脚', 'subdomain' => 'delivery', 'desc' => '配送管理システム', 'targetSystem' => 'delivery'],
            ['label' => '酒丸算盤', 'subdomain' => 'insights', 'desc' => '分析・レポートシステム', 'targetSystem' => 'insights'],
            ['label' => '酒丸通い帳', 'subdomain' => 'knowledge', 'desc' => 'ナレッジ管理システム', 'targetSystem' => 'knowledge'],
        ];

        $items = [];
        foreach ($systems as $itemSort => $system) {
            $url = $system['subdomain']
                ? "{$scheme}://{$system['subdomain']}.{$baseDomain}"
                : "{$scheme}://{$baseDomain}";

            $items[] = [
                'label' => $system['label'],
                'url' => $this->normalizeUrl($url),
                'isActive' => false,
                'icon' => null,
                'openInSplitView' => true,
                'desc' => $system['desc'],
                'permissionResource' => null,
                'sourceType' => 'external',
                'isExternal' => true,
                'targetSystem' => $system['targetSystem'],
                'itemKey' => $this->makeItemKey(
                    tabId: 'sakemaru_series',
                    groupKey: 'sakemaru_series',
                    label: $system['label'],
                    url: $url,
                ),
                'sort' => $itemSort + 1,
            ];
        }

        return [
            'id' => 'sakemaru_series',
            'label' => '酒丸',
            'icon' => 'fa-box',
            'sort' => count($this->tabs()) + 1,
            'groups' => [
                [
                    'key' => 'sakemaru_series',
                    'label' => '酒丸シリーズ',
                    'icon' => 'heroicon-o-arrow-top-right-on-square',
                    'sort' => 1,
                    'items' => $items,
                ],
            ],
        ];
    }

    protected function tabs(): array
    {
        return [
            [
                'id' => 'order',
                'label' => '発注',
                'icon' => 'fa-cart-shopping',
                'categories' => [
                    EMenuCategory::AUTO_ORDER,
                    EMenuCategory::ORDER_HISTORY,
                    EMenuCategory::ORDER_SETTINGS,
                ],
            ],
            [
                'id' => 'inbound',
                'label' => '入荷',
                'icon' => 'fa-download',
                'categories' => [
                    EMenuCategory::INBOUND,
                ],
            ],
            [
                'id' => 'outbound',
                'label' => '出荷',
                'icon' => 'fa-arrow-up-from-bracket',
                'categories' => [
                    EMenuCategory::OUTBOUND,
                    EMenuCategory::SHORTAGE,
                    EMenuCategory::HORIZONTAL_SHIPMENT,
                ],
            ],
            [
                'id' => 'inventory',
                'label' => '在庫',
                'icon' => 'fa-boxes-stacked',
                'categories' => [
                    EMenuCategory::INVENTORY,
                ],
            ],
            [
                'id' => 'master',
                'label' => 'マスタ',
                'icon' => 'fa-database',
                'categories' => [
                    EMenuCategory::MASTER_WAREHOUSE,
                    EMenuCategory::MASTER_ORDER,
                    EMenuCategory::MASTER_PICKING,
                ],
            ],
            [
                'id' => 'analysis',
                'label' => '統計',
                'icon' => 'fa-chart-bar',
                'categories' => [
                    EMenuCategory::STATISTICS,
                    EMenuCategory::LOGS,
                ],
            ],
            [
                'id' => 'system',
                'label' => 'システム',
                'icon' => 'fa-cogs',
                'categories' => [
                    EMenuCategory::ORDER_TRANSMISSION,
                    EMenuCategory::WAVE_MANAGEMENT,
                    EMenuCategory::SETTINGS,
                    EMenuCategory::TEST_DATA,
                ],
            ],
            [
                'id' => 'guide',
                'label' => '解説',
                'icon' => 'fa-book-open',
                'categories' => [
                    EMenuCategory::GUIDE_ORDER,
                ],
            ],
        ];
    }

    protected function customNavigationRegistry(): array
    {
        return [
            [
                'url' => '/api/documentation',
                'permissionResource' => 'api-document',
            ],
            [
                'url' => config('app.core_url').'/stocks/inventory/transfer',
                'permissionResource' => 'warehouse-stock-transfer-delivery-course',
            ],
        ];
    }

    protected function resolvePermissionResource(string $class, string $suffix): string
    {
        $defaults = (new ReflectionClass($class))->getDefaultProperties();
        $value = $defaults['permissionResource'] ?? null;
        if (filled($value)) {
            return $value;
        }

        $base = class_basename($class);
        if (str_ends_with($base, $suffix)) {
            $base = substr($base, 0, -strlen($suffix));
        }

        return $this->normalizePermissionResourceSlug((string) Str::of($base)->kebab());
    }

    protected function resolveTargetSystem(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $parsed = parse_url($url);
        $host = $parsed['host'] ?? null;
        if ($host === null) {
            return config('sakemaru.system', 'wms');
        }

        $currentHost = parse_url(config('app.url'), PHP_URL_HOST) ?? '';
        $baseDomain = preg_replace('/^[^.]+\./', '', $currentHost);

        if ($host === $baseDomain) {
            return 'sakemaru';
        }

        if (str_ends_with($host, '.'.$baseDomain)) {
            return Str::before($host, '.');
        }

        return null;
    }

    protected function normalizeUrl(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        if (str_starts_with($url, '/')) {
            $url = URL::to($url);
        }

        return rtrim($url, '/') ?: $url;
    }

    protected function normalizePermissionResourceSlug(string $slug): string
    {
        $segments = explode('-', $slug);
        $prefix = [];

        while ($segments !== [] && strlen($segments[0]) === 1) {
            $prefix[] = array_shift($segments);
        }

        if (count($prefix) < 2) {
            return $slug;
        }

        array_unshift($segments, implode('', $prefix));

        return implode('-', $segments);
    }

    protected function makeItemKey(
        string $tabId,
        ?string $groupKey,
        string $label,
        ?string $url,
        ?string $permissionResource = null,
    ): string {
        $base = $permissionResource ?? $tabId;
        $hash = substr(md5(implode('|', [$tabId, $groupKey, $label, $url])), 0, 12);

        return "{$base}:{$hash}";
    }

    protected function getIconString($icon): ?string
    {
        $iconClass = null;

        if ($icon instanceof \BackedEnum) {
            $iconClass = $icon->value;
        } elseif (is_string($icon)) {
            $iconClass = $icon;
        }

        if ($iconClass && (str_starts_with($iconClass, 'o-') || str_starts_with($iconClass, 's-')) && ! str_starts_with($iconClass, 'heroicon-')) {
            return 'heroicon-'.$iconClass;
        }

        return $iconClass;
    }
}
