<?php
/**
 * "Payment" card of a PO Internal / customer order: $payCard from PaymentStatus::poCard() / orderCard().
 * Each document (supplier invoice / receiving report / bill) lists its payments (vouchers / collections) with the
 * method, check no., bank and where the check is.
 */
declare(strict_types=1);

/** @var array $payCard */
$payStatus = $payCard['status'];
?>
<section class="card card--pad pay-card" id="payCard" data-payment="<?= e($payStatus['key']) ?>">
    <div class="pay-card__head">
        <h2 class="card__title"><?= e($payCard['title']) ?></h2>
        <?php if ($payStatus['key'] !== 'none'): ?><span class="badge <?= e($payStatus['badge']) ?>" id="payStatus"><?= e($payStatus['label']) ?></span><?php endif; ?>
    </div>
    <?php if ($payStatus['notes']): ?>
        <p class="pay-notes"><?php foreach ($payStatus['notes'] as $n): ?><span><?= icon('alert') ?> <?= e($n) ?></span><?php endforeach; ?></p>
    <?php endif; ?>
    <?php if ($payCard['totals']): ?>
        <dl class="detail-list pay-totals">
            <?php foreach ($payCard['totals'] as [$payK, $payV, $payCls]): ?><div><dt><?= e($payK) ?></dt><dd class="<?= e($payCls) ?>"><?= e($payV) ?></dd></div><?php endforeach; ?>
        </dl>
    <?php endif; ?>
    <?php if ($payCard['docs']): ?>
        <ul class="pay-docs">
            <?php foreach ($payCard['docs'] as $payDoc): ?>
                <li class="pay-doc<?= $payDoc['void'] ? ' is-void' : '' ?>">
                    <div class="pay-row">
                        <span>
                            <?php if ($payDoc['url']): ?><a class="doc-no" href="<?= e(url($payDoc['url'])) ?>"><?= e($payDoc['no']) ?></a><?php else: ?><span class="doc-no"><?= e($payDoc['no']) ?></span><?php endif; ?>
                            <small class="muted block"><?= e($payDoc['sub']) ?></small>
                            <small class="block pay-amount"><?= e($payDoc['amount']) ?></small>
                        </span>
                        <span class="badge <?= e($payDoc['badge'][1]) ?>"><?= e($payDoc['badge'][0]) ?></span>
                    </div>
                    <?php if (!empty($payDoc['action'])): ?>
                        <a class="btn btn--light btn--sm pay-action" href="<?= e(url($payDoc['action'][1])) ?>"<?= !empty($payDoc['action'][3]) ? ' target="_blank" rel="noopener"' : '' ?>><?= icon($payDoc['action'][2] ?? 'plus') ?> <?= e($payDoc['action'][0]) ?></a>
                    <?php endif; ?>
                    <?php if ($payDoc['lines']): ?>
                        <ul class="pay-lines">
                            <?php foreach ($payDoc['lines'] as $payLine): ?>
                                <li class="<?= $payLine['void'] ? 'is-void' : '' ?>">
                                    <span>
                                        <?php if ($payLine['url']): ?><a class="doc-no" href="<?= e(url($payLine['url'])) ?>"><?= e($payLine['no']) ?></a><?php else: ?><span class="doc-no"><?= e($payLine['no']) ?></span><?php endif; ?>
                                        <small class="muted block"><?= e($payLine['sub']) ?></small>
                                        <small class="block pay-amount"><?= e($payLine['amount']) ?></small>
                                    </span>
                                    <span class="badge <?= e($payLine['badge'][1]) ?>"><?= e($payLine['badge'][0]) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php elseif ($payDoc['none']): ?>
                        <p class="muted pay-none"><?= e($payDoc['none']) ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php elseif ($payCard['empty']): ?>
        <p class="muted pu-empty"><?= e($payCard['empty']) ?></p>
    <?php endif; ?>
    <p class="muted pay-foot"><?= e($payCard['foot']) ?></p>
</section>
