<?php
/**
 * POS pricing rules (Phase 9). Prices are VAT-exclusive (VAT is added on top, as before).
 *
 *   suggested = the branch price, else products.price (BranchPrices); the cashier may charge another price ("actual") per line:
 *     - any change needs pos.change_price; a lower price needs a reason;
 *     - lower than the role limit (roles.max_price_drop %) or below cost needs approval;
 *   sale discount: needs pos.discount; above roles.max_discount % needs approval;
 *   below cost = the line's net price (after the sale discount) is under the branch average cost.
 *   Approval = a user with pos.price_override: either the seller themselves, or another user who types their
 *   own username + password at the till (approve()) -> one-time token for that cashier, branch and exact
 *   product + price (or discount %), valid APPROVAL_MINUTES, consumed by Sales::complete().
 * Cost is never sent to the browser here; "needs approval" does not say whether the cause is the limit or the cost.
 */
declare(strict_types=1);

final class Pricing
{
    public const APPROVAL_MINUTES = 5;
    public const MAX_PRICE_CENTS  = 999999999; // 9,999,999.99

    /** @return array{price_drop:float, discount:float, change_price:bool, give_discount:bool, override:bool} */
    public static function limits(): array
    {
        $user = Auth::user();
        if ($user === null) {
            return ['price_drop' => 0.0, 'discount' => 0.0, 'change_price' => false, 'give_discount' => false, 'override' => false];
        }
        if (Auth::isSuper()) {
            return ['price_drop' => 100.0, 'discount' => 100.0, 'change_price' => true, 'give_discount' => true, 'override' => true];
        }
        $stmt = db()->prepare('SELECT max_price_drop, max_discount FROM roles WHERE code = ?');
        $stmt->execute([$user['role']]);
        $row = $stmt->fetch() ?: ['max_price_drop' => 0, 'max_discount' => 0];
        return [
            'price_drop'    => (float) $row['max_price_drop'],
            'discount'      => (float) $row['max_discount'],
            'change_price'  => Auth::can('pos.change_price'),
            'give_discount' => Auth::can('pos.discount'),
            'override'      => Auth::can('pos.price_override'),
        ];
    }

    /** Percent as integer hundredths ("12.5" -> 1250). */
    public static function bp(float|string $percent): int
    {
        return (int) round((float) $percent * 100);
    }

    /** Is $actual more than $limitBp below $suggested? (integer math) */
    public static function beyondLimit(int $suggested, int $actual, int $limitBp): bool
    {
        return $actual < $suggested && ($suggested - $actual) * 10000 > $suggested * $limitBp;
    }

    /** Is the net unit price (after a $discountBp sale discount) below $costUnits (1/10000)? Cost 0 = unknown: never. */
    public static function belowCost(int $actual, int $discountBp, int $costUnits): bool
    {
        return $costUnits > 0 && $actual * (10000 - $discountBp) < $costUnits * 100;
    }

