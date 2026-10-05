<?php
/**
 * The document chain strip at the top of every buying / selling document (includes/doc-chain.php):
 *   buy   Request → PO Internal → Receiving → Supplier invoice → Disbursement
 *   sell  Quotation → PO Outgoing → Delivery → Bill → Collection
 * of(type, id) starts from the open document, walks UP the chain (payment → invoice → RR → PO → PR, collection → bill
 * → order → quotation) and back DOWN, so every linked document shows whichever one is open. A stage is shown only
 * when the user can open its pages (same checks as Flow::tabs()); every query stays in the branch scope.
 * Read-only, no money amounts (costs stay on the pages that already check products.cost).
 */
declare(strict_types=1);

final class DocChain
{
    private const MAX = 6; // entries shown per stage ("+N more" after)

    /** @param string $type pr | po | rr | ap | dv | qt | co | dr | bill | cr  @return array{side:string, stages:list<array>}|null */
    public static function of(string $type, int $id): ?array
    {
        $buy = in_array($type, ['pr', 'po', 'rr', 'ap', 'dv'], true);
        $ids = array_fill_keys($buy ? ['pr', 'po', 'rr', 'ap', 'dv'] : ['qt', 'co', 'dr', 'bill', 'cr'], []);
        $ids[$type] = [$id];
        $buy ? self::expandBuy($ids) : self::expandSell($ids);
        $stages = $buy ? self::buyStages($ids, $type, $id) : self::sellStages($ids, $type, $id);
        $linked = array_sum(array_map(static fn (array $s): int => $s['total'], $stages));
        return $linked > 1 ? ['side' => $buy ? 'buy' : 'sell', 'stages' => $stages] : null;
    }

    // ------------------------------------------------------------------
    // Buying
    // ------------------------------------------------------------------

