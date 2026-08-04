<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Source-IP allowlist for a gateway IPN (docs/06 §6.6: "source-IP
 * allowlist where the gateway publishes ranges"). Reads
 * `services.{gateway}.ipn_ip_allowlist` — a comma-separated env value —
 * and is a deliberate no-op when that list is empty, rather than
 * hardcoding a guessed range: an incorrect allowlist silently drops real
 * money notifications, which is worse than skipping this layer until
 * someone pastes SSLCommerz's actual published IPN ranges into `.env`.
 * Signature verification in the gateway adapter is the primary defense
 * either way — this is defense-in-depth on top of it, not instead of it.
 *
 * Route usage: `->middleware('ipn.allowlist:sslcommerz')`.
 */
class EnsureIpnFromAllowlistedIp
{
    public function handle(Request $request, Closure $next, string $gateway): Response
    {
        $allowlist = config("services.{$gateway}.ipn_ip_allowlist", []);

        if (! is_array($allowlist) || $allowlist === []) {
            return $next($request);
        }

        if (! in_array($request->ip(), $allowlist, true)) {
            return response()->json([
                'code' => 'ipn_source_not_allowlisted',
                'message' => 'This IP address is not allowlisted for this gateway\'s IPN.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
