# EXECOM Logistics POS: project memory for Claude

EXECOM Logistics POS & Inventory (IT products: laptops, peripherals, accessories, network, office supplies).
Rebuilt from the earlier CoffeeSystem codebase: same architecture, new concept + blue theme.
PHP 8.2 (XAMPP) + PDO + MariaDB 10.4, no framework, plain CSS, vanilla JS.
DB: `execomlogistics_db`, root / no password. URL: http://localhost/EXECOMLOGISTICS. UI follows the navy/blue mockup
(dark navy topbar with E logo/"POS SYSTEM Fast • Secure • Reliable"/search/date/time/user/logout, navy sidebar with
mountain backdrop, light blue-gray content, blue Current Sale header, blue Save / green Print / red Cancel).
Read this first; open only the files a task needs.

## Phase status
- [x] Phase 1: full EXECOM rebrand of everything: database.sql (5 IT categories, 12 mockup products ITM-0001..0012,
      4 sample sales), config/.env, auth, layout, POS (`pages/pos.php`, `assets/js/pos.js`, `api/pos/*`), receipt,
      Inventory (`pages/inventory.php`, `pages/product-form.php`), Customers, stock audit (`stock_movements`),
      12 sample illustrations `assets/uploads/products/sample-itm-000N.png`, logo `assets/img/logo-mark.svg`.
- [x] Phase 2: Sales History `pages/sales-history.php` (+ `assets/css/sales.css`) and sale details `pages/sale-view.php`
      (reprint, admin Void with reason). `Sales::count/search/summary/cashiers/void`, `input_date()` helper,
      `[data-open]` dialog opener in app.js. Migration `migrations/002_phase2_sales_history.sql`
      (sales.voided_at / voided_by / void_reason); database.sql already includes it.
- [x] Phase 3: Reports `pages/reports.php` (+ `assets/css/reports.css`, `assets/js/reports.js`), queries in
      `system/Reports.php`. No schema change (no migration). Icons added: trend-up, trend-down, download.
      Shared filter styles (.date-field, .quick-ranges, .chip, .page-actions) moved to app.css.
- [x] Phase 4: Settings `pages/settings.php` (company/VAT/receipt footer + preview), Users `pages/users.php` +
      `pages/user-form.php`, My Account `pages/account.php` (all roles), tabs `includes/settings-nav.php`,
      `assets/css/settings.css`, classes `system/Settings.php` + `system/Users.php`, password stamp in `Auth`.
      No schema change. All four phases are done; the old coming-soon placeholder was removed.
- [x] Phase 5 (= v2 phases 1+2): branches, branch isolation, per-branch stock, data-driven roles & permissions,
      Branch master data, audit log. Migration `migrations/003_branches_permissions.sql`. Plan for later phases
      (master data, warehouse/receiving/serials, branch transfers, POS pricing, job orders, dashboards) is in the
      "EXECOM Migration Blueprint" artifact (v2, 27 sections).
- [x] Phase 6 (= v2 phase 3): master data (categories, brands, models, units, customer types, service lists),
      suppliers + contacts, product brand/model/unit/cost/serial/warranty/specs, customer type/TIN/contacts.
      Migration `migrations/004_master_data.sql`. Payment methods / discount types wait for POS pricing (v2 phase 6).
- [x] Phase 7a (= v2 phase 4, part 1): Receiving Reports, branch moving-average cost, serial numbers (receiving + POS),
      serial lookup, stock integrity page. Migration `migrations/005_receiving_cost_serials.sql`.
- [x] Phase 7b (= v2 phase 4, part 2): warehouses & locations, stock operations (transfer / damage / display /
      restore, internal-use issue, write-off), stock counts with approval, serial registration for stock on hand.
      Migration `migrations/006_warehouse_ops.sql`.
- [x] Phase 8 (= v2 phase 5): branch-to-branch transfers (request → approve → release/in transit → receive, cancel
      before release). Migration `migrations/007_branch_transfers.sql`. Built in the main session without agents
      (user request).
- [x] Phase 9 (= v2 phase 6): POS pricing — actual vs suggested price, per-role price / discount limits, admin
      approval at the till, cost toggle. Migration `migrations/008_pos_pricing.sql`. Built without agents.
- [x] Phase 10a (= v2 phase 7, part 1): job orders & technicians — intake, assign / take, diagnosis + estimate,
      quotation approval, repair statuses, timeline + notes, ticket + claim stub print, technician work lists.
      Migration `migrations/009_job_orders.sql`. Built without agents.
- [x] Phase 10b (= v2 phase 7, part 2): job parts (request → issue to job custody → used / returned), billing on the
      job page (a sale that does not deduct parts again), release (paid / warranty / no charge), back-jobs.
      Migration `migrations/010_job_parts_billing.sql`. Built without agents.
- [x] Phase 11 (= v2 phase 8): Dashboard + reports (profit, job orders & technicians, price overrides & discounts,
      branch comparison). No schema change (no migration). Built without agents.
- [x] Phase 12 (= v2 phase 9): audit & security hardening — sign-in / security events in the audit log, POS
      override audit, audit CSV export, dedicated MySQL users, backups + restore test + nightly task, FORCE_HTTPS +
      HSTS (off until go-live), sensitive parameters, legacy folders moved out of htdocs, indexes
      (`migrations/011_security_indexes.sql`). Built without agents.

