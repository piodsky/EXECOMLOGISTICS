<?php
/**
 * Serial numbers of track_serial products (product_serials).
 *
 *  - Rows are created by Receiving::post() (status in_stock at the RR location) or register()
 *    (existing stock of an item that starts tracking serials) and deleted only by Receiving::cancel().
 *    Sales mark them sold (markSold) and a void puts them back (restore); sale_item_serials keeps
 *    the history. Stock documents (InventoryDocs) move them between locations or mark them
 *    'removed' (internal use, write-off, missing at a count); inventory_doc_serials keeps that history.
 *  - Lookups are scoped to the current branch (product_serials.branch_id) and never carry cost.
 *  - Write helpers run inside the caller's transaction after the product + stock locks
 *    (lock order: products -> stock_balances -> product_branches -> product_serials).
 */
declare(strict_types=1);

final class Serials
{
    /** Allowed serial number after trim + strtoupper. */
    public const PATTERN = '/^[A-Z0-9][A-Z0-9._\/-]{0,59}$/';
    public const MAX_AVAILABLE = 500;

    /** Trim + uppercase; null when it is not a valid serial number. */
    public static function normalize(string $serial): ?string
    {
        $serial = strtoupper(trim($serial));
        return preg_match(self::PATTERN, $serial) ? $serial : null;
    }

