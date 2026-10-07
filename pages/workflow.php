<?php
/**
 * How It Works: every workflow of the system step by step (content in Workflow), for any signed-in user.
 * Steps the user may do are marked "You"; their page links only show for users who may open them.
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('workflow');

$flows    = Workflow::flows();
$rules    = Workflow::rules();
$myRole   = (string) (Auth::user()['role'] ?? '');
$byKey    = array_column($flows, null, 'key');
$pageStyles = ['css/workflow.css'];

require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>How It Works</h1>
        <p class="muted">Every workflow of EXECOM POS, step by step: who does each step and what happens. Steps marked <span class="badge badge--success">You</span> are ones you can do.</p>
    </div>
</div>

<section class="card card--pad wf-map" aria-label="The big picture">
    <h2 class="card__title">The big picture</h2>
    <ol class="wf-lane">
        <?php foreach ([['buy', 'Buy', 'Request, order and receive from suppliers'], ['stock', 'Stock', 'Keep, move, count and price per branch'],
                        ['pos', 'Sell', 'POS sales and customer orders'], ['collect', 'Collect', 'Bills, collections and checks']] as [$k, $label, $sub]): ?>
            <li>
                <a class="wf-lane__item" href="#wf-<?= e($k) ?>">
                    <span class="wf-lane__icon"><?= icon($byKey[$k]['icon']) ?></span>
                    <strong><?= e($label) ?></strong>
                    <small><?= e($sub) ?></small>
                </a>
            </li>
        <?php endforeach; ?>
    </ol>
    <div class="wf-side">
        <a class="wf-lane__item wf-lane__item--alt" href="#wf-service">
            <span class="wf-lane__icon"><?= icon('wrench') ?></span>
            <strong>Service</strong>
            <small>Job orders use parts from stock and are billed like a sale</small>
        </a>
        <a class="wf-lane__item wf-lane__item--alt" href="#wf-orders">
            <span class="wf-lane__icon"><?= icon('file') ?></span>
            <strong>Customer orders</strong>
            <small>Quotation → customer PO → delivery → bill</small>
        </a>
    </div>
    <nav class="wf-jump" aria-label="Workflows">
        <?php foreach ($flows as $f): ?>
            <a class="chip" href="#wf-<?= e($f['key']) ?>"><?= icon($f['icon']) ?> <?= e($f['title']) ?></a>
        <?php endforeach; ?>
        <a class="chip" href="#wf-rules"><?= icon('shield') ?> Rules</a>
        <a class="chip" href="#wf-roles"><?= icon('user') ?> Who does what</a>
    </nav>
</section>

<?php foreach ($flows as $f): ?>
    <section class="card card--pad wf-flow" id="wf-<?= e($f['key']) ?>">
        <header class="wf-flow__head">
            <span class="wf-flow__icon"><?= icon($f['icon']) ?></span>
            <div>
                <h2><?= e($f['title']) ?></h2>
                <p class="muted"><?= e($f['intro']) ?></p>
            </div>
        </header>
        <ol class="wf-steps">
            <?php foreach ($f['steps'] as $i => $s): ?>
                <?php $mine = Workflow::canDo($s['perm']); ?>
                <li class="wf-step<?= $mine ? ' is-mine' : '' ?>">
                    <div class="wf-step__top">
                        <span class="wf-step__no"><?= $i + 1 ?></span>
                        <strong class="wf-step__title"><?= e($s['title']) ?></strong>
                        <?php if ($mine): ?><span class="badge badge--success">You</span><?php endif; ?>
                    </div>
                    <p class="wf-step__text"><?= e($s['text']) ?></p>
                    <div class="wf-step__meta">
                        <span class="wf-who"><?= icon('user') ?> <?= e($s['who']) ?></span>
                        <?php if (!empty($s['status'])): ?><span class="badge badge--info"><?= e($s['status']) ?></span><?php endif; ?>
                    </div>
                    <?php if ($s['url'] !== null && $mine): ?>
                        <a class="wf-step__link" href="<?= e(url($s['url'])) ?>">Open <?= icon('chevron-right') ?></a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php if ($f['notes']): ?>
            <ul class="wf-notes">
                <?php foreach ($f['notes'] as $n): ?><li><?= icon('info') ?> <?= e($n) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<section class="card card--pad" id="wf-rules">
    <h2 class="card__title">Rules everywhere</h2>
    <div class="wf-rules">
        <?php foreach ($rules as [$ic, $title, $text]): ?>
            <div class="wf-rule">
                <span class="wf-rule__icon"><?= icon($ic) ?></span>
                <div><strong><?= e($title) ?></strong><p class="muted"><?= e($text) ?></p></div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="card card--pad" id="wf-roles">
    <h2 class="card__title">Who does what</h2>
    <div class="wf-roles">
        <?php foreach (Workflow::ROLES as $code => [$name, $text]): ?>
            <div class="wf-role<?= $code === $myRole ? ' is-mine' : '' ?>">
                <strong><?= e($name) ?></strong>
                <?php if ($code === $myRole): ?><span class="badge badge--success">Your role</span><?php endif; ?>
                <p class="muted"><?= e($text) ?></p>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="form-hint">These are the default roles. A super administrator can change what each role may do in Settings → Roles.</p>
</section>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
