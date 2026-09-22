<?php

// Which upstream proxies may set X-Forwarded-For / -Proto / -Port (the
// headers bootstrap/app.php trusts; X-Forwarded-Host is never one of them).
//
//  - TRUSTED_PROXIES=*            trust the calling IP (behind a single
//                                 container ingress: Railway, Vercel).
//  - TRUSTED_PROXIES=a.b.c.d,...  trust exactly those addresses.
//  - unset                        trust nothing — the local XAMPP default,
//                                 where every LAN client reaches Apache
//                                 directly and a forwarded header is just
//                                 client input.
//
// Railway injects RAILWAY_ENVIRONMENT_NAME / RAILWAY_PUBLIC_DOMAIN into every
// service and terminates TLS at its edge, so a Railway service with no
// explicit TRUSTED_PROXIES would otherwise see every request as plain HTTP:
// asset() and route() would emit http:// URLs on an https:// page (blocked as
// mixed content — an unstyled login page), and SESSION_SECURE_COOKIE would
// refuse to set the session cookie at all. The same platform inference the
// framework applies to Forge/Vapor/Cloud hosts, made explicit here. An
// explicit TRUSTED_PROXIES value always wins over the inference.
$explicit = env('TRUSTED_PROXIES');
$onRailway = trim((string) env('RAILWAY_ENVIRONMENT_NAME')) !== ''
    || trim((string) env('RAILWAY_PUBLIC_DOMAIN')) !== '';

return [
    'proxies' => match (true) {
        $explicit === '*' => '*',
        $explicit !== null && $explicit !== '' => array_values(array_filter(array_map('trim', explode(',', $explicit)))),
        $onRailway => '*',
        default => [],
    },
];
