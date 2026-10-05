<?php
/**
 * Master data registry (Phase 6): the simple lists managed on pages/master-data.php?list=<key>.
 * key => [
 *   label, singular, icon (sprite id), hint (one line under the tabs),
 *   table      : DB table (code literal, never from input),
 *   list       : lookups.list value (only for table 'lookups'),
 *   fields     : editable fields besides name/is_active: 'code' | 'brand_id' | 'icon' | 'sort_order',
 *   name_max   : max length of the name column,
 *   permission : who may manage it.
 * Order = tab order. Usage checks (delete only if unused) live in MasterData::USAGE.
 */
declare(strict_types=1);

return [
    'categories' => [
        'label' => 'Categories', 'singular' => 'Category', 'icon' => 'grid', 'table' => 'categories',
        'fields' => ['icon', 'sort_order'], 'name_max' => 50, 'permission' => 'master_data.manage',
        'hint' => 'Product categories for the POS category bar and reports. The icon shows on POS cards without an image; deactivating a category hides its products from the POS.',
    ],
    'brands' => [
        'label' => 'Brands', 'singular' => 'Brand', 'icon' => 'tag', 'table' => 'brands',
        'fields' => [], 'name_max' => 80, 'permission' => 'master_data.manage',
        'hint' => 'Product brands (e.g. Lenovo, HP, TP-Link).',
    ],
    'models' => [
        'label' => 'Models', 'singular' => 'Model', 'icon' => 'laptop', 'table' => 'product_models',
        'fields' => ['brand_id'], 'name_max' => 80, 'permission' => 'master_data.manage',
        'hint' => 'Product models, each under one brand.',
    ],
    'units' => [
        'label' => 'Units', 'singular' => 'Unit', 'icon' => 'box', 'table' => 'units',
        'fields' => ['code', 'sort_order'], 'name_max' => 40, 'permission' => 'master_data.manage',
        'hint' => 'Units of measure (PC = Piece is the default for new products).',
    ],
    'customer-types' => [
        'label' => 'Customer Types', 'singular' => 'Customer Type', 'icon' => 'user', 'table' => 'customer_types',
        'fields' => ['sort_order'], 'name_max' => 60, 'permission' => 'master_data.manage',
        'hint' => 'Customer groups (e.g. Government, Reseller), chosen on the customer form.',
    ],
    'device-types' => [
        'label' => 'Device Types', 'singular' => 'Device Type', 'icon' => 'laptop', 'table' => 'lookups', 'list' => 'device_type',
        'fields' => ['sort_order'], 'name_max' => 80, 'permission' => 'master_data.manage',
        'hint' => 'Devices customers bring in for service (used by job orders later).',
    ],
    'job-types' => [
        'label' => 'Job Types', 'singular' => 'Job Type', 'icon' => 'clipboard', 'table' => 'lookups', 'list' => 'job_type',
        'fields' => ['sort_order'], 'name_max' => 80, 'permission' => 'master_data.manage',
        'hint' => 'Kinds of service work (used by job orders later).',
    ],
    // Job order intake checklists (the ticked names are saved as text on the job, so entries can be renamed or
    // deactivated freely; old jobs keep their text).
    'accessories' => [
        'label' => 'Accessories', 'singular' => 'Accessory', 'icon' => 'plug', 'table' => 'lookups', 'list' => 'accessory',
        'fields' => ['sort_order'], 'name_max' => 80, 'permission' => 'master_data.manage',
        'hint' => 'Checkboxes for "Accessories left with the device" on the job order intake.',
    ],
    'conditions' => [
        'label' => 'Device Conditions', 'singular' => 'Device Condition', 'icon' => 'eye', 'table' => 'lookups', 'list' => 'device_condition',
        'fields' => ['sort_order'], 'name_max' => 80, 'permission' => 'master_data.manage',
        'hint' => 'Checkboxes for "Condition on arrival" on the job order intake.',
    ],
    'problems' => [
        'label' => 'Common Problems', 'singular' => 'Common Problem', 'icon' => 'alert', 'table' => 'lookups', 'list' => 'problem',
        'fields' => ['sort_order'], 'name_max' => 80, 'permission' => 'master_data.manage',
        'hint' => 'Quick picks above "Problem reported" on the job order intake (each tick adds a line to the text).',
    ],
    'service-categories' => [
        'label' => 'Service Categories', 'singular' => 'Service Category', 'icon' => 'settings', 'table' => 'lookups', 'list' => 'service_category',
        'fields' => ['sort_order'], 'name_max' => 80, 'permission' => 'master_data.manage',
        'hint' => 'Service categories (used by job orders later).',
    ],
    'warranty-types' => [
        'label' => 'Warranty Types', 'singular' => 'Warranty Type', 'icon' => 'shield', 'table' => 'lookups', 'list' => 'warranty_type',
        'fields' => ['sort_order'], 'name_max' => 80, 'permission' => 'master_data.manage',
        'hint' => 'Warranty kinds (used by serials and job orders later).',
    ],
];
