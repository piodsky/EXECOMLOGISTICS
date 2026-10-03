<?php
/**
 * Master data lists (Phase 6), registered in config/master-data.php: categories, brands, models,
 * units, customer types and the generic `lookups` lists. Company-wide (no branch_id).
 *
 * Rules (enforced here, not only in the UI):
 *   - names are unique per list, case-insensitively (models: per brand; units: code unique too);
 *   - delete only when unused, otherwise 409 "deactivate instead";
 *   - a model's brand can't change while products use the model (products.brand_id would move);
 *   - inactive entries stay on existing records but options() only offers them as the current value;
 *   - every write is audited (module 'master_data') inside its transaction.
 */
declare(strict_types=1);

final class MasterData
{
    /** Icons a category may use (all exist in assets/img/icons.svg). */
    public const CATEGORY_ICONS = ['grid', 'laptop', 'mouse', 'plug', 'network', 'clipboard', 'box', 'tag',
        'printer', 'truck', 'barcode', 'cart', 'store', 'file'];

    /** Extra editable columns a registry entry may declare. */
    private const FIELDS = ['code', 'brand_id', 'icon', 'sort_order'];

    /** list key => [[table, column, reason], ...] — rows that reference an entry (code literals). */
    private const USAGE = [
        'categories'     => [['products', 'category_id', 'products use it']],
        'brands'         => [['products', 'brand_id', 'products use it'], ['product_models', 'brand_id', 'it has models']],
        'models'         => [['products', 'model_id', 'products use it']],
        'units'          => [['products', 'unit_id', 'products use it']],
        'customer-types' => [['customers', 'customer_type_id', 'customers use it']],
    ];

    public const MAX_SORT = 9999;

    /** @return array<string,array> key => definition */
    public static function lists(): array
    {
        return config('master-data', []);
    }

    /** Definition of a list (+ 'key'), 404 for an unknown key. */
    public static function def(string $key): array
    {
        $def = self::lists()[$key] ?? throw new HttpException(404, 'Unknown list.');
        if (array_diff($def['fields'], self::FIELDS) || !preg_match('/^[a-z_]+$/', $def['table'])) {
            throw new LogicException("Invalid master data definition [{$key}]");
        }
        return $def + ['key' => $key, 'list' => null];
    }

    private static function has(array $def, string $field): bool
    {
        return in_array($field, $def['fields'], true);
    }

