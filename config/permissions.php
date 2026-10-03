<?php
/**
 * Permission registry: key => [module, label].
 * Must match the `permissions` table seed (database.sql / migrations/003, 004). Roles get permissions
 * through `role_permissions`; a role with roles.is_super = 1 holds every key (also future ones).
 * Check with Auth::can('key'); an unknown key is a programming error.
 */
declare(strict_types=1);

return [
    'pos.access'          => ['POS',       'Open the POS and complete sales'],
    'sales.view'          => ['Sales',     'View sales history and receipts'],
    'sales.cancel'        => ['Sales',     'Void sales'],
    'customers.view'      => ['Customers', 'View customers'],
    'customers.edit'      => ['Customers', 'Add and edit customers'],
    'customers.delete'    => ['Customers', 'Deactivate and delete customers'],
    'inventory.view'      => ['Inventory', 'View products and stock (read-only)'],
    'inventory.adjust'    => ['Inventory', 'Adjust stock'],
    'products.manage'     => ['Inventory', 'Add, edit, deactivate and delete products'],
    'products.cost'       => ['Inventory', 'See and edit unit cost'],
    'master_data.manage'  => ['Master Data', 'Manage categories, brands, models, units, customer types and service lists'],
    'suppliers.view'      => ['Suppliers', 'View suppliers'],
    'suppliers.manage'    => ['Suppliers', 'Add, edit and deactivate suppliers'],
    'reports.view'        => ['Reports',   'View reports and export CSV'],
    'users.view'          => ['Users',     'View users'],
    'users.manage'        => ['Users',     'Add and edit users, reset passwords, activate/deactivate'],
    'users.delete'        => ['Users',     'Delete users'],
    'roles.manage'        => ['Roles',     'Manage roles and permissions'],
    'branches.manage'     => ['Branches',  'Manage branch details'],
    'branches.access_all' => ['Branches',  'Access all branches (view and switch)'],
    'settings.manage'     => ['Settings',  'Manage company settings'],
    'audit_logs.view'     => ['Audit Log', 'View the audit log'],
];
