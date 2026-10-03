<?php
/**
 * Permission registry: key => [module, label].
 * Must match the `permissions` table seed (database.sql / migrations/003-008). Roles get permissions
 * through `role_permissions`; a role with roles.is_super = 1 holds every key (also future ones).
 * Check with Auth::can('key'); an unknown key is a programming error.
 */
declare(strict_types=1);

return [
    'pos.access'          => ['POS',       'Open the POS and complete sales'],
    'pos.change_price'    => ['POS',       'Change the selling price within the role limit (reason when lower)'],
    'pos.discount'        => ['POS',       'Give a sale discount within the role limit'],
    'pos.price_override'  => ['POS',       'Approve prices / discounts beyond the limits or below cost'],
    'pos.view_cost'       => ['POS',       'Show unit cost and margin on the POS (toggle)'],
    'sales.view'          => ['Sales',     'View sales history and receipts'],
    'sales.cancel'        => ['Sales',     'Void sales'],
    'customers.view'      => ['Customers', 'View customers'],
    'customers.edit'      => ['Customers', 'Add and edit customers'],
    'customers.delete'    => ['Customers', 'Deactivate and delete customers'],
    'inventory.view'      => ['Inventory', 'View products and stock (read-only)'],
    'inventory.adjust'    => ['Inventory', 'Adjust stock'],
    'receiving.view'      => ['Receiving', 'View receiving reports'],
    'receiving.manage'    => ['Receiving', 'Create and edit receiving drafts'],
    'receiving.post'      => ['Receiving', 'Post receiving reports - adds stock and sets cost'],
    'receiving.cancel'    => ['Receiving', 'Cancel posted receiving reports'],
    'serials.view'        => ['Inventory', 'Look up serial numbers'],
    'inventory.integrity' => ['Inventory', 'Run stock integrity checks'],
    'inventory.transfer'  => ['Inventory', 'Move stock between locations of a branch'],
    'inventory.damage'    => ['Inventory', 'Mark stock damaged and write off damaged stock'],
    'products.manage'     => ['Inventory', 'Add, edit, deactivate and delete products'],
    'inventory.issue'     => ['Inventory', 'Issue stock for internal use and display units'],
    'counts.create'       => ['Inventory', 'Create stock counts and enter counted quantities'],
    'counts.approve'      => ['Inventory', 'Approve or cancel stock counts'],
    'products.cost'       => ['Inventory', 'See and edit unit cost'],
    'master_data.manage'  => ['Master Data', 'Manage categories, brands, models, units, customer types and service lists'],
    'suppliers.view'      => ['Suppliers', 'View suppliers'],
    'suppliers.manage'    => ['Suppliers', 'Add, edit and deactivate suppliers'],
    'reports.view'        => ['Reports',   'View reports and export CSV'],
    'transfers.request'   => ['Transfers', 'Request stock from another branch'],
    'transfers.approve'   => ['Transfers', "Approve or cancel requests for this branch's stock"],
    'transfers.release'   => ['Transfers', 'Release approved transfers (stock leaves the branch)'],
    'transfers.receive'   => ['Transfers', 'Receive incoming transfers (stock enters the branch)'],
    'users.view'          => ['Users',     'View users'],
    'users.manage'        => ['Users',     'Add and edit users, reset passwords, activate/deactivate'],
    'users.delete'        => ['Users',     'Delete users'],
    'roles.manage'        => ['Roles',     'Manage roles and permissions'],
    'branches.manage'     => ['Branches',  'Manage branch details'],
    'warehouses.manage'   => ['Branches',  'Manage warehouses and storage locations'],
    'branches.access_all' => ['Branches',  'Access all branches (view and switch)'],
    'settings.manage'     => ['Settings',  'Manage company settings'],
    'audit_logs.view'     => ['Audit Log', 'View the audit log'],
];
