<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Only the public, unauthenticated surface is ever called directly from
    | a browser: public content/ticket-type GETs, registration create, and
    | payment-initiate (see the frontend's docs/01-frontend-architecture.md
    | §5). The attendee portal never makes a cross-origin request itself —
    | it proxies through its own BFF route handlers — so no cookie/credential
    | support is needed here (Gap G2).
    |
    | `FRONTEND_URL` already exists as an env var for the SSLCommerz return
    | redirect (config/services.php) — reused here so there's one source of
    | truth for "what origin is the frontend." Falls back to just the local
    | dev origin when unset, e.g. in review/CI environments with no frontend
    | deployed yet. Production value: https://100.nsbatihighschool.edu.bd — set
    | via FRONTEND_URL in the production env, not hardcoded here.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter([
        'http://localhost:3000',
        env('FRONTEND_URL'),
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept', 'Accept-Language', 'Idempotency-Key'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
