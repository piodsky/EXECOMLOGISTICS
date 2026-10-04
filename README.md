# EXECOM Logistics — POS & Inventory System

PHP 8 + PDO + MySQL point-of-sale and inventory for an IT products distributor
(laptops, peripherals, accessories, network gear, office supplies). No framework, plain CSS, vanilla JS.

## Setup on XAMPP (Windows)

1. **Copy the folder**: put `EXECOMLOGISTICS` in `C:\xampp\htdocs\` so you have
   `C:\xampp\htdocs\EXECOMLOGISTICS\index.php`.
2. **Start services**: open the XAMPP Control Panel and start **Apache** and **MySQL**.
3. **Create/import the database**
   - Open http://localhost/phpmyadmin, then **Import**, choose `database.sql` and click **Go**.
     (The file creates `execomlogistics_db` if it doesn't exist.)
   - Or from a terminal:
     `C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\EXECOMLOGISTICS\database.sql`
   - ⚠ Re-importing **drops and recreates all tables** (all data is reset).
4. **Check `.env`**: the defaults match stock XAMPP (`root`, empty password, `execomlogistics_db`).
   If you set a MySQL password, put it in `DB_PASS=`. If `.env` is missing, copy `.env.example` to `.env`.
5. **Open** http://localhost/EXECOMLOGISTICS and sign in:

   | Username | Password     | Role    |
   |----------|--------------|---------|
   | admin    | admin123     | Super Administrator (Maramag, all branches) |
   | cashier  | cashier123   | Cashier (Maramag) |

   **Change these passwords right away**: user menu (top right) → **Change Password**. The app reminds you at
   sign-in while a default password is in use. Add your staff under **Settings → Users**.
6. **Company details on receipts**: the sample data uses placeholders (`Company Address, City, Province`,
   `(000) 000-0000`, no TIN). Replace them in **Settings → Company & Receipt** (a banner reminds you until you do).

### Upgrading an existing install (keeps your data)
Copy the new files over the old folder, then run the migrations you haven't run yet, in order. They only
**add** tables and columns and can safely be run twice:

| From | Run |
|---|---|
| Phase 1 → 2 | `migrations\002_phase2_sales_history.sql` (void columns on `sales`) |
| Phase 2 → 3 | nothing: Reports uses the existing tables (just copy the files) |
| Phase 3 → 4 | nothing: Settings and Users use the existing tables (just copy the files) |
| Phase 4 → 5 | `migrations\003_branches_permissions.sql` (branches, branch stock, roles & permissions, audit log) |
| Phase 5 → 6 | `migrations\004_master_data.sql` (brands, models, units, suppliers, customer types, contacts, lists) |
| Phase 6 → 7a | `migrations\005_receiving_cost_serials.sql` (receiving reports, branch average cost, serial numbers) |
| Phase 7a → 7b | `migrations\006_warehouse_ops.sql` (warehouses & locations, stock operations, stock counts) |
| Phase 7b → 8 | `migrations\007_branch_transfers.sql` (branch-to-branch transfers) |
| Phase 8 → 9 | `migrations\008_pos_pricing.sql` (POS pricing: actual price, limits, approvals) |
| Phase 9 → 10a | `migrations\009_job_orders.sql` (job orders & technicians, quotation threshold) |
| Phase 10a → 10b | `migrations\010_job_parts_billing.sql` (job parts, billing & release, back-jobs) |
| Phase 10b → 11 | nothing: Dashboard and the new reports use the existing tables (just copy the files) |
| Phase 11 → 12 | `migrations\011_security_indexes.sql` (indexes only), then the tools in *Security, database user & backups* below |
| Phase 12 → 13a | `migrations\012_purchasing.sql` (purchase requests, PO Internal, receiving from a PO; fills the MAR / MLB / CDO letterhead addresses if empty). Run as **root** |
| Phase 13a → 13b | `migrations_customer_orders.sql` (customer orders / PO Outgoing, delivery receipts, billing on account). Run as **root** |

```
C:\xampp\mysql\bin\mysql.exe -u root execomlogistics_db < C:\xampp\htdocs\EXECOMLOGISTICS\migrations\002_phase2_sales_history.sql
```
(Or in phpMyAdmin: select `execomlogistics_db` → **Import** → the migration file.)
A fresh install (importing `database.sql`) already includes every migration.

### Using it from other devices on the network
Set `APP_URL=http://<PC-IP>/EXECOMLOGISTICS` in `.env` (for example `http://192.168.1.10/EXECOMLOGISTICS`) and
allow Apache through Windows Firewall. Links are host-relative, so tablets on the LAN work as well.

