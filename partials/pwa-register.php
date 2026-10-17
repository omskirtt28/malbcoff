<?php
// Patch 6b: registers (or, when config 'enable_service_worker' is false, removes) the static-files service worker.
// Included just before </body> on every page, including login.
$swEnabled = !empty($app['enable_service_worker']);
?>
<script src="assets/js/sw-register.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/sw-register.js') ?>" data-enabled="<?= $swEnabled ? '1' : '0' ?>" data-version="<?= e((string)($app['version'] ?? '1.0.0')) ?>" defer></script>
