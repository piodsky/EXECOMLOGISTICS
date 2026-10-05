<?php
/**
 * Payment status of purchase orders (PO Internal: supplier invoices + disbursement vouchers of its posted receiving
 * reports) and customer orders (PO Outgoing: its bills + collections), read-only. Used by the PO / order lists, Order
 * Tracking and the "Payment" card on po-view / co-view (includes/payment-card.php).
 *
 *   PO     none (nothing received) / to_invoice (received, no supplier invoice) / unpaid / partial / paid
 *          (every posted RR invoiced and every invoice paid). Needs Payables::canView() (costs).
 *   order  none (nothing delivered) / to_bill (delivered, nothing billed) / unpaid / partial / paid (every delivery
 *          billed and every bill paid). A cash / GCash / card bill is paid at billing; an on-account bill is paid
 *          by its collections (sales.settled_amount). Check details need Collections::canView().
 * A check that is still on hand / deposited (customer) or issued (supplier) is noted: it is counted as paid by the
 * collection / voucher, but the money is not in (out of) the bank yet.
 */
declare(strict_types=1);

final class PaymentStatus
{
    public const LABELS = ['none' => '—', 'to_invoice' => 'Not invoiced', 'to_bill' => 'Not billed', 'unpaid' => 'Unpaid',
                           'partial' => 'Partly paid', 'paid' => 'Paid'];
    public const BADGES = ['none' => '', 'to_invoice' => 'badge--info', 'to_bill' => 'badge--info', 'unpaid' => 'badge--danger',
                           'partial' => 'badge--warning', 'paid' => 'badge--success'];
    public const FILTERS = ['unpaid' => 'Payment: not fully paid', 'paid' => 'Payment: fully paid'];

    // ------------------------------------------------------------------
    // Purchase orders (alias o)
    // ------------------------------------------------------------------