    /**
     * An approver types their credentials at the cashier's till. $lines = list of [product_id, price cents];
     * $discountPercent = the sale discount to allow (or null). @return array{approver:string,
     * lines: array<int,string>, discount:?string} one-time tokens (product_id => token, discount token)
     */
    public static function approve(string $username, #[SensitiveParameter] string $password, array $lines, ?string $discountPercent): array
    {
        if (!Auth::can('pos.access')) {
            throw new HttpException(403, 'You do not have permission to use the POS.');
        }
        $branchId  = Branch::forWrite();
        $cashierId = (int) Auth::id();
        if (!$lines && $discountPercent === null) {
            throw new HttpException(422, 'Nothing to approve.');
        }
        if (count($lines) > Sales::MAX_LINES) {
            throw new HttpException(422, 'Too many lines to approve.');
        }
        $approver = Auth::verifyCredentials($username, $password);
        if ($approver['id'] === $cashierId) {
            throw new HttpException(403, 'Another person must approve. If you may approve yourself, the sale goes through without this step.');
        }
        if (!Auth::userCan($approver, 'pos.price_override')) {
            throw new HttpException(403, "{$approver['full_name']} may not approve prices or discounts.");
        }
        $accessAll = (int) $approver['is_super'] === 1 || Auth::userCan($approver, 'branches.access_all');
        if (!in_array($branchId, Branch::allowedIdsFor($approver['id'], $approver['branch_id'], $accessAll), true)) {
            throw new HttpException(403, "{$approver['full_name']} does not work at this branch.");
        }

        $clean = [];
        foreach ($lines as $line) {
            $pid   = (int) ($line[0] ?? 0);
            $price = (int) ($line[1] ?? -1);
            if ($pid < 1 || $price < 0 || $price > self::MAX_PRICE_CENTS || isset($clean[$pid])) {
                throw new HttpException(422, 'Invalid item to approve.');
            }
            $clean[$pid] = $price;
        }
        if ($discountPercent !== null && (self::bp($discountPercent) < 0 || self::bp($discountPercent) > 10000)) {
            throw new HttpException(422, 'Discount must be between 0 and 100%.');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $products = [];
            if ($clean) {
                $in_  = implode(',', array_fill(0, count($clean), '?'));
                $stmt = $pdo->prepare("SELECT id, code FROM products WHERE id IN ({$in_}) AND is_active = 1");
                $stmt->execute(array_keys($clean));
                $products = array_column($stmt->fetchAll(), 'code', 'id');
                if (count($products) !== count($clean)) {
                    throw new HttpException(422, 'An item to approve is no longer available.');
                }
            }
            $expires = date('Y-m-d H:i:s', time() + self::APPROVAL_MINUTES * 60);
            $ins = $pdo->prepare(
                'INSERT INTO price_approvals (token_hash, branch_id, cashier_id, approver_id, product_id, price, discount_percent, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $out = ['approver' => $approver['full_name'], 'lines' => [], 'discount' => null];
            foreach ($clean as $pid => $price) {
                $token = bin2hex(random_bytes(32));
                $ins->execute([hash('sha256', $token), $branchId, $cashierId, $approver['id'], $pid, from_cents($price), null, $expires]);
                $out['lines'][$pid] = $token;
            }
            if ($discountPercent !== null) {
                $token = bin2hex(random_bytes(32));
                $ins->execute([hash('sha256', $token), $branchId, $cashierId, $approver['id'], null, null,
                               number_format(self::bp($discountPercent) / 100, 2, '.', ''), $expires]);
                $out['discount'] = $token;
            }
            Audit::record('sales', 'price_approval', 'user', $cashierId, null, null, array_filter([
                'approver' => $approver['full_name'],
                'items'    => array_map(static fn (int $pid, int $price): string => $products[$pid] . ' @ ' . from_cents($price), array_keys($clean), $clean) ?: null,
                'discount_percent' => $discountPercent,
            ], static fn ($v) => $v !== null), $branchId);
            $pdo->commit();
            return $out;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Use an approval inside the sale transaction: it must be unused, unexpired, for this cashier + branch and
     * exactly this product + price (or this discount). @return ?int the approver id, null when it does not match
     */
    public static function consume(string $token, int $branchId, int $cashierId, ?int $productId, ?int $priceCents,
                                   ?int $discountBp, int $saleId): ?int
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $pdo  = db();
        $stmt = $pdo->prepare('SELECT * FROM price_approvals WHERE token_hash = ? FOR UPDATE');
        $stmt->execute([hash('sha256', $token)]);
        $a = $stmt->fetch();
        if (!$a || $a['used_at'] !== null || strtotime((string) $a['expires_at']) < time()
            || (int) $a['branch_id'] !== $branchId || (int) $a['cashier_id'] !== $cashierId) {
            return null;
        }
        $ok = $productId !== null
            ? (int) $a['product_id'] === $productId && $a['price'] !== null && to_cents($a['price']) === $priceCents
            : $a['product_id'] === null && $a['discount_percent'] !== null && self::bp($a['discount_percent']) === $discountBp;
        if (!$ok) {
            return null;
        }
        $pdo->prepare('UPDATE price_approvals SET used_at = NOW(), sale_id = ? WHERE id = ?')->execute([$saleId, (int) $a['id']]);
        return (int) $a['approver_id'];
    }
}
