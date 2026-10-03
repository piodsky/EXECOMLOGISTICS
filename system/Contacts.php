<?php
/**
 * Contact persons of a supplier or customer (supplier_contacts / customer_contacts, Phase 6).
 * Form fields: contacts[i][name|position|phone|email]; completely blank rows are ignored.
 * Saved by the owner's create/update inside its transaction: delete + re-insert (max MAX rows).
 * Field errors / old input use flat keys "contact_{i}_{field}" (flash_old only keeps strings).
 */
declare(strict_types=1);

final class Contacts
{
    public const MAX = 5;
    private const FIELDS = ['name' => 100, 'position' => 60, 'phone' => 30, 'email' => 120];
    /** owner table => [contacts table, foreign key] (code literals, never from input) */
    private const TABLES = ['suppliers' => ['supplier_contacts', 'supplier_id'], 'customers' => ['customer_contacts', 'customer_id']];

    /**
     * @return array{0: list<array{name:string, position:?string, phone:?string, email:?string}>, 1: array<string,string>}
     */
    public static function parse(array $input): array
    {
        $rows   = is_array($input['contacts'] ?? null) ? $input['contacts'] : [];
        $out    = [];
        $errors = [];
        foreach (array_values($rows) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $c = [];
            foreach (self::FIELDS as $field => $max) {
                $c[$field] = input_string($row, $field, $max);
            }
            if (implode('', $c) === '') {
                continue; // blank row
            }
            if (mb_strlen($c['name']) < 2) {
                $errors["contact_{$i}_name"] = 'Enter the contact name.';
            }
            if ($c['phone'] !== '' && !preg_match('/^[0-9+()\s-]{7,30}$/', $c['phone'])) {
                $errors["contact_{$i}_phone"] = 'Digits, spaces, + - ( ) only (7–30).';
            }
            if ($c['email'] !== '' && filter_var($c['email'], FILTER_VALIDATE_EMAIL) === false) {
                $errors["contact_{$i}_email"] = 'Enter a valid email address.';
            }
            foreach (['position', 'phone', 'email'] as $field) {
                $c[$field] = $c[$field] !== '' ? $c[$field] : null;
            }
            $out[] = $c;
        }
        if (count($out) > self::MAX) {
            $errors['contacts'] = 'Add at most ' . self::MAX . ' contacts.';
        }
        return [$out, $errors];
    }

    /** Submitted rows as flat strings for flash_old(): contact_{i}_{field}. */
    public static function flatOld(array $input): array
    {
        $flat = [];
        $rows = is_array($input['contacts'] ?? null) ? array_values($input['contacts']) : [];
        foreach (array_slice($rows, 0, self::MAX) as $i => $row) {
            foreach (array_keys(self::FIELDS) as $field) {
                $flat["contact_{$i}_{$field}"] = is_array($row) && is_string($row[$field] ?? null) ? $row[$field] : '';
            }
        }
        $flat['contact_rows'] = (string) min(self::MAX, count($rows));
        return $flat;
    }

    public static function forOwner(string $owner, int $id): array
    {
        [$table, $fk] = self::TABLES[$owner] ?? throw new LogicException("Unknown contact owner [{$owner}]");
        $stmt = db()->prepare("SELECT name, position, phone, email FROM {$table} WHERE {$fk} = ? ORDER BY sort_order, id");
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    }

    /** Replace the owner's contacts. Call inside the owner's transaction. */
    public static function replace(string $owner, int $id, array $contacts): void
    {
        [$table, $fk] = self::TABLES[$owner] ?? throw new LogicException("Unknown contact owner [{$owner}]");
        if (!db()->inTransaction()) {
            throw new LogicException('Contacts must be saved inside a transaction.');
        }
        $pdo = db();
        $pdo->prepare("DELETE FROM {$table} WHERE {$fk} = ?")->execute([$id]);
        $add = $pdo->prepare("INSERT INTO {$table} ({$fk}, name, position, phone, email, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
        foreach (array_slice(array_values($contacts), 0, self::MAX) as $i => $c) {
            $add->execute([$id, $c['name'], $c['position'], $c['phone'], $c['email'], $i + 1]);
        }
    }

    /**
     * Rows to render on a form: old input after a failed submit, else the saved ones; plus blank
     * rows (at least 2, never more than MAX in total).
     * @param list<array> $saved
     */
    public static function formRows(array $saved): array
    {
        if (has_old()) {
            $rows = [];
            $n = min(self::MAX, max(0, (int) old('contact_rows', '0')));
            for ($i = 0; $i < $n; $i++) {
                $row = [];
                foreach (array_keys(self::FIELDS) as $field) {
                    $row[$field] = old("contact_{$i}_{$field}");
                }
                $rows[] = $row;
            }
        } else {
            $rows = array_map(static fn (array $c) => array_map(static fn ($v) => (string) ($v ?? ''), $c), $saved);
            $rows = array_merge($rows, array_fill(0, 2, ['name' => '', 'position' => '', 'phone' => '', 'email' => '']));
        }
        return array_slice($rows, 0, self::MAX);
    }
}
