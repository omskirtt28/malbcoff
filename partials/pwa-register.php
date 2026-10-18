<?php
// Patch 6b: registers (or, when config 'enable_service_worker' is false, removes) the static-files service worker.
// Included just before </body> on every page, including login.
// Auth pages (login, change-password, maintenance) set $pwaAuthPage = true: they only ask an old worker to update, never register.
$swEnabled = !empty($app['enable_service_worker']);
?>
<script src="assets/js/sw-register.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/sw-register.js') ?>" data-enabled="<?= $swEnabled ? '1' : '0' ?>" data-mode="<?= !empty($pwaAuthPage) ? 'auth' : 'full' ?>" data-version="<?= e((string)($app['version'] ?? '1.0.0')) ?>" defer></script>
