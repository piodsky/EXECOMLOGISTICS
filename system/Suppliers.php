<?php
/**
 * Suppliers (Phase 6): company-wide partner list with up to Contacts::MAX contact persons.
 * suppliers.view lists/opens them; suppliers.manage adds, edits, (de)activates and deletes.
 * Delete only while nothing references the supplier (deleteBlocker(); receiving will add checks).
 * Every write is audited (module 'suppliers') inside its transaction.
 */
declare(strict_types=1);

final class Suppliers
{
    private const AUDIT_FIELDS = ['code', 'name', 'tin', 'address', 'phone', 'email', 'payment_terms', 'notes', 'is_active'];

    /** @param array{q:string, status:string} $f */
    public static function count(array $f): int
    {
        [$where, $params] = self::where($f);
        $stmt = db()->prepare("SELECT COUNT(*) FROM suppliers s WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function search(array $f, int $limit, int $offset): array
    {
        [$where, $params] = self::where($f);
        $stmt = db()->prepare(
            "SELECT s.*,
                    (SELECT c.name FROM supplier_contacts c WHERE c.supplier_id = s.id ORDER BY c.sort_order, c.id LIMIT 1) AS contact_name,
                    (SELECT CONCAT_WS(' · ', c.phone, c.email) FROM supplier_contacts c WHERE c.supplier_id = s.id ORDER BY c.sort_order, c.id LIMIT 1) AS contact_reach,
                    (SELECT COUNT(*) FROM supplier_contacts c WHERE c.supplier_id = s.id) AS contacts
               FROM suppliers s
              WHERE {$where}
              ORDER BY s.is_active DESC, s.name
              LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    private static function where(array $f): array
    {
        $where  = ['1 = 1'];
        $params = [];
        if ($f['q'] !== '') {
            $where[] = '(s.code LIKE ? OR s.name LIKE ? OR s.phone LIKE ? OR s.email LIKE ? OR s.tin LIKE ?)';
            $like = like_pattern($f['q']);
            array_push($params, $like, $like, $like, $like, $like);
        }
        $where[] = match ($f['status']) {
            'active'   => 's.is_active = 1',
            'inactive' => 's.is_active = 0',
            default    => '1 = 1',
        };
        return [implode(' AND ', $where), $params];
    }

    /** Supplier with 'contacts' (list) or null. */
    public static function find(int $id): ?array
    {
        $stmt = db()->prepare('SELECT * FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['contacts'] = Contacts::forOwner('suppliers', $id);
        return $row;
    }

    /** Next free code in the SUP-0001 series. */
    public static function nextCode(): string
    {
        $stmt = db()->prepare("SELECT MAX(CAST(SUBSTRING(code, 5) AS UNSIGNED)) FROM suppliers WHERE code REGEXP ?");
        $stmt->execute(['^SUP-[0-9]+$']);
        return sprintf('SUP-%04d', (int) $stmt->fetchColumn() + 1);
    }

    /**
     * @return array{0: array, 1: list<array>, 2: array<string,string>} [data, contacts, field errors]
     */
    public static function validate(array $in, ?array $supplier): array
    {
        $data = [
            'code'          => strtoupper(input_string($in, 'code', 20)),
            'name'          => input_string($in, 'name', 120),
            'tin'           => input_string($in, 'tin', 20),
            'address'       => input_string($in, 'address', 255),
            'phone'         => input_string($in, 'phone', 30),
            'email'         => input_string($in, 'email', 120),
            'payment_terms' => input_string($in, 'payment_terms', 60),
            'notes'         => input_string($in, 'notes', 255),
            'is_active'     => isset($in['is_active']) ? 1 : 0,
        ];
        $errors = [];

        if ($data['code'] === '') {
            $data['code'] = null; // assigned on save (next SUP-0001 number)
        } elseif (!preg_match('/^[A-Z0-9][A-Z0-9-]{1,19}$/', $data['code'])) {
            $errors['code'] = 'Use 2–20 letters, numbers or dashes (e.g. SUP-0001), or leave it blank.';
        } else {
            $stmt = db()->prepare('SELECT 1 FROM suppliers WHERE code = ? AND id <> ?');
            $stmt->execute([$data['code'], $supplier['id'] ?? 0]);
            if ($stmt->fetchColumn()) {
                $errors['code'] = 'Another supplier already uses this code.';
            }
        }
        if (mb_strlen($data['name']) < 2) {
            $errors['name'] = 'Enter the supplier name (at least 2 characters).';
        }
        if ($data['tin'] !== '' && !preg_match('/^[0-9][0-9-]{8,19}$/', $data['tin'])) {
            $errors['tin'] = 'Use digits and dashes (e.g. 123-456-789-000).';
        }
        if ($data['phone'] !== '' && !preg_match('/^[0-9+()\s-]{7,30}$/', $data['phone'])) {
            $errors['phone'] = 'Enter a valid phone number (digits, spaces, + - ( ) only).';
        }
        if ($data['email'] !== '' && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        }
        foreach (['tin', 'address', 'phone', 'email', 'payment_terms', 'notes'] as $key) {
            $data[$key] = $data[$key] !== '' ? $data[$key] : null;
        }

        [$contacts, $contactErrors] = Contacts::parse($in);
        return [$data, $contacts, $errors + $contactErrors];
    }

    private static function requireManage(): void
    {
        if (!Auth::can('suppliers.manage')) {
            throw new HttpException(403, 'You do not have permission to manage suppliers.');
        }
    }

    /** Create ($supplier = null) or update. @return int supplier id */
    public static function save(array $data, array $contacts, ?array $supplier): int
    {
        self::requireManage();
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($data['code'] === null) {
                $data['code'] = $supplier['code'] ?? self::nextCode();
            }
            $values = array_map(static fn (string $k) => $data[$k], self::AUDIT_FIELDS);
            if ($supplier === null) {
                $pdo->prepare('INSERT INTO suppliers (' . implode(', ', self::AUDIT_FIELDS) . ') VALUES ('
                    . implode(', ', array_fill(0, count(self::AUDIT_FIELDS), '?')) . ')')->execute($values);
                $id = (int) $pdo->lastInsertId();
                Contacts::replace('suppliers', $id, $contacts);
                Audit::record('suppliers', 'create', 'supplier', $id, $data['code'], null,
                    array_combine(self::AUDIT_FIELDS, $values) + ['contacts' => array_column($contacts, 'name')]);
            } else {
                $id = (int) $supplier['id'];
                $set = implode(', ', array_map(static fn (string $k) => "{$k} = ?", self::AUDIT_FIELDS));
                $pdo->prepare("UPDATE suppliers SET {$set} WHERE id = ?")->execute([...$values, $id]);
                Contacts::replace('suppliers', $id, $contacts);
                [$old, $new] = Audit::diff(
                    array_intersect_key($supplier, array_flip(self::AUDIT_FIELDS)) + ['contacts' => array_column($supplier['contacts'], 'name')],
                    array_combine(self::AUDIT_FIELDS, $values) + ['contacts' => array_column($contacts, 'name')]
                );
                if ($new) {
                    Audit::record('suppliers', 'update', 'supplier', $id, $data['code'], $old, $new);
                }
            }
            $pdo->commit();
            return $id;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new HttpException(422, 'Another supplier already uses this code. Please try again.');
            }
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array the supplier after the change */
    public static function toggleActive(int $id): array
    {
        self::requireManage();
        $supplier = self::find($id) ?? throw new HttpException(404, 'Supplier not found.');
        $next = (int) $supplier['is_active'] === 1 ? 0 : 1;
        $pdo  = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE suppliers SET is_active = ? WHERE id = ?')->execute([$next, $id]);
            Audit::record('suppliers', $next ? 'activate' : 'deactivate', 'supplier', $id, $supplier['code'],
                ['is_active' => (int) $supplier['is_active']], ['is_active' => $next]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $supplier['is_active'] = $next;
        return $supplier;
    }

    /**
     * Why the supplier can't be deleted, or null. Add the purchase tables here when they arrive
     * (receiving_reports.supplier_id is RESTRICT).
     */
    public static function deleteBlocker(int $id): ?string
    {
        $checks = ['receiving_reports' => 'it has receiving reports', 'purchase_orders' => 'it has purchase orders']; // fixed table names, never input
        foreach ($checks as $table => $reason) {
            $stmt = db()->prepare("SELECT 1 FROM {$table} WHERE supplier_id = ? LIMIT 1");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn()) {
                return $reason;
            }
        }
        return null;
    }

    /** @return array the deleted supplier */
    public static function delete(int $id): array
    {
        self::requireManage();
        $supplier = self::find($id) ?? throw new HttpException(404, 'Supplier not found.');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id FROM suppliers WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $reason = self::deleteBlocker($id);
            if ($reason !== null) {
                throw new HttpException(409, "{$supplier['name']} can't be deleted because {$reason}. Deactivate it instead.");
            }
            $pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$id]); // contacts cascade
            Audit::record('suppliers', 'delete', 'supplier', $id, $supplier['code'],
                array_intersect_key($supplier, array_flip(self::AUDIT_FIELDS)) + ['contacts' => array_column($supplier['contacts'], 'name')], null);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((int) ($e->errorInfo[1] ?? 0) === 1451) {
                throw new HttpException(409, "{$supplier['name']} is in use and can't be deleted. Deactivate it instead.");
            }
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $supplier;
    }
}
