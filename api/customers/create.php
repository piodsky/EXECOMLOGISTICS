<?php
/**
 * POST /api/customers/create.php   (JSON, X-CSRF-Token header)
 * {"name": "...", "phone": "...", "email": "..."}  — quick-add from the POS.
 */
declare(strict_types=1);

require __DIR__ . '/../../system/bootstrap.php';
api_guard('POST', 'customers.edit');

$customer = Customers::validate(request_json());
$id = Customers::create($customer);

json_response([
    'ok'       => true,
    'message'  => "Customer {$customer['name']} added.",
    'customer' => ['id' => $id, 'name' => $customer['name'], 'phone' => $customer['phone']],
], 201);
