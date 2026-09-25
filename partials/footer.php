</div>
<?php if (($page ?? '') === 'inventory'): ?>
<script src="assets/js/inventory-forward.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/inventory-forward.js') ?>"></script>
<?php endif; ?>
<script src="assets/js/app.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
<?php if (($page ?? '') === 'branch-transfers'): ?><script src="assets/js/branch-transfers.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/branch-transfers.js') ?>"></script><?php endif; ?>
<?php if (($page ?? '') === 'pos'): ?><script src="assets/js/pos.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/pos.js') ?>"></script><?php endif; ?>
<?php if (in_array($page ?? '', ['pos', 'inventory', 'add-item'], true)): ?>
<script src="assets/vendor/zxing-wasm/reader.js"></script>
<script src="assets/js/imei-reader.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/imei-reader.js') ?>"></script>
<script src="assets/vendor/legacy-scanner/tesseract.min.js"></script>
<script src="assets/js/serial-label-reader.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/serial-label-reader.js') ?>"></script>
<script src="assets/js/device-scanner.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/device-scanner.js') ?>"></script>
<?php endif; ?>
</body>
</html>
