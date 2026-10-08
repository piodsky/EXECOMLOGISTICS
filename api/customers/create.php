<?php
/**
 * POST /api/customers/create.php   (JSON, X-CSRF-Token header)
 * {"name", "phone", "email", "address"?, "customer_type_id"?, "tin"?}: quick-add from the POS and from the
 * customer selects of the quotation, customer PO and job order forms (customers.edit, working branch).
 */
declare(strict_types=1);

require __DIR__ . '/../../system/bootstrap.php';
api_guard('POST', 'customers.edit');

$customer = Customers::validate(request_json());
$id = Customers::create($customer);

$typeName = null;
if ($customer['customer_type_id'] !== null) {
    $stmt = db()->prepare('SELECT name FROM customer_types WHERE id = ?');
    $stmt->execute([$customer['customer_type_id']]);
    $typeName = $stmt->fetchColumn() ?: null;
}

json_response([
    'ok'       => true,
    'message'  => "Customer {$customer['name']} added.",
    'customer' => ['id' => $id, 'name' => $customer['name'], 'phone' => $customer['phone'],
                   'address' => $customer['address'], 'type_name' => $typeName],
], 201);
