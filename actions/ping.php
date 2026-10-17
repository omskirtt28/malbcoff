<?php
// Tiny, public, no-session heartbeat for the connection banner (assets/js/safe-submit.js). Never touches the DB.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
echo '{"ok":true}';