## Security & operations (Phase 12) — user decisions
- MySQL: the app runs as `execom_app` (SELECT/INSERT/UPDATE/DELETE on execomlogistics_db + execomlogistics_e2e,
  localhost / 127.0.0.1); `execom_backup` (SELECT, SHOW VIEW, TRIGGER, LOCK TABLES) for backups. Created / rotated by
  `tools/setup-db-users.ps1` (random passwords into the live .env). Migrations and imports run as **root**.
  e2e imports with `E2E_DB_USER` / `E2E_DB_PASS` (default root, no password); the test copy runs as execom_app.
  Scratch PHP tests on other DBs need `DB_USER=root` + `DB_PASS=` in their scratch env file.
- `tools/` (web-blocked): `env.ps1` (shared .env reader/writer, Invoke-Mysql with MYSQL_PWD), `backup.ps1`
  (mysqldump --single-transaction → `C:\EXECOM-Backups\execomlogistics_db-<stamp>.sql.gz`, keep 30 days, backup.log),
  `restore-test.ps1` (loads into execom_restore_test, row counts + stock rule, drops it), `install-backup-task.ps1`
  (scheduled task "EXECOM Database Backup", daily 21:00). Keep the .ps1 files ASCII-only.
- Legacy folders (CoffeeSystem, Globalchips 2010 on User, NewEXECOM, NewEXECOM - Copy BACKUP 92726, SystemsMISPYO)
  were moved to `C:\xampp\legacy-apps` (not web-reachable).
- Audit module `auth` (global, label "Sign-in & Security"): login (branch = home), login_failed (ref = attempted
  username, reason; never the password), login_locked, approval_failed (till approver), logout, branch_switch.
  `sales.price_override` = POS sale with lowered lines / discount (suggested → actual, reason, approver). Audit log
  Export CSV (filters, newest 5,000).
- `FORCE_HTTPS` (.env, default false): `force_https()` in bootstrap redirects http → https (GET 301, other 308) and
  `send_security_headers()` adds HSTS on https. `zend.exception_ignore_args=1` outside debug; password parameters
  carry `#[SensitiveParameter]`. Apache ServerTokens / expose_php are documented for go-live (not changed here).
      Next (v2 phase 10): data migration from the legacy system (if wanted) / go-live.

## Dashboard & reports (Phase 11) — user decisions
- Menu `dashboard` (first item, permission `reports.view`) → super / branch admins land on `pages/dashboard.php`;
  cashiers (POS) and technicians (Job Orders) keep their landing pages. Loaded on open, Refresh button, no polling.
  `Dashboard` class: KPIs (today, month, profit with products.cost, stock value at cost with products.cost else price,
  open jobs), "Needs you" tiles (concrete branch, per permission: transfers approve/release/incoming, counts to
  approve, RR drafts, parts to issue, jobs ready / unassigned / waiting for the customer), branch rows (All branches),
  last 7 days, jobs by status + technician workload, low stock, overrides today, transit, recent audit (audit_logs.view).
- Reports tabs (`includes/reports-nav.php`, period travels with the tabs; shared `includes/report-kit.php` +
  `includes/report-filter.php`): Sales (`reports.php`), Profit (`report-profit.php`, products.cost; sale level =
  subtotal − discount − cost_total, uncosted sales footnoted; items before the sale discount, labour no cost), Job
  Orders (`report-jobs.php`: received / completed / released / back-jobs by event date, avg days, job bill revenue
  parts + labour, technicians, devices, oldest open), Price Overrides (`report-pricing.php`: lines below suggested
  + discounts, by cashier), Branches (`report-branches.php`, `Branch::canSeeAll()` only, ignores the scope). Each
  has `?export=csv`. Queries live in `Reports` (alias `lines` must be backticked in MariaDB).
- Existing DBs need a migration file in `migrations/`, not a re-import.

## Job orders (Phase 10a) — user decisions
- Class `JobOrders` (`job_orders` + `job_order_events` timeline), menu "Job Orders" (`job-orders`, icon `wrench`, after
  Sales History, so technicians land there): `job-orders.php` (list, work tiles My Jobs / Unassigned / For Approval /
  Waiting for Parts / Completed; default filter = open jobs, `status=all` for every status), `job-form.php` (intake;
  `?id=` edits intake details of an open job), `job-view.php` (next step, details, timeline + notes, assign, cancel,
  print = ticket + claim stub via `@media print`). JS `assets/js/jobs.js`, CSS `assets/css/jobs.css`. Audit module `job_orders`.
- Permissions: `job_orders.view` (all branch jobs), `.create` (intake + edit intake), `.update` (work on own jobs, take
  unassigned new jobs), `.assign` (assign/reassign, act on any job, cancel). Defaults: branch_admin all; cashier
  view + create; technician create + update. Without view/assign a user sees own jobs, jobs they took in and the
  branch's unassigned new jobs (`JobOrders::visibleSql`); anything else → 404.
