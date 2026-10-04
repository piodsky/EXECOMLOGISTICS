<?php
/**
 * Customers: validation, listing with purchase stats, CRUD.
 * Used by the Customers pages and the POS quick-add (api/customers/create.php).
 *
 * Customers are shared across branches: one record with a home branch (customers.branch_id)
 * plus visibility links (customer_branches). Everything here only sees customers linked to the
 * current branch scope; purchase stats only count sales of that scope.
 */
declare(strict_types=1);

final class Customers
{
    /** [SQL, params]: customer `c` is visible in the current branch scope. */
    private static function visible(): array
    {
        [$scope, $params] = Branch::scopeSql('cb.branch_id');
        return ["EXISTS (SELECT 1 FROM customer_branches cb WHERE cb.customer_id = c.id AND {$scope})", $params];
    }

    /** Active customers for dropdowns (POS). */
    public static function active(): array
    {
        [$visible, $params] = self::visible();
        $stmt = db()->prepare("SELECT c.id, c.name, c.phone FROM customers c WHERE c.is_active = ? AND {$visible} ORDER BY c.name");
        $stmt->execute([1, ...$params]);
        return $stmt->fetchAll();
    }

    /** Customer visible in the current scope (with 'contacts'), or null. */
    public static function find(int $id): ?array
    {
        [$visible, $params] = self::visible();
        $stmt = db()->prepare(
            "SELECT c.*, b.code AS branch_code, b.name AS branch_name, ct.name AS type_name
               FROM customers c JOIN branches b ON b.id = c.branch_id
               LEFT JOIN customer_types ct ON ct.id = c.customer_type_id
              WHERE c.id = ? AND {$visible}"
        );
        $stmt->execute([$id, ...$params]);
        $customer = $stmt->fetch() ?: null;
        if ($customer !== null) {
            $customer['contacts'] = Contacts::forOwner('customers', $id);
        }
        return $customer;
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * Validate raw input (form or JSON).
     * Customer type and TIN are optional (the POS quick-add sends neither).
     * @return array{0: array{name:string, phone:?string, email:?string, address:?string, customer_type_id:?int, tin:?string}, 1: array<string,string>}
     */
    public static function check(array $input, ?int $id = null): array
    {
        $name    = input_string($input, 'name', 100);
        $phone   = input_string($input, 'phone', 30);
        $email   = input_string($input, 'email', 120);
        $address = input_string($input, 'address', 255);
        $tin     = input_string($input, 'tin', 20);
        $typeId  = input_int($input, 'customer_type_id', 1);
        $errors  = [];

        $rawType = $input['customer_type_id'] ?? '';
        if ((is_string($rawType) && trim($rawType) !== '') || is_int($rawType)) {
            $currentType = null;
            if ($id !== null) {
                $stmt = db()->prepare('SELECT customer_type_id FROM customers WHERE id = ?');
                $stmt->execute([$id]);
                $currentType = ($v = $stmt->fetchColumn()) !== false && $v !== null ? (int) $v : null;
            }
            if (!MasterData::isChoice('customer-types', $typeId, $currentType)) {
                $errors['customer_type_id'] = 'Choose a customer type from the list.';
            }
        }
        if ($tin !== '' && !preg_match('/^[0-9][0-9-]{8,19}$/', $tin)) {
            $errors['tin'] = 'Use digits and dashes (e.g. 123-456-789-000).';
        }

        if (mb_strlen($name) < 2) {
            $errors['name'] = 'Enter the customer name (at least 2 characters).';
        }
        if ($phone !== '') {
            if (!preg_match('/^[0-9+()\s-]{7,30}$/', $phone)) {
                $errors['phone'] = 'Enter a valid phone number (digits, spaces, + - ( ) only).';
            } elseif ($owner = self::phoneOwner($phone, $id)) {
                // Company-wide check; don't reveal a customer the user can't see.
                $errors['phone'] = $owner['visible']
                    ? "This number already belongs to {$owner['name']}."
                    : 'This phone number is already registered at another branch.';
            }
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        }

        return [[
            'name'    => $name,
            'phone'   => $phone !== '' ? $phone : null,
            'email'   => $email !== '' ? $email : null,
            'address' => $address !== '' ? $address : null,
            'customer_type_id' => $typeId,
            'tin'     => $tin !== '' ? $tin : null,
        ], $errors];
    }

    /** Same as check(), but throws the first error as a 422 (for JSON APIs). */
    public static function validate(array $input, ?int $id = null): array
    {
        [$data, $errors] = self::check($input, $id);
        if ($errors) {
            throw new HttpException(422, (string) reset($errors), ['errors' => $errors]);
        }
        return $data;
    }

    /** @return array{name:string, visible:bool}|null */
    private static function phoneOwner(string $phone, ?int $exceptId): ?array
    {
        [$visible, $params] = self::visible();
        $stmt = db()->prepare(
            "SELECT c.name, ({$visible}) AS visible FROM customers c WHERE c.phone = ? AND c.id <> ? LIMIT 1"
        );
        $stmt->execute([...$params, $phone, $exceptId ?? 0]);
        $row = $stmt->fetch();
        return $row ? ['name' => (string) $row['name'], 'visible' => (int) $row['visible'] === 1] : null;
    }

    // ------------------------------------------------------------------
    // Listing
    // ------------------------------------------------------------------

    /** @param array{q:string, status:string, type?:?int} $f */
    public static function count(array $f): int
    {
        [$where, $params] = self::where($f);
        $stmt = db()->prepare("SELECT COUNT(*) FROM customers c WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function search(array $f, int $limit, int $offset): array
    {
        [$where, $params] = self::where($f);
        [$salesScope, $salesParams] = Branch::scopeSql('sa.branch_id');
        $stmt = db()->prepare(
            "SELECT c.id, c.name, c.phone, c.email, c.tin, c.is_active, c.created_at, c.branch_id,
                    b.code AS branch_code, b.name AS branch_name, ct.name AS type_name,
                    COALESCE(s.visits, 0) AS visits, COALESCE(s.spent, 0) AS spent, s.last_visit,
                    (SELECT COUNT(*) FROM sales sx WHERE sx.customer_id = c.id) AS all_sales
               FROM customers c
               JOIN branches b ON b.id = c.branch_id
               LEFT JOIN customer_types ct ON ct.id = c.customer_type_id
               LEFT JOIN (
                    SELECT sa.customer_id, COUNT(*) AS visits, SUM(sa.total) AS spent, MAX(sa.created_at) AS last_visit
                      FROM sales sa WHERE sa.status = ? AND sa.customer_id IS NOT NULL AND {$salesScope}
                     GROUP BY sa.customer_id
               ) s ON s.customer_id = c.id
              WHERE {$where}
              ORDER BY c.is_active DESC, c.name
              LIMIT ? OFFSET ?"
        );
        $stmt->execute(['completed', ...$salesParams, ...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    private static function where(array $f): array
    {
        [$visible, $params] = self::visible();
        $where = [$visible];
        if ($f['q'] !== '') {
            $where[] = '(c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.tin LIKE ?)';
            $like = like_pattern($f['q']);
            array_push($params, $like, $like, $like, $like);
        }
        if (($f['type'] ?? null) !== null) {
            $where[]  = 'c.customer_type_id = ?';
            $params[] = $f['type'];
        }
        $where[] = match ($f['status']) {
            'active'   => 'c.is_active = 1',
            'inactive' => 'c.is_active = 0',
            default    => '1 = 1',
        };
        return [implode(' AND ', $where), $params];
    }

    /** Visits, total spent, average and last visit for one customer (sales in the current scope). */
    public static function stats(int $id): array
    {
        [$scope, $params] = Branch::scopeSql('s.branch_id');
        $stmt = db()->prepare(
            "SELECT COUNT(*) AS visits, COALESCE(SUM(s.total), 0) AS spent,
                    COALESCE(AVG(s.total), 0) AS average, MAX(s.created_at) AS last_visit
               FROM sales s WHERE s.customer_id = ? AND s.status = ? AND {$scope}"
        );
        $stmt->execute([$id, 'completed', ...$params]);
        return $stmt->fetch();
    }

    public static function recentSales(int $id, int $limit = 10): array
    {
        [$scope, $params] = Branch::scopeSql('s.branch_id');
        $stmt = db()->prepare(
            "SELECT s.id, s.sale_no, s.total, s.payment_type, s.status, s.created_at,
                    (SELECT COALESCE(SUM(quantity), 0) FROM sale_items si WHERE si.sale_id = s.id) AS items
               FROM sales s
              WHERE s.customer_id = ? AND {$scope}
              ORDER BY s.created_at DESC
              LIMIT ?"
        );
        $stmt->execute([$id, ...$params, $limit]);
        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------
    // Create / update (customers.edit) — status / delete (customers.delete)
    // ------------------------------------------------------------------

    private static function requirePermission(string $key, string $message): void
    {
        if (!Auth::can($key)) {
            throw new HttpException(403, $message);
        }
    }

    private const AUDIT_FIELDS = ['name', 'phone', 'email', 'address', 'customer_type_id', 'tin'];

    /**
     * New customer at the current branch (home branch + visibility link).
     * @param list<array> $contacts from Contacts::parse()
     */
    public static function create(array $data, array $contacts = []): int
    {
        self::requirePermission('customers.edit', 'You do not have permission to add customers.');
        $branchId = Branch::forWrite();
        $data += ['customer_type_id' => null, 'tin' => null];
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO customers (name, phone, email, address, customer_type_id, tin, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$data['name'], $data['phone'], $data['email'], $data['address'], $data['customer_type_id'], $data['tin'], $branchId]);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO customer_branches (customer_id, branch_id) VALUES (?, ?)')->execute([$id, $branchId]);
            if ($contacts) {
                Contacts::replace('customers', $id, $contacts);
            }
            Audit::record('customers', 'create', 'customer', $id, $data['name'], null,
                array_intersect_key($data, array_flip(self::AUDIT_FIELDS)) + ['contacts' => array_column($contacts, 'name')], $branchId);
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param list<array>|null $contacts from Contacts::parse() (replaces the saved ones); null keeps them */
    public static function update(int $id, array $data, ?array $contacts = null): void
    {
        self::requirePermission('customers.edit', 'You do not have permission to edit customers.');
        $before = self::find($id) ?? throw new HttpException(404, 'Customer not found.');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE customers SET name = ?, phone = ?, email = ?, address = ?, customer_type_id = ?, tin = ? WHERE id = ?')
                ->execute([$data['name'], $data['phone'], $data['email'], $data['address'], $data['customer_type_id'], $data['tin'], $id]);
            if ($contacts !== null) {
                Contacts::replace('customers', $id, $contacts);
            }
            [$old, $new] = Audit::diff(
                array_intersect_key($before, array_flip(self::AUDIT_FIELDS)) + ['contacts' => array_column($before['contacts'], 'name')],
                array_intersect_key($data, array_flip(self::AUDIT_FIELDS)) + ['contacts' => array_column($contacts ?? $before['contacts'], 'name')]
            );
            if ($new) {
                Audit::record('customers', 'update', 'customer', $id, $data['name'], $old, $new);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function toggleActive(int $id): array
    {
        self::requirePermission('customers.delete', 'You do not have permission to deactivate customers.');
        $customer = self::find($id) ?? throw new HttpException(404, 'Customer not found.');
        $next = (int) $customer['is_active'] === 1 ? 0 : 1;
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE customers SET is_active = ? WHERE id = ?')->execute([$next, $id]);
            Audit::record('customers', $next ? 'activate' : 'deactivate', 'customer', $id, $customer['name'],
                ['is_active' => (int) $customer['is_active']], ['is_active' => $next]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $customer['is_active'] = $next;
        return $customer;
    }

    /** Hard delete — only for customers without any sales (their receipts must keep the name). */
    public static function delete(int $id): array
    {
        self::requirePermission('customers.delete', 'You do not have permission to delete customers.');
        $customer = self::find($id) ?? throw new HttpException(404, 'Customer not found.');
        $stmt = db()->prepare('SELECT COUNT(*) FROM sales WHERE customer_id = ?');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new HttpException(409, "{$customer['name']} has purchase history, so they can't be deleted. Deactivate them instead to hide them from the POS.");
        }
        $stmt = db()->prepare('SELECT COUNT(*) FROM job_orders WHERE customer_id = ?');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new HttpException(409, "{$customer['name']} has job orders, so they can't be deleted. Deactivate them instead.");
        }
        $stmt = db()->prepare('SELECT COUNT(*) FROM customer_orders WHERE customer_id = ?');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new HttpException(409, "{$customer['name']} has customer orders, so they can't be deleted. Deactivate them instead.");
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]); // links cascade
            Audit::record('customers', 'delete', 'customer', $id, $customer['name'],
                array_intersect_key($customer, array_flip(['name', 'phone', 'email', 'address', 'branch_id', 'is_active'])), null);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $customer;
    }
}
