<?php
/**
 * In-app notifications (migration 018). Made from the audit log: Audit::record() calls fromAudit() for every
 * transaction, inside the caller's transaction (a rollback removes the notification too).
 *
 *   event       "module.action" in RULES -> icon, tone, message ({actor} / {ref}), permissions that should know,
 *               optional people involved (requester, creator, the job's technicians …) and which branch counts.
 *   recipients  active users with one of the permissions who can access that branch (home branch, extra branch
 *               or branches.access_all) + every active super admin + the people involved; never the person who
 *               did it. One notification_recipients row each, with its own read_at.
 *   read        opening a notification (pages/notification-open.php) marks it read; "Mark all read".
 * Messages carry document numbers and names only, never amounts (cost rule). Failures are logged and never break
 * the transaction they came from.
 */
declare(strict_types=1);

final class Notifications
{
    /** event => [icon, tone, message, permissions, people involved (resolver) | null, branch: event | from | to] */
    private const RULES = [
        'purchasing.pr_create'      => ['cart', 'blue', '{actor} sent purchase request {ref} for approval', ['purchasing.approve'], null, 'event'],
        'purchasing.pr_approve'     => ['cart', 'green', '{actor} approved purchase request {ref}', ['purchasing.order'], 'requester', 'event'],
        'purchasing.pr_reject'      => ['cart', 'red', '{actor} rejected purchase request {ref}', [], 'requester', 'event'],
        'purchasing.po_submit'      => ['cart', 'blue', '{actor} sent {ref} for approval', ['purchasing.approve'], null, 'event'],
        'purchasing.po_approve'     => ['cart', 'green', '{actor} approved {ref}: send it to the supplier', ['purchasing.order', 'receiving.manage'], 'creator', 'event'],
        'purchasing.po_return'      => ['cart', 'orange', '{actor} returned {ref} to draft', [], 'creator', 'event'],
        'purchasing.po_cancel'      => ['cart', 'red', '{actor} cancelled {ref}', ['purchasing.order'], null, 'event'],
        'receiving.post'            => ['truck', 'green', '{actor} posted {ref}: record the supplier invoice', ['payables.manage'], null, 'event'],
        'receiving.cancel'          => ['truck', 'red', '{actor} cancelled {ref}', ['receiving.post'], null, 'event'],
        'transfers.request'         => ['network', 'blue', '{actor} requested stock from your branch: {ref}', ['transfers.approve'], null, 'from'],
        'transfers.approve'         => ['network', 'green', '{actor} approved {ref}: ready to release', ['transfers.release'], 'requester', 'from'],
        'transfers.release'         => ['network', 'blue', '{ref} is on its way: receive it when it arrives', ['transfers.receive'], 'requester', 'to'],
        'transfers.receive'         => ['network', 'green', '{actor} received {ref}', [], 'releaser', 'to'],
        'transfers.cancel'          => ['network', 'red', '{actor} cancelled {ref}', ['transfers.approve'], 'requester', 'from'],
        'customer_orders.submit'    => ['file', 'blue', '{actor} sent {ref} for confirmation', ['customer_orders.approve'], null, 'event'],
        'customer_orders.return'    => ['file', 'orange', '{actor} returned {ref} to draft', [], 'creator', 'event'],
        'customer_orders.confirm'   => ['file', 'green', '{actor} confirmed {ref}: stock reserved, ready to deliver', ['customer_orders.deliver'], 'creator', 'event'],
        'customer_orders.cancel'    => ['file', 'red', '{actor} cancelled {ref}', ['customer_orders.manage'], null, 'event'],
        'customer_orders.delivered' => ['truck', 'green', '{ref} was delivered: ready to bill', ['customer_orders.bill'], null, 'event'],
        'customer_orders.bill'      => ['receipt', 'green', '{actor} billed {ref}', ['collections.manage'], null, 'event'],
        'collections.post'          => ['wallet', 'green', '{actor} recorded collection {ref}', ['collections.cancel'], null, 'event'],
        'collections.cancel'        => ['wallet', 'red', '{actor} cancelled collection {ref}', ['collections.manage', 'collections.cancel'], null, 'event'],
        'payables.invoice'          => ['clipboard', 'blue', '{actor} recorded supplier invoice {ref}', ['payables.manage'], null, 'event'],
        'payables.pay'              => ['clipboard', 'green', '{actor} paid a supplier: {ref}', ['payables.manage'], null, 'event'],
        'payables.check_cleared'    => ['clipboard', 'green', 'The check of {ref} cleared', ['payables.manage'], null, 'event'],
        'job_orders.create'         => ['wrench', 'blue', 'New job {ref} is waiting for a technician', ['job_orders.assign', 'job_orders.update'], null, 'event'],
        'job_orders.assign'         => ['wrench', 'blue', '{actor} assigned {ref} to you', [], 'job_team', 'event'],
        'job_orders.diagnose'       => ['wrench', 'orange', "{ref} needs the customer's decision on the estimate", ['job_orders.create'], null, 'event'],
        'job_orders.decision'       => ['wrench', 'green', 'The customer answered the quotation for {ref}', [], 'job_team', 'event'],
        'job_orders.parts_request'  => ['wrench', 'blue', '{actor} requested parts for {ref}', ['job_parts.issue'], null, 'event'],
        'job_orders.parts_issue'    => ['wrench', 'green', 'Parts were issued for {ref}', [], 'job_team', 'event'],
        'job_orders.complete'       => ['wrench', 'green', '{ref} is completed: bill and release it', ['job_orders.release'], null, 'event'],
        'job_orders.cancel'         => ['wrench', 'red', '{actor} cancelled {ref}', [], 'job_team', 'event'],
        'inventory.count_submit'    => ['stock', 'blue', '{actor} submitted stock count {ref} for approval', ['counts.approve'], null, 'event'],
        'inventory.count_post'      => ['stock', 'green', 'Stock count {ref} was approved and posted', [], 'creator', 'event'],
        'sales.void'                => ['receipt', 'red', '{actor} voided sale No. {ref}', ['sales.cancel'], null, 'event'],
        'sales.price_override'      => ['receipt', 'orange', 'Sale No. {ref} was sold below the price limit (approved)', ['pos.price_override'], null, 'event'],
        'auth.login_locked'         => ['shield', 'red', 'Sign-in locked for "{ref}" after failed attempts', [], null, 'none'],
    ];

