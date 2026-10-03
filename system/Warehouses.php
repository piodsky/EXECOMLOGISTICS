<?php
/**
 * Warehouses and storage locations of a branch (warehouses.manage), audit module 'warehouses'.
 *
 *  - Every warehouse has GENERAL (kind stock, default of the warehouse) + DAMAGED (kind damaged) +
 *    DISPLAY (kind display), created by createLocations() (also used by Branches::create).
 *    DAMAGED / DISPLAY are "system" locations: never sellable/default (CHECKs), rename only.
 *  - Extra locations (bins) are kind 'stock', not sellable: the POS only sells from the branch's
 *    default location of its default warehouse (Branch::defaultLocation).
 *  - Codes are uppercase, unique per branch (warehouses) / per warehouse (locations), immutable.
 *  - Deactivating needs zero stock there and no open/submitted stock count; never the default
 *    warehouse, the default location or a system location.
 *  - Lock order (InventoryDocs uses the same): storage_locations rows -> warehouses row.
 */
declare(strict_types=1);

final class Warehouses
{
    public const KINDS = ['stock' => 'Stock', 'damaged' => 'Damaged', 'display' => 'Display / Demo'];

    /** Reserved location codes => kind (one each per warehouse). */
    public const SYSTEM_CODES = ['DAMAGED' => 'damaged', 'DISPLAY' => 'display'];

