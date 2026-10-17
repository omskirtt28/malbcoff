</div>
<?php /* Patch 6b: every script is deferred (runs after the page is parsed, in this order). */ ?>
<?php if (($page ?? '') === 'inventory'): ?>
<script src="assets/js/inventory-forward.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/inventory-forward.js') ?>" defer></script>
<?php endif; ?>
<script src="assets/js/safe-submit.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/safe-submit.js') ?>" defer></script>
<script src="assets/js/outbox.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/outbox.js') ?>" defer></script>
<script src="assets/js/app.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/app.js') ?>" defer></script>
<?php if (($page ?? '') === 'branch-transfers'): ?><script src="assets/js/branch-transfers.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/branch-transfers.js') ?>" defer></script><?php endif; ?>
<?php if (($page ?? '') === 'users'): ?><script src="assets/js/users.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/users.js') ?>" defer></script><?php endif; ?>
<?php if (($page ?? '') === 'pos'): ?><script src="assets/js/pos.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/pos.js') ?>" defer></script><?php endif; ?>
<?php if (in_array($page ?? '', ['pos', 'inventory', 'add-item'], true)): ?>
<script src="assets/vendor/zxing-wasm/reader.js" defer></script>
<script src="assets/js/imei-reader.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/imei-reader.js') ?>" defer></script>
<script src="assets/vendor/legacy-scanner/tesseract.min.js" defer></script>
<script src="assets/js/serial-label-reader.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/serial-label-reader.js') ?>" defer></script>
<script src="assets/js/device-scanner.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/device-scanner.js') ?>" defer></script>
<?php endif; ?>
<?php require __DIR__ . '/pwa-register.php'; ?>
</body>
</html>
