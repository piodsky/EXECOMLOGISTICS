<?php
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('pos');

$stmt = db()->prepare('SELECT id, name, icon FROM categories WHERE is_active = ? ORDER BY sort_order, name');
$stmt->execute([1]);
$categories = $stmt->fetchAll();

$posBranch = Branch::current();          // 0 = "All branches": nothing can be sold until one is chosen
$customers = Branch::isConcrete() ? Customers::active() : [];
$vatRate   = (float) setting('vat_rate', '12');
$vatLabel  = rtrim(rtrim(number_format($vatRate, 2, '.', ''), '0'), '.');
$canAddCustomer = Auth::can('customers.edit');

$pageStyles  = ['css/pos.css'];
$pageScripts = ['js/pos.js'];

require ROOT_PATH . '/includes/header.php';
?>

<?php if (!Branch::isConcrete()): ?>
    <div class="alert alert--warning pos-branch-notice" role="status" id="chooseBranchNotice">
        <?= icon('store') ?>
        <span><strong>Choose a branch to start selling.</strong> You are viewing all branches; the POS sells from one branch's stock at a time.</span>
        <button type="button" class="btn btn--sm btn--light" data-focus-branch>Choose branch</button>
    </div>
<?php endif; ?>

<div class="pos" id="pos"
     data-user-id="<?= (int) Auth::id() ?>"
     data-branch-id="<?= (int) $posBranch ?>"
     data-vat-rate="<?= e((string) $vatRate) ?>"
     data-currency="<?= e(config('app.currency')) ?>"
     data-icons="<?= e(asset('img/icons.svg')) ?>"
     data-receipt-url="<?= e(url('pages/receipt.php')) ?>">

    <!-- ============ Products ============ -->
    <section class="pos-products panel" aria-label="Products">
        <div class="tabs" role="tablist" aria-label="Categories">
            <button type="button" class="tab is-active" role="tab" aria-selected="true" data-category="all">
                <?= icon('grid') ?> All Items
            </button>
            <?php foreach ($categories as $cat): ?>
                <button type="button" class="tab" role="tab" aria-selected="false" data-category="<?= (int) $cat['id'] ?>">
                    <?= icon($cat['icon']) ?> <?= e($cat['name']) ?>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="product-grid" id="productGrid">
            <p class="product-grid__state">Loading products…</p>
        </div>

        <footer class="pos-summary">
            <button type="button" class="pos-summary__btn" id="allItemsBtn" aria-haspopup="dialog" title="Show every item with its stock">
                <?= icon('barcode') ?>
                <span><small>Total Items <em>View</em></small><strong id="sumItems">0</strong></span>
            </button>
            <button type="button" class="pos-summary__btn" id="lowStockBtn" aria-haspopup="dialog" title="Show the items at or below their reorder level">
                <?= icon('alert') ?>
                <span><small>Low Stock <em>View</em></small><strong id="sumLow">0</strong></span>
            </button>
            <div>
                <?= icon('clock') ?>
                <span><small>Last Updated</small><strong id="sumUpdated">—</strong></span>
            </div>
        </footer>
    </section>

    <!-- ============ Current sale ============ -->
    <section class="pos-cart panel" aria-label="Current sale">
        <header class="cart-head">
            <?= icon('cart') ?>
            <h2>Current Sale</h2>
            <span class="cart-head__no">No. <span id="saleNo"><?= e(Sales::nextNumber()) ?></span></span>
            <?php if (Auth::can('pos.view_cost')): ?>
                <button type="button" class="cost-toggle" id="costToggle" aria-pressed="false" title="Show unit cost and margin (hidden from customers by default)"><?= icon('eye') ?><span>Cost</span></button>
            <?php endif; ?>
        </header>

        <div class="cart-fields">
            <div class="cart-field">
                <span class="cart-field__icon"><?= icon('user') ?></span>
                <label for="customerSelect">Customer</label>
                <select id="customerSelect">
                    <option value="">Walk-in Customer</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($canAddCustomer): ?>
                    <button type="button" class="icon-btn" id="addCustomerBtn" aria-label="Add new customer" title="Add new customer">
                        <?= icon('plus') ?>
                    </button>
                <?php endif; ?>
            </div>
            <div class="cart-field">
                <span class="cart-field__icon"><?= icon('wallet') ?></span>
                <label for="paymentSelect">Payment Type</label>
                <select id="paymentSelect" class="cart-field__wide">
                    <?php foreach (Sales::PAYMENT_TYPES as $value => $label): ?>
                        <option value="<?= e($value) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                    <?php if (Auth::can('sales.charge')): ?><option value="charge">On account (credit customer)</option><?php endif; ?>
                </select>
            </div>
        </div>

        <div class="cart-table-wrap">
            <table class="cart-table">
                <thead>
                <tr>
                    <th class="c-n">#</th>
                    <th>Item</th>
                    <th class="c-qty">Qty</th>
                    <th class="num c-price">Unit Price</th>
                    <th class="num c-cost">Cost / Margin</th>
                    <th class="num">Total</th>
                    <th class="c-act">Action</th>
                </tr>
                </thead>
                <tbody id="cartBody"></tbody>
            </table>
            <div class="cart-empty" id="cartEmpty">
                <?= icon('cart') ?>
                <p>No items yet</p>
                <small>Click a product or scan a barcode (F2)</small>
            </div>
        </div>

        <div class="totals">
            <div class="totals__row">
                <span>Sub Total</span>
                <strong id="tSubtotal"><?= e(money(0)) ?></strong>
            </div>
            <div class="totals__row">
                <label for="discountInput">Discount <small class="muted" id="tDiscount"></small></label>
                <span class="discount-input">
                    <input id="discountInput" type="number" min="0" max="100" step="0.01" value="0" inputmode="decimal">
                    <span>%</span>
                </span>
            </div>
            <div class="totals__row">
                <span>VAT (<?= e($vatLabel) ?>%)</span>
                <strong id="tVat"><?= e(money(0)) ?></strong>
            </div>
            <div class="totals__grand">
                <span>Total Amount</span>
                <strong id="tTotal"><?= e(money(0)) ?></strong>
            </div>
        </div>

        <div class="pos-actions">
            <button type="button" class="pos-btn pos-btn--light" id="btnNew"><?= icon('file') ?><span>New Sale</span></button>
            <button type="button" class="pos-btn pos-btn--blue" id="btnSave"><?= icon('save') ?><span>Save</span></button>
            <button type="button" class="pos-btn pos-btn--green" id="btnPrint"><?= icon('printer') ?><span>Print</span></button>
            <button type="button" class="pos-btn pos-btn--red" id="btnCancel"><?= icon('x') ?><span>Cancel</span></button>
        </div>

        <div class="shortcut-bar">
            <?= icon('tag') ?>
            <span>Press <kbd>F2</kbd> to scan barcode</span><i>|</i>
            <span><kbd>F3</kbd> to search item</span><i>|</i>
            <span><kbd>F4</kbd> to add item</span>
        </div>
    </section>