### Security, database user & backups
Run these from `C:\xampp\htdocs\EXECOMLOGISTICS` in PowerShell (XAMPP's MySQL must be running):

| Task | Command |
|---|---|
| Dedicated MySQL users (app + backup) with new random passwords, written to `.env`. Run again to rotate the passwords. | `powershell -ExecutionPolicy Bypass -File tools\setup-db-users.ps1` |
| Back up the database now (to `C:\EXECOM-Backups`, `.sql.gz`, keeps 30 days) | `powershell -ExecutionPolicy Bypass -File tools\backup.ps1` |
| Prove a backup restores (loads the newest into a scratch DB, checks it, drops it) | `powershell -ExecutionPolicy Bypass -File tools\restore-test.ps1` |
| Nightly backup at 9 PM (Windows Task Scheduler "EXECOM Database Backup") | `powershell -ExecutionPolicy Bypass -File tools\install-backup-task.ps1` |

- The app user `execom_app` can only read and write data (no schema changes), so **migrations run as `root`**
  (`mysql.exe -u root execomlogistics_db < migrations\…`). phpMyAdmin keeps using `root` (local PC only).
- Backups only run while the PC and MySQL are on; check `C:\EXECOM-Backups\backup.log`. Copy backups to another
  disk / cloud regularly. Restore a backup: create an empty database, then
  `mysql.exe -u root <database> < backup.sql` (unzip the `.gz` first, e.g. with 7-Zip).
- Settings → Audit Log has a **Sign-in & Security** filter (sign-ins, failed sign-ins, lockouts, sign-outs, branch
  switches) and **Export CSV**.

### Going live checklist
- `.env`: `APP_ENV=production`, `APP_DEBUG=false`
- MySQL: run `tools\setup-db-users.ps1` on the server, give `root` a password, keep MySQL bound to the server
  (`bind-address=127.0.0.1` when the app runs on the same machine)
- HTTPS when branches connect over the internet / VPN: install a certificate in Apache (`conf\extra\httpd-ssl.conf`),
  then set `FORCE_HTTPS=true` in `.env` (http is redirected to https, browsers remember it for a year; session
  cookies become `Secure` automatically). Don't turn it on before the certificate works.
- Hide the Apache / PHP version: in `C:\xampp\apache\conf\extra\httpd-default.conf` set `ServerTokens Prod` and
  `ServerSignature Off`, in `php.ini` set `expose_php=Off`, then restart Apache
- Schedule `tools\install-backup-task.ps1` on the server and run `tools\restore-test.ps1` once a month
- MySQL memory on the central server: `innodb_buffer_pool_size=1G` (or more) in `my.ini`
- Optional auto-logout: set `SESSION_IDLE_TIMEOUT` (seconds). `0` = stay signed in until Logout.

## Using the POS

| Action | How |
|---|---|
| Add an item | Click a product card, **or** scan its barcode, **or** type a name and press **Enter** |
| Scan barcode | **F2**, then scan. (Scanning works even without F2, because typing goes straight to the search box) |
| Search | **F3**, type; **↑ / ↓** to pick; **F4** or **Enter** adds the highlighted item |
| Change quantity | − / + buttons or type the number (can't exceed stock) |
| Discount | Type a % in *Discount*. VAT 12% is applied after the discount |
| **Save** | Opens payment: enter the cash received (or tap a quick amount), shows the change → completes the sale and deducts stock |
| **Print** | Same as Save, then prints the receipt. With an empty cart it reprints the last sale |
| **New Sale / Cancel** | Clears the current cart (asks first) |
| New customer | **+** next to *Customer* |

Out-of-stock items can't be added, and the server re-checks stock when saving. If two terminals sell the last
unit at the same moment, only one sale goes through.

**Receipt printer:** receipts are laid out for 80 mm thermal paper (they also print fine on A4). In the Chrome/Edge
print dialog choose the printer, set *Margins: None* and untick *Headers and footers* (the browser remembers this).
For one-click printing without the dialog, start Edge/Chrome on the POS PC with `--kiosk-printing`.

## Sales History

- **Sales History** (admin + cashier): every completed and voided sale, newest first.
  - Summary for the chosen period: completed sales, total sales, average sale, voided (count + amount).
  - Filters: search by **sale no., customer or item** (name/code: handy for warranty look-ups), date range with
    quick buttons (*Today, Yesterday, Last 7 days, This month, All time*), status, payment type, cashier.
  - **Eye icon** opens the sale: items, discount, VAT, total, payment, change, cashier, customer.
  - **Printer icon / Reprint Receipt** opens the receipt in a new tab and prints it.
- **Void Sale** (admin only, on the sale's page): enter a reason → the sale becomes *Voided*, is no longer counted
  in sales totals, and **every item goes back to stock** (shown in each product's Stock History as
  "Voided sale No. …"). A voided receipt prints with a **VOID** stamp, the date and the reason. Voiding can't be
  undone; ring the sale up again if it was voided by mistake.

## Reports (admin)

- **Period**: quick buttons (*Today, Last 7 days, Last 30 days, This month, Last month, This year*) or any
  From/To range. Everything on the page follows the same period. Default: last 30 days.
  Only **completed** sales count; voided sales are left out (a note says how many).
- **Summary**: Net Sales (incl. VAT), Transactions, Average Sale, Items Sold, each compared with the previous
  period of the same length (e.g. the 30 days before).
- **Daily Net Sales** chart (monthly when the range is over 3 months). Hover a column, or click the chart and
  use ← / →, to read each day; **Show as table** lists every day.
- **Top 10 Items** (by sales or by quantity), **Sales by Category**, **Payment Types**, **Sales by Cashier**.
  Item and category figures are item totals *before* the sale discount and VAT.
- **Inventory** (right now): stock value, active products, low / out of stock, stock value by category and a
  **Reorder List** of products at or below their low-stock level.
- **Export CSV** (opens in Excel) and **Print** (charts print, buttons don't).

## Settings & Users (admin)

- **Settings → Company & Receipt**: company name, address, phone, VAT Reg. TIN (leave empty to hide it),
  VAT rate and the receipt footer, with a live receipt preview. A new VAT rate applies to new sales only;
  past sales keep the rate they were made with.
- **Settings → Users**: add staff, edit names/usernames/roles, reset passwords, deactivate or delete.
  - **Admin** can use every page. **Cashier** can use the POS, Sales History and Customers.
  - Resetting a password signs that person out everywhere else; deactivating signs them out immediately.
  - You can't deactivate, delete or change the role of your own account, and there is always at least one
    active admin. Users who rang up sales can only be deactivated, so their sales keep their name.
- **My Account** (everyone, from the user menu → **Change Password**): change your own password after entering
  the current one. Other devices signed in as you are signed out.
- Passwords: at least 8 characters, not containing the username, and not a common password (e.g. `password`,
  `12345678`, the sample `admin123` / `cashier123`).

## Inventory & Customers

- **Inventory** (admin): search by name/code/barcode, filter by category or *Low stock / Out of stock*.
  - **Add / Edit product**: name, category, price, item code (e.g. `ITM-0013`), barcode, description, image,
    low-stock alert level.
  - **Adjust stock** (box icon): add or remove with a reason (restock/delivery, customer return,
    damaged/defective, returned to supplier (RMA), stock count correction…).
    Every change, including each sale, is kept in the product's **Stock History** with who did it.
  - **Deactivate** (power icon) hides a product from the POS but keeps its history. **Delete** only appears for
    products that were never sold.
  - **Images**: JPG, PNG or WebP up to 2 MB (square looks best). The 12 sample products come with original
    illustrations; replace them with your own product photos anytime.
- **Customers** (admin + cashier): add/edit, search by name/phone/email, see visits, total spent and past receipts.
  Only an admin can deactivate or delete a customer; customers with purchases can only be deactivated.

## Purchasing (stock in)

- **Purchase Requests** (all staff): list the items the branch needs (end-user, needed-by date, purpose, optional
  job order). A branch admin who did not make the request approves (possibly fewer) or rejects it.
- **PO Internal** (branch admin): the purchase order to the supplier, made from approved requests or from scratch
  (supplier, terms, expected delivery, contact, ship-to, forwarder, unit costs). Draft → For Approval → approved by a
  branch admin other than the preparer (it gets its `PO-<branch>-<year>-NNNNNN` number) → **Print PO** on the EXECOM
  letterhead and send it.
- **Receive Delivery** on the PO opens a receiving report with what is still due (partial deliveries are fine);
  posting it adds the stock as before. The PO shows ordered / received / still due and every delivery, and becomes
  *Received* when everything arrived. *Close* a partly received PO when the rest won't come; *Cancel* one that
  received nothing.

## Customer Orders (stock out)

- **PO Outgoing**: enter the purchase order a government office, company or school sent you (customer, their PO no.
  and date, end-user, place of delivery, terms, deadline, mode of procurement, award / BAC reference) with the agreed
  prices (VAT is added on the bill; a price below the suggested price needs a reason).
- A branch admin who did not prepare it **confirms** it: it gets its `CO-<branch>-<year>-NNNNNN` number and its items
  are **reserved** at the branch. Reserved units can't be sold at the POS or used by any other stock-out (job parts,
  transfers, write-offs, adjustments) until they are delivered, or the order is closed / cancelled.
- **Delivery Receipt**: release what goes on each trip (partial deliveries are fine; serial numbers are chosen); the
  stock leaves the branch then. Print the DR, and **Mark Delivered** with who received it and the IAR no. A DR that
  was not billed can be returned to stock.
- **Bill**: tick the delivery receipts and choose cash / GCash / card, or **On account** for customers who pay later.
  The bill is a normal sale (Sales History, reports) that does not deduct the stock again; print the **Billing
  Statement**. Voiding the bill makes the receipts billable again.
- **Order Tracking** shows customer orders still to confirm / deliver / bill and purchase orders still coming in.

## Folder structure

```
EXECOMLOGISTICS/
├── .env / .env.example   Secrets & environment settings (blocked from the web)
├── .htaccess             Blocks dotfiles, .sql, .md; no directory listing
├── index.php             Redirects to login or POS
├── login.php / logout.php
├── database.sql          Schema + sample data (fresh install)
├── migrations/           Upgrade scripts for existing databases                 [web-blocked]
├── config/               app.php, database.php, menu.php (menu + page roles)   [web-blocked]
├── system/               Core: bootstrap, Env, Database, Session, Csrf, Auth, helpers,
│                         Sales, Reports, Products, Customers, Users, Settings, ImageUpload, HttpException [web-blocked]
├── includes/             Layout partials: header, sidebar, footer, flash, error, settings-nav [web-blocked]
├── pages/                One file per screen (pos, receipt, sales-history, sale-view, reports, inventory,
│                         customers, settings, users, user-form, account, ...)
├── api/                  JSON endpoints: pos/products, pos/checkout, customers/create
├── assets/               css/, js/, img/ (logo, icons.svg sprite), uploads/products/ (product images)
├── storage/logs/         Error logs                                        [web-blocked]
└── tests/                Browser tests: e2e-smoke.ps1 (PowerShell + Edge); e2e-*.mjs (Node.js)  [web-blocked]
```

## Security built in
- `password_hash` / `password_verify`; automatic rehash; timing-safe login (no username enumeration)
- Login throttling: 5 failures per username+IP → 15 min lockout (configurable in `.env`)
- PDO with real prepared statements (emulation off) and MySQL strict mode
- CSRF token on every form and on AJAX (`X-CSRF-Token` header); logout is POST-only
- All output escaped with `e()` (`htmlspecialchars`)
- Sessions: strict mode, HttpOnly, SameSite=Lax, path-scoped cookie, new ID at login and every 15 min,
  bound to the browser user-agent; optional idle/absolute timeout
- Roles checked on every page (`require_page()`) and API endpoint (`api_guard()`)
- Security headers: CSP (no inline scripts/styles), X-Frame-Options, nosniff, no-store cache
- Uploads folder serves images only; scripts can never run there

## Roadmap
- [x] **Phase 1**: EXECOM Logistics foundation: `execomlogistics_db` (IT categories + 12 sample products),
      login/logout/roles, blue layout (topbar + sidebar), POS screen (tabs, grid, barcode/F2/F3/F4, cart, discount,
      VAT, payment, receipt printing), Inventory (CRUD, images, stock adjustments + audit log), Customers
- [x] **Phase 2**: Sales History: summary, filters (search incl. items, date range + quick ranges, status, payment,
      cashier), sale details, reprint, void with reason (admin; restocks items, audit-logged)
- [x] **Phase 3**: Reports: period presets + range, KPIs vs previous period, daily/monthly sales chart (+ table),
      top items, by category / payment / cashier, inventory value + reorder list, CSV export, print
- [x] **Phase 4**: Settings (company details, VAT, receipt footer, receipt preview), Users (add/edit/reset password/
      deactivate/delete with safety rules), My Account (change own password), default-password reminder
