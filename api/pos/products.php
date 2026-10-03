<?php
/**
 * GET /api/pos/products.php
 * All sellable products with the stock available at the current branch (its default sellable
 * location, where the POS sells from), for the POS grid (filtered client-side).
 * "All branches" -> 422 "Choose a branch first."
 * cost_cents (branch average) only with pos.view_cost; 'pricing' = the user's POS limits (Pricing::limits()).
 */
declare(strict_types=1);

require __DIR__ . '/../../system/bootstrap.php';
api_guard('GET', 'pos.access');

$branchId = Branch::forWrite();
$location = Branch::defaultLocation($branchId);
$viewCost = Auth::can('pos.view_cost');

$stmt = db()->prepare(
    'SELECT p.id, p.category_id, p.code, p.barcode, p.name, p.price, COALESCE(sb.qty, 0) AS stock, p.reorder_level, p.image, p.track_serial,
            c.icon AS category_icon, COALESCE(pb.avg_cost, p.unit_cost) AS cost
       FROM products p
       JOIN categories c ON c.id = p.category_id
       LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.location_id = ?
       LEFT JOIN product_branches pb ON pb.product_id = p.id AND pb.branch_id = ?
      WHERE p.is_active = ? AND c.is_active = ?
      ORDER BY p.code'
);
$stmt->execute([$location['id'], $branchId, 1, 1]);

$products = array_map(static fn (array $p): array => [
    'id'            => (int) $p['id'],
    'category_id'   => (int) $p['category_id'],
    'code'          => $p['code'],
    'barcode'       => $p['barcode'],
    'name'          => $p['name'],
    'price_cents'   => to_cents($p['price']),
    'stock'         => (int) $p['stock'],
    'reorder_level' => (int) $p['reorder_level'],
    'category_icon' => $p['category_icon'],
    'image_url'     => ImageUpload::url($p['image']),
    'track_serial'  => (int) $p['track_serial'] === 1, // POS picks serials via api/pos/serials.php
] + ($viewCost ? ['cost_cents' => (int) round((float) $p['cost'] * 100)] : []), $stmt->fetchAll());

json_response([
    'ok'           => true,
    'products'     => $products,
    'next_sale_no' => Sales::nextNumber(),
    'vat_rate'     => (float) setting('vat_rate', '12'),
    'branch'       => ['id' => $location['branch_id'], 'name' => $location['branch_name']],
    'pricing'      => Pricing::limits() + ['view_cost' => $viewCost],
]);