    private static function expandBuy(array &$ids): void
    {
        // up: DV → invoices → RRs → POs → PRs
        $ids['ap'] = self::merge($ids['ap'], self::col('SELECT DISTINCT dl.invoice_id FROM disbursement_lines dl WHERE dl.disbursement_id IN (%s)', $ids['dv']));
        $ids['rr'] = self::merge($ids['rr'], self::col('SELECT DISTINCT i.receiving_id FROM supplier_invoices i WHERE i.id IN (%s)', $ids['ap']));
        $ids['po'] = self::merge($ids['po'], self::col('SELECT DISTINCT r.po_id FROM receiving_reports r WHERE r.id IN (%s) AND r.po_id IS NOT NULL', $ids['rr']));
        $ids['pr'] = self::merge($ids['pr'], self::col(
            'SELECT DISTINCT rl.request_id FROM purchase_order_request_lines x JOIN purchase_order_lines ol ON ol.id = x.po_line_id
               JOIN purchase_request_lines rl ON rl.id = x.request_line_id WHERE ol.po_id IN (%s)', $ids['po']));
        // down: PRs → POs → RRs → invoices → DVs
        $ids['po'] = self::merge($ids['po'], self::col(
            'SELECT DISTINCT ol.po_id FROM purchase_order_request_lines x JOIN purchase_order_lines ol ON ol.id = x.po_line_id
               JOIN purchase_request_lines rl ON rl.id = x.request_line_id WHERE rl.request_id IN (%s)', $ids['pr']));
        $ids['rr'] = self::merge($ids['rr'], self::col('SELECT r.id FROM receiving_reports r WHERE r.po_id IN (%s)', $ids['po']));
        $ids['ap'] = self::merge($ids['ap'], self::col('SELECT i.id FROM supplier_invoices i WHERE i.receiving_id IN (%s)', $ids['rr']));
        $ids['dv'] = self::merge($ids['dv'], self::col('SELECT DISTINCT dl.disbursement_id FROM disbursement_lines dl WHERE dl.invoice_id IN (%s)', $ids['ap']));
    }

    private static function buyStages(array $ids, string $type, int $id): array
    {
        $stages = [];
        if (Auth::canAny('purchasing.request', 'purchasing.approve', 'purchasing.order')) {
            $stages[] = self::stage('Request', $type === 'pr' ? $id : null, self::docs(
                'SELECT t.id, t.pr_no AS no, t.status FROM purchase_requests t WHERE t.id IN (%s)', $ids['pr'],
                static fn (array $r): array => [$r['no'], 'pages/pr-view.php?id=' . $r['id'], PurchaseRequests::STATUSES[$r['status']] ?? $r['status'],
                    PurchaseRequests::BADGES[$r['status']] ?? '']));
        }
        if (PurchaseOrders::canView()) {
            $stages[] = self::stage('PO Internal', $type === 'po' ? $id : null, self::docs(
                'SELECT t.id, t.po_no AS no, t.status FROM purchase_orders t WHERE t.id IN (%s)', $ids['po'],
                static fn (array $r): array => [$r['no'] ?? 'Draft PO #' . $r['id'], 'pages/po-view.php?id=' . $r['id'],
                    PurchaseOrders::STATUSES[$r['status']] ?? $r['status'], PurchaseOrders::BADGES[$r['status']] ?? '']));
        }
        if (Auth::can('receiving.view')) {
            $stages[] = self::stage('Receiving', $type === 'rr' ? $id : null, self::docs(
                'SELECT t.id, t.rr_no AS no, t.status FROM receiving_reports t WHERE t.id IN (%s)', $ids['rr'],
                static fn (array $r): array => [$r['no'] ?? 'Draft #' . $r['id'], 'pages/receiving-view.php?id=' . $r['id'],
                    Receiving::STATUSES[$r['status']] ?? $r['status'], ['draft' => 'badge--info', 'posted' => 'badge--success', 'cancelled' => 'badge--danger'][$r['status']] ?? '']));
        }
        if (Payables::canView()) {
            $stages[] = self::stage('Supplier invoice', $type === 'ap' ? $id : null, self::docs(
                'SELECT t.id, t.ap_no AS no, t.status, t.paid_amount, t.due_date FROM supplier_invoices t WHERE t.id IN (%s)', $ids['ap'],
                static fn (array $r): array => [$r['no'], 'pages/ap-view.php?id=' . $r['id'], ...match (true) {
                    $r['status'] === 'open' && $r['due_date'] < date('Y-m-d') => ['Overdue', 'badge--danger'],
                    $r['status'] === 'open' && to_cents((string) $r['paid_amount']) > 0 => ['Partly paid', 'badge--warning'],
                    default => [Payables::STATUSES[$r['status']] ?? $r['status'], Payables::BADGES[$r['status']] ?? ''],
                }]), ($ids['rr'] && !$ids['ap']) ? 'Not invoiced yet' : null);
            $stages[] = self::stage('Disbursement', $type === 'dv' ? $id : null, self::docs(
                'SELECT t.id, t.dv_no AS no, t.status, t.check_status FROM disbursements t WHERE t.id IN (%s)', $ids['dv'],
                static fn (array $r): array => [$r['no'], 'pages/dv-view.php?id=' . $r['id'], ...match (true) {
                    $r['status'] === 'cancelled'      => ['Cancelled', 'badge--danger'],
                    $r['check_status'] === 'issued'   => ['Check not cleared', 'badge--warning'],
                    $r['check_status'] === 'cleared'  => ['Check cleared', 'badge--success'],
                    default                           => ['Paid', 'badge--success'],
                }]), $ids['ap'] && !$ids['dv'] ? 'Not paid yet' : null);
        }
        return $stages;
    }

    // ------------------------------------------------------------------
    // Selling
    // ------------------------------------------------------------------

    private static function expandSell(array &$ids): void
    {
        // up: collections → bills → orders (+ DRs of the bills) → quotations
        $ids['bill'] = self::merge($ids['bill'], self::col('SELECT DISTINCT cl.sale_id FROM collection_lines cl WHERE cl.collection_id IN (%s)', $ids['cr']));
        $ids['co'] = self::merge($ids['co'], self::col('SELECT DISTINCT d.order_id FROM customer_deliveries d WHERE d.id IN (%s)', $ids['dr']));
        $ids['co'] = self::merge($ids['co'], self::col('SELECT DISTINCT s.customer_order_id FROM sales s WHERE s.id IN (%s) AND s.customer_order_id IS NOT NULL', $ids['bill']));
        $ids['qt'] = self::merge($ids['qt'], self::col('SELECT DISTINCT o.quotation_id FROM customer_orders o WHERE o.id IN (%s) AND o.quotation_id IS NOT NULL', $ids['co']));
        // down: quotations → orders → DRs + bills → collections
        $ids['co'] = self::merge($ids['co'], self::col('SELECT o.id FROM customer_orders o WHERE o.quotation_id IN (%s)', $ids['qt']));
        $ids['dr'] = self::merge($ids['dr'], self::col('SELECT d.id FROM customer_deliveries d WHERE d.order_id IN (%s)', $ids['co']));
        $ids['bill'] = self::merge($ids['bill'], self::col('SELECT s.id FROM sales s WHERE s.customer_order_id IN (%s)', $ids['co']));
        $ids['cr'] = self::merge($ids['cr'], self::col('SELECT DISTINCT cl.collection_id FROM collection_lines cl WHERE cl.sale_id IN (%s)', $ids['bill']));
    }

    private static function sellStages(array $ids, string $type, int $id): array
    {
        $stages = [];
        $orders = Auth::canAny(...CustomerOrders::VIEW_PERMISSIONS);
        if ($orders) {
            $stages[] = self::stage('Quotation', $type === 'qt' ? $id : null, self::docs(
                'SELECT t.id, t.quote_no AS no, t.status FROM quotations t WHERE t.id IN (%s)', $ids['qt'],
                static fn (array $r): array => [$r['no'], 'pages/quote-view.php?id=' . $r['id'], Quotations::STATUSES[$r['status']] ?? $r['status'],
                    Quotations::BADGES[$r['status']] ?? '']));
            $stages[] = self::stage('PO Outgoing', $type === 'co' ? $id : null, self::docs(
                'SELECT t.id, t.order_no AS no, t.status FROM customer_orders t WHERE t.id IN (%s)', $ids['co'],
                static fn (array $r): array => [$r['no'] ?? 'Draft Order #' . $r['id'], 'pages/co-view.php?id=' . $r['id'],
                    CustomerOrders::STATUSES[$r['status']] ?? $r['status'], CustomerOrders::BADGES[$r['status']] ?? '']));
            $stages[] = self::stage('Delivery', $type === 'dr' ? $id : null, self::docs(
                'SELECT t.id, t.dr_no AS no, t.status FROM customer_deliveries t WHERE t.id IN (%s)', $ids['dr'],
                static fn (array $r): array => [$r['no'], 'pages/dr-view.php?id=' . $r['id'], CustomerDeliveries::STATUSES[$r['status']] ?? $r['status'],
                    CustomerDeliveries::BADGES[$r['status']] ?? '']), $ids['co'] && !$ids['dr'] ? 'Nothing delivered yet' : null);
        }
        if ($orders || Auth::can('sales.view') || Collections::canView()) {
            $link = Auth::can('sales.view');
            $stages[] = self::stage('Bill', $type === 'bill' ? $id : null, self::docs(
                'SELECT t.id, t.sale_no AS no, t.status, t.payment_type, t.total, t.settled_amount, t.due_date FROM sales t WHERE t.id IN (%s)', $ids['bill'],
                static fn (array $r): array => ['Bill No. ' . $r['no'], $link ? 'pages/sale-view.php?id=' . $r['id'] : null, ...match (true) {
                    $r['status'] !== 'completed'                                          => ['Voided', 'badge--danger'],
                    $r['payment_type'] !== 'charge' || to_cents((string) $r['settled_amount']) >= to_cents((string) $r['total']) => ['Paid', 'badge--success'],
                    $r['due_date'] !== null && $r['due_date'] < date('Y-m-d')             => ['Overdue', 'badge--danger'],
                    to_cents((string) $r['settled_amount']) > 0                           => ['Partly paid', 'badge--warning'],
                    default                                                               => ['Unpaid', 'badge--warning'],
                }]), $ids['dr'] && !$ids['bill'] ? 'Not billed yet' : null);
        }
        if (Collections::canView()) {
            $stages[] = self::stage('Collection', $type === 'cr' ? $id : null, self::docs(
                'SELECT t.id, t.collection_no AS no, t.status, t.check_status FROM collections t WHERE t.id IN (%s)', $ids['cr'],
                static fn (array $r): array => [$r['no'], 'pages/collection-view.php?id=' . $r['id'], ...match (true) {
                    $r['check_status'] === 'bounced'   => ['Check bounced', 'badge--danger'],
                    $r['status'] === 'cancelled'       => ['Cancelled', 'badge--danger'],
                    $r['check_status'] === 'on_hand'   => ['Check on hand', 'badge--warning'],
                    $r['check_status'] === 'deposited' => ['Check deposited', 'badge--info'],
                    $r['check_status'] === 'cleared'   => ['Check cleared', 'badge--success'],
                    default                            => ['Received', 'badge--success'],
                }]));
        }
        return $stages;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Ids from one query over a set of ids (%s = placeholders); [] when the set is empty. */
    private static function col(string $sql, array $in): array
    {
        if (!$in) {
            return [];
        }
        $stmt = db()->prepare(sprintf($sql, implode(',', array_fill(0, count($in), '?'))));
        $stmt->execute(array_values($in));
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function merge(array $a, array $b): array
    {
        return array_values(array_unique([...$a, ...$b]));
    }

    /** Rows (alias t, branch scope) mapped to [no, url|null, status label, badge]. */
    private static function docs(string $sql, array $in, callable $map): array
    {
        if (!$in) {
            return [];
        }
        [$scope, $params] = Branch::scopeSql('t.branch_id');
        $stmt = db()->prepare(sprintf($sql, implode(',', array_fill(0, count($in), '?'))) . " AND {$scope} ORDER BY t.id");
        $stmt->execute([...array_values($in), ...$params]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['id']] = $map($r);
        }
        return $out;
    }

    private static function stage(string $label, ?int $currentId, array $docs, ?string $empty = null): array
    {
        $entries = [];
        foreach ($docs as $docId => [$no, $url, $status, $badge]) {
            $entries[] = ['no' => $no, 'url' => $url, 'status' => $status, 'badge' => $badge, 'current' => $docId === $currentId];
        }
        usort($entries, static fn (array $a, array $b): int => $b['current'] <=> $a['current']); // the open document first
        return ['label' => $label, 'entries' => array_slice($entries, 0, self::MAX), 'more' => max(0, count($entries) - self::MAX),
            'total' => count($entries), 'empty' => $empty, 'current' => $currentId !== null];
    }
}