    /** entity type => page that shows it (?id=). */
    private const LINKS = [
        'purchase_request' => 'pr-view.php', 'purchase_order' => 'po-view.php', 'receiving' => 'receiving-view.php',
        'stock_transfer' => 'transfer-view.php', 'customer_order' => 'co-view.php', 'customer_delivery' => 'dr-view.php',
        'collection' => 'collection-view.php', 'supplier_invoice' => 'ap-view.php', 'disbursement' => 'dv-view.php',
        'job_order' => 'job-view.php', 'inventory_doc' => 'stock-doc-view.php', 'sale' => 'sale-view.php',
    ];

    /** People involved: resolver => [entity type => [table, column]] (table / column names are code literals). */
    private const PEOPLE = [
        'requester' => ['purchase_request' => ['purchase_requests', 'requested_by'], 'stock_transfer' => ['stock_transfers', 'requested_by']],
        'creator'   => ['purchase_order' => ['purchase_orders', 'created_by'], 'customer_order' => ['customer_orders', 'created_by'],
                        'inventory_doc' => ['inventory_docs', 'created_by']],
        'releaser'  => ['stock_transfer' => ['stock_transfers', 'released_by']],
    ];

    public const TONES = ['blue', 'green', 'orange', 'red'];

    // ------------------------------------------------------------------
    // Creating (from Audit::record)
    // ------------------------------------------------------------------

