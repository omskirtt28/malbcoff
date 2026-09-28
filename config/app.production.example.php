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
    'require_security_schema' => true,
    'audit_retention_days' => 180,
];
