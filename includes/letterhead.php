<?php
/**
 * Letterhead for printed documents (PO, PR): the EXECOM logo and the address / contact numbers of every active
 * branch that has an address (Settings → Branches), main branch first. Styles in assets/css/print-doc.css.
 */
$lhStmt = db()->prepare(
    "SELECT name, address, contact_no FROM branches
      WHERE is_active = 1 AND address IS NOT NULL AND address <> ''
      ORDER BY is_main DESC, id LIMIT 4"
);
$lhStmt->execute();
$lhBranches = $lhStmt->fetchAll();
?>
<header class="lh">
    <img class="lh__logo" src="<?= e(asset('img/execom-logo.png')) ?>" alt="<?= e(setting('shop_name', 'EXECOM')) ?>">
    <div class="lh__branches">
        <?php foreach ($lhBranches as $lhb): ?>
            <p><?= e($lhb['address']) ?><?php if ($lhb['contact_no']): ?><br><?= e($lhb['contact_no']) ?><?php endif; ?></p>
        <?php endforeach; ?>
        <?php if (!$lhBranches): ?>
            <p><?= e(setting('shop_address')) ?><br><?= e(setting('shop_phone')) ?></p>
        <?php endif; ?>
    </div>
</header>