    public static function fromAudit(string $module, string $action, ?string $entityType, ?int $entityId, ?string $ref,
                                     ?array $new, ?int $branchId): void
    {
        $rule = self::RULES["{$module}.{$action}"] ?? null;
        if ($rule === null) {
            return;
        }
        try {
            [$icon, $tone, $message, $perms, $people, $branchMode] = $rule;
            $user = Auth::user();
            $actorId = isset($user['id']) ? (int) $user['id'] : null;
            $users = [];

            // Event-specific details.
            switch ("{$module}.{$action}") {
                case 'job_orders.create': // assigned at intake: tell the technicians instead of everyone
                    $team = self::jobTeam((int) $entityId);
                    if ($team) {
                        [$message, $perms, $users] = ['New job {ref} was assigned to you', ['job_orders.assign'], $team];
                    }
                    break;
                case 'job_orders.diagnose':
                    if (($new['approval'] ?? '') !== 'pending') {
                        return; // no quotation to approve
                    }
                    break;
                case 'job_orders.decision':
                    $declined = ($new['approval'] ?? '') === 'declined';
                    [$message, $tone] = [$declined ? 'The customer declined the quotation for {ref}' : 'The customer approved the quotation for {ref}: start the repair',
                        $declined ? 'red' : 'green'];
                    break;
                case 'collections.cancel':
                    if (str_starts_with((string) ($new['reason'] ?? ''), 'Check bounced')) {
                        [$message, $tone] = ['The check of collection {ref} bounced: the bills are open again', 'red'];
                    }
                    break;
            }
            if ($people === 'job_team') {
                $users = [...$users, ...self::jobTeam((int) $entityId)];
            } elseif ($people !== null && $entityId !== null && isset(self::PEOPLE[$people][$entityType])) {
                [$table, $col] = self::PEOPLE[$people][$entityType];
                $stmt = db()->prepare("SELECT {$col} FROM {$table} WHERE id = ?");
                $stmt->execute([$entityId]);
                $uid = $stmt->fetchColumn();
                if ($uid) {
                    $users[] = (int) $uid;
                }
            }
            if ($branchMode === 'from' || $branchMode === 'to') {
                $stmt = db()->prepare('SELECT ' . ($branchMode === 'from' ? 'from_branch_id' : 'to_branch_id') . ' FROM stock_transfers WHERE id = ?');
                $stmt->execute([(int) $entityId]);
                $branchId = (int) $stmt->fetchColumn() ?: $branchId;
            } elseif ($branchMode === 'none') {
                $branchId = null;
            }

            $recipients = self::recipients($perms, $branchId, $users, $actorId);
            if (!$recipients) {
                return;
            }
            $name  = (string) ($user['full_name'] ?? 'Someone');
            $text  = strtr($message, ['{actor}' => $name, '{ref}' => (string) ($ref ?? '')]);
            $page  = self::LINKS[(string) $entityType] ?? null;
            $link  = $page !== null && $entityId !== null ? 'pages/' . $page . '?id=' . $entityId : ($module === 'auth' ? 'pages/audit-log.php' : null);
            db()->prepare(
                'INSERT INTO notifications (branch_id, module, event, icon, tone, message, ref, link, actor_id, actor_name)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$branchId, $module, "{$module}.{$action}", $icon, $tone, mb_substr($text, 0, 255),
                $ref !== null ? mb_substr($ref, 0, 60) : null, $link, $actorId, $actorId !== null ? mb_substr($name, 0, 100) : null]);
            $nid = (int) db()->lastInsertId();
            $ins = db()->prepare('INSERT IGNORE INTO notification_recipients (notification_id, user_id) VALUES (?, ?)');
            foreach ($recipients as $uid) {
                $ins->execute([$nid, $uid]);
            }
            if (random_int(1, 50) === 1) {
                self::purge();
            }
        } catch (Throwable $e) {
            log_message('error', 'Notification for ' . $module . '.' . $action . ' failed: ' . $e->getMessage());
        }
    }

    /**
     * Active users who should see an event: one of $perms + access to $branchId (null = company-wide: the permission
     * is enough), every active super admin, and $users (people involved); never $actorId.
     * @return list<int>
     */
    private static function recipients(array $perms, ?int $branchId, array $users, ?int $actorId): array
    {
        $params = [];
        $permSql = '0';
        if ($perms) {
            $permSql = 'EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
                                 WHERE rp.role_id = r.id AND p.perm_key IN (' . implode(',', array_fill(0, count($perms), '?')) . '))';
            $params = [...$perms];
            if ($branchId !== null) {
                $permSql .= " AND (u.branch_id = ? OR EXISTS (SELECT 1 FROM user_branches ub WHERE ub.user_id = u.id AND ub.branch_id = ?)
                                   OR EXISTS (SELECT 1 FROM role_permissions rp2 JOIN permissions p2 ON p2.id = rp2.permission_id
                                               WHERE rp2.role_id = r.id AND p2.perm_key = 'branches.access_all'))";
                array_push($params, $branchId, $branchId);
            }
        }
        $userSql = $users ? ' OR u.id IN (' . implode(',', array_fill(0, count($users), '?')) . ')' : '';
        $stmt = db()->prepare(
            "SELECT u.id FROM users u JOIN roles r ON r.code = u.role
              WHERE u.is_active = 1 AND u.id <> ? AND (r.is_super = 1 OR ({$permSql}){$userSql})
              ORDER BY u.id"
        );
        $stmt->execute([$actorId ?? 0, ...$params, ...array_map('intval', $users)]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Lead + helper technicians of a job. @return list<int> */
    private static function jobTeam(int $jobId): array
    {
        $stmt = db()->prepare(
            'SELECT technician_id FROM job_orders WHERE id = ? AND technician_id IS NOT NULL
             UNION SELECT user_id FROM job_order_technicians WHERE job_order_id = ?'
        );
        $stmt->execute([$jobId, $jobId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Read notifications older than 90 days, and notifications nobody still holds. */
    private static function purge(): void
    {
        db()->prepare('DELETE FROM notification_recipients WHERE read_at IS NOT NULL AND read_at < NOW() - INTERVAL 90 DAY')->execute();
        db()->prepare('DELETE n FROM notifications n LEFT JOIN notification_recipients r ON r.notification_id = n.id
                        WHERE r.notification_id IS NULL AND n.created_at < NOW() - INTERVAL 1 DAY')->execute();
    }

    // ------------------------------------------------------------------
    // Reading (bell, notifications page)
    // ------------------------------------------------------------------

    public static function unreadCount(int $userId): int
    {
        $stmt = db()->prepare('SELECT COUNT(*) FROM notification_recipients WHERE user_id = ? AND read_at IS NULL');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public static function count(int $userId, bool $unreadOnly): int
    {
        $stmt = db()->prepare('SELECT COUNT(*) FROM notification_recipients WHERE user_id = ?' . ($unreadOnly ? ' AND read_at IS NULL' : ''));
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /** The user's notifications, newest first. @return list<array> */
    public static function forUser(int $userId, bool $unreadOnly, int $limit, int $offset = 0): array
    {
        $stmt = db()->prepare(
            'SELECT n.id, n.created_at, n.module, n.icon, n.tone, n.message, n.ref, n.link, n.actor_name, r.read_at, b.code AS branch_code
               FROM notification_recipients r
               JOIN notifications n ON n.id = r.notification_id
               LEFT JOIN branches b ON b.id = n.branch_id
              WHERE r.user_id = ?' . ($unreadOnly ? ' AND r.read_at IS NULL' : '') . '
              ORDER BY n.created_at DESC, n.id DESC
              LIMIT ? OFFSET ?'
        );
        $stmt->execute([$userId, $limit, $offset]);
        return $stmt->fetchAll();
    }

    /** Mark one notification read; returns its link (app path) or null when it is not the user's. */
    public static function open(int $userId, int $id): ?string
    {
        $stmt = db()->prepare('SELECT n.link FROM notification_recipients r JOIN notifications n ON n.id = r.notification_id
                                WHERE r.user_id = ? AND r.notification_id = ?');
        $stmt->execute([$userId, $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        db()->prepare('UPDATE notification_recipients SET read_at = NOW() WHERE user_id = ? AND notification_id = ? AND read_at IS NULL')
            ->execute([$userId, $id]);
        return (string) ($row['link'] ?? '');
    }

    public static function markAllRead(int $userId): int
    {
        $stmt = db()->prepare('UPDATE notification_recipients SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL');
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    /** "5m", "3h", "2d", or "Sep 21" after a week. */
    public static function ago(string $ts): string
    {
        $s = max(0, time() - strtotime($ts));
        return match (true) {
            $s < 60     => 'now',
            $s < 3600   => intdiv($s, 60) . 'm',
            $s < 86400  => intdiv($s, 3600) . 'h',
            $s < 604800 => intdiv($s, 86400) . 'd',
            default     => date('M j', strtotime($ts)),
        };
    }

    /** API / panel shape of one row. */
    public static function present(array $n): array
    {
        return [
            'id' => (int) $n['id'], 'message' => (string) $n['message'], 'ref' => (string) ($n['ref'] ?? ''),
            'icon' => (string) $n['icon'], 'tone' => in_array($n['tone'], self::TONES, true) ? $n['tone'] : 'blue',
            'actor' => (string) ($n['actor_name'] ?? ''), 'branch' => (string) ($n['branch_code'] ?? ''),
            'ago' => self::ago((string) $n['created_at']), 'today' => substr((string) $n['created_at'], 0, 10) === date('Y-m-d'),
            'unread' => $n['read_at'] === null, 'url' => url('pages/notification-open.php?id=' . (int) $n['id']),
        ];
    }
}
