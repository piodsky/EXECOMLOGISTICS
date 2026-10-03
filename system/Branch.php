<?php
/**
 * The branch the signed-in user is working in, and branch scoping for queries.
 *
 *  - allowedIds(): active branches the user may open. branches.access_all = every active branch;
 *    otherwise the home branch (users.branch_id) + extra branches granted in user_branches.
 *  - current(): $_SESSION['branch_id'], re-validated on every request. 0 = "All branches"
 *    (only with branches.access_all). Missing/invalid -> home branch (or the first allowed one).
 *  - scopeSql('s.branch_id'): WHERE fragment + params limiting a query to the current scope.
 *  - forWrite(): the concrete branch new sales / stock / customers belong to.
 */
declare(strict_types=1);

final class Branch
{
    /** Session value meaning "All branches". */
    public const ALL = 0;

    /** @var array<int,array>|null id => branch row (memoised per request) */
    private static ?array $allowed = null;
    private static ?int $current = null;
    private static bool $resolved = false;

    public static function reset(): void
    {
        self::$allowed  = null;
        self::$current  = null;
        self::$resolved = false;
    }

    /**
     * Active branch ids a user may open (used at sign-in, before a session exists).
     * @return list<int>
     */
    public static function allowedIdsFor(int $userId, int $homeBranchId, bool $accessAll): array
    {
        return array_keys(self::loadAllowed($userId, $homeBranchId, $accessAll));
    }

    /** @return array<int,array> id => row (id, code, name, address, contact_no, tin_branch_code, is_main) */
    public static function allowed(): array
    {
        if (self::$allowed === null) {
            $user = Auth::user();
            self::$allowed = $user === null
                ? []
                : self::loadAllowed($user['id'], $user['branch_id'], Auth::can('branches.access_all'));
        }
        return self::$allowed;
    }

    /** @return list<int> */
    public static function allowedIds(): array
    {
        return array_keys(self::allowed());
    }

    private static function loadAllowed(int $userId, int $homeBranchId, bool $accessAll): array
    {
        $cols = 'b.id, b.code, b.name, b.address, b.contact_no, b.tin_branch_code, b.is_main';
        if ($accessAll) {
            $stmt = db()->prepare("SELECT {$cols} FROM branches b WHERE b.is_active = ? ORDER BY b.is_main DESC, b.name");
            $stmt->execute([1]);
        } else {
            $stmt = db()->prepare(
                "SELECT {$cols} FROM branches b
                  WHERE b.is_active = ?
                    AND (b.id = ? OR EXISTS (SELECT 1 FROM user_branches ub WHERE ub.user_id = ? AND ub.branch_id = b.id))
                  ORDER BY b.id = ? DESC, b.is_main DESC, b.name"
            );
            $stmt->execute([1, $homeBranchId, $userId, $homeBranchId]);
        }
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['id'] = (int) $row['id'];
            $rows[$row['id']] = $row;
        }
        return $rows;
    }

    /** Can the user pick "All branches"? */
    public static function canSeeAll(): bool
    {
        return Auth::can('branches.access_all');
    }

    /**
     * Current branch id, 0 (= All branches) or null when the user has no usable branch.
     */
    public static function current(): ?int
    {
        if (!self::$resolved) {
            self::$resolved = true;
            self::$current  = null;
            $user = Auth::user();
            if ($user !== null) {
                $ids = self::allowedIds();
                $want = $_SESSION['branch_id'] ?? null;
                if ($want === self::ALL && self::canSeeAll()) {
                    self::$current = self::ALL;
                } elseif (is_int($want) && in_array($want, $ids, true)) {
                    self::$current = $want;
                } elseif (in_array($user['branch_id'], $ids, true)) {
                    self::$current = $user['branch_id'];
                } else {
                    self::$current = $ids[0] ?? null;
                }
                if (self::$current !== null && $want !== self::$current) {
                    $_SESSION['branch_id'] = self::$current;
                }
            }
        }
        return self::$current;
    }

    /** True when the user is looking at one concrete branch. */
    public static function isConcrete(): bool
    {
        $cur = self::current();
        return $cur !== null && $cur !== self::ALL;
    }

    /** Row of the current branch, or null for "All branches" / none. */
    public static function currentBranch(): ?array
    {
        $cur = self::current();
        return $cur ? (self::allowed()[$cur] ?? null) : null;
    }

    /** Short label for headers and exports: "Maramag City" or "All branches". */
    public static function label(): string
    {
        $cur = self::current();
        if ($cur === self::ALL) {
            return 'All branches';
        }
        return self::currentBranch()['name'] ?? 'No branch';
    }

    /** The concrete branch new records are written to. @throws HttpException 422 for "All branches" */
    public static function forWrite(): int
    {
        $cur = self::current();
        if ($cur === null || $cur === self::ALL) {
            throw new HttpException(422, 'Choose a branch first.');
        }
        return $cur;
    }

    /**
     * Switch the session to another branch (0 = All). Returns false when not allowed.
     */
    public static function switchTo(int $branchId): bool
    {
        if ($branchId === self::ALL ? !self::canSeeAll() : !in_array($branchId, self::allowedIds(), true)) {
            return false;
        }
        $_SESSION['branch_id'] = $branchId;
        self::$resolved = false;
        return true;
    }

    /**
     * WHERE fragment limiting $column (a code literal like 's.branch_id') to the current scope.
     * @return array{0:string, 1:list<int>}
     */
    public static function scopeSql(string $column): array
    {
        if (!preg_match('/^[a-z_]+\.[a-z_]+$/', $column)) {
            throw new LogicException("Invalid scope column [{$column}]");
        }
        $cur = self::current();
        if ($cur === self::ALL) {
            return ['1 = 1', []];
        }
        if ($cur === null) {
            return ['0 = 1', []];
        }
        return ["{$column} IN (?)", [$cur]];
    }

    /** Is a record of this branch inside the current scope? */
    public static function inScope(?int $branchId): bool
    {
        $cur = self::current();
        if ($cur === self::ALL) {
            return true;
        }
        return $cur !== null && $branchId !== null && $branchId === $cur;
    }

    /** @throws HttpException 404 when the record's branch is out of scope */
    public static function assertAccess(?int $branchId): void
    {
        if (!self::inScope($branchId)) {
            throw new HttpException(404, 'Not found.');
        }
    }

    /**
     * Default sellable location of a branch's default warehouse (where POS sells from and
     * stock adjustments go). @return array{id:int, warehouse_id:int, branch_id:int, branch_name:string}
     */
    public static function defaultLocation(int $branchId): array
    {
        $stmt = db()->prepare(
            'SELECT l.id, l.warehouse_id, l.branch_id, b.name AS branch_name
               FROM storage_locations l
               JOIN warehouses w ON w.id = l.warehouse_id AND w.branch_id = l.branch_id
               JOIN branches b ON b.id = l.branch_id
              WHERE l.branch_id = ? AND w.is_default = 1 AND w.is_active = 1
                AND l.is_default = 1 AND l.is_sellable = 1 AND l.is_active = 1
              ORDER BY w.id, l.id
              LIMIT 1'
        );
        $stmt->execute([$branchId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new HttpException(409, 'This branch has no default stock location. Contact the administrator.');
        }
        return [
            'id'           => (int) $row['id'],
            'warehouse_id' => (int) $row['warehouse_id'],
            'branch_id'    => (int) $row['branch_id'],
            'branch_name'  => (string) $row['branch_name'],
        ];
    }
}
