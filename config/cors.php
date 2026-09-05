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

    /*
     * Local development only, and deliberately not in `allowed_origins`.
     *
     * `next dev` binds 3000 when it can and silently moves to 3001, 3002 …
     * when something already holds it — a stray dev server from an earlier
     * session is enough. The frontend reads `/public/ticket-types` straight
     * from the browser, so a refused preflight does not degrade a corner of
     * the page: the ticket form disappears entirely and is replaced by a
     * panel, with the real cause visible only in the browser console. That
     * has cost real debugging time more than once.
     *
     * Read through `env()` rather than `app()->environment()`: config files
     * are evaluated before the application knows its own environment, and
     * asking too early answered false here — which silently dropped *every*
     * origin, port 3000 included.
     *
     * Gated on `local` because a pattern this wide is a genuine hole
     * anywhere else: any page on any localhost port could call the API. In
     * production only `FRONTEND_URL` is trusted, exactly as before.
     */
    'allowed_origins_patterns' => array_values(array_filter([
        env('APP_ENV') === 'local' ? '#^http://(localhost|127\.0\.0\.1):\d+$#' : null,
    ])),

    'allowed_headers' => ['Content-Type', 'Accept', 'Accept-Language', 'Idempotency-Key'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
