<?php
/**
 * "Attachments" card (photos / scanned PDFs) on po-view, co-view and collection-view. Rules: Attachments.
 *
 * @var string $attType   purchase_order | customer_order | collection
 * @var int    $attId     document id
 * @var string $attReturn this page (pages/ relative, e.g. po-view.php?id=5), for the redirect back
 */
declare(strict_types=1);

$attDoc   = Attachments::document($attType, $attId);
$attFiles = Attachments::forDocument($attType, $attId);
$attCanUp = Attachments::canUpload($attType, $attDoc) && count($attFiles) < Attachments::MAX_FILES;
$attSize  = static fn (int $b): string => $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : max(1, (int) round($b / 1024)) . ' KB';
?>
<section class="card card--pad att-card no-print" id="attachments">
    <h2 class="card__title">Attachments <small class="muted">(<?= count($attFiles) ?>)</small></h2>
    <?php if ($attFiles): ?>
        <ul class="att-grid">
            <?php foreach ($attFiles as $attFile): ?>
                <?php $attUrl = url('pages/attachment.php?id=' . (int) $attFile['id']); $attPdf = $attFile['mime'] === 'application/pdf'; ?>
                <li class="att-item" data-attachment="<?= (int) $attFile['id'] ?>">
                    <a class="att-thumb<?= $attPdf ? ' att-thumb--pdf' : '' ?>" href="<?= e($attUrl) ?>" target="_blank" rel="noopener" title="<?= e($attFile['original_name']) ?>">
                        <?php if ($attPdf): ?><?= icon('file') ?><span>PDF</span><?php else: ?><img src="<?= e($attUrl) ?>" alt="<?= e($attFile['label']) ?>" loading="lazy"><?php endif; ?>
                    </a>
                    <div class="att-meta">
                        <strong><?= e($attFile['label']) ?></strong>
                        <small class="muted block" title="<?= e($attFile['original_name']) ?>"><?= e($attFile['original_name']) ?></small>
                        <small class="muted block"><?= e($attSize((int) $attFile['size_bytes'])) ?> · <?= e($attFile['uploaded_by_name']) ?> · <?= e(date('M j, Y', strtotime($attFile['uploaded_at']))) ?></small>
                        <span class="att-actions">
                            <a href="<?= e($attUrl . '&download=1') ?>"><?= icon('download') ?> Download</a>
                            <?php if (Attachments::canDelete($attType, $attDoc, $attFile)): ?>
                                <form method="post" action="<?= e(url('pages/attachments.php')) ?>" data-confirm="Delete this attachment (<?= e($attFile['label']) ?>)?">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $attFile['id'] ?>">
                                    <input type="hidden" name="return" value="<?= e($attReturn) ?>">
                                    <button type="submit" class="link-danger att-delete">Delete</button>
                                </form>
                            <?php endif; ?>
                        </span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="muted pu-empty">No attachments<?= $attCanUp ? '. Optional: add a photo or scanned PDF.' : '.' ?></p>
    <?php endif; ?>
    <?php if ($attCanUp): ?>
        <form class="att-form" method="post" action="<?= e(url('pages/attachments.php')) ?>" enctype="multipart/form-data" id="attForm">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="doc_type" value="<?= e($attType) ?>">
            <input type="hidden" name="doc_id" value="<?= (int) $attId ?>">
            <input type="hidden" name="return" value="<?= e($attReturn) ?>">
            <label class="form-field">
                <span class="form-label">What is it?</span>
                <select class="form-input" name="label" required>
                    <?php foreach (Attachments::labels($attType) as $attLabel): ?><option value="<?= e($attLabel) ?>"><?= e($attLabel) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="form-field">
                <span class="form-label">Photo or PDF (max 5 MB)</span>
                <input class="form-input" type="file" name="file" accept="<?= e(Attachments::ACCEPT) ?>" required>
            </label>
            <button type="submit" class="btn btn--light btn--sm"><?= icon('plus') ?> Attach</button>
        </form>
    <?php endif; ?>
</section>