    public const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9_-]{0,19}$/';

    private static function requireManage(): void
    {
        if (!Auth::can('warehouses.manage')) {
            throw new HttpException(403, 'You do not have permission to manage warehouses.');
        }
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /**
     * Warehouses in the current branch scope, each with 'locations' (qty = units there, items = products
     * with stock there, open_counts = open/submitted counts there). warehouses.manage.
     */
    public static function list(): array
    {
        self::requireManage();
        [$scope, $params] = Branch::scopeSql('w.branch_id');
        $stmt = db()->prepare(
            "SELECT w.id, w.branch_id, w.code, w.name, w.is_default, w.is_active, b.code AS branch_code, b.name AS branch_name
               FROM warehouses w JOIN branches b ON b.id = w.branch_id
              WHERE {$scope}
              ORDER BY b.is_main DESC, b.name, w.is_default DESC, w.code"
        );
        $stmt->execute($params);
        $warehouses = [];
        foreach ($stmt->fetchAll() as $w) {
            $w['locations'] = [];
            $warehouses[(int) $w['id']] = $w;
        }

        [$scope, $params] = Branch::scopeSql('l.branch_id');
        $stmt = db()->prepare(
            "SELECT l.id, l.warehouse_id, l.branch_id, l.code, l.name, l.kind, l.is_sellable, l.is_default, l.is_active,
                    COALESCE((SELECT SUM(sb.qty) FROM stock_balances sb WHERE sb.location_id = l.id), 0) AS qty,
                    (SELECT COUNT(*) FROM stock_balances sb WHERE sb.location_id = l.id AND sb.qty > 0) AS items,
                    (SELECT COUNT(*) FROM inventory_docs d WHERE d.from_location_id = l.id AND d.doc_type = 'count'
                        AND d.status IN ('open', 'submitted')) AS open_counts
               FROM storage_locations l
              WHERE {$scope}
              ORDER BY l.warehouse_id, l.is_default DESC, l.kind = 'stock' DESC, l.kind, l.code"
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $l) {
            $l['is_system'] = isset(self::SYSTEM_CODES[$l['code']]);
            if (isset($warehouses[(int) $l['warehouse_id']])) {
                $warehouses[(int) $l['warehouse_id']]['locations'][] = $l;
            }
        }
        return array_values($warehouses);
    }

    /** Warehouse row or null; 404 outside the branch scope. */
    public static function findWarehouse(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT w.*, b.code AS branch_code, b.name AS branch_name FROM warehouses w JOIN branches b ON b.id = w.branch_id WHERE w.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        Branch::assertAccess((int) $row['branch_id']);
        return $row;
    }

    /** Location row (+ warehouse code/name/is_active, is_system) or null; 404 outside the branch scope. */
    public static function findLocation(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT l.*, w.code AS warehouse_code, w.name AS warehouse_name, w.is_active AS warehouse_active,
                    w.is_default AS warehouse_default, b.name AS branch_name
               FROM storage_locations l
               JOIN warehouses w ON w.id = l.warehouse_id
               JOIN branches b ON b.id = l.branch_id
              WHERE l.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        Branch::assertAccess((int) $row['branch_id']);
        $row['is_system'] = isset(self::SYSTEM_CODES[$row['code']]);
        return $row;
    }

    /**
     * Active locations in active warehouses of a branch (pickers for stock documents / counts).
     * $kinds limits the kinds (null = all). No cost, no permission beyond branch access.
     * @return list<array{id:int, warehouse_id:int, warehouse_code:string, warehouse_name:string, code:string,
     *                    name:string, kind:string, is_default:int, label:string}>
     */
    public static function pickerLocations(int $branchId, ?array $kinds = null): array
    {
        Branch::assertAccess($branchId);
        $stmt = db()->prepare(
            'SELECT l.id, l.warehouse_id, w.code AS warehouse_code, w.name AS warehouse_name, l.code, l.name, l.kind,
                    l.is_default, w.is_default AS warehouse_default
               FROM storage_locations l JOIN warehouses w ON w.id = l.warehouse_id
              WHERE l.branch_id = ? AND l.is_active = 1 AND w.is_active = 1
              ORDER BY w.is_default DESC, w.code, l.is_default DESC, l.kind = \'stock\' DESC, l.kind, l.code'
        );
        $stmt->execute([$branchId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            if ($kinds !== null && !in_array($r['kind'], $kinds, true)) {
                continue;
            }
            $out[] = [
                'id'             => (int) $r['id'],
                'warehouse_id'   => (int) $r['warehouse_id'],
                'warehouse_code' => (string) $r['warehouse_code'],
                'warehouse_name' => (string) $r['warehouse_name'],
                'code'           => (string) $r['code'],
                'name'           => (string) $r['name'],
                'kind'           => (string) $r['kind'],
                'is_default'     => (int) $r['is_default'] === 1 && (int) $r['warehouse_default'] === 1 ? 1 : 0,
                'label'          => $r['warehouse_code'] . ' / ' . $r['code'] . ' - ' . $r['name'],
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Locations of a new warehouse (inside the caller's transaction)
    // ------------------------------------------------------------------

    /** GENERAL (stock, sellable, default of the warehouse) + DAMAGED + DISPLAY. */
    public static function createLocations(int $warehouseId, int $branchId): void
    {
        if (!db()->inTransaction()) {
            throw new LogicException('Warehouses::createLocations() must run inside a transaction.');
        }
        $ins = db()->prepare(
            'INSERT INTO storage_locations (warehouse_id, branch_id, code, name, kind, is_sellable, is_default)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$warehouseId, $branchId, 'GENERAL', 'General Stock', 'stock', 1, 1]);
        $ins->execute([$warehouseId, $branchId, 'DAMAGED', 'Damaged Stock', 'damaged', 0, 0]);
        $ins->execute([$warehouseId, $branchId, 'DISPLAY', 'Display / Demo', 'display', 0, 0]);
    }

    // ------------------------------------------------------------------
    // Warehouses
    // ------------------------------------------------------------------

    /**
     * Form fields: code (create only), name. $warehouse = existing row (edit) or null (create at the
     * current branch). @return array{0: array{code:?string, name:string}, 1: array<string,string>}
     */
    public static function validateWarehouse(array $in, ?array $warehouse): array
    {
        $errors = [];
        $data   = ['code' => null, 'name' => input_string($in, 'name', 100)];
        if ($warehouse === null) {
            $data['code'] = strtoupper(input_string($in, 'code', 20));
            if (!preg_match(self::CODE_PATTERN, $data['code'])) {
                $errors['code'] = 'Use 1–20 capital letters, numbers, dashes or underscores (e.g. WH2).';
            } else {
                $branchId = Branch::current();
                $stmt = db()->prepare('SELECT 1 FROM warehouses WHERE branch_id = ? AND code = ?');
                $stmt->execute([$branchId ?? 0, $data['code']]);
                if ($stmt->fetchColumn()) {
                    $errors['code'] = 'Another warehouse of this branch already uses this code.';
                }
            }
        }
        if (mb_strlen($data['name']) < 2) {
            $errors['name'] = 'Enter the warehouse name (2–100 characters).';
        }
        return [$data, $errors];
    }

    /** Create (id null, at the current branch, with its 3 locations) or rename. @return int warehouse id */
    public static function saveWarehouse(?int $id, array $d): int
    {
        self::requireManage();
        $name = trim((string) ($d['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            throw new HttpException(422, 'Enter the warehouse name (2–100 characters).');
        }
        $branchId = $id === null ? Branch::forWrite() : 0;
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id === null) {
                $code = strtoupper(trim((string) ($d['code'] ?? '')));
                if (!preg_match(self::CODE_PATTERN, $code)) {
                    throw new HttpException(422, 'Use 1–20 capital letters, numbers, dashes or underscores for the code.');
                }
                try {
                    $pdo->prepare('INSERT INTO warehouses (branch_id, code, name, is_default) VALUES (?, ?, ?, ?)')
                        ->execute([$branchId, $code, $name, 0]);
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        throw new HttpException(422, 'Another warehouse of this branch already uses this code.',
                            ['errors' => ['code' => 'Another warehouse of this branch already uses this code.']]);
                    }
                    throw $e;
                }
                $id = (int) $pdo->lastInsertId();
                self::createLocations($id, $branchId);
                Audit::record('warehouses', 'create', 'warehouse', $id, $code, null,
                    ['code' => $code, 'name' => $name, 'locations' => ['GENERAL', 'DAMAGED', 'DISPLAY']], $branchId);
            } else {
                $stmt = $pdo->prepare('SELECT * FROM warehouses WHERE id = ? FOR UPDATE');
                $stmt->execute([$id]);
                $w = $stmt->fetch() ?: throw new HttpException(404, 'Warehouse not found.');
                self::assertWorkingBranch((int) $w['branch_id']);
                if ($w['name'] !== $name) {
                    $pdo->prepare('UPDATE warehouses SET name = ? WHERE id = ?')->execute([$name, $id]);
                    Audit::record('warehouses', 'update', 'warehouse', $id, (string) $w['code'],
                        ['name' => $w['name']], ['name' => $name], (int) $w['branch_id']);
                }
            }
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Locations
    // ------------------------------------------------------------------

    /**
     * Form fields: code (create only), name. @return array{0: array{code:?string, name:string}, 1: array<string,string>}
     */
    public static function validateLocation(array $in, ?array $location, int $warehouseId): array
    {
        $errors = [];
        $data   = ['code' => null, 'name' => input_string($in, 'name', 100)];
        if ($location === null) {
            $data['code'] = strtoupper(input_string($in, 'code', 20));
            if (!preg_match(self::CODE_PATTERN, $data['code'])) {
                $errors['code'] = 'Use 1–20 capital letters, numbers, dashes or underscores (e.g. BIN-A).';
            } elseif (isset(self::SYSTEM_CODES[$data['code']])) {
                $errors['code'] = "{$data['code']} is reserved for the warehouse's system location.";
            } else {
                $stmt = db()->prepare('SELECT 1 FROM storage_locations WHERE warehouse_id = ? AND code = ?');
                $stmt->execute([$warehouseId, $data['code']]);
                if ($stmt->fetchColumn()) {
                    $errors['code'] = 'Another location of this warehouse already uses this code.';
                }
            }
        }
        if (mb_strlen($data['name']) < 2) {
            $errors['name'] = 'Enter the location name (2–100 characters).';
        }
        return [$data, $errors];
    }

    /**
     * Create a stock location (bin) in a warehouse of the current branch (id null), or rename one
     * (system locations too). @return int location id
     */
    public static function saveLocation(?int $id, int $warehouseId, array $d): int
    {
        self::requireManage();
        $name = trim((string) ($d['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            throw new HttpException(422, 'Enter the location name (2–100 characters).');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id === null) {
                $stmt = $pdo->prepare('SELECT * FROM warehouses WHERE id = ? LOCK IN SHARE MODE');
                $stmt->execute([$warehouseId]);
                $w = $stmt->fetch() ?: throw new HttpException(404, 'Warehouse not found.');
                $branchId = self::assertWorkingBranch((int) $w['branch_id']);
                if ((int) $w['is_active'] !== 1) {
                    throw new HttpException(422, 'Activate the warehouse first.');
                }
                $code = strtoupper(trim((string) ($d['code'] ?? '')));
                if (!preg_match(self::CODE_PATTERN, $code)) {
                    throw new HttpException(422, 'Use 1–20 capital letters, numbers, dashes or underscores for the code.');
                }
                if (isset(self::SYSTEM_CODES[$code])) {
                    throw new HttpException(422, "{$code} is reserved for the warehouse's system location.");
                }
                try {
                    $pdo->prepare(
                        'INSERT INTO storage_locations (warehouse_id, branch_id, code, name, kind, is_sellable, is_default)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    )->execute([$warehouseId, $branchId, $code, $name, 'stock', 0, 0]);
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        throw new HttpException(422, 'Another location of this warehouse already uses this code.',
                            ['errors' => ['code' => 'Another location of this warehouse already uses this code.']]);
                    }
                    throw $e;
                }
                $id = (int) $pdo->lastInsertId();
                Audit::record('warehouses', 'location_create', 'location', $id, $w['code'] . '/' . $code, null,
                    ['warehouse' => $w['code'], 'code' => $code, 'name' => $name, 'kind' => 'stock'], $branchId);
            } else {
                $stmt = $pdo->prepare('SELECT * FROM storage_locations WHERE id = ? FOR UPDATE');
                $stmt->execute([$id]);
                $l = $stmt->fetch() ?: throw new HttpException(404, 'Location not found.');
                self::assertWorkingBranch((int) $l['branch_id']);
                if ((int) $l['warehouse_id'] !== $warehouseId) {
                    throw new HttpException(404, 'Location not found.');
                }
                if ($l['name'] !== $name) {
                    $pdo->prepare('UPDATE storage_locations SET name = ? WHERE id = ?')->execute([$name, $id]);
                    Audit::record('warehouses', 'location_update', 'location', $id, (string) $l['code'],
                        ['name' => $l['name']], ['name' => $name], (int) $l['branch_id']);
                }
            }
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Activate / deactivate
    // ------------------------------------------------------------------

    /**
     * $type 'warehouse' | 'location'. Deactivating needs zero stock and no open count there; never the
     * default warehouse / default location / DAMAGED / DISPLAY. @return array the row after the change
     */
    public static function setActive(string $type, int $id, bool $active): array
    {
        self::requireManage();
        if (!in_array($type, ['warehouse', 'location'], true)) {
            throw new HttpException(422, 'Unknown type.');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($type === 'location') {
                $stmt = $pdo->prepare('SELECT * FROM storage_locations WHERE id = ? FOR UPDATE');
                $stmt->execute([$id]);
                $row = $stmt->fetch() ?: throw new HttpException(404, 'Location not found.');
                self::assertWorkingBranch((int) $row['branch_id']);
                $locationIds = [$id];
                $stmt = $pdo->prepare('SELECT * FROM warehouses WHERE id = ? LOCK IN SHARE MODE');
                $stmt->execute([(int) $row['warehouse_id']]);
                $w = $stmt->fetch();
                $ref = $w['code'] . '/' . $row['code'];
                if (!$active) {
                    if ((int) $row['is_default'] === 1) {
                        throw new HttpException(409, "The default location {$row['code']} can't be deactivated.");
                    }
                    if ($row['kind'] !== 'stock') {
                        throw new HttpException(409, "{$row['code']} is a system location and can't be deactivated.");
                    }
                } elseif ((int) $w['is_active'] !== 1) {
                    throw new HttpException(422, "Activate warehouse {$w['code']} first.");
                }
            } else {
                // storage_locations rows first, then the warehouse row (same order as InventoryDocs).
                $stmt = $pdo->prepare('SELECT id FROM storage_locations WHERE warehouse_id = ? ORDER BY id FOR UPDATE');
                $stmt->execute([$id]);
                $locationIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                $stmt = $pdo->prepare('SELECT * FROM warehouses WHERE id = ? FOR UPDATE');
                $stmt->execute([$id]);
                $row = $stmt->fetch() ?: throw new HttpException(404, 'Warehouse not found.');
                self::assertWorkingBranch((int) $row['branch_id']);
                $ref = (string) $row['code'];
                if (!$active && (int) $row['is_default'] === 1) {
                    throw new HttpException(409, "The default warehouse {$row['code']} can't be deactivated.");
                }
            }

            if ((int) $row['is_active'] === ($active ? 1 : 0)) {
                $pdo->commit(); // nothing to change
                return $row;
            }

            if (!$active && $locationIds) {
                $in_  = implode(',', array_fill(0, count($locationIds), '?'));
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(qty), 0) FROM stock_balances WHERE location_id IN ({$in_})");
                $stmt->execute($locationIds);
                if ((int) $stmt->fetchColumn() > 0) {
                    throw new HttpException(409, "{$ref} still holds stock. Move its stock first.");
                }
                $stmt = $pdo->prepare(
                    "SELECT doc_no FROM inventory_docs
                      WHERE from_location_id IN ({$in_}) AND doc_type = ? AND status IN ('open', 'submitted') LIMIT 1"
                );
                $stmt->execute([...$locationIds, 'count']);
                $open = $stmt->fetchColumn();
                if ($open !== false) {
                    throw new HttpException(409, "Stock count {$open} is still open at {$ref}. Finish or cancel it first.");
                }
            }

            $table = $type === 'location' ? 'storage_locations' : 'warehouses'; // code literal, never input
            $pdo->prepare("UPDATE {$table} SET is_active = ? WHERE id = ?")->execute([$active ? 1 : 0, $id]);
            Audit::record('warehouses', ($active ? 'activate' : 'deactivate') . ($type === 'location' ? '_location' : ''),
                $type, $id, $ref, ['is_active' => (int) $row['is_active']], ['is_active' => $active ? 1 : 0],
                (int) $row['branch_id']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $row['is_active'] = $active ? 1 : 0;
        return $row;
    }

    /**
     * Writes to an existing record need the session working in its branch:
     * other branch -> 404, "All branches" -> 422 (the Receiving rule). @return int the branch id
     */
    public static function assertWorkingBranch(int $branchId): int
    {
        Branch::assertAccess($branchId);
        if (Branch::current() !== $branchId) {
            $stmt = db()->prepare('SELECT name FROM branches WHERE id = ?');
            $stmt->execute([$branchId]);
            throw new HttpException(422, 'Switch to branch ' . (string) $stmt->fetchColumn() . ' first.');
        }
        return $branchId;
    }
}
