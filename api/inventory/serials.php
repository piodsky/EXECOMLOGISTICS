<?php
/**
 * GET /api/inventory/serials.php?product_id=N&location_id=M
 * Stock of a product at one storage location of the current branch, for the stock document form
 * (transfer / issue / write-off): {ok, qty, track_serial, serials: [{id, serial_no}, ...]}.
 * serials = in-stock serial numbers there (only for serial-tracked products). Never returns cost.
 * Needs one of the stock-operation permissions; "All branches" -> 422 "Choose a branch first.";
 * a location of another branch (or an inactive one) -> 404.
 */
declare(strict_types=1);

require __DIR__ . '/../../system/bootstrap.php';
api_guard('GET', InventoryDocs::VIEW_PERMISSIONS);

$branchId   = Branch::forWrite();
$productId  = input_int($_GET, 'product_id', 1) ?? throw new HttpException(422, 'Choose a product.');
$locationId = input_int($_GET, 'location_id', 1) ?? throw new HttpException(422, 'Choose a location.');

// Only the active locations of the working branch (same list as the form's pickers).
$usable = array_column(Warehouses::pickerLocations($branchId), 'id');
if (!in_array($locationId, $usable, true)) {
    throw new HttpException(404, 'Location not found.');
}

$stmt = db()->prepare('SELECT id, track_serial FROM products WHERE id = ? AND is_active = ?');
$stmt->execute([$productId, 1]);
$product = $stmt->fetch() ?: throw new HttpException(404, 'Item not found.');

$stmt = db()->prepare('SELECT qty FROM stock_balances WHERE product_id = ? AND location_id = ?');
$stmt->execute([$productId, $locationId]);
$qty = (int) ($stmt->fetchColumn() ?: 0);

$track = (int) $product['track_serial'] === 1;
json_response([
    'ok'           => true,
    'qty'          => $qty,
    'track_serial' => $track,
    'serials'      => $track ? Serials::available($productId, $locationId) : [],
]);
