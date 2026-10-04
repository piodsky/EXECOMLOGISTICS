<?php
/**
 * POST /api/pos/checkout.php   (JSON, X-CSRF-Token header)
 * {
 *   "items": [{"product_id": 1, "qty": 2}, {"product_id": 13, "qty": 1, "serial_ids": [7]}, ...],
 *                                     // serial_ids: serial-tracked items only, one per unit
 *                                     // optional per item: "price": "950.00" (actual, VAT-exclusive),
 *                                     //   "reason": "..." (when lower), "approval": "<token from approve.php>"
 *   "discount_approval": "<token>" | null,
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

// --- Items: product IDs + quantities, optional actual price / reason / approval (checked in Sales::complete) ---
$items = $data['items'] ?? null;
if (!is_array($items) || $items === []) {
    throw new HttpException(422, 'The cart is empty.');
}
if (count($items) > Sales::MAX_LINES) {
    throw new HttpException(422, 'Too many lines in one sale (max ' . Sales::MAX_LINES . ').');
}

$qtyById     = [];
$serialsById = []; // product_id => product_serials ids (serial-tracked items; checked in Sales::complete)
$pricing     = []; // product_id => {price (cents) | null, reason, approval}
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

    $price = null;
    if (($item['price'] ?? null) !== null && $item['price'] !== '') {
        $p = is_string($item['price']) || is_int($item['price']) || is_float($item['price'])
            ? input_decimal(['v' => (string) $item['price']], 'v', 0, 9999999.99) : null;
        if ($p === null) {
            throw new HttpException(422, 'The cart has an invalid price.');
        }
        $price = to_cents($p);
    }
    if (isset($pricing[$id]) && $pricing[$id]['price'] !== $price) {
        throw new HttpException(422, 'The same item is in the cart twice with different prices.');
    }
    $reason   = is_string($item['reason'] ?? null) ? mb_substr($item['reason'], 0, 255) : null;
    $approval = is_string($item['approval'] ?? null) && preg_match('/^[0-9a-f]{64}$/', $item['approval']) ? $item['approval'] : null;
    $pricing[$id] = ['price' => $price, 'reason' => $reason ?? ($pricing[$id]['reason'] ?? null), 'approval' => $approval ?? ($pricing[$id]['approval'] ?? null)];

    if (array_key_exists('serial_ids', $item) && $item['serial_ids'] !== null) {
        $raw = $item['serial_ids'];
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > Sales::MAX_QTY) {
            throw new HttpException(422, 'The cart has invalid serial numbers.');
        }
        foreach ($raw as $sid) {
            $sid = is_int($sid) || is_string($sid) ? filter_var($sid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if ($sid === false) {
                throw new HttpException(422, 'The cart has invalid serial numbers.');
            }
            $serialsById[$id][] = $sid;
        }
    }
}
foreach ($serialsById as $list) {
    if (count($list) !== count(array_unique($list))) {
        throw new HttpException(422, 'The same serial number is in the cart twice.');
    }
}

// --- Payment, customer, discount ---
$paymentType = is_string($data['payment_type'] ?? null) ? $data['payment_type'] : '';
if (!array_key_exists($paymentType, Sales::PAYMENT_TYPES) && !($paymentType === 'charge' && Auth::can('sales.charge'))) {
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

$discountApproval = is_string($data['discount_approval'] ?? null) && preg_match('/^[0-9a-f]{64}$/', $data['discount_approval'])
    ? $data['discount_approval'] : null;

$sale = Sales::complete((int) Auth::id(), $qtyById, $customerId, $paymentType, (float) $discount, $paidCents, $serialsById,
    $pricing, $discountApproval);

json_response([
    'ok'           => true,
    'message'      => "Sale {$sale['sale_no']} completed.",
    'sale'         => $sale,
    'next_sale_no' => Sales::nextNumber(),
]);
