<?php
/**
 * Sidebar menu AND page access control — single source of truth.
 * require_page('<key>') checks 'permission' (a key, or a list = any of them; see config/permissions.php).
 * Order matters: home_url() sends a user to the first item they can open.
 * 'tabs' => true: the link goes to the first Settings tab the user can open (settings_tabs()).
 */
declare(strict_types=1);

return [
    // Admins (reports.view) land here; cashiers and technicians do not have it and land on POS / Job Orders.
    'dashboard' => [
        'label'      => 'Dashboard',
        'icon'       => 'grid',
        'url'        => 'pages/dashboard.php',
        'permission' => 'reports.view',
    ],
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
    // Job orders (= JobOrders::VIEW_PERMISSIONS); technicians land here (first page they can open).
    'job-orders' => [
        'label'      => 'Job Orders',
        'icon'       => 'wrench',
        'url'        => 'pages/job-orders.php',
        'permission' => ['job_orders.view', 'job_orders.create', 'job_orders.update', 'job_orders.assign', 'job_parts.issue', 'job_orders.release'],
    ],
    'inventory' => [
        'label'      => 'Inventory',
        'icon'       => 'box',
        'url'        => 'pages/inventory.php',
        'permission' => 'inventory.view',
    ],
    // Purchase requests + purchase orders to suppliers (= PurchaseRequests::VIEW_PERMISSIONS).
    'purchasing' => [
        'label'      => 'Purchasing',
        'icon'       => 'cart',
        'url'        => 'pages/purchase-requests.php',
        'permission' => ['purchasing.request', 'purchasing.approve', 'purchasing.order'],
    ],
    'receiving' => [
        'label'      => 'Receiving',
        'icon'       => 'truck',
        'url'        => 'pages/receiving.php',
        'permission' => 'receiving.view',
    ],
    // Transfers, damaged / display units, internal use, write-offs, stock counts (= InventoryDocs::VIEW_PERMISSIONS).
    'stock-docs' => [
        'label'      => 'Stock Operations',
        'icon'       => 'stock',
        'url'        => 'pages/stock-docs.php',
        'permission' => ['inventory.transfer', 'inventory.damage', 'inventory.issue', 'counts.create', 'counts.approve'],
    ],
    // Branch-to-branch transfers (= Transfers::VIEW_PERMISSIONS).
    'transfers' => [
        'label'      => 'Branch Transfers',
        'icon'       => 'network',
        'url'        => 'pages/transfers.php',
        'permission' => ['transfers.request', 'transfers.approve', 'transfers.release', 'transfers.receive'],
    ],
    'serials' => [
        'label'      => 'Serial Lookup',
        'icon'       => 'barcode',
        'url'        => 'pages/serials.php',
        'permission' => 'serials.view',
    ],
    'customers' => [
        'label'      => 'Customers',
        'icon'       => 'user',
        'url'        => 'pages/customers.php',
        'permission' => 'customers.view',
    ],
    // Lists tab (master_data.manage) or, without it, the Suppliers tab (see pages/master-data.php).
    'master-data' => [
        'label'      => 'Master Data',
        'icon'       => 'layers',
        'url'        => 'pages/master-data.php',
        'permission' => ['master_data.manage', 'suppliers.view'],
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
        'permission' => ['settings.manage', 'users.view', 'roles.manage', 'branches.manage', 'warehouses.manage', 'audit_logs.view'],
        'tabs'       => true,
    ],
];
