<?php
/**
 * POST /api/pos/checkout.php   (JSON, X-CSRF-Token header)
 * {
 *   "items": [{"product_id": 1, "qty": 2}, ...],
 *   "customer_id": 3 | null,          // null = walk-in
 *   "payment_type": "cash"|"gcash"|"card",
 *   "discount_percent": "10",
 *   "amount_paid": "500.00"           // cash only
 * }
 */
declare(strict_types=1);

require __DIR__ . '/../../system/bootstrap.php';
api_guard('POST', 'pos.access');

$data = request_json();

// --- Items: product IDs + quantities only (prices come from the database) ---
$items = $data['items'] ?? null;
if (!is_array($items) || $items === []) {
    throw new HttpException(422, 'The cart is empty.');
}
if (count($items) > Sales::MAX_LINES) {
    throw new HttpException(422, 'Too many lines in one sale (max ' . Sales::MAX_LINES . ').');
}

$qtyById = [];
foreach ($items as $item) {
    $id  = is_array($item) ? input_int($item, 'product_id', 1) : null;
    $qty = is_array($item) ? input_int($item, 'qty', 1, Sales::MAX_QTY) : null;
    if ($id === null || $qty === null) {
        throw new HttpException(422, 'The cart has an invalid item or quantity.');
    }
    $qtyById[$id] = ($qtyById[$id] ?? 0) + $qty;
    if ($qtyById[$id] > Sales::MAX_QTY) {
        throw new HttpException(422, 'Quantity is too large (max ' . Sales::MAX_QTY . ' per item).');
    }
}

// --- Payment, customer, discount ---
$paymentType = is_string($data['payment_type'] ?? null) ? $data['payment_type'] : '';
if (!array_key_exists($paymentType, Sales::PAYMENT_TYPES)) {
    throw new HttpException(422, 'Choose a valid payment type.');
}

$customerId = null;
if (($data['customer_id'] ?? null) !== null && $data['customer_id'] !== '') {
    $customerId = input_int($data, 'customer_id', 1);
    if ($customerId === null) {
        throw new HttpException(422, 'Choose a valid customer.');
    }
}

$discount = input_decimal(['v' => $data['discount_percent'] ?? '0'], 'v', 0, 100);
if ($discount === null) {
    throw new HttpException(422, 'Discount must be between 0 and 100%.');
}

$paidCents = null;
if ($paymentType === 'cash') {
    $paid = input_decimal($data, 'amount_paid', 0, 9999999.99);
    if ($paid === null) {
        throw new HttpException(422, 'Enter the amount received.');
    }
    $paidCents = to_cents($paid);
}

$sale = Sales::complete((int) Auth::id(), $qtyById, $customerId, $paymentType, $discount, $paidCents);

json_response([
    'ok'           => true,
    'message'      => "Sale {$sale['sale_no']} completed.",
    'sale'         => $sale,
    'next_sale_no' => Sales::nextNumber(),
]);
