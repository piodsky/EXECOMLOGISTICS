<?php
/**
 * "How It Works" page content (pages/workflow.php): every workflow of the system, step by step.
 * Each step names who does it (default roles), the permission behind it (to mark "you can do this" and to link
 * the page only for users who may open it) and the status the document gets. Text only, no data from the DB.
 */
declare(strict_types=1);

final class Workflow
{
    /** Default roles (Settings → Roles can change them). */
    public const ROLES = [
        'super_admin'  => ['Super Administrator', 'Every module at every branch; approves anything; sets up branches, users, roles and master data.'],
        'branch_admin' => ['Branch Administrator', 'Runs one branch: approvals, voids, purchasing, receiving, stock, deliveries, collections, payables, reports, branch prices and branch users.'],
        'cashier'      => ['Cashier', 'Sells at the POS, looks after customers, takes in job orders, enters customer POs, bills and records collections.'],
        'technician'   => ['Technician', 'Takes and repairs job orders, requests parts, looks up customers, stock and serial numbers.'],
    ];

    /**
     * @return list<array{key:string, title:string, icon:string, intro:string,
     *   steps: list<array{title:string, text:string, who:string, perm:string|list<string>|null, url:?string, status?:string}>,
     *   notes: list<string>}>
     */
    public static function flows(): array
    {
        return [
            [
                'key' => 'pos', 'title' => 'Selling at the counter (POS)', 'icon' => 'home',
                'intro' => 'A walk-in or regular customer buys items. Stock leaves the branch POS location the moment the sale is saved.',
                'steps' => [
                    ['title' => 'Add items', 'text' => 'Scan the barcode (F2), search (F3) or click a product card. Serial-numbered items ask for the serial of each unit.', 'who' => 'Cashier', 'perm' => 'pos.access', 'url' => 'pages/pos.php'],
                    ['title' => 'Price & discount', 'text' => 'The suggested price is the branch price (or the company price). A lower price needs a reason; beyond the role limit or below cost needs an admin approval at the till.', 'who' => 'Cashier · Branch Admin approves', 'perm' => 'pos.change_price', 'url' => null],
                    ['title' => 'Customer & payment', 'text' => 'Walk-in or a customer record. Cash, GCash, card, or on account (credit customers within their limit).', 'who' => 'Cashier', 'perm' => 'pos.access', 'url' => null],
                    ['title' => 'Save / Print', 'text' => 'The sale is completed, stock is deducted and the receipt prints.', 'who' => 'Cashier', 'perm' => 'pos.access', 'url' => null, 'status' => 'Completed'],
                    ['title' => 'Void (if needed)', 'text' => 'A wrong sale is voided with a reason; the stock comes back. Bills with collections cannot be voided.', 'who' => 'Branch Admin', 'perm' => 'sales.cancel', 'url' => 'pages/sales-history.php', 'status' => 'Cancelled'],
                ],
                'notes' => ['Sales History shows every sale; anyone with the page can reprint a receipt.', 'Units reserved for confirmed customer orders cannot be sold at the POS.'],
            ],
            [
                'key' => 'orders', 'title' => 'Customer orders (PO Outgoing)', 'icon' => 'file',
                'intro' => 'A company or government office asks for a quotation, sends its purchase order, we deliver and bill it, then collect.',
                'steps' => [
                    ['title' => 'Quotation', 'text' => 'Draft → Sent to the customer → Won or Lost. Nothing is reserved yet.', 'who' => 'Cashier / Branch Admin', 'perm' => 'customer_orders.manage', 'url' => 'pages/quotations.php', 'status' => 'Sent / Won'],
                    ['title' => 'Customer PO', 'text' => "Enter the customer's PO (from a won quotation or new) and send it for confirmation.", 'who' => 'Cashier / Branch Admin', 'perm' => 'customer_orders.manage', 'url' => 'pages/customer-orders.php', 'status' => 'Pending'],
                    ['title' => 'Confirm', 'text' => 'Another person confirms. The items are RESERVED: they can no longer be sold at the POS.', 'who' => 'Branch Admin', 'perm' => 'customer_orders.approve', 'url' => null, 'status' => 'Confirmed'],
                    ['title' => 'Delivery receipt', 'text' => 'Release the items (serials picked); stock leaves the branch. Record who received it and the IAR / acceptance.', 'who' => 'Branch Admin', 'perm' => 'customer_orders.deliver', 'url' => 'pages/deliveries.php', 'status' => 'Delivered'],
                    ['title' => 'Bill', 'text' => 'Bill the delivered receipts: cash, GCash, card, or on account (due after the credit terms).', 'who' => 'Cashier / Branch Admin', 'perm' => 'customer_orders.bill', 'url' => 'pages/order-tracking.php', 'status' => 'Completed'],
                    ['title' => 'Collect', 'text' => 'Go to Billing & Collections. See the next workflow.', 'who' => 'Cashier / Branch Admin', 'perm' => 'collections.manage', 'url' => 'pages/collections.php'],
                ],
                'notes' => ['Selling → Overview shows every step with counts at a glance.', 'Partial deliveries are fine; a partly delivered order can be closed with a reason.'],
            ],
            [
                'key' => 'collect', 'title' => 'Billing & collections', 'icon' => 'wallet',
                'intro' => 'Every bill on account (POS, job, customer order) waits here until it is fully paid.',
                'steps' => [
                    ['title' => 'Bills', 'text' => 'Unpaid bills with due date and days overdue (aging 1-30, 31-60, 61-90, 90+).', 'who' => 'Cashier / Branch Admin', 'perm' => ['collections.manage', 'collections.cancel'], 'url' => 'pages/collections.php'],
                    ['title' => 'Collection receipt', 'text' => 'Record the payment: cash, check, bank or GCash. Government customers may withhold EWT (2307) and VAT (2306).', 'who' => 'Cashier / Branch Admin', 'perm' => 'collections.manage', 'url' => 'pages/collection-receipts.php', 'status' => 'Posted'],
                    ['title' => 'Checks', 'text' => 'A check is On hand → Deposited → Cleared. A bounced check cancels the collection and opens the bills again.', 'who' => 'Cashier · Branch Admin (bounced)', 'perm' => 'collections.manage', 'url' => 'pages/checks.php', 'status' => 'Cleared'],
                    ['title' => 'Certificates', 'text' => 'Mark the 2307 received when the customer hands it over.', 'who' => 'Cashier / Branch Admin', 'perm' => 'collections.manage', 'url' => null],
                    ['title' => 'Statement of account', 'text' => 'Print what a customer still owes.', 'who' => 'Cashier / Branch Admin', 'perm' => ['collections.manage', 'collections.cancel'], 'url' => 'pages/soa.php'],
                ],
                'notes' => ['Credit days and credit limit are set on the customer form by a branch admin.'],
            ],
            [
                'key' => 'buy', 'title' => 'Buying from suppliers (PO Internal)', 'icon' => 'cart',
                'intro' => 'The branch needs stock: request it, order it from a supplier, receive it, then pay the supplier.',
                'steps' => [
                    ['title' => 'Purchase request', 'text' => 'Anyone lists what the branch needs (optionally for a job order).', 'who' => 'Cashier / Technician / Branch Admin', 'perm' => 'purchasing.request', 'url' => 'pages/purchase-requests.php', 'status' => 'Requested'],
                    ['title' => 'Approve request', 'text' => 'Approve the quantities (never your own request) or reject with a note.', 'who' => 'Branch Admin', 'perm' => 'purchasing.approve', 'url' => null, 'status' => 'Approved'],
                    ['title' => 'Purchase order', 'text' => 'Prepare the PO to a supplier from approved requests, submit it, another admin approves it, then send it.', 'who' => 'Branch Admin', 'perm' => 'purchasing.order', 'url' => 'pages/purchase-orders.php', 'status' => 'Approved'],
                    ['title' => 'Receive', 'text' => 'Receiving report from the PO (partial deliveries allowed). Posting adds the stock, the serials and the branch average cost.', 'who' => 'Branch Admin', 'perm' => 'receiving.post', 'url' => 'pages/receiving.php', 'status' => 'Received'],
                    ['title' => 'Supplier invoice', 'text' => "Record the supplier's invoice for the receiving report; due date from the supplier terms.", 'who' => 'Branch Admin', 'perm' => 'payables.manage', 'url' => 'pages/payables.php', 'status' => 'Unpaid'],
                    ['title' => 'Pay', 'text' => 'Disbursement voucher (cash or check, optional EWT). The check is Issued → Cleared.', 'who' => 'Branch Admin', 'perm' => 'payables.manage', 'url' => 'pages/disbursements.php', 'status' => 'Paid'],
                ],
                'notes' => ['Buying → Overview shows every step with counts at a glance.', 'The PO page shows whether the supplier is already paid (Payment card).'],
            ],
            [
                'key' => 'stock', 'title' => 'Stock & inventory', 'icon' => 'box',
                'intro' => 'Every branch keeps its own stock, cost and prices. Stock only changes through documents, so every unit is traceable.',
                'steps' => [
                    ['title' => 'Inventory', 'text' => 'Products, stock at the branch per location, low stock. Products are company-wide; stock is per branch.', 'who' => 'Everyone (view)', 'perm' => 'inventory.view', 'url' => 'pages/inventory.php'],
                    ['title' => 'Branch prices', 'text' => 'Each branch may sell at its own price; blank = the company price.', 'who' => 'Branch Admin (own branch)', 'perm' => 'products.branch_price', 'url' => 'pages/branch-prices.php'],
                    ['title' => 'Stock operations', 'text' => 'Move between locations, mark damaged or display, issue for internal use, write off.', 'who' => 'Branch Admin', 'perm' => ['inventory.transfer', 'inventory.damage', 'inventory.issue'], 'url' => 'pages/stock-docs.php'],
                    ['title' => 'Stock count', 'text' => 'Open a count → enter counted quantities → submit → another person approves and posts the differences.', 'who' => 'Branch Admin', 'perm' => ['counts.create', 'counts.approve'], 'url' => 'pages/stock-docs.php', 'status' => 'Posted'],
                    ['title' => 'Branch transfer', 'text' => 'The receiving branch requests → the sending branch approves → releases (in transit) → the receiving branch receives.', 'who' => 'Branch Admins of both branches', 'perm' => ['transfers.request', 'transfers.approve', 'transfers.release', 'transfers.receive'], 'url' => 'pages/transfers.php', 'status' => 'Received'],
                    ['title' => 'Serial lookup', 'text' => 'Find any serial number: where it is, who bought it, its warranty.', 'who' => 'Everyone', 'perm' => 'serials.view', 'url' => 'pages/serials.php'],
                ],
                'notes' => ['Choose the branch in the top bar; "All branches" shows company totals (read-only for most actions).'],
            ],
            [
                'key' => 'service', 'title' => 'Service & repairs (Job orders)', 'icon' => 'wrench',
                'intro' => 'A customer leaves a device for repair. The job moves from intake to release; parts and labour are billed at the end.',
                'steps' => [
                    ['title' => 'Intake', 'text' => 'Customer, device, accessories left, condition, problem; print the ticket and claim stub.', 'who' => 'Cashier / Technician', 'perm' => 'job_orders.create', 'url' => 'pages/job-form.php', 'status' => 'New'],
                    ['title' => 'Assign', 'text' => 'A lead technician (plus helpers) is assigned, or a technician takes the job.', 'who' => 'Branch Admin / Technician', 'perm' => ['job_orders.assign', 'job_orders.update'], 'url' => 'pages/job-orders.php', 'status' => 'Assigned'],
                    ['title' => 'Diagnose', 'text' => 'Diagnosis + estimate. Above the quotation threshold the customer must approve first.', 'who' => 'Technician', 'perm' => 'job_orders.update', 'url' => null, 'status' => 'For approval / In repair'],
                    ['title' => 'Repair & parts', 'text' => 'Request parts → a branch admin issues them → used or returned. Waiting for parts pauses the job.', 'who' => 'Technician · Branch Admin issues', 'perm' => ['job_orders.update', 'job_parts.issue'], 'url' => null, 'status' => 'In repair'],
                    ['title' => 'Test & complete', 'text' => 'For testing → completed with the work done and the labour charge.', 'who' => 'Technician', 'perm' => 'job_orders.update', 'url' => null, 'status' => 'Completed'],
                    ['title' => 'Bill & release', 'text' => 'Bill parts + labour (or warranty / no charge), check the claim stub and release the device.', 'who' => 'Cashier / Branch Admin', 'perm' => 'job_orders.release', 'url' => null, 'status' => 'Released'],
                ],
                'notes' => ['A device that comes back is a back-job linked to the first job.', 'Never write device passwords on the job.'],
            ],
        ];
    }

    /** Rules that apply everywhere. */
    public static function rules(): array
    {
        return [
            ['shield', 'Two people', 'Whoever prepares a document cannot approve it (requests, POs, customer orders, counts, transfers).'],
            ['store', 'Branch first', 'You work in one branch at a time (top bar). Records of other branches are not visible unless you may see all branches.'],
            ['bell', 'Notifications', 'The bell tells the right people when something needs them: an approval, a delivery to bill, a check that cleared.'],
            ['clipboard', 'Audit log', 'Every important action is recorded with who, when and what changed (Settings → Audit Log).'],
            ['receipt', 'Nothing is erased', 'Posted documents are cancelled or voided with a reason, never deleted, so the history stays complete.'],
            ['tag', 'Prices', 'Prices are VAT-exclusive; VAT is added on top. Past sales keep the price they were sold at.'],
        ];
    }

    /** Can the signed-in user do this step? */
    public static function canDo(string|array|null $perm): bool
    {
        return $perm !== null && Auth::canAny(...(array) $perm);
    }
}