</div>

<!-- ============ Templates (filled by pos.js with textContent — no HTML injection) ============ -->
<template id="productCardTpl">
    <button type="button" class="product-card">
        <span class="product-card__media"></span>
        <span class="product-card__incart" hidden></span>
        <span class="product-card__name"></span>
        <span class="product-card__code"></span>
        <span class="product-card__foot">
            <span class="stock-pill"></span>
            <span class="product-card__add"><?= icon('plus') ?></span>
        </span>
    </button>
</template>

<template id="cartRowTpl">
    <tr>
        <td class="c-n"></td>
        <td class="cart-row__name"><strong></strong><small></small><ul class="cart-sn" aria-label="Serial numbers" hidden></ul></td>
        <td class="c-qty">
            <span class="qty">
                <button type="button" data-act="dec" aria-label="Decrease quantity"><?= icon('minus') ?></button>
                <input type="number" min="1" step="1" inputmode="numeric" aria-label="Quantity">
                <button type="button" data-act="inc" aria-label="Increase quantity"><?= icon('plus') ?></button>
            </span>
        </td>
        <td class="num c-price">
            <button type="button" class="price-btn" data-act="price" aria-label="Change price">
                <span class="cart-row__price"></span><small class="cart-row__was" hidden></small>
            </button>
        </td>
        <td class="num c-cost"><span class="cart-row__cost"></span><small class="cart-row__margin"></small></td>
        <td class="num cart-row__total"></td>
        <td class="c-act">
            <button type="button" class="icon-btn icon-btn--danger" data-act="remove" aria-label="Remove item"><?= icon('trash') ?></button>
        </td>
    </tr>
</template>

<template id="cartSerialTpl">
    <li class="cart-sn__chip">
        <span class="cart-sn__no"></span>
        <button type="button" class="cart-sn__remove" data-act="sn-remove"><?= icon('x') ?></button>
    </li>
</template>

<template id="serialOptionTpl">
    <li>
        <label class="sn-option">
            <input type="checkbox">
            <span class="sn-option__no"></span>
        </label>
    </li>
</template>

