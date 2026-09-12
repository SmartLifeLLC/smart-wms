<?php

$toKebab = static function (string $name, string $suffix): string {
    if (str_ends_with($name, $suffix)) {
        $name = substr($name, 0, -strlen($suffix));
    }

    return (string) str($name)->kebab();
};

$scan = static function (string $directory, string $suffix) use ($toKebab): array {
    $values = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($iterator as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), $suffix.'.php')) {
            continue;
        }

        $values[] = $toKebab(pathinfo($file->getFilename(), PATHINFO_FILENAME), $suffix);
    }

    sort($values);

    return array_values(array_unique($values));
};

$buildPermissions = static function (array $resources, array $actions = ['view', 'create', 'edit', 'delete'], string $menu = 'Admin', string $subMenu = 'Resources'): array {
    $permissions = [];

    foreach ($resources as $resource) {
        $screenName = str($resource)->replace('-', ' ')->title()->value();

        foreach ($actions as $action) {
            $permissions[] = [
                'name' => "wms.{$resource}.{$action}",
                'system' => 'wms',
                'resource' => $resource,
                'action' => $action,
                'display_name' => "{$screenName} {$action}",
                'menu' => $menu,
                'sub_menu' => $subMenu,
                'screen_name' => $screenName,
            ];
        }
    }

    return $permissions;
};

$resourcePermissions = $buildPermissions(
    $scan(app_path('Filament/Resources'), 'Resource')
);

$pagePermissions = $buildPermissions([
    'dashboard',
    'auto-order-guide',
    'floor-plan-editor',
    'jx-test-data',
    'lot-adjustment',
    'modal-showcase',
    'picking-route-visualization',
    'test-data-generator',
    'wms-inbound',
    'wms-outbound',
], ['view'], 'Admin', 'Pages');

return [
    'cache' => [
        'ttl' => 30,
        'prefix' => 'sakemaru_permissions',
    ],

    'database' => [
        'connection' => 'sakemaru',
    ],

    'migrations' => [
        'load' => false,
    ],

    'permissions' => array_merge(
        [
            [
                'name' => 'wms.access',
                'system' => 'wms',
                'resource' => 'access',
                'action' => 'view',
                'display_name' => 'WMS Admin Access',
                'menu' => 'Admin',
                'sub_menu' => 'Access',
                'screen_name' => 'WMS Admin',
            ],
            [
                'name' => 'wms.floor-plan.view',
                'system' => 'wms',
                'resource' => 'floor-plan',
                'action' => 'view',
                'display_name' => 'Floor Plan View',
                'menu' => 'Admin',
                'sub_menu' => 'Floor Plan',
                'screen_name' => 'Floor Plan',
            ],
            [
                'name' => 'wms.floor-plan.edit',
                'system' => 'wms',
                'resource' => 'floor-plan',
                'action' => 'edit',
                'display_name' => 'Floor Plan Edit',
                'menu' => 'Admin',
                'sub_menu' => 'Floor Plan',
                'screen_name' => 'Floor Plan',
            ],
            [
                'name' => 'wms.picking-route.view',
                'system' => 'wms',
                'resource' => 'picking-route',
                'action' => 'view',
                'display_name' => 'Picking Route View',
                'menu' => 'Admin',
                'sub_menu' => 'Picking Route',
                'screen_name' => 'Picking Route',
            ],
            [
                'name' => 'wms.jx-transmission-log.download',
                'system' => 'wms',
                'resource' => 'jx-transmission-log',
                'action' => 'download',
                'display_name' => 'JX Transmission Log Download',
                'menu' => 'Admin',
                'sub_menu' => 'JX',
                'screen_name' => 'Transmission Logs',
            ],
            [
                'name' => 'wms.execute-wms-picking-task.execute',
                'system' => 'wms',
                'resource' => 'execute-wms-picking-task',
                'action' => 'execute',
                'display_name' => 'Execute WMS Picking Task',
                'menu' => 'Admin',
                'sub_menu' => 'Picking',
                'screen_name' => 'Execute WMS Picking Task',
            ],
            [
                'name' => 'wms.api-document.view',
                'system' => 'wms',
                'resource' => 'api-document',
                'action' => 'view',
                'display_name' => 'API Document View',
                'menu' => 'Admin',
                'sub_menu' => 'Settings',
                'screen_name' => 'API Document',
            ],
            [
                'name' => 'wms.warehouse-stock-transfer-delivery-course.view',
                'system' => 'wms',
                'resource' => 'warehouse-stock-transfer-delivery-course',
                'action' => 'view',
                'display_name' => 'Warehouse Stock Transfer Delivery Course View',
                'menu' => 'Admin',
                'sub_menu' => 'Order History',
                'screen_name' => 'Warehouse Stock Transfer Delivery Course',
            ],
            [
                'name' => 'wms.wms-warehouse-transfer-candidate.confirm',
                'system' => 'wms',
                'resource' => 'wms-warehouse-transfer-candidate',
                'action' => 'confirm',
                'display_name' => 'Wms Warehouse Transfer Candidate confirm',
                'menu' => 'Admin',
                'sub_menu' => 'Resources',
                'screen_name' => 'Wms Warehouse Transfer Candidate',
            ],
            [
                'name' => 'wms.wms-warehouse-transfer-candidate.cancel',
                'system' => 'wms',
                'resource' => 'wms-warehouse-transfer-candidate',
                'action' => 'cancel',
                'display_name' => 'Wms Warehouse Transfer Candidate cancel',
                'menu' => 'Admin',
                'sub_menu' => 'Resources',
                'screen_name' => 'Wms Warehouse Transfer Candidate',
            ],
        ],
        $resourcePermissions,
        $pagePermissions,
    ),
];
