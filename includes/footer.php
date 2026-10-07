<?php
/**
 * Page layout — bottom.
 * Optional: $pageScripts = ['js/pos.js'] for page-specific JS.
 */
?>
    </main>
</div>
<?php require ROOT_PATH . '/includes/bottom-nav.php'; ?>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<script src="<?= e(asset('js/notifications.js')) ?>" defer></script>
<?php foreach ($pageScripts ?? [] as $script): ?>
    <script src="<?= e(asset($script)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
