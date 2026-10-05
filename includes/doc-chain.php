<?php
/**
 * Document chain strip (DocChain::of()): every linked document of the buying / selling chain with its status, the open
 * document highlighted. Nothing is shown when the document has no linked documents.
 *
 * @var array|null $chain DocChain::of(type, id)
 */
declare(strict_types=1);
?>
<?php if ($chain): ?>
<nav class="doc-chain no-print" aria-label="<?= $chain['side'] === 'buy' ? 'Buying' : 'Selling' ?> document chain" id="docChain">
    <?php foreach ($chain['stages'] as $chainStage): ?>
        <div class="doc-chain__stage<?= $chainStage['current'] ? ' is-current' : '' ?><?= $chainStage['total'] === 0 ? ' is-empty' : '' ?>">
            <span class="doc-chain__label"><?= e($chainStage['label']) ?></span>
            <?php foreach ($chainStage['entries'] as $chainDoc): ?>
                <span class="doc-chain__doc<?= $chainDoc['current'] ? ' is-current' : '' ?>">
                    <?php if ($chainDoc['url'] && !$chainDoc['current']): ?><a class="doc-no" href="<?= e(url($chainDoc['url'])) ?>"><?= e($chainDoc['no']) ?></a><?php else: ?><span class="doc-no"><?= e($chainDoc['no']) ?></span><?php endif; ?>
                    <span class="badge <?= e($chainDoc['badge']) ?>"><?= e($chainDoc['status']) ?></span>
                </span>
            <?php endforeach; ?>
            <?php if ($chainStage['more'] > 0): ?><small class="muted">+<?= (int) $chainStage['more'] ?> more</small><?php endif; ?>
            <?php if ($chainStage['total'] === 0): ?><small class="muted"><?= e($chainStage['empty'] ?? '—') ?></small><?php endif; ?>
        </div>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