<!-- ============ Serial number picker (serial-tracked items) ============ -->
<dialog class="modal modal--serials" id="serialDialog" aria-labelledby="serialTitle">
    <form class="modal__body" id="serialForm" novalidate>
        <header class="modal__head">
            <h2 id="serialTitle">Select Serial Numbers</h2>
            <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
        </header>
        <p class="sn-product"><strong id="serialProduct"></strong> <small class="muted" id="serialCode"></small></p>
        <label class="field">
            <span class="field__label">Scan or filter</span>
            <span class="field__control field__control--plain">
                <input id="serialFilter" type="search" maxlength="60" autocomplete="off" spellcheck="false" placeholder="Serial number">
            </span>
        </label>
        <ul class="sn-options" id="serialList" aria-label="Serial numbers in stock"></ul>
        <p class="sn-state muted" id="serialState" role="status"></p>
        <p class="pay-error" id="serialError" role="alert" hidden></p>
        <footer class="modal__foot">
            <span class="sn-count muted" id="serialCount" aria-live="polite"></span>
            <button type="button" class="btn btn--light" data-close>Back</button>
            <button type="submit" class="btn btn--primary" id="serialConfirm">Add to Sale</button>
        </footer>
    </form>
</dialog>

<!-- ============ Payment dialog ============ -->
<dialog class="modal" id="payDialog" aria-labelledby="payTitle">
    <form class="modal__body" id="payForm" novalidate>
        <header class="modal__head">
            <h2 id="payTitle">Complete Sale</h2>
            <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
        </header>

        <div class="pay-total">
            <small>Total Amount</small>
            <strong id="payTotal"></strong>
            <span class="badge" id="payMethod"></span>
        </div>

        <div id="cashFields">
            <label class="field">
                <span class="field__label">Amount Received</span>
                <span class="field__control">
                    <span class="field__prefix"><?= e(config('app.currency')) ?></span>
                    <input id="payAmount" type="text" inputmode="decimal" autocomplete="off" maxlength="12">
                </span>
            </label>
            <div class="quick-cash" id="quickCash"></div>
            <div class="pay-change" id="payChangeBox">
                <span id="payChangeLabel">Change</span>
                <strong id="payChange"></strong>
            </div>
        </div>

        <p class="pay-error" id="payError" role="alert" hidden></p>

        <footer class="modal__foot">
            <button type="button" class="btn btn--light" data-close>Back</button>
            <button type="submit" class="btn btn--primary" id="payConfirm">Confirm Payment</button>
        </footer>
    </form>
</dialog>

<!-- ============ Sale completed dialog ============ -->
<!-- ============ Low stock list (no prices; filled by pos.js from the loaded products) ============ -->
<dialog class="modal modal--low" id="lowDialog" aria-labelledby="lowTitle">
    <div class="modal__body">
        <header class="modal__head">
            <h2 id="lowTitle">Low Stock</h2>
            <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
        </header>
        <p class="muted" id="lowIntro" data-low="Items at or below their reorder level here (<?= e(Branch::label()) ?>), lowest first."
           data-all="Every item sold here (<?= e(Branch::label()) ?>) with its stock, A to Z."></p>
        <input class="form-input low-search" type="search" id="lowSearch" placeholder="Filter by item name or code" aria-label="Filter items" hidden>
        <div class="low-list">
            <table class="low-table" id="lowTable">
                <thead><tr><th>Item</th><th class="num">In stock</th><th class="num">Reorder at</th></tr></thead>
                <tbody id="lowBody"></tbody>
            </table>
            <p class="muted low-empty" id="lowEmpty" hidden>No low-stock items. Everything is above its reorder level.</p>
        </div>
        <footer class="modal__foot modal__foot--split">
            <?php if (Auth::can('inventory.view')): ?>
                <a class="btn btn--light" id="lowInventory" href="<?= e(url('pages/inventory.php?status=low')) ?>" data-low="<?= e(url('pages/inventory.php?status=low')) ?>" data-all="<?= e(url('pages/inventory.php')) ?>"><?= icon('box') ?> Open in Inventory</a>
            <?php endif; ?>
            <?php if (Auth::can('purchasing.request')): ?>
                <a class="btn btn--primary" href="<?= e(url('pages/pr-form.php')) ?>"><?= icon('cart') ?> Request Purchase</a>
            <?php else: ?>
                <button type="button" class="btn btn--primary" data-close>Close</button>
            <?php endif; ?>
        </footer>
    </div>
</dialog>
<template id="lowRowTpl">
    <tr>
        <td><strong class="low-name"></strong><small class="muted low-code"></small></td>
        <td class="num"><span class="stock-pill low-stock"></span></td>
        <td class="num low-reorder"></td>
    </tr>
</template>

