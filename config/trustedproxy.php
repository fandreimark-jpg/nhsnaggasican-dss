<?php

return [
    // Set * only behind Vercel's ingress. Local XAMPP trusts no proxy by default.
    'proxies' => env('TRUSTED_PROXIES') === '*'
        ? '*'
        : array_filter(explode(',', env('TRUSTED_PROXIES', ''))),
];