- Flow (`JobOrders::act()`, row locked, session must work in the job's branch, All → 422): new → assigned (take /
  assign) → diagnosing (start) → diagnose (diagnosis 3–2000 + estimate): estimate > setting `job_quote_threshold`
  (Settings → Company, default 1000.00) or "ask the customer anyway" → for_approval, else in_repair →
  decision (worker or front desk; approve → in_repair, decline → completed; method + who answered recorded) →
  in_repair ⇄ waiting_parts (note required) → for_testing → completed (work done required) | test_failed → in_repair.
  Cancel (reason) only new/assigned: supervisor, or the creator (job_orders.create) while new. Notes on open +
  completed jobs. released/closed exist in the ENUM for 10b.
- Intake: walk-in (name + phone snapshot) or a customer record (fills name/phone); device type required (lookup
  `device_type`), job type optional (`job_type`), brand/model/serial free text. A serial we sold (product_serials
  sold + completed sale) links `serial_id` + `warranty_until` = sale date + product warranty_days. Never store
  device passwords. Numbers `JO-<BRANCH>-<YEAR>-NNNNNN` (document_sequences 'JO').
- Guards: customers / device or job types / branches with job orders can't be deleted. `setting()` is cached per
  request (tests: read the DB).

## Job parts, billing & release (Phase 10b) — user decisions
- `JobParts` (`job_order_parts` = one row per request, `job_order_part_serials`): request (worker, job diagnosing /
  in_repair / waiting_parts / for_testing) → issue (`job_parts.issue`, branch_admin, never the requester, job open;
  qty 1..requested or exact serials; stock leaves the branch POS location, movement `job_issue` with
  `stock_movements.job_order_id`, cost = branch average snapshot on the line; serials → `in_custody`) → use (worker,
  no movement; serials → `installed`) / return (`job_parts.issue`, job open or completed; `Costing::inbound` at the
  line cost + `job_return`; serials → `in_stock` at the POS location). Cancel = never-issued requests. Custody =
  issued − used − returned is in NO location (like transit): products.stock drops at issue.
- `JobOrders`: complete needs no open requests + a labour charge (`labor`, suggested = estimate − parts used at
  price, ≥ 0); `set_labor` while completed. Release blockers (`JobBilling::blocker`): open requests or parts in custody.
- `JobBilling` on the job page (`job_orders.release`: cashier + branch_admin): `bill()` = a normal sale with
  `sales.job_order_id`, `sale_items.line_type` part (product, current suggested price, cost = issue snapshot) /
  labor (no product, code LABOR); discount within `Pricing::limits()` (no till approval: above → ask a branch admin);
  VAT/payment as the POS; NO stock movement; installed serials linked via sale_item_serials (receipt S/N). Job →
  released (`release_type` paid, `sale_id`). `releaseFree()`: warranty (also `job_orders.assign`, reason required, no
  sale) or no_charge (only when nothing to bill). Release needs "claim stub presented" or a note (e.g. ID checked);
  `released_to` defaults to the customer. `Sales::void()` of a bill restocks nothing and re-opens the job
  (`onSaleVoid` → completed, event bill_voided).
- Back-job: `job-form.php?parent=ID` (released/closed job of the branch, `job_orders.create`) prefills customer +
  device; `job_orders.parent_job_id`; both jobs show the link.
- Integrity checks `job_part_movements` + `custody_serials`; serial tracking can't change / `Serials::register` is
  refused while units are in custody; products with job parts can't be deleted. Receipt + sale-view show the job.

## POS pricing (Phase 9) — user decisions
- Prices are VAT-exclusive (VAT added on top as before). `products.price` = suggested; `sale_items.unit_price` = actual,
  `suggested_price` / `price_reason` / `price_approved_by` snapshots; `sales.discount_approved_by`. Receipt shows the
  actual price only; sale-view shows the suggested price struck through + reason + approver.
- Rules in `Pricing` + `Sales::checkPricing()` (server only): changing a price needs `pos.change_price`, a lower price
  needs a reason (≥ 3 chars); more than `roles.max_price_drop` % below suggested, or a net price (after the sale
  discount) below the branch average cost, needs approval; a discount needs `pos.discount`, above
  `roles.max_discount` % needs approval. Defaults: cashier 5 / 5, branch admin 20 / 20 (Settings → Roles → POS
  Limits; super admin unlimited). Users with `pos.price_override` are self-approved (recorded as approver).
- Approval at the till: checkout returns 422 `details.approval {lines: [{product_id, name, price}], discount}` (never
  says whether the cause is cost); the POS opens the Admin Approval dialog → `api/pos/approve.php` with the
  approver's username + password (`Auth::verifyCredentials`, same lockout as login, `#[SensitiveParameter]`; must be
  someone else with `pos.price_override` who works at the branch) → one-time tokens in `price_approvals`
  (SHA-256 only, this cashier + branch + exact product/price or discount, 5 minutes) → the POS resubmits; tokens are
  used inside the sale transaction (rollback frees them). Tokens never stored in localStorage; any cart change drops them.
- Cost on the POS: `api/pos/products.php` adds `cost_cents` only with `pos.view_cost`; "Cost" toggle in the cart header
  (off by default, never stored) shows a Cost / Margin column. Cashiers never receive cost.

## Branch transfers (Phase 8)
- Class `Transfers` (`stock_transfers` + lines + serials), menu "Branch Transfers" (`transfers`): pages
  `transfers.php` (list + work lists To Approve / To Release / Incoming), `transfer-form.php` (request),
  `transfer-view.php` (approve / release / receive forms per `Transfers::actions()`, cancel dialog, printable slip).
  Permissions `transfers.request/approve/release/receive` (branch_admin all four). Audit module `transfers`.
