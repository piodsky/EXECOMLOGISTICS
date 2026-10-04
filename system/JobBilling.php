<?php
/**
 * Billing and release of completed job orders (Phase 10b), audit module 'job_orders'.
 *
 *   bill()        job_orders.release: a normal sale (sales.job_order_id) with the parts used (line_type 'part', current
 *                 suggested price, cost = the issue snapshot) + labour (line_type 'labor', no product). VAT, discount
 *                 (POS role limit, no till approval here) and payment as at the POS. The parts left stock when they
 *                 were issued, so the sale writes NO stock movement. The job becomes released (paid).
 *   releaseFree() warranty (job_orders.release + job_orders.assign, reason required) or no charge (nothing to bill).
 *   onSaleVoid()  Sales::void() of a bill: the job goes back to completed (not released); parts stay installed.
 * Release needs: status completed, no open parts requests, nothing in custody, the claim stub (or a note).
 */
declare(strict_types=1);

final class JobBilling
{
    /**
     * Bill preview from the parts used + labour. Prices in cents.
     * @return array{lines: list<array{type:string, product_id:?int, code:string, name:string, qty:int, price:int, cost:?string, serials:list<int>}>,
     *               subtotal:int}
     */
    public static function quote(array $job): array
    {
        $stmt = db()->prepare(
            "SELECT jp.id, jp.product_id, jp.qty_used, jp.unit_cost, p.code, p.name, p.price
               FROM job_order_parts jp JOIN products p ON p.id = jp.product_id
              WHERE jp.job_order_id = ? AND jp.status = 'issued' AND jp.qty_used > 0 ORDER BY jp.product_id, jp.id"
        );
        $stmt->execute([(int) $job['id']]);
        $byProduct = [];
        foreach ($stmt->fetchAll() as $r) {
            $pid = (int) $r['product_id'];
            $byProduct[$pid] ??= ['type' => 'part', 'product_id' => $pid, 'code' => (string) $r['code'], 'name' => (string) $r['name'],
                                  'qty' => 0, 'price' => to_cents($r['price']), 'costs' => [], 'parts' => []];
            $byProduct[$pid]['qty'] += (int) $r['qty_used'];
            $byProduct[$pid]['costs'][] = ['qty' => (int) $r['qty_used'], 'cost' => (string) $r['unit_cost']];
            $byProduct[$pid]['parts'][] = (int) $r['id'];
        }
        $lines = [];
        $subtotal = 0;
        foreach ($byProduct as $l) {
            $serials = [];
            $in_  = implode(',', array_fill(0, count($l['parts']), '?'));
            $stmt = db()->prepare("SELECT serial_id FROM job_order_part_serials WHERE part_id IN ({$in_}) AND state = 'used' ORDER BY serial_id");
            $stmt->execute($l['parts']);
            $serials = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $lines[] = ['type' => 'part', 'product_id' => $l['product_id'], 'code' => $l['code'], 'name' => $l['name'], 'qty' => $l['qty'],
                        'price' => $l['price'], 'cost' => Costing::weighted($l['costs']), 'serials' => $serials];
            $subtotal += $l['price'] * $l['qty'];
        }
        $labor = $job['labor'] !== null ? to_cents($job['labor']) : 0;
        if ($labor > 0) {
            $lines[] = ['type' => 'labor', 'product_id' => null, 'code' => 'LABOR', 'name' => 'Labour / service · ' . $job['job_no'],
                        'qty' => 1, 'price' => $labor, 'cost' => null, 'serials' => []];
            $subtotal += $labor;
        }
        return ['lines' => $lines, 'subtotal' => $subtotal];
    }

    /** Why the job can't be released yet, or null. */
    public static function blocker(array $job): ?string
    {
        if ($job['status'] !== 'completed') {
            return "{$job['job_no']} is " . strtolower(JobOrders::STATUSES[$job['status']] ?? $job['status']) . ', so it cannot be released.';
        }
        $open = JobParts::openCounts((int) $job['id']);
        if ($open['pending'] > 0) {
            return 'Cancel or issue the open parts requests first.';
        }
        if ($open['custody'] > 0) {
            return "{$open['custody']} issued part" . ($open['custody'] === 1 ? ' is' : 's are') . ' still with the technician: record them as used or return them first.';
        }
        return null;
    }