    private const PO_RR = "(SELECT COUNT(*) FROM receiving_reports prr WHERE prr.po_id = o.id AND prr.status = 'posted')";
    private const PO_UNINVOICED = "(SELECT COUNT(*) FROM receiving_reports prr WHERE prr.po_id = o.id AND prr.status = 'posted'
                                      AND NOT EXISTS (SELECT 1 FROM supplier_invoices psi WHERE psi.receiving_id = prr.id AND psi.status <> 'cancelled'))";
    private const PO_INVOICED = "(SELECT COALESCE(SUM(psi.amount), 0) FROM supplier_invoices psi JOIN receiving_reports prr ON prr.id = psi.receiving_id
                                   WHERE prr.po_id = o.id AND psi.status <> 'cancelled')";
    private const PO_PAID = "(SELECT COALESCE(SUM(psi.paid_amount), 0) FROM supplier_invoices psi JOIN receiving_reports prr ON prr.id = psi.receiving_id
                               WHERE prr.po_id = o.id AND psi.status <> 'cancelled')";

    /** Extra SELECT columns for a purchase order list (alias o). */
    public const PO_COLUMNS = self::PO_RR . ' AS pay_rr, ' . self::PO_UNINVOICED . ' AS pay_uninvoiced, '
        . self::PO_INVOICED . ' AS pay_invoiced, ' . self::PO_PAID . ' AS pay_paid, '
        . "(SELECT COUNT(*) FROM supplier_invoices psi JOIN receiving_reports prr ON prr.id = psi.receiving_id
             WHERE prr.po_id = o.id AND psi.status = 'open' AND psi.due_date < CURDATE()) AS pay_overdue, "
        . "(SELECT COUNT(DISTINCT pd.id) FROM disbursements pd JOIN disbursement_lines pdl ON pdl.disbursement_id = pd.id
              JOIN supplier_invoices psi ON psi.id = pdl.invoice_id JOIN receiving_reports prr ON prr.id = psi.receiving_id
             WHERE prr.po_id = o.id AND pd.status = 'posted' AND pd.check_status = 'issued') AS pay_checks";

    /** WHERE fragment for a PO list payment filter (unpaid / paid), or null. */
    public static function poWhere(string $filter): ?string
    {
        $paid = self::PO_RR . ' > 0 AND ' . self::PO_UNINVOICED . ' = 0 AND ' . self::PO_INVOICED . ' > 0 AND ' . self::PO_PAID . ' >= ' . self::PO_INVOICED;
        return match ($filter) {
            'paid'   => "({$paid})",
            'unpaid' => '(' . self::PO_RR . " > 0 AND NOT ({$paid}))",
            default  => null,
        };
    }

    /** Row with the PO_COLUMNS → ['key', 'label', 'badge', 'notes' => string[]]. */
    public static function po(array $row): array
    {
        $invoiced = to_cents((string) $row['pay_invoiced']);
        $paid     = to_cents((string) $row['pay_paid']);
        $key = match (true) {
            (int) $row['pay_rr'] === 0 => 'none',
            $invoiced === 0            => 'to_invoice',
            $paid === 0                => 'unpaid',
            $paid < $invoiced || (int) $row['pay_uninvoiced'] > 0 => 'partial',
            default                    => 'paid',
        };
        $notes = [];
        if ((int) $row['pay_overdue'] > 0) {
            $notes[] = 'Overdue';
        }
        if ((int) $row['pay_checks'] > 0) {
            $notes[] = 'Check not cleared';
        }
        if ($invoiced > 0 && (int) $row['pay_uninvoiced'] > 0) {
            $notes[] = (int) $row['pay_uninvoiced'] . ' receiving not invoiced';
        }
        return self::status($key, $notes);
    }

    /** Payment card of one purchase order (caller checked Payables::canView() and access to the PO). */
    public static function poCard(int $poId): array
    {
        $stmt = db()->prepare('SELECT o.id, ' . self::PO_COLUMNS . ' FROM purchase_orders o WHERE o.id = ?');
        $stmt->execute([$poId]);
        $status = self::po($stmt->fetch());

        $stmt = db()->prepare(
            "SELECT si.id, si.ap_no, si.invoice_no, si.invoice_date, si.due_date, si.amount, si.paid_amount, si.status, rr.rr_no
               FROM supplier_invoices si JOIN receiving_reports rr ON rr.id = si.receiving_id
              WHERE rr.po_id = ? ORDER BY si.id"
        );
        $stmt->execute([$poId]);
        $invoices = $stmt->fetchAll();
        $payments = [];
        if ($invoices) {
            $ids = array_map(static fn (array $i): int => (int) $i['id'], $invoices);
            $stmt = db()->prepare(
                'SELECT dl.invoice_id, d.id, d.dv_no, d.payment_date, d.method, d.reference, d.bank_name, d.check_date, d.check_status,
                        d.cleared_at, d.status, dl.amount, dl.ewt_amount
                   FROM disbursement_lines dl JOIN disbursements d ON d.id = dl.disbursement_id
                  WHERE dl.invoice_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY d.payment_date, d.id'
            );
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $p) {
                $payments[(int) $p['invoice_id']][] = $p;
            }
        }

        $owed = $paid = 0;
        $docs = [];
        $today = date('Y-m-d');
        foreach ($invoices as $i) {
            $live = $i['status'] !== 'cancelled';
            $balance = to_cents((string) $i['amount']) - to_cents((string) $i['paid_amount']);
            if ($live) {
                $owed += to_cents((string) $i['amount']);
                $paid += to_cents((string) $i['paid_amount']);
            }
            $late = $i['status'] === 'open' && $i['due_date'] < $today;
            $lines = [];
            foreach ($payments[(int) $i['id']] ?? [] as $p) {
                $ewt = to_cents((string) $p['ewt_amount']);
                $lines[] = [
                    'no'    => $p['dv_no'],
                    'url'   => 'pages/dv-view.php?id=' . (int) $p['id'],
                    'void'  => $p['status'] === 'cancelled',
                    'sub'   => self::when($p['payment_date']) . ' · ' . self::method($p['method'], $p['reference'], $p['bank_name'], $p['check_date']),
                    'amount' => money($p['amount']) . ($ewt > 0 ? ' + EWT ' . money($p['ewt_amount']) : ''),
                    'badge' => match (true) {
                        $p['status'] === 'cancelled'      => ['Cancelled', 'badge--danger'],
                        $p['check_status'] === 'issued'   => ['Check issued, not cleared', 'badge--warning'],
                        $p['check_status'] === 'cleared'  => ['Cleared ' . self::when($p['cleared_at']), 'badge--success'],
                        default                           => ['Paid', 'badge--success'],
                    },
                ];
            }
            $docs[] = [
                'no'    => $i['ap_no'],
                'url'   => 'pages/ap-view.php?id=' . (int) $i['id'],
                'void'  => !$live,
                'sub'   => 'Supplier invoice ' . $i['invoice_no'] . ' · ' . $i['rr_no'] . ' · due ' . self::when($i['due_date']),
                'amount' => money($i['amount']) . ($live && $balance > 0 && $balance < to_cents((string) $i['amount']) ? ' · balance ' . money(from_cents($balance)) : ''),
                'badge' => match (true) {
                    !$live                     => ['Cancelled', 'badge--danger'],
                    $i['status'] === 'paid'    => ['Paid', 'badge--success'],
                    $late                      => ['Overdue', 'badge--danger'],
                    to_cents((string) $i['paid_amount']) > 0 => ['Partly paid', 'badge--warning'],
                    default                    => ['Unpaid', 'badge--warning'],
                },
                'lines' => $lines,
                'none'  => $live && !$lines ? 'No payment yet.' : null,
            ];
        }

        $stmt = db()->prepare(
            "SELECT rr.id, rr.rr_no, rr.received_date, rr.total_cost FROM receiving_reports rr
              WHERE rr.po_id = ? AND rr.status = 'posted'
                AND NOT EXISTS (SELECT 1 FROM supplier_invoices si WHERE si.receiving_id = rr.id AND si.status <> 'cancelled') ORDER BY rr.id"
        );
        $stmt->execute([$poId]);
        foreach ($stmt->fetchAll() as $rr) {
            $docs[] = ['no' => $rr['rr_no'], 'url' => 'pages/receiving-view.php?id=' . (int) $rr['id'], 'void' => false,
                'sub' => 'Received ' . self::when($rr['received_date']) . ': no supplier invoice recorded yet',
                'amount' => money($rr['total_cost'] ?? 0), 'badge' => ['Not invoiced', 'badge--info'], 'lines' => [], 'none' => null,
                'action' => Auth::can('payables.manage') ? ['Record invoice', 'pages/ap-form.php?rr=' . (int) $rr['id']] : null];
        }

        return [
            'title'  => 'Payment to supplier',
            'status' => $status,
            'totals' => $owed > 0 ? [['Invoiced', money(from_cents($owed)), ''], ['Paid', money(from_cents($paid)), ''],
                ['Balance', money(from_cents($owed - $paid)), $owed > $paid ? 'text-danger' : '']] : [],
            'docs'   => $docs,
            'empty'  => $status['key'] === 'none' ? 'Nothing received yet: there is nothing to pay.' : null,
            'foot'   => 'Payments are recorded in Payables (supplier invoice → disbursement voucher).',
        ];
    }

    // ------------------------------------------------------------------
    // Customer orders (alias o)
    // ------------------------------------------------------------------

    private const CO_BILLS  = "(SELECT COUNT(*) FROM sales cs WHERE cs.customer_order_id = o.id AND cs.status = 'completed')";
    private const CO_BILLED = "(SELECT COALESCE(SUM(cs.total), 0) FROM sales cs WHERE cs.customer_order_id = o.id AND cs.status = 'completed')";
    private const CO_PAID   = "(SELECT COALESCE(SUM(IF(cs.payment_type = 'charge', cs.settled_amount, cs.total)), 0) FROM sales cs
                                 WHERE cs.customer_order_id = o.id AND cs.status = 'completed')";
    private const CO_TO_BILL = '(SELECT COALESCE(SUM(cl.qty_delivered - cl.qty_billed), 0) FROM customer_order_lines cl WHERE cl.order_id = o.id)';

    /** Extra SELECT columns for a customer order list (alias o). */
    public const CO_COLUMNS = "o.status AS pay_order_status, " . self::CO_BILLS . ' AS pay_bills, ' . self::CO_BILLED . ' AS pay_billed, ' . self::CO_PAID . ' AS pay_paid, '
        . self::CO_TO_BILL . ' AS pay_to_bill, '
        . "(SELECT COALESCE(SUM(cl.qty_delivered), 0) FROM customer_order_lines cl WHERE cl.order_id = o.id) AS pay_delivered, "
        . "(SELECT COUNT(*) FROM sales cs WHERE cs.customer_order_id = o.id AND cs.status = 'completed' AND cs.payment_type = 'charge'
              AND cs.settled_amount < cs.total AND cs.due_date < CURDATE()) AS pay_overdue, "
        . "(SELECT COUNT(DISTINCT cc.id) FROM collections cc JOIN collection_lines ccl ON ccl.collection_id = cc.id
              JOIN sales cs ON cs.id = ccl.sale_id
             WHERE cs.customer_order_id = o.id AND cc.status = 'posted' AND cc.check_status IN ('on_hand', 'deposited')) AS pay_checks, "
        . "(SELECT COUNT(DISTINCT cc.id) FROM collections cc JOIN collection_lines ccl ON ccl.collection_id = cc.id
              JOIN sales cs ON cs.id = ccl.sale_id
             WHERE cs.customer_order_id = o.id AND cc.status = 'posted' AND cc.form_2307 = 'pending') AS pay_2307";

    public static function orderWhere(string $filter): ?string
    {
        return match ($filter) {
            'paid'   => '(' . self::CO_BILLS . ' > 0 AND ' . self::CO_PAID . ' >= ' . self::CO_BILLED . ' AND ' . self::CO_TO_BILL . ' = 0)',
            'unpaid' => '(' . self::CO_BILLS . ' > 0 AND ' . self::CO_PAID . ' < ' . self::CO_BILLED . ')',
            default  => null,
        };
    }

    public static function order(array $row): array
    {
        $billed = to_cents((string) $row['pay_billed']);
        $paid   = to_cents((string) $row['pay_paid']);
        $key = match (true) {
            (int) $row['pay_bills'] === 0 => (int) $row['pay_delivered'] > 0 ? 'to_bill' : 'none',
            $paid === 0                   => 'unpaid',
            $paid < $billed || (int) $row['pay_to_bill'] > 0 => 'partial',
            default                       => 'paid',
        };
        $notes = [];
        if ((int) $row['pay_overdue'] > 0) {
            $notes[] = 'Overdue';
        }
        if ((int) $row['pay_checks'] > 0) {
            $notes[] = 'Check not cleared';
        }
        if ((int) $row['pay_2307'] > 0) {
            $notes[] = '2307 to receive';
        }
        if ((int) $row['pay_bills'] > 0 && in_array($row['pay_order_status'], ['confirmed', 'partial'], true)) {
            $notes[] = 'More to deliver';
        }
        if ((int) $row['pay_bills'] > 0 && (int) $row['pay_to_bill'] > 0) {
            $notes[] = 'Delivered items not billed';
        }
        return self::status($key, $notes);
    }

    /** Payment card of one customer order (caller can view the order). */
    public static function orderCard(int $orderId): array
    {
        $stmt = db()->prepare('SELECT o.id, ' . self::CO_COLUMNS . ' FROM customer_orders o WHERE o.id = ?');
        $stmt->execute([$orderId]);
        $status = self::order($stmt->fetch());

        $stmt = db()->prepare(
            'SELECT id, sale_no, status, total, payment_type, settled_amount, due_date, created_at FROM sales WHERE customer_order_id = ? ORDER BY id'
        );
        $stmt->execute([$orderId]);
        $bills = $stmt->fetchAll();
        $showCollections = Collections::canView();
        $collections = [];
        if ($bills && $showCollections) {
            $ids = array_map(static fn (array $b): int => (int) $b['id'], $bills);
            $stmt = db()->prepare(
                'SELECT cl.sale_id, c.id, c.collection_no, c.collection_date, c.method, c.reference, c.bank_name, c.check_date, c.check_status,
                        c.deposited_at, c.cleared_at, c.form_2307, c.form_2307_received_at, c.status, cl.amount, cl.ewt_amount, cl.vat_withheld
                   FROM collection_lines cl JOIN collections c ON c.id = cl.collection_id
                  WHERE cl.sale_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY c.collection_date, c.id'
            );
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $c) {
                $collections[(int) $c['sale_id']][] = $c;
            }
        }

        $billed = $paid = 0;
        $docs = [];
        $today = date('Y-m-d');
        foreach ($bills as $b) {
            $live   = $b['status'] === 'completed';
            $charge = $b['payment_type'] === 'charge';
            $total  = to_cents((string) $b['total']);
            $got    = $charge ? to_cents((string) $b['settled_amount']) : $total;
            if ($live) {
                $billed += $total;
                $paid   += $got;
            }
            $lines = [];
            if (!$charge) {
                $lines[] = ['no' => 'Paid at billing', 'url' => null, 'void' => !$live,
                    'sub' => self::when($b['created_at']) . ' · ' . (Sales::ALL_PAYMENT_TYPES[$b['payment_type']] ?? $b['payment_type']),
                    'amount' => money($b['total']), 'badge' => $live ? ['Paid', 'badge--success'] : ['Voided', 'badge--danger']];
            }
            foreach ($collections[(int) $b['id']] ?? [] as $c) {
                $extra = [];
                if (to_cents((string) $c['ewt_amount']) > 0) {
                    $extra[] = 'EWT ' . money($c['ewt_amount']);
                }
                if (to_cents((string) $c['vat_withheld']) > 0) {
                    $extra[] = 'VAT withheld ' . money($c['vat_withheld']);
                }
                $sub = self::when($c['collection_date']) . ' · ' . self::method($c['method'], $c['reference'], $c['bank_name'], $c['check_date']);
                if ($c['form_2307'] === 'pending') {
                    $sub .= ' · 2307 to receive';
                } elseif ($c['form_2307'] === 'received') {
                    $sub .= ' · 2307 received ' . self::when($c['form_2307_received_at']);
                }
                $lines[] = [
                    'no'     => $c['collection_no'],
                    'url'    => 'pages/collection-view.php?id=' . (int) $c['id'],
                    'void'   => $c['status'] === 'cancelled',
                    'sub'    => $sub,
                    'amount' => money($c['amount']) . ($extra ? ' + ' . implode(' + ', $extra) : ''),
                    'badge'  => match (true) {
                        $c['check_status'] === 'bounced'   => ['Check bounced', 'badge--danger'],
                        $c['status'] === 'cancelled'       => ['Cancelled', 'badge--danger'],
                        $c['check_status'] === 'on_hand'   => ['Check on hand, not deposited', 'badge--warning'],
                        $c['check_status'] === 'deposited' => ['Deposited ' . self::when($c['deposited_at']) . ', not cleared', 'badge--info'],
                        $c['check_status'] === 'cleared'   => ['Cleared ' . self::when($c['cleared_at']), 'badge--success'],
                        default                            => ['Received', 'badge--success'],
                    },
                ];
            }
            $balance = $total - $got;
            $late = $live && $charge && $balance > 0 && $b['due_date'] !== null && $b['due_date'] < $today;
            $docs[] = [
                'no'     => 'Bill No. ' . $b['sale_no'],
                'url'    => Auth::can('sales.view') ? 'pages/sale-view.php?id=' . (int) $b['id'] : null,
                'void'   => !$live,
                'sub'    => self::when($b['created_at']) . ' · ' . (Sales::ALL_PAYMENT_TYPES[$b['payment_type']] ?? $b['payment_type'])
                    . ($charge && $b['due_date'] !== null ? ' · due ' . self::when($b['due_date']) : ''),
                'amount' => money($b['total']) . ($live && $balance > 0 && $balance < $total ? ' · balance ' . money(from_cents($balance)) : ''),
                'badge'  => match (true) {
                    !$live         => ['Voided', 'badge--danger'],
                    $balance <= 0  => ['Paid', 'badge--success'],
                    $late          => ['Overdue', 'badge--danger'],
                    $got > 0       => ['Partly paid', 'badge--warning'],
                    default        => ['Unpaid', 'badge--warning'],
                },
                'lines'  => $lines,
                'none'   => $live && $charge && !$lines ? ($showCollections ? 'No collection yet.' : 'Collected ' . money(from_cents($got)) . '.') : null,
                'action' => ['Statement', 'pages/bill-print.php?id=' . (int) $b['id'], 'printer', true],
            ];
        }

        return [
            'title'  => 'Payment from customer',
            'status' => $status,
            'totals' => $billed > 0 ? [['Billed', money(from_cents($billed)), ''], ['Collected', money(from_cents($paid)), ''],
                ['Balance', money(from_cents($billed - $paid)), $billed > $paid ? 'text-danger' : '']] : [],
            'docs'   => $docs,
            'empty'  => $bills ? null : ($status['key'] === 'to_bill' ? 'Delivered but not billed yet.' : 'Nothing billed yet.'),
            'foot'   => 'On-account bills are paid through Billing & Collections (collection receipts, checks).',
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Live supplier invoice of a receiving report (receiving-view link), or null. */
    public static function invoiceForReceiving(int $rrId): ?array
    {
        $stmt = db()->prepare("SELECT id, ap_no, status, amount, paid_amount FROM supplier_invoices WHERE receiving_id = ? AND status <> 'cancelled' LIMIT 1");
        $stmt->execute([$rrId]);
        return $stmt->fetch() ?: null;
    }

    private static function status(string $key, array $notes): array
    {
        return ['key' => $key, 'label' => self::LABELS[$key], 'badge' => self::BADGES[$key], 'notes' => $notes];
    }

    private static function when(?string $date): string
    {
        return $date ? date('M j, Y', strtotime($date)) : '—';
    }

    private static function method(string $method, ?string $ref, ?string $bank, ?string $checkDate): string
    {
        return match ($method) {
            'check' => 'Check No. ' . ($ref ?? '—') . ($bank ? ' · ' . $bank : '') . ($checkDate ? ' · dated ' . self::when($checkDate) : ''),
            'bank'  => 'Bank' . ($bank ? ' (' . $bank . ')' : '') . ($ref ? ' · ref. ' . $ref : ''),
            'gcash' => 'GCash' . ($ref ? ' · ref. ' . $ref : ''),
            default => Collections::METHODS[$method] ?? $method,
        };
    }
}
