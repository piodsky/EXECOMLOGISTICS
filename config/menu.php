<?php
/**
 * Sidebar menu AND page access control — single source of truth.
 * require_page('<key>') checks 'permission' (a key, or a list = any of them; see config/permissions.php).
 * Order matters: home_url() sends a user to the first item they can open.
 * 'tabs' => true: the link goes to the first Settings tab the user can open (settings_tabs()).
 */
declare(strict_types=1);

return [
    'pos' => [
        'label'      => 'POS Sales',
        'icon'       => 'home',
        'url'        => 'pages/pos.php',
        'permission' => 'pos.access',
    ],
    'sales-history' => [
        'label'      => 'Sales History',
        'icon'       => 'receipt',
        'url'        => 'pages/sales-history.php',
        'permission' => 'sales.view',
    ],
    'inventory' => [
        'label'      => 'Inventory',
        'icon'       => 'box',
        'url'        => 'pages/inventory.php',
        'permission' => 'inventory.view',
    ],
    'customers' => [
        'label'      => 'Customers',
        'icon'       => 'user',
        'url'        => 'pages/customers.php',
        'permission' => 'customers.view',
    ],
    'reports' => [
        'label'      => 'Reports',
        'icon'       => 'chart',
        'url'        => 'pages/reports.php',
        'permission' => 'reports.view',
    ],
    'settings' => [
        'label'      => 'Settings',
        'icon'       => 'settings',
        'url'        => 'pages/settings.php',
        'permission' => ['settings.manage', 'users.view', 'roles.manage', 'branches.manage', 'audit_logs.view'],
        'tabs'       => true,
    ],
];