    /** Release actions for the current user: bill, no_charge, warranty. */
    public static function actions(array $job, array $quote): array
    {
        $ok = Branch::current() === (int) $job['branch_id'] && $job['status'] === 'completed' && Auth::can('job_orders.release');
        return [
            'bill'      => $ok && $quote['subtotal'] > 0,
            'no_charge' => $ok && $quote['subtotal'] === 0,
            'warranty'  => $ok && $quote['subtotal'] > 0 && Auth::can('job_orders.assign'),
        ];
    }

    /**
     * Bill and release: discount_percent, payment_type, amount_paid (cash), released_to, stub (1) or release_note.
     * @return array{sale_id:int, sale_no:string, total_cents:int, change_cents:int}
     */
    public static function bill(int $jobId, array $in, int $userId): array
    {
        if (!Auth::can('job_orders.release')) {
            throw new HttpException(403, 'You do not have permission to bill and release job orders.');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $j = JobOrders::lockForAction($jobId);
            if ($b = self::blocker($j)) {
                throw new HttpException(409, $b);
            }
            [$to, $note] = self::releaseFields($in);
            $quote = self::quote($j);
            if ($quote['subtotal'] <= 0) {
                throw new HttpException(422, 'There is nothing to charge on this job. Release it without charge instead.');
            }
            $payment = input_string($in, 'payment_type', 10);
            if (!isset(Sales::PAYMENT_TYPES[$payment]) && !($payment === 'charge' && Auth::can('sales.charge'))) {
                throw new HttpException(422, 'Choose the payment type.', ['errors' => ['payment_type' => 'Choose the payment type.']]);
            }
            $rawDisc  = $in['discount_percent'] ?? '';
            $discount = is_string($rawDisc) && trim($rawDisc) === '' ? 0.0 : input_decimal($in, 'discount_percent', 0, 100, 2);
            if ($discount === null) {
                throw new HttpException(422, 'Enter a discount from 0 to 100%.', ['errors' => ['discount_percent' => '0 to 100']]);
            }
            if ($discount > 0) {
                $lim = Pricing::limits();
                if (!$lim['give_discount']) {
                    throw new HttpException(403, 'You do not have permission to give discounts.');
                }
                if (Pricing::bp($discount) > Pricing::bp($lim['discount']) && !$lim['override']) {
                    throw new HttpException(422, 'A ' . rtrim(rtrim(number_format($discount, 2), '0'), '.') . '% discount is above your limit of '
                        . rtrim(rtrim(number_format($lim['discount'], 2), '0'), '.') . '%. Ask a branch admin to bill this job.',
                        ['errors' => ['discount_percent' => 'Above your limit.']]);
                }
            }
            $subtotal = $quote['subtotal'];
            $vatRate  = (float) setting('vat_rate', '12');
            $disc     = (int) round($subtotal * $discount / 100);
            $vat      = (int) round(($subtotal - $disc) * $vatRate / 100);
            $total    = $subtotal - $disc + $vat;
            if ($payment === 'cash') {
                $raw  = is_string($in['amount_paid'] ?? null) ? str_replace(',', '', $in['amount_paid']) : '';
                $paid = input_decimal(['v' => $raw], 'v', 0, 9999999.99, 2);
                if ($paid === null || to_cents((string) $paid) < $total) {
                    throw new HttpException(422, 'Amount received is less than the total of ' . money(from_cents($total)) . '.',
                        ['errors' => ['amount_paid' => 'At least ' . money(from_cents($total)) . '.']]);
                }
                $paidCents = to_cents((string) $paid);
            } else {
                $paidCents = $total;
            }
            $change = $paidCents - $total;
            $dueDate = null;
            if ($payment === 'charge') { // on account: the job's customer must be a credit customer (checked below)
                $change = 0;
            }
            $costCents = 0;
            foreach ($quote['lines'] as $l) {
                if ($l['cost'] !== null) {
                    $costCents += Costing::lineCents($l['qty'], $l['cost']);
                }
            }
            // The job's customer when still active and visible at this branch, else walk-in.
            $customerId = null;
            if ($j['customer_id'] !== null) {
                $stmt = $pdo->prepare('SELECT c.id FROM customers c WHERE c.id = ? AND c.is_active = 1
                                          AND EXISTS (SELECT 1 FROM customer_branches cb WHERE cb.customer_id = c.id AND cb.branch_id = ?)');
                $stmt->execute([(int) $j['customer_id'], (int) $j['branch_id']]);
                $customerId = $stmt->fetchColumn() !== false ? (int) $j['customer_id'] : null;
            }
            if ($payment === 'charge') {
                $dueDate   = Collections::chargeTerms($customerId, $total, true);
                $paidCents = 0;
            }

            $pdo->prepare(
                'INSERT INTO sales (sale_no, branch_id, user_id, customer_id, job_order_id, payment_type, status, subtotal, discount_percent,
                                    discount_amount, vat_rate, vat_amount, total, cost_total, amount_paid, change_amount, created_at, completed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            )->execute([
                'TMP' . bin2hex(random_bytes(8)), (int) $j['branch_id'], $userId, $customerId, $jobId, $payment, 'completed',
                from_cents($subtotal), number_format($discount, 2, '.', ''), from_cents($disc), number_format($vatRate, 2, '.', ''),
                from_cents($vat), from_cents($total), from_cents($costCents), from_cents($paidCents), from_cents($change),
            ]);
            $saleId = (int) $pdo->lastInsertId();
            $saleNo = Sales::formatNumber($saleId);
            $pdo->prepare('UPDATE sales SET sale_no = ?, due_date = ? WHERE id = ?')->execute([$saleNo, $dueDate, $saleId]);

            $item = $pdo->prepare(
                'INSERT INTO sale_items (sale_id, product_id, line_type, product_code, product_name, unit_price, suggested_price, unit_cost, quantity, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $link = $pdo->prepare('INSERT INTO sale_item_serials (sale_item_id, serial_id) VALUES (?, ?)');
            foreach ($quote['lines'] as $l) {
                $item->execute([$saleId, $l['product_id'], $l['type'], mb_substr($l['code'], 0, 20), mb_substr($l['name'], 0, 100),
                    from_cents($l['price']), from_cents($l['price']), $l['cost'], $l['qty'], from_cents($l['price'] * $l['qty'])]);
                $itemId = (int) $pdo->lastInsertId();
                foreach ($l['serials'] as $sid) {
                    $link->execute([$itemId, $sid]); // shows the S/N on the receipt; the serial stays 'installed'
                }
            }

            $pdo->prepare(
                "UPDATE job_orders SET status = 'released', release_type = 'paid', released_to = ?, release_note = ?, released_by = ?,
                                       released_at = NOW(), sale_id = ? WHERE id = ?"
            )->execute([$to, $note, $userId, $saleId, $jobId]);
            JobOrders::event($jobId, $userId, 'release', 'completed', 'released',
                "Billed on sale No. {$saleNo} (" . money(from_cents($total)) . "). Released to {$to}." . ($note !== null ? " {$note}" : ''));
            Audit::record('job_orders', 'release', 'job_order', $jobId, $j['job_no'], ['status' => 'completed'],
                array_filter(['status' => 'released', 'type' => 'paid', 'sale_no' => $saleNo, 'total' => from_cents($total),
                              'discount_percent' => $discount > 0 ? number_format($discount, 2, '.', '') : null, 'released_to' => $to],
                    static fn ($v) => $v !== null), (int) $j['branch_id']);
            $pdo->commit();
            return ['sale_id' => $saleId, 'sale_no' => $saleNo, 'total_cents' => $total, 'change_cents' => $paidCents - $total];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Release without a sale: $type 'warranty' (needs job_orders.assign + reason in release_note) or 'no_charge'. */
    public static function releaseFree(int $jobId, string $type, array $in, int $userId): string
    {
        if (!Auth::can('job_orders.release')) {
            throw new HttpException(403, 'You do not have permission to release job orders.');
        }
        if ($type === 'warranty' && !Auth::can('job_orders.assign')) {
            throw new HttpException(403, 'A free warranty release needs a branch admin.');
        }
        if (!in_array($type, ['warranty', 'no_charge'], true)) {
            throw new HttpException(400, 'Unknown release type.');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $j = JobOrders::lockForAction($jobId);
            if ($b = self::blocker($j)) {
                throw new HttpException(409, $b);
            }
            $quote = self::quote($j);
            if ($type === 'no_charge' && $quote['subtotal'] > 0) {
                throw new HttpException(422, 'This job has parts or labour to charge: bill it, or release it under warranty.');
            }
            [$to, $note] = self::releaseFields($in);
            if ($type === 'warranty' && ($note === null || mb_strlen($note) < 3)) {
                throw new HttpException(422, 'Enter the warranty reason (3 to 255 characters).', ['errors' => ['release_note' => 'Enter the warranty reason.']]);
            }
            $pdo->prepare(
                "UPDATE job_orders SET status = 'released', release_type = ?, released_to = ?, release_note = ?, released_by = ?, released_at = NOW()
                  WHERE id = ?"
            )->execute([$type, $to, $note, $userId, $jobId]);
            $label = $type === 'warranty' ? 'Released under warranty (no charge' . ($quote['subtotal'] > 0 ? ', ' . money(from_cents($quote['subtotal'])) . ' waived' : '') . ')'
                                          : 'Released with no charge';
            JobOrders::event($jobId, $userId, 'release', 'completed', 'released', "{$label} to {$to}." . ($note !== null ? " {$note}" : ''));
            Audit::record('job_orders', 'release', 'job_order', $jobId, $j['job_no'], ['status' => 'completed'],
                array_filter(['status' => 'released', 'type' => $type, 'released_to' => $to, 'note' => $note,
                              'waived' => $type === 'warranty' ? from_cents($quote['subtotal']) : null], static fn ($v) => $v !== null),
                (int) $j['branch_id']);
            $pdo->commit();
            return "{$j['job_no']} was released to {$to}" . ($type === 'warranty' ? ' under warranty.' : ' with no charge.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Inside Sales::void() (its transaction, sale row locked): a voided bill re-opens its job (completed, not released).
     * Parts stay installed (no stock change). @return ?string job no when the job was re-opened
     */
    public static function onSaleVoid(int $saleId, int $jobId, int $userId, string $reason): ?string
    {
        $stmt = db()->prepare('SELECT id, job_no, status, sale_id, branch_id FROM job_orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$jobId]);
        $j = $stmt->fetch();
        if (!$j || (int) $j['sale_id'] !== $saleId || $j['status'] !== 'released') {
            return null;
        }
        db()->prepare(
            "UPDATE job_orders SET status = 'completed', release_type = NULL, released_to = NULL, release_note = NULL, released_by = NULL,
                                   released_at = NULL, sale_id = NULL WHERE id = ?"
        )->execute([$jobId]);
        JobOrders::event($jobId, $userId, 'bill_voided', 'released', 'completed', "The bill was voided: {$reason}. Bill the job again before releasing it.");
        Audit::record('job_orders', 'bill_voided', 'job_order', $jobId, $j['job_no'], ['status' => 'released'], ['status' => 'completed', 'sale_id' => $saleId],
            (int) $j['branch_id']);
        return (string) $j['job_no'];
    }

    /** released_to (2-100, default the customer's name) + note; the claim stub ticked or a note (3+). @return array{0:string, 1:?string} */
    private static function releaseFields(array $in): array
    {
        $to   = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', input_string($in, 'released_to', 101)) ?? '');
        $note = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', input_string($in, 'release_note', 256)) ?? '');
        $note = $note === '' ? null : $note;
        $errs = [];
        if (mb_strlen($to) < 2 || mb_strlen($to) > 100) {
            $errs['released_to'] = 'Enter who collects the device (2 to 100 characters).';
        }
        if ($note !== null && mb_strlen($note) > 255) {
            $errs['release_note'] = 'Keep the note under 255 characters.';
        }
        if (empty($in['stub']) && ($note === null || mb_strlen($note) < 3)) {
            $errs['stub'] = 'Tick "claim stub presented", or write how the person was identified in the note.';
        }
        if ($errs) {
            throw new HttpException(422, reset($errs), ['errors' => $errs]);
        }
        return [$to, $note];
    }
}
