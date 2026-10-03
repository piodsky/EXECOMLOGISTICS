<?php
/**
 * Branch master data (branches.manage): list, validate, create/update, delete.
 * Rules: exactly one main branch (setting a new main unsets the old one); the main branch can't
 * be deactivated or deleted; a branch with active home users can't be deactivated (they could no
 * longer sign in); a branch with any history (sales, stock, users, customers) can't be deleted.
 * Creating a branch also creates its MAIN warehouse with GENERAL (sellable, default), DAMAGED and
 * DISPLAY locations (Warehouses::createLocations).
 * Address / contact / TIN stay empty until the owner fills them in (never invented).
 */
declare(strict_types=1);

final class Branches
{
    public static function all(): array
    {
        $stmt = db()->prepare(
            "SELECT b.*,
                    (SELECT COUNT(*) FROM users u WHERE u.branch_id = b.id) AS users,
                    (SELECT COUNT(*) FROM users u WHERE u.branch_id = b.id AND u.is_active = 1) AS active_users,
                    (SELECT COUNT(*) FROM sales s WHERE s.branch_id = b.id) AS sales,
                    (SELECT GROUP_CONCAT(CONCAT(w.code, ' / ', l.code) ORDER BY w.id, l.id SEPARATOR ', ')
                       FROM storage_locations l JOIN warehouses w ON w.id = l.warehouse_id
                      WHERE l.branch_id = b.id) AS locations
               FROM branches b
              ORDER BY b.is_main DESC, b.name"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT b.*,
                    (SELECT COUNT(*) FROM users u WHERE u.branch_id = b.id AND u.is_active = 1) AS active_users
               FROM branches b WHERE b.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Warehouses + locations of a branch (read-only display). */
    public static function locations(int $branchId): array
    {
        $stmt = db()->prepare(
            'SELECT w.code AS warehouse_code, w.name AS warehouse_name, w.is_default AS warehouse_default,
                    w.is_active AS warehouse_active, l.code, l.name, l.kind, l.is_sellable, l.is_default, l.is_active
               FROM storage_locations l JOIN warehouses w ON w.id = l.warehouse_id AND w.branch_id = l.branch_id
              WHERE l.branch_id = ?
              ORDER BY w.id, l.id'
        );
        $stmt->execute([$branchId]);
        return $stmt->fetchAll();
    }

    /** True while the address or contact number is still empty. */
    public static function needsDetails(array $branch): bool
    {
        return trim((string) ($branch['address'] ?? '')) === '' || trim((string) ($branch['contact_no'] ?? '')) === '';
    }

    /**
     * @return array{0: array, 1: array<string,string>}
     */
    public static function validate(array $in, ?array $branch): array
    {
        $data = [
            'code'            => strtoupper(input_string($in, 'code', 10)),
            'name'            => input_string($in, 'name', 100),
            'address'         => input_string($in, 'address', 255),
            'contact_no'      => input_string($in, 'contact_no', 50),
            'tin_branch_code' => input_string($in, 'tin_branch_code', 30),
            'is_active'       => isset($in['is_active']) ? 1 : 0,
            'is_main'         => isset($in['is_main']) ? 1 : 0,
        ];
        $errors = [];

        if (!preg_match('/^[A-Z]{2,10}$/', $data['code'])) {
            $errors['code'] = 'Use 2–10 capital letters (e.g. MAR).';
        } else {
            $stmt = db()->prepare('SELECT 1 FROM branches WHERE code = ? AND id <> ?');
            $stmt->execute([$data['code'], $branch['id'] ?? 0]);
            if ($stmt->fetchColumn()) {
                $errors['code'] = 'Another branch already uses this code.';
            }
        }
        if (mb_strlen($data['name']) < 2) {
            $errors['name'] = 'Enter the branch name (at least 2 characters).';
        }
        if ($data['contact_no'] !== '' && !preg_match('/^[0-9+()\s\/,.-]{7,50}$/', $data['contact_no'])) {
            $errors['contact_no'] = 'Use digits, spaces and + ( ) - / , . (7–50 characters).';
        }
        if ($data['tin_branch_code'] !== '' && !preg_match('/^[0-9A-Za-z-]{1,30}$/', $data['tin_branch_code'])) {
            $errors['tin_branch_code'] = 'Use letters, numbers and dashes only.';
        }

        $isMain = $branch !== null && (int) $branch['is_main'] === 1;
        if ($isMain && $data['is_main'] === 0) {
            $errors['is_main'] = 'There must be a main branch. Make another branch the main store instead.';
        }
        if ($data['is_main'] === 1 && $data['is_active'] === 0) {
            $errors['is_active'] = "The main branch can't be inactive.";
        }
        if ($branch !== null && (int) $branch['is_active'] === 1 && $data['is_active'] === 0 && (int) $branch['active_users'] > 0) {
            $errors['is_active'] = "{$branch['active_users']} active " . ((int) $branch['active_users'] === 1 ? 'user has' : 'users have')
                . ' this as their home branch and could no longer sign in. Move or deactivate them first.';
        }

        foreach (['address', 'contact_no', 'tin_branch_code'] as $key) {
            $data[$key] = $data[$key] !== '' ? $data[$key] : null;
        }
        return [$data, $errors];
    }

    private static function requireManage(): void
    {
        if (!Auth::can('branches.manage')) {
            throw new HttpException(403, 'You do not have permission to manage branches.');
        }
    }

    private const AUDIT_FIELDS = ['code', 'name', 'address', 'contact_no', 'tin_branch_code', 'is_active', 'is_main'];

    public static function create(array $data): int
    {
        self::requireManage();
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($data['is_main'] === 1) {
                $pdo->prepare('UPDATE branches SET is_main = 0 WHERE is_main = 1')->execute([]);
            }
            $pdo->prepare(
                'INSERT INTO branches (code, name, address, contact_no, tin_branch_code, is_main, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$data['code'], $data['name'], $data['address'], $data['contact_no'], $data['tin_branch_code'],
                $data['is_main'], $data['is_active']]);
            $id = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO warehouses (branch_id, code, name, is_default) VALUES (?, ?, ?, ?)')
                ->execute([$id, 'MAIN', 'Main Warehouse', 1]);
            $warehouseId = (int) $pdo->lastInsertId();
            Warehouses::createLocations($warehouseId, $id); // GENERAL (sellable, default) + DAMAGED + DISPLAY

            Audit::record('branches', 'create', 'branch', $id, $data['code'], null,
                array_intersect_key($data, array_flip(self::AUDIT_FIELDS)));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $id;
    }

    public static function update(array $branch, array $data): void
    {
        self::requireManage();
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($data['is_main'] === 1 && (int) $branch['is_main'] !== 1) {
                $pdo->prepare('UPDATE branches SET is_main = 0 WHERE is_main = 1 AND id <> ?')->execute([$branch['id']]);
            }
            $pdo->prepare(
                'UPDATE branches SET code = ?, name = ?, address = ?, contact_no = ?, tin_branch_code = ?, is_main = ?, is_active = ?
                  WHERE id = ?'
            )->execute([$data['code'], $data['name'], $data['address'], $data['contact_no'], $data['tin_branch_code'],
                $data['is_main'], $data['is_active'], $branch['id']]);

            [$old, $new] = Audit::diff(
                array_intersect_key($branch, array_flip(self::AUDIT_FIELDS)),
                array_intersect_key($data, array_flip(self::AUDIT_FIELDS))
            );
            if ($new) {
                Audit::record('branches', 'update', 'branch', (int) $branch['id'], $data['code'], $old, $new);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        Branch::reset();
    }

    /** Why the branch can't be deleted, or null. */
    public static function deleteBlocker(int $id): ?string
    {
        $checks = [
            'sales'             => 'it has sales',
            'users'             => 'users belong to it',
            'customers'         => 'customers belong to it',
            'customer_branches' => 'customers are linked to it',
            'user_branches'     => 'users have access to it',
            'stock_balances'    => 'it has stock records',
            'stock_movements'   => 'it has stock history',
            'inventory_docs'    => 'it has stock documents',
        ];
        foreach ($checks as $table => $reason) {
            // $table comes from the whitelist above, never from input.
            $stmt = db()->prepare("SELECT 1 FROM {$table} WHERE branch_id = ? LIMIT 1");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn()) {
                return $reason;
            }
        }
        return null;
    }

    /** @return array the deleted branch */
    public static function delete(int $id): array
    {
        self::requireManage();
        $branch = self::find($id) ?? throw new HttpException(404, 'Branch not found.');
        if ((int) $branch['is_main'] === 1) {
            throw new HttpException(409, "{$branch['name']} is the main branch and can't be deleted.");
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id FROM branches WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $reason = self::deleteBlocker($id);
            if ($reason !== null) {
                throw new HttpException(409, "{$branch['name']} can't be deleted because {$reason}. Deactivate it instead.");
            }
            $pdo->prepare('DELETE FROM storage_locations WHERE branch_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM warehouses WHERE branch_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM branches WHERE id = ?')->execute([$id]);
            Audit::record('branches', 'delete', 'branch', $id, $branch['code'], array_intersect_key($branch, array_flip(self::AUDIT_FIELDS)), null);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((int) ($e->errorInfo[1] ?? 0) === 1451) {
                throw new HttpException(409, "{$branch['name']} is still in use and can't be deleted. Deactivate it instead.");
            }
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $branch;
    }
}
