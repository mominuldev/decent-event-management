<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The header set from docs/06 §6.7 "Headers", applied globally rather than
 * per-route: `Strict-Transport-Security`, `Content-Security-Policy` (strict,
 * nonce-based, no `unsafe-inline`), `X-Content-Type-Options`,
 * `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`.
 *
 * The nonce plumbing already existed one layer down — `resources/views/app.blade.php`
 * has called `Vite::cspNonce()` on its inline theme-flash script since Phase 3 —
 * but nothing ever called `Vite::useCspNonce()` to generate one, so that call
 * was rendering an empty `nonce=""` attribute with no CSP header to make it
 * mean anything. This middleware generates the nonce *before* `$next()` so the
 * same value reaches both the blade view (via the Vite facade, including
 * every `@vite` tag) and the `script-src` directive below.
 *
 * `style-src` keeps `'unsafe-inline'` — a deliberate, narrower exception to
 * "no unsafe-inline", not a silent drop of it. React/Radix-style component
 * libraries set inline `style="..."` attributes at runtime; CSP nonces don't
 * apply to style attributes (only to `<style>` elements) in any shipping
 * browser, so enforcing strict style-src here would need a `style-src-attr`
 * hash allowlist regenerated on every component render, which is not
 * practical. `script-src` — the control that actually stops XSS — stays
 * nonce-only with no `unsafe-inline`.
 *
 * Suppressed entirely in `local`: Vite's dev-server CSS/JS HMR injects
 * `<style>`/`<script>` tags at runtime with no nonce support, so a strict CSP
 * only breaks local development without protecting anything (no third party
 * ever reaches a local dev box). Never suppressed in `testing`, `staging`, or
 * `production` — matching this codebase's one other environment-gated
 * exception, `AuthController`'s local-only 2FA bypass.
 *
 * `Permissions-Policy` denies `camera` outright rather than restricting it to
 * "the scanner origin" as docs/06 literally says: the scanner app is a
 * separate React Native (Expo) build, not a web origin this Laravel app ever
 * serves (see CLAUDE.md "Three frontends, two repos"), so there is no camera
 * use to permit here at all.
 */
class SetSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $suppressCsp = app()->environment('local');

        $nonce = app(Vite::class)->useCspNonce();

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains; preload');

        if (! $suppressCsp) {
            $response->headers->set('Content-Security-Policy', $this->csp($nonce));
        }

        return $response;
    }

    private function csp(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
