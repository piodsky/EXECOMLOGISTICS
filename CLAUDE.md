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
- Existing DBs need a migration file in `migrations/`, not a re-import.

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
- Keep folders clean (`config/ system/ includes/ pages/ api/ assets/ storage/ tests/`, `.env`).
- **Use the agent workflow** (user request) for every non-trivial task, see below.

## Agent workflow (`.claude/agents/`)
The user asked for this workflow, so use these subagents without asking again. Subagents can't launch subagents:
the main session runs each step with the agent named in project-manager's plan.
- Non-trivial / multi-file / cross-module task: **project-manager** first (plan + workflow), then the steps:
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
- Cart lives in the browser (+ localStorage `bb.pos.<userId>`); the server only receives product_id + qty and
  recomputes everything in `Sales::complete()` (transaction, `SELECT … FOR UPDATE`, `stock >= ?` guard).
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
- Stock changes ONLY via sales or `Products::adjustStock()` (reasons in `Products::REASONS`, direction-checked);
  every change writes `stock_movements` (type initial/sale/restock/adjustment/void, signed qty, stock_after).
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
- **Node.js is NOT installed on this PC.** Use **`powershell -ExecutionPolicy Bypass -File tests\e2e-smoke.ps1 [outdir]`**
  (174 checks incl. master data, suppliers, unit-cost visibility, role × branch isolation, branch stock, roles, audit, DB integrity; PowerShell + Edge DevTools protocol; login, mockup cart totals, F2/F3/F4, checkout, stock, receipt,
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