- Flow: the receiving branch requests (working in it) → the sending branch approves (qty 0..requested, never the
  requester, super admin included) → releases (stock out of its POS location, type `transfer_out`, cost = its branch
  average snapshot on the line; serial lines need exactly the approved serials → `in_transit`) → the receiving branch
  receives (never the releaser; qty 0..released, serials ticked as arrived; any shortage needs a note; stock into its
  POS location, type `transfer_in`, `Costing::inbound` at the sender's cost; missing serials → `removed`).
  Cancel (reason 3–255) only while requested/approved, by the requesting side (`transfers.request`) or the sending
  side (`transfers.approve`). Approval does NOT reserve stock; release checks availability under locks.
- In transit = in neither branch: `products.stock` (company total) drops at release and comes back at receipt, so
  products.stock = Σ balances = Σ movements still holds. Numbers `BT-<SENDING BRANCH>-<YEAR>-NNNNNN`.
- Visible when either branch is in scope; actions need the session in the acting branch (other → 403, All → 422).
  Source availability ("in stock here") is shown to the sending side only. Cost only with `products.cost`.
- Guards: serial tracking can't change and `Serials::register` is refused while units of the product are in transit;
  products/branches with transfers can't be deleted. `Stock::move(..., ?int $transferId)` (last parameter).
  Integrity checks `transfer_movements` + `transit_serials` (14 in "All branches"); `removed_serials` accepts
  serials missing on a received transfer.

## Warehouses & stock operations (Phase 7b)
- Locations have `kind` stock/damaged/display. Every warehouse gets GENERAL (stock) + DAMAGED + DISPLAY (system,
  never sellable/default, can't be deactivated) via `Warehouses::createLocations()` (also used by `Branches::create`).
  New bins are non-sellable; the POS and adjustStock act only on the branch's default location. Inventory "Stock" =
  all locations of the branch (damaged/display included); the Location filter narrows it. Settings tab "Warehouses"
  (`warehouses.manage`, branch admin = own branch; "All branches" is read-only; writes need the working branch).
- One generic document `InventoryDocs` (`inventory_docs` + lines + serials), menu "Stock Operations" (`stock-docs`):
  transfer (purpose move/damage/display/restore, derived server-side from the location kinds, permission per
  purpose), issue (internal use), writeoff, count. Numbers TRF/ISS/WOF/CNT-<BRANCH>-<YEAR>-NNNNNN. Transfers, issues
  and write-offs post in one step (no draft); the form posts once per page load.
- Costing: moves between locations keep branch qty + average; issue/write-off store the branch average as cost
  snapshot (cost only with `products.cost`). Serials move with the stock; issued/written-off/unfound serials become
  `removed`. Display → damaged allowed, damaged → display refused (restore to stock first).
- Counts: open (system qty + serials frozen at create; one open count per location) → submitted → posted. Approval
  (`counts.approve`) never by the creator, the submitter or anyone who saved counts (audit `count_save`), super admin
  included. Posting moves counted − frozen (sales during the count stay correct); tracked items can't count up.
- `Serials::register` (super admin / `products.manage`): serials = qty at every location (switch to All branches if
  the item is at several branches), then tracking turns on with no stock movement; refused while on an open count.
- `Stock::move(..., ?int $receivingId, ?int $docId)`; movement types + transfer/issue/write_off/count. Any later
  movement (incl. transfers/counts) blocks an RR cancel.
- Follow-ups (code review nits): stock-doc-view approval "Est. value" duplicates `Costing::avg` in the page (add a
  read-only helper) and overstates serial lines whose serial was sold during the count; serials.php history says
  "removed from stock" for every not-found serial; a `counted_by` column would be sturdier than the audit-based
  `hasCounted()`; inventory/product-form re-find the POS location instead of `Branch::defaultLocation()`.

## Receiving, cost & serials (Phase 7a)
- Classes: `Receiving` (RR draft/post/cancel), `Costing` (branch average), `Serials`, `Integrity`. Pages
  `receiving.php`, `receiving-form.php`, `receiving-view.php`, `serials.php` (menu "Serial Lookup"),
  `stock-integrity.php` (non-menu, `inventory.integrity`, linked from Inventory). API `api/pos/serials.php`.
- Permissions: `receiving.view/manage/post/cancel`, `inventory.integrity` (branch_admin), `serials.view` (also cashier +
  technician). Posting also needs `products.cost`. Audit module `receiving` (branch-scoped).
- RR: draft (no number, editable, deletable) → post (`RR-<BRANCH>-<posting year>-NNNNNN` from a locked
  `document_sequences` row; supplier required; every line needs a cost; serial count = qty for tracked products) →
  cancel (reason 3–255; only when untouched: no later movement of its products at that branch, average unchanged, its
  serials still in stock at the RR location; restores the previous average). Acting on an existing RR needs the
  current branch = the RR branch (other branch → 404, All → 422 "Switch to branch …"). Costs accept "1,234.50".
- Cost: `products.unit_cost` = "Default cost" (seeds a branch average, prices opening stock; receiving never writes
  it). Branch average in `product_branches.avg_cost` (DECIMAL 12,4, integer 1/10000 math, half-up): re-averaged by
  receiving and void (at the line snapshot; NULL snapshot = unchanged); stock in without a cost leaves it unchanged.
  `sale_items.unit_cost` + `sales.cost_total` are snapshots (NULL for pre-7a sales). Read cost only via
  `Sales::costs()` / `Receiving::find()` (gated by `products.cost`); `Sales::find()` stays cost-free.
- Lock order everywhere: RR → sequence → products (ORDER BY id) → stock_balances → product_branches → serials (ORDER BY id).
- Serials (`products.track_serial`): unique per product, status in_stock/sold/removed, `sale_item_serials` kept after
  void. Tracked products: opening stock must be 0, adjustStock → 422 (Adjust hidden), stock only via RR. Tracking can't
  change while stock > 0 and can't be switched off once any serial exists; a void whose serial-ness no longer matches
  the product → 409. POS: picker dialog (qty = serials picked), scanning a serial adds it, checkout
  `items[].serial_ids`, 409 drops sold serials from the cart; cart/localStorage keep only `{id, serial_no}`.
  Receipt and sale-view list S/N; sale-view Cost/Margin columns only with `products.cost`.
- `Stock::move(..., ?int $receivingId)` with type `receiving`. `Suppliers::deleteBlocker` checks receiving_reports.

## Master data & suppliers (Phase 6)
- Permissions: `master_data.manage` (super admin only), `suppliers.view` / `suppliers.manage` / `products.cost`
  (also branch_admin). Menu "Master Data" (icon `layers`) = any of master_data.manage / suppliers.view; a
  suppliers-only user lands on `suppliers.php`. Audit modules `master_data` + `suppliers` are global (branch NULL).
- Simple lists: registry `config/master-data.php` (table/list names are code literals), class `MasterData`, one page
  `pages/master-data.php?list=<key>` (dialog PRG form, tabs `includes/master-data-nav.php`). Generic lists live in
  `lookups` (device_type, job_type, service_category, warranty_type). Names unique case-insensitively (models: per
  brand); delete only when unused (`MasterData::USAGE`), else 409 "Deactivate instead"; a used model's brand is locked
  (also a composite FK). Inactive entries are only offered as a record's current value (`options()/isChoice()`).
  Products in a deactivated category are hidden from the POS and refused by `Sales::complete()`.
- Suppliers: `Suppliers` (code auto `SUP-0001`, `deleteBlocker()` gets receiving checks later), `pages/suppliers.php`
  + `supplier-form.php` (read-only without suppliers.manage).
- Contacts (`Contacts`, suppliers + customers): max 5, form keys `contacts[i][name|position|phone|email]`, flat old
  keys `contact_{i}_{field}`, `replace()` only inside the owner's transaction; `Customers::update(..., null)` keeps
  them. Audit stores contact names only. Shared rows template `includes/contact-rows.php`.
- Products: `price` = suggested price (label "Suggested price"); unit required (PC default); model must belong to
  the brand; `unit_cost` only with `products.cost`: never rendered/exported without it (find() unsets it, audit viewer
  hides it), ignored on save (update keeps the stored value, create = 0); warranty_days 0–3650; track_serial; specs.
- Dependent selects in app.js: `select[data-filter-by]` + `option[data-parent]`.
- Shared stock join: `Stock::scopeJoin()`. Users: grants to inactive branches are kept on save; update rechecks
  role/branches (`assertAssignable`).
- Deferred: linking an existing customer to another branch (needs a "link to this branch" action; the duplicate-phone
  message still says "registered at another branch").

## Branches, roles & permissions (Phase 5)
- Branches: MAR Maramag City (main, id 1, all pre-Phase-5 data), MLB, CDO, DAV, VAL. Address/contact/TIN are NULL until
  the owner fills them in Settings → Branches (don't invent). Each branch has warehouse MAIN + location GENERAL
  (created automatically with a new branch).
- Roles live in `roles` (super_admin is_super = every permission, locked; branch_admin; cashier; technician; custom roles
  allowed). `users.role` = role code (FK roles.code). Permission keys: `config/permissions.php` (must match the
  `permissions` table). Check with `Auth::can/canAny/requirePermission`; `hasRole/requireRole` are deprecated.
  Menu items use `'permission'` (string or any-of array); `api_guard('POST', 'pos.access')` takes permission keys (no default).
  Settings tabs: `settings_page($tab, $title, ...)`. Landing page: `home_url()` (first menu item the user can open).
- Permission checks are repeated inside the classes (void = `sales.cancel`, product CRUD = `products.manage`, adjust =
  `inventory.adjust`, customer delete = `customers.delete`, users = `users.manage/delete`, settings = `settings.manage`).
- Users: assign only roles whose permissions are a strict subset of yours; can't manage users with branches you can't
  access; extra branches (`user_branches`) only by `branches.access_all`; ≥ 1 active super admin (row-locked).
- Branch scope: `Branch::current()` (session, revalidated each request; 0 = All, only for `branches.access_all`),
  `forWrite()` (422 "Choose a branch first."), `scopeSql('t.branch_id')` on every list/count/report/export,
  `assertAccess()` → 404 for other branches' records. Never take branch_id/user_id/role from the browser.
- Stock: ONLY `Stock::move()` writes stock (needs a transaction; locks product + `stock_balances` row; 409 if not enough
  at the branch). `stock_balances` = qty per product × location; `products.stock` = company total; POS sells from the
  branch's default sellable location. Integrity rule: products.stock = Σ stock_balances = Σ stock_movements.
- Customers: one shared record with home branch + `customer_branches` visibility links; duplicate-phone check is
  company-wide with a generic message for other branches' customers.
- Audit: `Audit::record(module, action, …)` inside the caller's transaction (secrets stripped); viewer Settings → Audit Log.
- Receipt prints the branch name (+ branch address/contact when set). POS cart key `bb.pos.<userId>.<branchId>`.
- `includes/header.php` also defines `$hdr*` variables.

## User decisions (don't revert)
- **No auto-logout.** User said "don't use session expired". `SESSION_IDLE_TIMEOUT=0`,
  `SESSION_ABSOLUTE_TIMEOUT=0` (0 = off). Never show "session expired" wording.
- Build in phases, zip + XAMPP setup steps each phase (zip goes to `~/Downloads/EXECOMLOGISTICS-phaseN.zip`).
- Nothing coffee-related anywhere (names, icons, colors, sample data). CSS tokens are neutral:
  `--navy-*`, `--primary`, `--primary-700/400`, `--accent`, `--surface`, `--surface-2`, `--bg`, `--border`.
- Company address/phone/TIN in `settings` are placeholders until the user gives real ones (don't invent them).
- Keep folders clean (`config/ system/ includes/ pages/ api/ assets/ storage/ tests/ tools/`, `.env`).
- **Use the agent workflow** (user request) for every non-trivial task, see below.

## Agent workflow (`.claude/agents/`)
The user asked for this workflow, so use these subagents without asking again. Subagents can't launch subagents:
the main session runs each step with the agent named in project-manager's plan.
**Token-efficient rule (user, 2026-10-03): never use agents just because they exist.**
- Simple (text/label/button rename, spacing, small CSS, typo, small isolated PHP bug): NO agents. Understand →
  modify → verify (lint + a quick check) directly.
- Medium (one area): only the matching specialist (backend → backend-developer, DB → database-specialist,
  UI → frontend-uiux, auth → security-reviewer). No project-manager, no unrelated reviewers.
- Complex (new workflow, major DB change, several modules, auth/permissions, inventory/PO/receiving flows): the full
  chain below.
- Don't have several agents inspect the same files unless their expertise differs; pass on earlier findings
  instead of re-analysing. Give each agent only the files/context it needs and ask for short reports (changed
  files, what changed, test result, issues).
- Complex task: **project-manager** first (plan + workflow), then the steps:
  - Simple: system-analyst → backend-developer or frontend-uiux → qa-tester → code-reviewer
  - Database-heavy: system-analyst → database-specialist → backend-developer → security-reviewer → qa-tester → code-reviewer
  - UI: system-analyst → frontend-uiux → backend-developer (if needed) → qa-tester → code-reviewer
  - Security-sensitive (auth, sessions, roles, CSRF, uploads, money, void, users): system-analyst →
    backend-developer → security-reviewer → qa-tester → code-reviewer
- One-line fixes and questions/explanations: do them directly, but still lint + test behaviour changes.
- UNDERSTAND → PLAN → IMPLEMENT → TEST → SECURITY REVIEW → CODE REVIEW → FINAL VERIFICATION. The existing
  system is the source of truth; smallest safe change; no rewrites of working modules.
- Done = lint clean + real qa-tester results + code-reviewer APPROVED (+ security-reviewer for sensitive work).
  If a reviewer says CHANGES REQUIRED, fix and re-run QA + review.

## POS behaviour
- Buttons: **Save** = payment dialog → complete sale (deduct stock). **Print** = same + auto-print; with an empty
  cart it reprints the last sale. **New Sale** / **Cancel** clear the cart (confirm). `held` status is unused so far.
- Cart lives in the browser (+ localStorage `bb.pos.<userId>.<branchId>`); the server receives product_id + qty (+ the
  actual price / reason / approval token of changed lines, see Phase 9) and recomputes everything in
  `Sales::complete()` (transaction, `SELECT … FOR UPDATE`, `stock >= ?` guard).
- Receipt printing: hidden iframe loads `receipt.php?id=X&autoprint=1`; receipt.php calls `allow_same_origin_framing()`.
- Keys: F2 scan (focus search), F3 search, F4 add highlighted card, ↑/↓ move highlight, Enter = exact barcode/code
  match else highlighted. Typing anywhere (scanner) goes to the search box.

## Sales History behaviour (Phase 2)
- List shows only completed + cancelled (never held). Search = sale_no, customer name, or any item name/code.
  Filters: `search`, `from`/`to` (Y-m-d via `input_date()`, swapped if reversed, inclusive days), `status`,
  `payment`, `cashier`. Summary (`Sales::summary`) ignores the status filter so voids are always counted.
- Everyone with the page can view + reprint (`receipt.php?id=X&autoprint=1`, new tab). Void = `sales.cancel` (super/branch admin)
  (server-checked, 403 for cashier), reason 3–255 chars, `Sales::void()`: transaction, lock sale + products
  `FOR UPDATE`, restock per product, `Products::log(..., 'void', +qty, ..., saleId)` with note
  "Voided sale No. X: reason", then status 'cancelled' + voided_at/by/reason. Voiding twice → 409 message.
- Stock History / customer purchase history link to `sale-view.php?id=`.
- Tablet (≤1200px): Cashier + Items columns hidden (`.col-opt`), stats 2×2.

## Reports behaviour (Phase 3, admin only)
- `Reports::period(from, to)`: default last 30 days, swapped if reversed, clamped to today, max 3 years;
  previous period = same length right before (KPI deltas). > 92 days → grouped by month.
  All sales figures = status 'completed' only; voided shown as a footnote. Net = sales.total (incl. VAT);
  item/category figures = sale_items.line_total (before discount & VAT) and are labelled so.
- Charts follow the dataviz skill: single series → palette slot 1 `#2a78d6` (validated vs white card surface),
  hover `#5598e7`; hairline solid grid; columns ≤ 24px, 4px rounded top, square base; only the peak is labelled;
  every chart has a table twin (column chart: "Show as table"; bar lists print every value).
  Column chart = SVG drawn by reports.js at real pixel width (ResizeObserver) from
  `<script type="application/json" id="salesSeries">` (json_encode with JSON_HEX_*). Bar lists = server-rendered
  `<svg><rect width="NN%">` (percent attributes, no inline style — CSP). One shared tooltip `#chartTip`
  (hover + keyboard focus/arrow keys, textContent only; positioned via CSSOM `el.style.left`, which CSP allows).
- `?export=csv` → UTF-8 BOM CSV (summary, series, top items); cells starting with = + - @ get a `'` prefix.
- App is light-only, so charts have no dark mode.

## Settings / Users behaviour (Phase 4)
- Settings (admin): `Settings::validate/save` (upsert into `settings`); `Settings::PLACEHOLDERS` = the sample
  address/phone → warning banner until replaced. VAT change affects new sales only (sales store vat_rate).
- Users (`users.view/manage/delete`, tab via `settings_page('users')`): rules live in `Users` (not just the UI): no
  deactivate/delete/role change on your own account; always ≥ 1 active super admin; users with sales can't be deleted
  (sales.user_id is RESTRICT) → deactivate. Passwords: 8–72 chars, not containing the username, not in
  `Users::WEAK`. Never `flash_old()` password fields.
- Session password stamp: `$_SESSION['auth']['pw']` = sha256(password_hash); `Auth::user()` signs the session
  out when it no longer matches (password reset elsewhere). Own change → `Auth::refreshPasswordStamp()`.
  Sessions without a stamp (pre-Phase-4) are stamped lazily, never signed out for that.
- Login flashes a warning when the password is one of `Users::DEFAULT_PASSWORDS`.
- `includes/header.php` defines `$user`, `$activeKey`, `$roleName`, `$now`: don't use those names for page data
  (user-form.php uses `$target`).

## Inventory / Customers behaviour
- Stock changes ONLY via sales, receiving (RR post/cancel), stock documents (InventoryDocs), branch transfers, job parts (JobParts) or `Products::adjustStock()` (reasons in `Products::REASONS`, direction-checked);
  every change writes `stock_movements` (type initial/sale/restock/adjustment/void/receiving, signed qty, stock_after).
  The product edit form never edits stock; opening stock is set on create only.
- Delete is allowed only if never sold / never bought; otherwise deactivate (`is_active=0` hides from POS).
- Customers: `customers.edit` adds/edits; `customers.delete` deactivates/deletes. Duplicate phone numbers are rejected.
- Images: `ImageUpload::store($_FILES['image'])` (finfo + getimagesize, 2 MB, ≤4000px, random hex name);
  `ImageUpload::url()`; `ImageUpload::delete()` only deletes hex names (never the bundled sample-*.png).
  Store only after other fields validate; delete the new file if the DB save fails; delete the old one after success.

## Conventions
- Every entry file starts with `require __DIR__ . '/../system/bootstrap.php';` Classes in `system/` autoload.
- Page: `$page = require_page('<key>');` (login + role from `config/menu.php`), then
  `require ROOT_PATH.'/includes/header.php'` … `footer.php`. Optional `$pageStyles`/`$pageScripts` arrays.
  Non-menu pages: `Auth::requireRole('admin','cashier');`
- API: `api_guard('POST', ['admin','cashier']);` (method + login + role + CSRF header), `$data = request_json();`,
  reply `json_response(['ok'=>true,...])`. For errors, **throw `new HttpException(status, msg, details)`**; the global
  handler turns it into JSON (API) or the error page, and rolls nothing back itself, so domain code does that.
  JS: `await BB.api('pos/checkout.php', {method:'POST', body})` (throws Error with .status/.data), `BB.toast(msg, type)`.
- Every query goes through `db()->prepare()->execute()`, including ones with no params. Money math is done in integer cents: `to_cents()`,
  `from_cents()`, `money()`. Settings: `setting('vat_rate')`. Sales must use a transaction.
- Escape all output with `e()`. JS builds DOM from `<template>` + `textContent` (no innerHTML with data).
  Icons: `icon('name')` (sprite `assets/img/icons.svg`, ids `i-<name>`); in JS `svgIcon(name)`.
- CSP is strict: **no inline `<script>`, no `style=""` attributes, no CDNs**. Put CSS/JS in assets.
- Errors: `abort(code, msg, ?title)`. Don't use HTTP 419 (Apache turns it into 500); CSRF failures use 403.
- Dialogs: `<dialog class="modal">`, any `[data-close]` button closes it (app.js).
- Server-rendered CRUD forms (PRG): on error `flash_old(...)`, `flash_errors([...])`, redirect back; in the template
  `old('x', $default)`, `has_old()`, `<input …<?= invalid('x') ?>>` + `<?= field_error('x') ?>`. Classes:
  `.form-grid/.form-field/.form-label/.form-input`, `.card--pad`, `.form-layout` + `.form-side`.
  Row actions: small POST forms with CSRF + hidden `return` (validated by `safe_return()`); destructive ones get
  `data-confirm="…"` (app.js asks). Lists: `paginate($total)`, `includes/pagination.php` ($pg, $pgPath, $pgQuery),
  search param is `search` (not `q`, which belongs to the header search), `like_pattern()` for LIKE.
- Shortcuts: app.js dispatches cancelable `bb:shortcut` event ({key:'F2'|'F3'|'F4'}); page JS calls preventDefault to take over.

## Schema notes
- `sales.status`: held (unused) / completed (stock deducted) / cancelled (void).
  `customer_id NULL` = Walk-in. `sale_no` = 7-digit zero-padded id ('0000005'), set right after insert.
- VAT 12% applied on (subtotal − discount); rate lives in `settings.vat_rate`.
- `sale_items` stores code/name/price snapshots. Products are soft-deleted (`is_active=0`).
- `products.stock` has CHECK >= 0; `reorder_level` drives low-stock pills.
- Categories (sort order): Laptops & Computers (icon laptop), Peripherals (mouse), Accessories (plug),
  Network (network), Office Supplies (clipboard). POS grid is ordered by `p.code` (matches the mockup).
- Stock adjust reasons: restock, return, damaged (defective), supplier (RMA), count, other.

## Testing
- Lint: `C:\xampp\php\php.exe -l file.php`
- Node.js v24 is installed now (`C:\Program Files\nodejs`), but the main suite is still PowerShell: use **`powershell -ExecutionPolicy Bypass -File tests\e2e-smoke.ps1 [outdir]`**
  (413 checks incl. security events + audit CSV + FORCE_HTTPS redirect, dashboard + profit / jobs / price override / branch reports, job parts custody, job billing / warranty release / back-job, job orders (intake, take, diagnosis, quotation, repair, ticket), POS pricing + approvals, branch transfers, warehouses, stock operations, counts, serial registration, receiving, branch average cost, serials + POS picker, integrity, master data, suppliers, unit-cost visibility, role × branch isolation, branch stock, roles, audit, DB integrity; PowerShell + Edge DevTools protocol; login, mockup cart totals, F2/F3/F4, checkout, stock, receipt,
  sales history filters, cashier can't void, admin void + restock + audit, reports (KPIs, chart hover/keys, top
  items, CSV, monthly grouping), settings save → receipt, users rules, add user, My Account, new-user login,
  logout, inventory, adjust reasons,
  icons.svg XML, no JS/CSP errors). Screenshots go to `%TEMP%\execom-e2e`. Keep the .ps1 ASCII-only
  (PowerShell 5.1 reads BOM-less files as ANSI): build ₱ with `[char]0x20B1`, use -like for dashes.
  `tests/e2e-pos.mjs` / `tests/e2e-admin.mjs` (+ `tests/lib/browser.mjs`) are the Node versions, updated for the
  EXECOM data but not runnable here.
- e2e-smoke.ps1 is **isolated**: it copies the app to `htdocs\EXECOMLOGISTICS-e2e` (robocopy, no .git/.claude/
  uploads/logs, `.e2e-copy` marker) with its own `.env` (live .env + appended APP_URL / `DB_NAME=execomlogistics_e2e` /
  SESSION_NAME), imports database.sql into `execomlogistics_e2e` (name rewritten, guarded), runs there, and deletes
  the copy after a passing run (kept after failures or with `-Keep`; the test DB always stays). The live app and
  `execomlogistics_db` are never touched, so it can run any time. Needs the live `.env` (reads DB_* from it).
  One run at a time (lock file in the output dir; a second run exits with SETUP ERROR). A kept copy is reachable
  from the LAN with the sample passwords (test DB only): delete `htdocs\EXECOMLOGISTICS-e2e` when done with it.
  Date-dependent checks must use dates relative to today (sample sales are `NOW() - INTERVAL …`).
- Never re-import `database.sql` into the live DB once it has real data (`SELECT MAX(id) FROM sales` > 4): it drops tables.
- Sample product images: SVGs rendered by `msedge --headless=new --default-background-color=00000000
  --window-size=400,600 --screenshot=...`, then cropped to 400x400 (headless window size includes chrome).
- File uploads in tests: `DOM.setFileInputFiles` with an objectId (see e2e-admin.mjs). With curl, use Windows paths.
- Editing `assets/img/icons.svg`: keep a space between attributes; an XML error silently breaks later icons.
- API tests: curl + cookie jar; CSRF from `<meta name="csrf-token">` sent as `X-CSRF-Token`.
- Headless Edge has a ~500px minimum viewport, so phone-width screenshots crop rather than show the real layout.
