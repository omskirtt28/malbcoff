<?php
// Copy to config/app.local.php on the live server. Keep app.local.php private.
return [
    'environment' => 'production',
    'version' => '1.0.0',
    'debug' => false,
    'hsts_enabled' => false, // Enable only after HTTPS is verified.
    'hsts_include_subdomains' => false,
    'trust_cloudflare_proxy' => false, // change to true only after the domain is proxied through Cloudflare
    'maintenance_mode' => false,
    'enable_service_worker' => true, // Set to false to switch the service worker off instantly (see vault: How to turn off the service worker).
    'login_debug' => false, // true = log the login steps (never passwords) to the PHP error log and show a small "Login debug" box on the login page. Turn off again after testing.
    'require_security_schema' => true,
    'audit_retention_days' => 180,
];
