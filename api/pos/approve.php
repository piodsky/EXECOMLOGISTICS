<?php
/**
 * POST /api/pos/approve.php   (JSON, X-CSRF-Token header) — an admin approves at the cashier's till.
 * {
 *   "username": "...", "password": "...",              // the approver (pos.price_override, works at this branch)
 *   "lines": [{"product_id": 1, "price": "950.00"}],    // line prices to approve (exact)
 *   "discount_percent": "15" | null                     // sale discount to approve (exact)
 * }
 * -> {"ok": true, "approver": "Name", "lines": {"1": "<token>"}, "discount": "<token>" | null}
 * Tokens: one use, this cashier + branch only, Pricing::APPROVAL_MINUTES minutes. Never logged.
 */
declare(strict_types=1);

require __DIR__ . '/../../system/bootstrap.php';
api_guard('POST', 'pos.access');

$data     = request_json();
$username = is_string($data['username'] ?? null) ? mb_substr(trim($data['username']), 0, 50) : '';
$password = is_string($data['password'] ?? null) ? $data['password'] : '';
if ($username === '' || $password === '' || strlen($password) > 200) {
    throw new HttpException(422, 'Enter the approver\'s username and password.');
}

$lines = [];
$raw   = $data['lines'] ?? [];
if (!is_array($raw) || !array_is_list($raw) || count($raw) > Sales::MAX_LINES) {
    throw new HttpException(422, 'Invalid items to approve.');
}
foreach ($raw as $line) {
    $pid   = is_array($line) ? input_int($line, 'product_id', 1) : null;
    $price = is_array($line) ? input_decimal(['v' => is_scalar($line['price'] ?? null) ? (string) $line['price'] : ''], 'v', 0, 9999999.99) : null;
    if ($pid === null || $price === null) {
        throw new HttpException(422, 'Invalid items to approve.');
    }
    $lines[] = [$pid, to_cents($price)];
}

$discount = null;
if (($data['discount_percent'] ?? null) !== null && $data['discount_percent'] !== '') {
    $d = input_decimal(['v' => is_scalar($data['discount_percent']) ? (string) $data['discount_percent'] : ''], 'v', 0, 100);
    if ($d === null) {
        throw new HttpException(422, 'Discount must be between 0 and 100%.');
    }
    $discount = number_format($d, 2, '.', '');
}

$res = Pricing::approve($username, $password, $lines, $discount);
json_response(['ok' => true, 'message' => "Approved by {$res['approver']}.", ...$res]);