    /**
     * In-stock serials of a product at a location (POS picker).
     * @return list<array{id:int, serial_no:string}>
     */
    public static function available(int $productId, int $locationId): array
    {
        $stmt = db()->prepare(
            'SELECT id, serial_no FROM product_serials
              WHERE product_id = ? AND location_id = ? AND status = ?
              ORDER BY serial_no
              LIMIT ' . self::MAX_AVAILABLE
        );
        $stmt->execute([$productId, $locationId, 'in_stock']);
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'serial_no' => (string) $r['serial_no']],
            $stmt->fetchAll()
        );
    }

    /**
     * An in-stock serial of an active track_serial product at a location (POS scan), or null.
     * When the same text exists on several products, the lowest product id wins.
     * @return array{product_id:int, serial_id:int, serial_no:string}|null
     */
    public static function findInStock(string $serial, int $locationId): ?array
    {
        $serial = self::normalize($serial);
        if ($serial === null) {
            return null;
        }
        $stmt = db()->prepare(
            'SELECT ps.id, ps.product_id, ps.serial_no
               FROM product_serials ps
               JOIN products p ON p.id = ps.product_id
               JOIN categories c ON c.id = p.category_id
              WHERE ps.serial_no = ? AND ps.location_id = ? AND ps.status = ?
                AND p.is_active = 1 AND p.track_serial = 1 AND c.is_active = 1
              ORDER BY ps.product_id, ps.id
              LIMIT 1'
        );
        $stmt->execute([$serial, $locationId, 'in_stock']);
        $row = $stmt->fetch();
        return $row ? ['product_id' => (int) $row['product_id'], 'serial_id' => (int) $row['id'], 'serial_no' => (string) $row['serial_no']] : null;
    }

    private static function requireView(): void
    {
        if (!Auth::can('serials.view')) {
            throw new HttpException(403, 'You do not have permission to look up serial numbers.');
        }
    }

    /**
     * Serial lookup in the current branch scope (serials.view): matching serial numbers with
     * product, status, branch/location, the RR that brought it in and its latest sale.
     * $productId narrows the list to one product ($q may then be '' = all its serials).
     */
    public static function lookup(string $q, int $limit = 50, ?int $productId = null): array
    {
        self::requireView();
        $q = strtoupper(trim($q));
        if ($q === '' && $productId === null) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        [$scope, $params] = Branch::scopeSql('ps.branch_id');
        $stmt = db()->prepare(
            "SELECT ps.id, ps.serial_no, ps.status, ps.product_id, ps.branch_id, ps.created_at, ps.updated_at,
                    p.code AS product_code, p.name AS product_name,
                    b.code AS branch_code, b.name AS branch_name, w.code AS warehouse_code, l.code AS location_code,
                    r.id AS receiving_id, r.rr_no,
                    ls.sale_id, ls.sale_no, ls.sale_status,
                    (SELECT d.id FROM inventory_doc_serials ds JOIN inventory_doc_lines dl ON dl.id = ds.line_id
                       JOIN inventory_docs d ON d.id = dl.doc_id
                      WHERE ds.serial_id = ps.id AND d.status = 'posted'
                      ORDER BY d.posted_at DESC, d.id DESC LIMIT 1) AS last_doc_id,
                    (SELECT d.doc_no FROM inventory_doc_serials ds JOIN inventory_doc_lines dl ON dl.id = ds.line_id
                       JOIN inventory_docs d ON d.id = dl.doc_id
                      WHERE ds.serial_id = ps.id AND d.status = 'posted'
                      ORDER BY d.posted_at DESC, d.id DESC LIMIT 1) AS last_doc_no
               FROM product_serials ps
               JOIN products p ON p.id = ps.product_id
               JOIN branches b ON b.id = ps.branch_id
               JOIN warehouses w ON w.id = ps.warehouse_id
               JOIN storage_locations l ON l.id = ps.location_id
               LEFT JOIN receiving_items ri ON ri.id = ps.receiving_item_id
               LEFT JOIN receiving_reports r ON r.id = ri.receiving_id
               LEFT JOIN (SELECT sis.serial_id, s.id AS sale_id, s.sale_no, s.status AS sale_status
                            FROM sale_item_serials sis
                            JOIN sale_items si ON si.id = sis.sale_item_id
                            JOIN sales s ON s.id = si.sale_id
                           WHERE sis.sale_item_id = (SELECT MAX(x.sale_item_id) FROM sale_item_serials x
                                                      WHERE x.serial_id = sis.serial_id)) ls ON ls.serial_id = ps.id
              WHERE ps.serial_no LIKE ? AND ps.product_id = COALESCE(?, ps.product_id) AND {$scope}
              ORDER BY ps.serial_no = ? DESC, ps.serial_no, ps.id
              LIMIT {$limit}"
        );
        $stmt->execute([like_pattern($q), $productId, ...$params, $q]);
        return $stmt->fetchAll();
    }

    /**
     * One serial (404 outside the branch scope) with its events: received (RR) and every sale it was on
     * (sales outside the scope are left out).
     */
    public static function history(int $serialId): array
    {
        self::requireView();
        $stmt = db()->prepare(
            'SELECT ps.*, p.code AS product_code, p.name AS product_name, b.name AS branch_name,
                    l.code AS location_code, r.id AS receiving_id, r.rr_no, r.status AS rr_status, r.posted_at
               FROM product_serials ps
               JOIN products p ON p.id = ps.product_id
               JOIN branches b ON b.id = ps.branch_id
               JOIN storage_locations l ON l.id = ps.location_id
               LEFT JOIN receiving_items ri ON ri.id = ps.receiving_item_id
               LEFT JOIN receiving_reports r ON r.id = ri.receiving_id
              WHERE ps.id = ?'
        );
        $stmt->execute([$serialId]);
        $serial = $stmt->fetch() ?: throw new HttpException(404, 'Not found.');
        Branch::assertAccess((int) $serial['branch_id']);

        [$scope, $params] = Branch::scopeSql('s.branch_id');
        $stmt = db()->prepare(
            "SELECT s.id AS sale_id, s.sale_no, s.status, s.created_at, s.voided_at, b.name AS branch_name
               FROM sale_item_serials sis
               JOIN sale_items si ON si.id = sis.sale_item_id
               JOIN sales s ON s.id = si.sale_id
               JOIN branches b ON b.id = s.branch_id
              WHERE sis.serial_id = ? AND {$scope}
              ORDER BY s.id"
        );
        $stmt->execute([$serialId, ...$params]);
        $serial['sales'] = $stmt->fetchAll();

        // Posted stock documents it was on (transfers, issues, write-offs, counts; found 0 on a count = missing).
        [$scope, $params] = Branch::scopeSql('d.branch_id');
        $stmt = db()->prepare(
            "SELECT d.id AS doc_id, d.doc_no, d.doc_type, d.purpose, d.posted_at, ds.found,
                    fw.code AS from_warehouse_code, fl.code AS from_location_code,
                    tw.code AS to_warehouse_code, tl.code AS to_location_code, b.name AS branch_name
               FROM inventory_doc_serials ds
               JOIN inventory_doc_lines dl ON dl.id = ds.line_id
               JOIN inventory_docs d ON d.id = dl.doc_id
               JOIN branches b ON b.id = d.branch_id
               JOIN warehouses fw ON fw.id = d.from_warehouse_id
               JOIN storage_locations fl ON fl.id = d.from_location_id
               LEFT JOIN warehouses tw ON tw.id = d.to_warehouse_id
               LEFT JOIN storage_locations tl ON tl.id = d.to_location_id
              WHERE ds.serial_id = ? AND d.status = 'posted' AND {$scope}
              ORDER BY d.posted_at, d.id"
        );
        $stmt->execute([$serialId, ...$params]);
        $serial['documents'] = $stmt->fetchAll();

        // Job orders it was issued to (parts): issued / used (installed) / returned.
        [$scope, $params] = Branch::scopeSql('j.branch_id');
        $stmt = db()->prepare(
            "SELECT j.id AS job_id, j.job_no, js.state, jp.issued_at, b.name AS branch_name
               FROM job_order_part_serials js
               JOIN job_order_parts jp ON jp.id = js.part_id
               JOIN job_orders j ON j.id = jp.job_order_id
               JOIN branches b ON b.id = j.branch_id
              WHERE js.serial_id = ? AND {$scope}
              ORDER BY jp.issued_at, jp.id"
        );
        $stmt->execute([$serialId, ...$params]);
        $serial['jobs'] = $stmt->fetchAll();

        // Customer orders it was delivered on (delivery receipts; cancelled = back in stock).
        [$scope, $params] = Branch::scopeSql('d.branch_id');
        $stmt = db()->prepare(
            "SELECT d.id AS delivery_id, d.dr_no, d.status, d.released_at, d.cancelled_at, o.customer_name, o.order_no
               FROM customer_delivery_serials x
               JOIN customer_delivery_lines l ON l.id = x.line_id
               JOIN customer_deliveries d ON d.id = l.delivery_id
               JOIN customer_orders o ON o.id = d.order_id
              WHERE x.serial_id = ? AND {$scope}
              ORDER BY d.released_at, d.id"
        );
        $stmt->execute([$serialId, ...$params]);
        $serial['deliveries'] = $stmt->fetchAll();
        return $serial;
    }

    // ------------------------------------------------------------------
    // Registration of existing stock (products.manage)
    // ------------------------------------------------------------------

    /**
     * Start tracking serials for an item that already has stock: one serial per unit at every location.
     * The item must not track serials yet and have no serial rows; every location holding it must be
     * in the current branch scope. No stock movement / document (quantities don't change).
     *
     * @param array<int, list<string>|string> $serialsByLocation location id => serial numbers
     *        (list, or textarea text with one per line)
     * @return int number of serials registered
     * @throws HttpException 422 with details ['errors' => ['serials.<locationId>' => message]]
     */
    public static function register(int $productId, array $serialsByLocation, int $userId): int
    {
        if (!Auth::can('products.manage')) {
            throw new HttpException(403, 'You do not have permission to register serial numbers.');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id, code, name, track_serial FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$productId]);
            $p = $stmt->fetch() ?: throw new HttpException(404, 'Product not found.');
            if ((int) $p['track_serial'] === 1) {
                throw new HttpException(409, "{$p['name']} already tracks serial numbers.");
            }
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM product_serials WHERE product_id = ?');
            $stmt->execute([$productId]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new HttpException(409, "{$p['name']} already has serial numbers on record.");
            }
            $stmt = $pdo->prepare(
                "SELECT d.doc_no FROM inventory_doc_lines dl JOIN inventory_docs d ON d.id = dl.doc_id
                  WHERE dl.product_id = ? AND d.doc_type = 'count' AND d.status IN ('open', 'submitted') LIMIT 1"
            );
            $stmt->execute([$productId]);
            if ($countNo = $stmt->fetchColumn()) {
                throw new HttpException(409, "{$p['name']} is on stock count {$countNo}. Finish or cancel that count first.");
            }
            $stmt = $pdo->prepare(
                "SELECT t.transfer_no FROM stock_transfer_lines tl JOIN stock_transfers t ON t.id = tl.transfer_id
                  WHERE tl.product_id = ? AND t.status = 'released' AND tl.qty_released > 0 LIMIT 1"
            );
            $stmt->execute([$productId]);
            if ($transferNo = $stmt->fetchColumn()) {
                throw new HttpException(409, "{$p['name']} is in transit on {$transferNo}. Receive it first.");
            }
            $stmt = $pdo->prepare(
                "SELECT j.job_no FROM job_order_parts jp JOIN job_orders j ON j.id = jp.job_order_id
                  WHERE jp.product_id = ? AND jp.status = 'issued' AND jp.qty_issued > jp.qty_used + jp.qty_returned LIMIT 1"
            );
            $stmt->execute([$productId]);
            if ($jobNo = $stmt->fetchColumn()) {
                throw new HttpException(409, "{$p['name']} is issued to job order {$jobNo}. Have it used or returned first.");
            }

            $stmt = $pdo->prepare(
                'SELECT location_id, warehouse_id, branch_id, qty FROM stock_balances WHERE product_id = ? ORDER BY location_id FOR UPDATE'
            );
            $stmt->execute([$productId]);
            $held = [];
            foreach ($stmt->fetchAll() as $b) {
                if ((int) $b['qty'] > 0) {
                    $held[(int) $b['location_id']] = $b;
                }
            }
            if (!$held) {
                throw new HttpException(422, "{$p['name']} has no stock. Turn on serial tracking in the product form instead.");
            }
            foreach ($held as $b) {
                if (!Branch::inScope((int) $b['branch_id'])) {
                    throw new HttpException(422, 'This item has stock at other branches. Switch to All branches.');
                }
            }
            $in_  = implode(',', array_fill(0, count($held), '?'));
            $stmt = $pdo->prepare(
                "SELECT l.id, w.code AS warehouse_code, l.code, b.code AS branch_code
                   FROM storage_locations l JOIN warehouses w ON w.id = l.warehouse_id JOIN branches b ON b.id = l.branch_id
                  WHERE l.id IN ({$in_})"
            );
            $stmt->execute(array_keys($held));
            $labels = [];
            foreach ($stmt->fetchAll() as $l) {
                $labels[(int) $l['id']] = $l['branch_code'] . ' ' . $l['warehouse_code'] . ' / ' . $l['code'];
            }

            $errors = [];
            foreach ($serialsByLocation as $locId => $list) {
                $hasAny = is_array($list) ? array_filter($list, static fn ($s) => is_string($s) && trim($s) !== '') : (is_string($list) && trim($list) !== '');
                if (!isset($held[(int) $locId]) && $hasAny) {
                    $errors["serials.{$locId}"] = "There is no stock of {$p['name']} at this location.";
                }
            }
            $seen  = [];
            $clean = [];
            foreach ($held as $locId => $b) {
                $raw  = $serialsByLocation[$locId] ?? $serialsByLocation[(string) $locId] ?? [];
                $list = is_array($raw) ? array_filter($raw, 'is_string') : (is_string($raw) ? preg_split('/\R/', $raw) : []);
                $list = array_values(array_filter(array_map('trim', $list ?: []), static fn ($s) => $s !== ''));
                $key  = "serials.{$locId}";
                $bad  = [];
                $clean[$locId] = [];
                foreach ($list as $s) {
                    $n = self::normalize($s);
                    if ($n === null) {
                        $bad[] = mb_substr($s, 0, 60);
                    } elseif (isset($seen[$n])) {
                        $errors[$key] ??= "Serial {$n} is entered twice.";
                    } else {
                        $seen[$n] = true;
                        $clean[$locId][] = $n;
                    }
                }
                $qty = (int) $b['qty'];
                if ($bad) {
                    $errors[$key] = 'Invalid serial number: ' . implode(', ', array_slice($bad, 0, 5))
                        . '. Use letters, numbers, dot, dash, slash or underscore (max 60).';
                } elseif (!isset($errors[$key]) && count($clean[$locId]) !== $qty) {
                    $errors[$key] = ($labels[$locId] ?? 'Location') . ": enter exactly {$qty} serial number"
                        . ($qty === 1 ? '' : 's') . ' (' . count($clean[$locId]) . ' entered).';
                }
            }
            if ($errors) {
                throw new HttpException(422, implode(' ', array_slice($errors, 0, 3)), ['errors' => $errors]);
            }

            $ins = $pdo->prepare(
                'INSERT INTO product_serials (product_id, serial_no, branch_id, warehouse_id, location_id, status, receiving_item_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $count = 0;
            $perLocation = [];
            foreach ($held as $locId => $b) {
                foreach ($clean[$locId] as $serial) {
                    $ins->execute([$productId, $serial, (int) $b['branch_id'], (int) $b['warehouse_id'], $locId, 'in_stock', null]);
                    $count++;
                }
                $perLocation[] = ($labels[$locId] ?? (string) $locId) . ' x' . count($clean[$locId]);
            }
            $pdo->prepare('UPDATE products SET track_serial = ? WHERE id = ?')->execute([1, $productId]);
            Audit::record('inventory', 'serial_register', 'product', $productId, (string) $p['code'],
                ['track_serial' => 0], ['track_serial' => 1, 'serials' => $count, 'locations' => $perLocation]);
            $pdo->commit();
            return $count;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Sales support (inside Sales::complete / Sales::void transactions)
    // ------------------------------------------------------------------

    /**
     * Lock the chosen serials (ORDER BY id FOR UPDATE) and check each one is the right product,
     * in stock, at $locationId. Call after the product and stock locks.
     *
     * @param array<int, list<int>> $serialsById product_id => serial ids
     * @return array{problems: list<array{product_id:int, serial_id:int, message:string}>} empty problems = all valid
     */
    public static function lockForSale(array $serialsById, int $locationId): array
    {
        self::assertTransaction();
        $ids = [];
        foreach ($serialsById as $list) {
            foreach ($list as $sid) {
                $ids[] = (int) $sid;
            }
        }
        $out = ['problems' => []];
        if (!$ids) {
            return $out;
        }
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare(
            "SELECT id, product_id, serial_no, status, location_id FROM product_serials
              WHERE id IN ({$in}) ORDER BY id FOR UPDATE"
        );
        $stmt->execute($ids);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[(int) $r['id']] = $r;
        }
        foreach ($serialsById as $productId => $list) {
            foreach ($list as $sid) {
                $r = $rows[(int) $sid] ?? null;
                if ($r === null || (int) $r['product_id'] !== (int) $productId) {
                    $out['problems'][] = ['product_id' => (int) $productId, 'serial_id' => (int) $sid,
                                          'message' => 'A selected serial number is no longer available.'];
                } elseif ($r['status'] !== 'in_stock' || (int) $r['location_id'] !== $locationId) {
                    $out['problems'][] = ['product_id' => (int) $productId, 'serial_id' => (int) $sid,
                                          'message' => "Serial {$r['serial_no']} is no longer available."];
                }
            }
        }
        return $out;
    }

    /**
     * Link serials (already checked by lockForSale) to a sale line and mark them sold.
     * @param list<int> $serialIds
     * @throws HttpException 409 when one is no longer in stock
     */
    public static function markSold(int $saleItemId, array $serialIds): void
    {
        self::assertTransaction();
        $pdo  = db();
        $link = $pdo->prepare('INSERT INTO sale_item_serials (sale_item_id, serial_id) VALUES (?, ?)');
        $sold = $pdo->prepare('UPDATE product_serials SET status = ? WHERE id = ? AND status = ?');
        foreach ($serialIds as $sid) {
            $sold->execute(['sold', (int) $sid, 'in_stock']);
            if ($sold->rowCount() !== 1) {
                throw new HttpException(409, 'A selected serial number is no longer available.');
            }
            $link->execute([$saleItemId, (int) $sid]);
        }
    }

    /**
     * Void: put a sale's serials back in stock at $location (ORDER BY id FOR UPDATE).
     * Call after the product and stock locks.
     * @param array{id:int, warehouse_id:int, branch_id:int} $location
     * @return list<string> serial numbers returned (for the audit log)
     * @throws HttpException 409 when one of them is not sold any more
     */
    public static function restore(int $saleId, array $location): array
    {
        self::assertTransaction();
        $pdo  = db();
        $stmt = $pdo->prepare(
            'SELECT ps.id, ps.serial_no, ps.status, p.track_serial
               FROM product_serials ps
               JOIN products p ON p.id = ps.product_id
              WHERE ps.id IN (SELECT sis.serial_id FROM sale_item_serials sis
                               JOIN sale_items si ON si.id = sis.sale_item_id
                              WHERE si.sale_id = ?)
              ORDER BY ps.id
                FOR UPDATE'
        );
        $stmt->execute([$saleId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            if ($r['status'] !== 'sold') {
                throw new HttpException(409, "Serial {$r['serial_no']} is no longer marked as sold, so this sale can't be voided.");
            }
            if ((int) $r['track_serial'] !== 1) {
                throw new HttpException(409, "Serial {$r['serial_no']}: serial tracking changed since this sale, so it can't be voided automatically. Ask an administrator.");
            }
        }
        $upd = $pdo->prepare(
            'UPDATE product_serials SET status = ?, branch_id = ?, warehouse_id = ?, location_id = ? WHERE id = ?'
        );
        foreach ($rows as $r) {
            $upd->execute(['in_stock', $location['branch_id'], $location['warehouse_id'], $location['id'], (int) $r['id']]);
        }
        return array_map(static fn (array $r): string => (string) $r['serial_no'], $rows);
    }

    /** Serial numbers per sale_items.id for a sale (receipt / sale view; no cost). @return array<int, list<string>> */
    public static function forSale(int $saleId): array
    {
        $stmt = db()->prepare(
            'SELECT sis.sale_item_id, ps.serial_no
               FROM sale_item_serials sis
               JOIN sale_items si ON si.id = sis.sale_item_id
               JOIN product_serials ps ON ps.id = sis.serial_id
              WHERE si.sale_id = ?
              ORDER BY sis.sale_item_id, ps.serial_no'
        );
        $stmt->execute([$saleId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['sale_item_id']][] = (string) $r['serial_no'];
        }
        return $out;
    }

    private static function assertTransaction(): void
    {
        if (!db()->inTransaction()) {
            throw new LogicException('Serial changes must run inside a transaction.');
        }
    }
}
