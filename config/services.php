<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Phase 2 stand-in for the four real gateways (Phase 4). Shared HMAC
    // secret for FakeGateway's own webhook signing scheme — not a real
    // gateway credential.
    'fake_gateway' => [
        'webhook_secret' => env('FAKE_GATEWAY_WEBHOOK_SECRET', 'fake-gateway-webhook-secret'),
    ],

    // Phase 4A — SslCommerzClient. Sandbox credentials are self-service
    // (developer.sslcommerz.com), no merchant onboarding required; the
    // default sandbox demo store is `testbox` / `qwerty1234`. Swap the
    // three URLs to the live hosts only once a real merchant account
    // exists (Phase 4B) — never flip `sandbox` without also swapping the
    // credentials, or a real amount could hit the demo store.
    'sslcommerz' => [
        'store_id' => env('SSLCOMMERZ_STORE_ID', 'testbox'),
        'store_password' => env('SSLCOMMERZ_STORE_PASSWORD'),
        'sandbox' => env('SSLCOMMERZ_SANDBOX', true),
        'base_url' => env('SSLCOMMERZ_BASE_URL', 'https://sandbox-gw.sslcommerz.com'),
        'validation_base_url' => env('SSLCOMMERZ_VALIDATION_BASE_URL', 'https://sandbox.sslcommerz.com'),

        // Source-IP allowlist for the IPN (docs/06 §6.6), on top of
        // signature verification — empty (the default) is a deliberate
        // no-op rather than a guessed range; see EnsureIpnFromAllowlistedIp.
        'ipn_ip_allowlist' => array_filter(array_map('trim', explode(',', (string) env('SSLCOMMERZ_IPN_IP_ALLOWLIST', '')))),
    ],

    // Phase 6 — QrSigner (docs/06 §6.5). `active_private_key` signs new
    // tickets under `active_key_id`; `retired_public_keys` keeps prior
    // keys' public component around so tickets signed before a rotation
    // keep verifying. Store the private key in a secret manager in
    // staging/production, never committed.
    'qr_signing' => [
        'active_key_id' => env('QR_SIGNING_KEY_ID', 'key-1'),
        'active_private_key' => env('QR_SIGNING_PRIVATE_KEY'),
        'retired_public_keys' => json_decode((string) env('QR_SIGNING_PUBLIC_KEYS', '{}'), true) ?: [],
    ],

    // Public site origin (separate Next.js repo). Used only to build the
    // gateway return URL in InitiatePayment — never accepted from a
    // request, since a client-supplied redirect target is an open-redirect
    // risk.
    'frontend' => [
        'url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost')),

        // ISR revalidation hook (Phase 3.5). Left unset until the Next.js
        // repo exposes the route — RevalidateFrontendContent is a no-op
        // without it, so the CMS is fully usable in the meantime.
        'revalidate_url' => env('CONTENT_REVALIDATE_URL'),
        'revalidate_secret' => env('CONTENT_REVALIDATE_SECRET'),
    ],

];
