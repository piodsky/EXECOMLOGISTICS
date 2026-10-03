<?php
/**
 * Serial numbers of track_serial products (product_serials).
 *
 *  - Rows are created only by Receiving::post() (status in_stock at the RR location) and
 *    deleted only by Receiving::cancel(). Sales mark them sold (markSold) and a void puts
 *    them back (restore); sale_item_serials keeps the history.
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
                    ls.sale_id, ls.sale_no, ls.sale_status
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
        return $serial;
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