    private static function requireManage(array $def): void
    {
        if (!Auth::can($def['permission'])) {
            throw new HttpException(403, 'You do not have permission to manage master data.');
        }
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /** SQL expression: how many rows reference entry t.id ('0' when nothing can). */
    private static function usedSql(array $def): string
    {
        $parts = [];
        foreach (self::USAGE[$def['key']] ?? [] as [$table, $column]) {
            $parts[] = "(SELECT COUNT(*) FROM {$table} x WHERE x.{$column} = t.id)";
        }
        return $parts ? implode(' + ', $parts) : '0';
    }

    /** [SELECT ... FROM ... JOIN, ORDER BY] for a list. */
    private static function baseSql(array $def): array
    {
        $cols = ['t.id', 't.name', 't.is_active'];
        foreach ($def['fields'] as $field) {
            $cols[] = "t.{$field}";
        }
        $join = '';
        if (self::has($def, 'brand_id')) {
            $cols[] = 'b.name AS brand_name';
            $cols[] = 'b.is_active AS brand_active';
            $join   = ' JOIN brands b ON b.id = t.brand_id';
        }
        $cols[] = self::usedSql($def) . ' AS used';
        $order = match (true) {
            self::has($def, 'brand_id')   => 'b.name, t.name',
            self::has($def, 'sort_order') => 't.sort_order, t.name',
            default                       => 't.name',
        };
        return ['SELECT ' . implode(', ', $cols) . " FROM {$def['table']} t{$join}", $order];
    }

    /** @param array{q:string, status:string} $f */
    private static function where(array $def, array $f): array
    {
        $where  = ['1 = 1'];
        $params = [];
        if ($def['list'] !== null) {
            $where[]  = 't.list = ?';
            $params[] = $def['list'];
        }
        if ($f['q'] !== '') {
            $like  = like_pattern($f['q']);
            $or    = ['t.name LIKE ?'];
            $params[] = $like;
            if (self::has($def, 'code')) {
                $or[] = 't.code LIKE ?';
                $params[] = $like;
            }
            if (self::has($def, 'brand_id')) {
                $or[] = 'b.name LIKE ?';
                $params[] = $like;
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        $where[] = match ($f['status']) {
            'active'   => 't.is_active = 1',
            'inactive' => 't.is_active = 0',
            default    => '1 = 1',
        };
        return [implode(' AND ', $where), $params];
    }

    public static function count(array $def, array $f): int
    {
        [$where, $params] = self::where($def, $f);
        $join = self::has($def, 'brand_id') ? ' JOIN brands b ON b.id = t.brand_id' : '';
        $stmt = db()->prepare("SELECT COUNT(*) FROM {$def['table']} t{$join} WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function search(array $def, array $f, int $limit, int $offset): array
    {
        [$sql, $order] = self::baseSql($def);
        [$where, $params] = self::where($def, $f);
        $stmt = db()->prepare("{$sql} WHERE {$where} ORDER BY {$order} LIMIT ? OFFSET ?");
        $stmt->execute([...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    public static function find(array $def, int $id): ?array
    {
        [$sql] = self::baseSql($def);
        $where  = 't.id = ?';
        $params = [$id];
        if ($def['list'] !== null) {
            $where   .= ' AND t.list = ?';
            $params[] = $def['list'];
        }
        $stmt = db()->prepare("{$sql} WHERE {$where}");
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    /**
     * Choices for a select: active entries plus $currentId (even when inactive, so editing a record
     * never silently drops its value). Rows: id, name (+ brand_id for models, code for units).
     */
    public static function options(string $key, ?int $currentId = null): array
    {
        $def  = self::def($key);
        $cols = ['t.id', 't.name', 't.is_active'];
        foreach (array_intersect($def['fields'], ['code', 'brand_id', 'icon']) as $field) {
            $cols[] = "t.{$field}";
        }
        $where  = ['(t.is_active = ? OR t.id = ?)'];
        $params = [1, $currentId ?? 0];
        if ($def['list'] !== null) {
            $where[]  = 't.list = ?';
            $params[] = $def['list'];
        }
        $order = self::has($def, 'sort_order') ? 't.sort_order, t.name' : 't.name';
        $stmt = db()->prepare('SELECT ' . implode(', ', $cols) . " FROM {$def['table']} t WHERE "
            . implode(' AND ', $where) . " ORDER BY {$order}");
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['id'] = (int) $row['id'];
            $rows[$row['id']] = $row;
        }
        return $rows;
    }

    /** Is $id a valid choice: an active entry, or the record's current value? */
    public static function isChoice(string $key, ?int $id, ?int $currentId = null): bool
    {
        return $id !== null && isset(self::options($key, $currentId)[$id]);
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * @param array|null $row the entry being edited (from find()), null for a new one
     * @return array{0: array, 1: array<string,string>}
     */
    public static function validate(array $def, array $in, ?array $row): array
    {
        $data = [
            'name'      => input_string($in, 'name', (int) $def['name_max']),
            'is_active' => isset($in['is_active']) ? 1 : 0,
        ];
        $errors = [];
        $id     = (int) ($row['id'] ?? 0);

        if (self::has($def, 'code')) {
            $data['code'] = strtoupper(input_string($in, 'code', 10));
            if (!preg_match('/^[A-Z0-9]{1,10}$/', $data['code'])) {
                $errors['code'] = 'Use 1–10 capital letters or numbers (e.g. PC).';
            } elseif (self::taken($def, 'code', $data['code'], $id, null)) {
                $errors['code'] = 'Another unit already uses this code.';
            }
        }
        if (self::has($def, 'brand_id')) {
            $data['brand_id'] = input_int($in, 'brand_id', 1);
            $currentBrand     = $row !== null ? (int) $row['brand_id'] : null;
            if (!self::isChoice('brands', $data['brand_id'], $currentBrand)) {
                $errors['brand_id'] = 'Choose a brand.';
            } elseif ($row !== null && $data['brand_id'] !== $currentBrand && (int) $row['used'] > 0) {
                $errors['brand_id'] = "Products use this model, so its brand can't change. Add a new model under the other brand instead.";
            }
        }
        if (self::has($def, 'icon')) {
            $data['icon'] = input_string($in, 'icon', 30);
            if (!in_array($data['icon'], self::CATEGORY_ICONS, true)) {
                $errors['icon'] = 'Choose an icon.';
            }
        }
        if (self::has($def, 'sort_order')) {
            $raw = $in['sort_order'] ?? '';
            $data['sort_order'] = is_string($raw) && trim($raw) === '' ? 0 : input_int($in, 'sort_order', 0, self::MAX_SORT);
            if ($data['sort_order'] === null) {
                $errors['sort_order'] = 'Enter a whole number from 0 to ' . self::MAX_SORT . '.';
            }
        }

        if ($data['name'] === '') {
            $errors['name'] = 'Enter a name.';
        } elseif (!isset($errors['brand_id']) && self::taken($def, 'name', $data['name'], $id, $data['brand_id'] ?? null)) {
            $errors['name'] = self::has($def, 'brand_id')
                ? 'This brand already has a model with this name.'
                : "{$def['singular']} “{$data['name']}” already exists.";
        }
        return [$data, $errors];
    }

    /** Case-insensitive duplicate check (the tables use utf8mb4_unicode_ci). */
    private static function taken(array $def, string $column, string $value, int $exceptId, ?int $brandId): bool
    {
        $column = $column === 'code' ? 'code' : 'name'; // whitelist
        $sql    = "SELECT 1 FROM {$def['table']} WHERE LOWER({$column}) = LOWER(?) AND id <> ?";
        $params = [$value, $exceptId];
        if ($def['list'] !== null) {
            $sql     .= ' AND list = ?';
            $params[] = $def['list'];
        }
        if ($brandId !== null) {
            $sql     .= ' AND brand_id = ?';
            $params[] = $brandId;
        }
        $stmt = db()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    // ------------------------------------------------------------------
    // Writing
    // ------------------------------------------------------------------

    /** Create ($row = null) or update an entry. @return int the entry id */
    public static function save(array $def, array $data, ?array $row): int
    {
        self::requireManage($def);
        $columns = ['name', 'is_active', ...$def['fields']];
        $values  = array_map(static fn (string $c) => $data[$c], $columns);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($row === null) {
                if ($def['list'] !== null) {
                    $columns[] = 'list';
                    $values[]  = $def['list'];
                }
                if ($def['table'] === 'categories') {
                    $columns[] = 'slug';
                    $values[]  = self::uniqueSlug($data['name']);
                }
                $pdo->prepare("INSERT INTO {$def['table']} (" . implode(', ', $columns) . ') VALUES ('
                    . implode(', ', array_fill(0, count($columns), '?')) . ')')->execute($values);
                $id = (int) $pdo->lastInsertId();
                Audit::record('master_data', 'create', $def['key'], $id, $data['name'], null,
                    array_combine(['name', 'is_active', ...$def['fields']], array_slice($values, 0, count($def['fields']) + 2)));
            } else {
                $id = (int) $row['id'];
                // Lock the entry; a model's brand may only move while no product uses the model.
                $stmt = $pdo->prepare("SELECT id FROM {$def['table']} WHERE id = ? FOR UPDATE");
                $stmt->execute([$id]);
                if (self::has($def, 'brand_id') && (int) $data['brand_id'] !== (int) $row['brand_id'] && self::usage($def, $id) !== null) {
                    throw new HttpException(409, "Products use {$row['name']}, so its brand can't change.");
                }
                $set = implode(', ', array_map(static fn (string $c) => "{$c} = ?", $columns));
                $pdo->prepare("UPDATE {$def['table']} SET {$set} WHERE id = ?")->execute([...$values, $id]);
                [$old, $new] = Audit::diff(array_intersect_key($row, array_flip($columns)), array_combine($columns, $values));
                if ($new) {
                    Audit::record('master_data', 'update', $def['key'], $id, $data['name'], $old, $new);
                }
            }
            $pdo->commit();
            return $id;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new HttpException(422, "{$def['singular']} “{$data['name']}” already exists.");
            }
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** URL slug for a new category, unique in categories.slug. */
    private static function uniqueSlug(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $base = mb_substr($base !== '' ? $base : 'category', 0, 40);
        $slug = $base;
        $stmt = db()->prepare('SELECT 1 FROM categories WHERE slug = ?');
        for ($n = 2; ; $n++) {
            $stmt->execute([$slug]);
            if (!$stmt->fetchColumn()) {
                return $slug;
            }
            $slug = $base . '-' . $n;
        }
    }

    /** Why the entry can't be deleted (what references it), or null when unused. */
    private static function usage(array $def, int $id): ?string
    {
        foreach (self::USAGE[$def['key']] ?? [] as [$table, $column, $reason]) {
            // $table / $column come from the constant above, never from input.
            $stmt = db()->prepare("SELECT 1 FROM {$table} WHERE {$column} = ? LIMIT 1");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn()) {
                return $reason;
            }
        }
        return null;
    }

    /** @return array the entry after the change */
    public static function toggleActive(array $def, int $id): array
    {
        self::requireManage($def);
        $row  = self::find($def, $id) ?? throw new HttpException(404, "{$def['singular']} not found.");
        $next = (int) $row['is_active'] === 1 ? 0 : 1;
        $pdo  = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE {$def['table']} SET is_active = ? WHERE id = ?")->execute([$next, $id]);
            Audit::record('master_data', $next ? 'activate' : 'deactivate', $def['key'], $id, $row['name'],
                ['is_active' => (int) $row['is_active']], ['is_active' => $next]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $row['is_active'] = $next;
        return $row;
    }

    /** Hard delete, only while nothing references the entry. @return array the deleted entry */
    public static function delete(array $def, int $id): array
    {
        self::requireManage($def);
        $row = self::find($def, $id) ?? throw new HttpException(404, "{$def['singular']} not found.");
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT id FROM {$def['table']} WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $reason = self::usage($def, $id);
            if ($reason !== null) {
                throw new HttpException(409, "{$row['name']} is in use ({$reason}), so it can't be deleted. Deactivate it instead.");
            }
            $pdo->prepare("DELETE FROM {$def['table']} WHERE id = ?")->execute([$id]);
            Audit::record('master_data', 'delete', $def['key'], $id, $row['name'],
                array_intersect_key($row, array_flip(['name', 'is_active', ...$def['fields']])), null);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((int) ($e->errorInfo[1] ?? 0) === 1451) {
                throw new HttpException(409, "{$row['name']} is in use, so it can't be deleted. Deactivate it instead.");
            }
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $row;
    }
}
