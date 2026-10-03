<?php
/**
 * GET /api/pos/serials.php
 * In-stock serial numbers at the current branch's default sellable location (where the POS sells from).
 *   ?product_id=N  -> {ok, serials: [{id, serial_no}, ...]}        (active serial-tracked product, else 404)
 *   ?serial=ABC    -> {ok, match: {product_id, serial_id, serial_no}} (exact scan, else 404)
 * Never returns cost. "All branches" -> 422 "Choose a branch first."
 */
declare(strict_types=1);

require __DIR__ . '/../../system/bootstrap.php';
api_guard('GET', 'pos.access');

$location = Branch::defaultLocation(Branch::forWrite());

if (array_key_exists('serial', $_GET)) {
    $raw    = input_string($_GET, 'serial', 100);
    $serial = Serials::normalize($raw);
    $match  = $serial === null ? null : Serials::findInStock($serial, $location['id']);
    if ($match === null) {
        throw new HttpException(404, $serial === null
            ? 'That is not a valid serial number.'
            : "No item with serial {$serial} at this branch.");
    }
    json_response(['ok' => true, 'match' => $match]);
}

$productId = input_int($_GET, 'product_id', 1);
if ($productId === null) {
    throw new HttpException(422, 'Choose a product.');
}

// Same visibility as the POS grid: active product in an active category, serial-tracked.
$stmt = db()->prepare(
    'SELECT p.id FROM products p JOIN categories c ON c.id = p.category_id
      WHERE p.id = ? AND p.is_active = ? AND c.is_active = ? AND p.track_serial = ?'
);
$stmt->execute([$productId, 1, 1, 1]);
if ($stmt->fetchColumn() === false) {
    throw new HttpException(404, 'Item not found.');
}

json_response(['ok' => true, 'serials' => Serials::available($productId, $location['id'])]);