<dialog class="modal" id="doneDialog" aria-labelledby="doneTitle">
    <div class="modal__body done">
        <span class="done__icon"><?= icon('check') ?></span>
        <h2 id="doneTitle">Sale Completed</h2>
        <p class="muted">No. <strong id="doneNo"></strong></p>
        <div class="done__grid">
            <span>Total</span><strong id="doneTotal"></strong>
            <span>Paid</span><strong id="donePaid"></strong>
            <span>Change</span><strong id="doneChange" class="done__change"></strong>
        </div>
        <footer class="modal__foot modal__foot--split">
            <button type="button" class="btn btn--success" id="donePrint"><?= icon('printer') ?> Print Receipt</button>
            <button type="button" class="btn btn--primary" id="doneNew" autofocus>New Sale</button>
        </footer>
    </div>
</dialog>

<!-- ============ Quick add customer ============ -->
<dialog class="modal" id="customerDialog" aria-labelledby="customerTitle">
    <form class="modal__body" id="customerForm" novalidate>
        <header class="modal__head">
            <h2 id="customerTitle">Add Customer</h2>
            <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
        </header>
        <label class="field">
            <span class="field__label">Name *</span>
            <span class="field__control field__control--plain">
                <input name="name" required maxlength="100" autocomplete="off">
            </span>
        </label>
        <label class="field">
            <span class="field__label">Phone</span>
            <span class="field__control field__control--plain">
                <input name="phone" maxlength="30" inputmode="tel" autocomplete="off" placeholder="0917 123 4567">
            </span>
        </label>
        <label class="field">
            <span class="field__label">Email</span>
            <span class="field__control field__control--plain">
                <input name="email" type="email" maxlength="120" autocomplete="off">
            </span>
        </label>
        <p class="pay-error" id="customerError" role="alert" hidden></p>
        <footer class="modal__foot">
            <button type="button" class="btn btn--light" data-close>Cancel</button>
            <button type="submit" class="btn btn--primary">Save Customer</button>
        </footer>
    </form>
</dialog>

<!-- ============ Change price dialog ============ -->
<dialog class="modal" id="priceDialog" aria-labelledby="priceTitle">
    <form class="modal__body" id="priceForm" novalidate>
        <header class="modal__head">
            <h2 id="priceTitle">Change Price</h2>
            <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
        </header>
        <p class="price-item"><strong id="priceProduct"></strong><small class="muted" id="priceSuggested"></small></p>
        <label class="field">
            <span class="field__label">Selling price (before VAT)</span>
            <span class="field__control">
                <span class="field__prefix"><?= e(config('app.currency')) ?></span>
                <input id="priceInput" type="text" inputmode="decimal" autocomplete="off" maxlength="12">
            </span>
        </label>
        <label class="field" id="priceReasonField">
            <span class="field__label">Reason <small class="muted">(required when lower)</small></span>
            <span class="field__control field__control--plain">
                <input id="priceReason" type="text" maxlength="255" autocomplete="off" placeholder="e.g. Regular customer, bulk order">
            </span>
        </label>
        <p class="muted price-hint" id="priceHint"></p>
        <p class="price-cost" id="priceCost" hidden></p>
        <p class="pay-error" id="priceError" role="alert" hidden></p>
        <footer class="modal__foot">
            <button type="button" class="btn btn--light" id="priceReset">Suggested Price</button>
            <button type="submit" class="btn btn--primary">Apply</button>
        </footer>
    </form>
</dialog>

<!-- ============ Admin approval dialog (price / discount beyond the limits) ============ -->
<dialog class="modal" id="approveDialog" aria-labelledby="approveTitle">
    <form class="modal__body" id="approveForm" novalidate autocomplete="off">
        <header class="modal__head">
            <h2 id="approveTitle"><?= icon('lock') ?> Admin Approval</h2>
            <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
        </header>
        <p class="muted">These need an administrator's approval (beyond your limit or below the allowed price):</p>
        <ul class="approve-list" id="approveList"></ul>
        <label class="field">
            <span class="field__label">Approver username</span>
            <span class="field__control field__control--plain">
                <input id="approveUser" type="text" maxlength="50" autocomplete="off" autocapitalize="none" spellcheck="false">
            </span>
        </label>
        <label class="field">
            <span class="field__label">Approver password</span>
            <span class="field__control field__control--plain">
                <input id="approvePass" type="password" maxlength="200" autocomplete="new-password">
            </span>
        </label>
        <p class="pay-error" id="approveError" role="alert" hidden></p>
        <footer class="modal__foot">
            <button type="button" class="btn btn--light" data-close>Back</button>
            <button type="submit" class="btn btn--primary" id="approveSubmit"><?= icon('check') ?> Approve &amp; Complete</button>
        </footer>
    </form>
</dialog>

<template id="approveItemTpl"><li><span class="approve-list__name"></span><strong class="approve-list__price"></strong></li></template>

<iframe id="receiptFrame" class="receipt-frame" title="Receipt printer" tabindex="-1" aria-hidden="true"></iframe>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
