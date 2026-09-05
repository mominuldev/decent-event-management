<?php

/*
|--------------------------------------------------------------------------
| Which origins are allowed
|--------------------------------------------------------------------------
|
| One source of truth, and it is env: `FRONTEND_URL` (plus `FRONTEND_URLS`
| for any extra origins — a www/apex pair, a staging preview). Nothing is
| hardcoded here any more, so deploying never overwrites a hand-edit and no
| file has to be touched again to point the API at the live site: set the
| value in the host's .env once.
|
| Local development is NOT in this list. `http://localhost:3000` used to be,
| unconditionally — which meant production trusted it too, for no benefit —
| and it is redundant even locally, because `allowed_origins_patterns` below
| already matches every localhost port when APP_ENV=local.
|
| Every value is reduced to a bare origin (scheme://host[:port]). A browser's
| `Origin` header never carries a path or a trailing slash, so
| `FRONTEND_URL=https://example.com/` — the shape a copy-paste out of a
| browser bar produces — would otherwise sit in this list matching nothing,
| and the only symptom is a refused preflight visible in the browser console.
| Normalising here means the same env var can stay a full URL for the
| SSLCommerz return legs and the ticket email CTA, which want one.
*/
$normaliseOrigin = static function (string $url): ?string {
    $url = trim($url);

    if ($url === '') {
        return null;
    }

    $parts = parse_url($url);

    if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
        return null;
    }

    return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
};

$frontendOrigins = array_values(array_unique(array_filter(array_map(
    $normaliseOrigin,
    array_merge(
        [(string) env('FRONTEND_URL', '')],
        explode(',', (string) env('FRONTEND_URLS', '')),
    ),
))));

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
    | truth for "what origin is the frontend". `FRONTEND_URLS` is the
    | comma-separated overflow for the cases where one origin is not enough.
    | Production value: FRONTEND_URL=https://100.nsbatihighschool.edu.bd in
    | the host's .env, never hardcoded here.
    |
    | Unset means no cross-origin caller is allowed at all — correct for a
    | review or CI environment with no frontend deployed, and loud rather
    | than quiet if it is ever wrong in production.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],

    'allowed_origins' => $frontendOrigins,

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
