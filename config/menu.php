<?php
/**
 * Sidebar menu AND page access control — single source of truth.
 * require_page('<key>') checks 'permission' (a key, or a list = any of them; see config/permissions.php).
 * 'group' = sidebar section (MENU_GROUPS in includes/sidebar.php; the Buying / Selling headings link to their Overview).
 * 'home'  = landing priority: home_path() sends a user to the openable item with the lowest 'home' (default 100,
 *           then menu order): admins → Dashboard, cashiers → POS, technicians → Job Orders.
 * 'show'  = optional extra permission to SHOW the item in the sidebar (the page still opens with 'permission').
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
        'group'      => 'overview',
        'home'       => 1,
    ],

    // ---- Selling: POS, customer orders, receivables, customers
    'pos' => [
        'label'      => 'POS Sales',
        'icon'       => 'home',
        'url'        => 'pages/pos.php',
        'permission' => 'pos.access',
        'group'      => 'sell',
        'home'       => 2,
    ],
    'sales-history' => [
        'label'      => 'Sales History',
        'icon'       => 'receipt',
        'url'        => 'pages/sales-history.php',
        'permission' => 'sales.view',
        'group'      => 'sell',
    ],
    // Quotations, customer purchase orders (PO Outgoing), deliveries, order tracking (= CustomerOrders::VIEW_PERMISSIONS).
    'customer-orders' => [
        'label'      => 'Customer Orders',
        'icon'       => 'file',
        'url'        => 'pages/customer-orders.php',
        'permission' => ['customer_orders.manage', 'customer_orders.approve', 'customer_orders.deliver', 'customer_orders.bill'],
        'group'      => 'sell',
    ],
    'collections' => [
        'label'      => 'Billing & Collections',
        'icon'       => 'wallet',
        'url'        => 'pages/collections.php',
        'permission' => ['collections.manage', 'collections.cancel'],
        'group'      => 'sell',
    ],
    'customers' => [
        'label'      => 'Customers',
        'icon'       => 'user',
        'url'        => 'pages/customers.php',
        'permission' => 'customers.view',
        'group'      => 'sell',
    ],

    // ---- Service. Job orders (= JobOrders::VIEW_PERMISSIONS); technicians land here.
    'job-orders' => [
        'label'      => 'Job Orders',
        'icon'       => 'wrench',
        'url'        => 'pages/job-orders.php',
        'permission' => ['job_orders.view', 'job_orders.create', 'job_orders.update', 'job_orders.assign', 'job_parts.issue', 'job_orders.release'],
        'group'      => 'service',
        'home'       => 3,
    ],

    // ---- Buying: purchase requests + PO Internal, receiving, payables, suppliers
    'purchasing' => [
        'label'      => 'Purchasing',
        'icon'       => 'cart',
        'url'        => 'pages/purchase-requests.php',
        'permission' => ['purchasing.request', 'purchasing.approve', 'purchasing.order'],
        'group'      => 'buy',
    ],
    'receiving' => [
        'label'      => 'Receiving',
        'icon'       => 'truck',
        'url'        => 'pages/receiving.php',
        'permission' => 'receiving.view',
        'group'      => 'buy',
    ],
    'payables' => [
        'label'      => 'Payables',
        'icon'       => 'clipboard',
        'url'        => 'pages/payables.php',
        'permission' => ['payables.manage', 'payables.cancel'],
        'group'      => 'buy',
    ],
    'suppliers' => [
        'label'      => 'Suppliers',
        'icon'       => 'store',
        'url'        => 'pages/suppliers.php',
        'permission' => 'suppliers.view',
        'group'      => 'buy',
    ],

    // ---- Stock
    'inventory' => [
        'label'      => 'Inventory',
        'icon'       => 'box',
        'url'        => 'pages/inventory.php',
        'permission' => 'inventory.view',
        'group'      => 'stock',
    ],
    // Transfers, damaged / display units, internal use, write-offs, stock counts (= InventoryDocs::VIEW_PERMISSIONS).
    'stock-docs' => [
        'label'      => 'Stock Operations',
        'icon'       => 'stock',
        'url'        => 'pages/stock-docs.php',
        'permission' => ['inventory.transfer', 'inventory.damage', 'inventory.issue', 'counts.create', 'counts.approve'],
        'group'      => 'stock',
    ],
    // Branch-to-branch transfers (= Transfers::VIEW_PERMISSIONS).
    'transfers' => [
        'label'      => 'Branch Transfers',
        'icon'       => 'network',
        'url'        => 'pages/transfers.php',
        'permission' => ['transfers.request', 'transfers.approve', 'transfers.release', 'transfers.receive'],
        'group'      => 'stock',
    ],
    'serials' => [
        'label'      => 'Serial Lookup',
        'icon'       => 'barcode',
        'url'        => 'pages/serials.php',
        'permission' => 'serials.view',
        'group'      => 'stock',
    ],

    // ---- Admin
    'reports' => [
        'label'      => 'Reports',
        'icon'       => 'chart',
        'url'        => 'pages/reports.php',
        'permission' => 'reports.view',
        'group'      => 'admin',
    ],
    // Lists tab (master_data.manage) or, without it, the Suppliers tab (see pages/master-data.php). In the sidebar only
    // with master_data.manage: suppliers-only users have the Suppliers item.
    'master-data' => [
        'label'      => 'Master Data',
        'icon'       => 'layers',
        'url'        => 'pages/master-data.php',
        'permission' => ['master_data.manage', 'suppliers.view'],
        'show'       => 'master_data.manage',
        'group'      => 'admin',
    ],
    'settings' => [
        'label'      => 'Settings',
        'icon'       => 'settings',
        'url'        => 'pages/settings.php',
        'permission' => ['settings.manage', 'users.view', 'roles.manage', 'branches.manage', 'warehouses.manage', 'audit_logs.view'],
        'tabs'       => true,
        'group'      => 'admin',
    ],
];
