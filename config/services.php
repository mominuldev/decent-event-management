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

    // Which gateway a registration's payment intent is opened against when
    // the caller doesn't name one. `sslcommerz` is the only adapter backed
    // by a real implementation — bkash/nagad/rocket still resolve to
    // FakeGateway pending their merchant applications (Phase 4B), so
    // defaulting to one of those would silently take the public checkout
    // off the real money path.
    'payment' => [
        'default_method' => env('PAYMENT_DEFAULT_METHOD', 'sslcommerz'),
    ],

    // Phase 4A — SslCommerzClient. Sandbox credentials are self-service
    // (developer.sslcommerz.com), no merchant onboarding required. The
    // shared demo store is `testbox` / `qwerty` — verified against the live
    // sandbox on 2026-08-14, which is also how the previously-documented
    // `qwerty1234` was found to be wrong ("Store Password credential
    // mismatch"). Swap the URLs to the live hosts only once a real merchant
    // account exists (Phase 4B) — never flip `sandbox` without also
    // swapping the credentials, or a real amount could hit the demo store.
    //
    // Both `sandbox.sslcommerz.com` and the older `sandbox-gw.sslcommerz.com`
    // answer the session endpoint; the former is what the v4 docs publish
    // and returns the current EasyCheckOut URL, so it is the default for
    // session, validation and refund alike.
    'sslcommerz' => [
        'store_id' => env('SSLCOMMERZ_STORE_ID', 'testbox'),
        'store_password' => env('SSLCOMMERZ_STORE_PASSWORD', 'qwerty'),
        'sandbox' => env('SSLCOMMERZ_SANDBOX', true),
        'base_url' => env('SSLCOMMERZ_BASE_URL', 'https://sandbox.sslcommerz.com'),
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

        // key_id => base64 private key, for holding the incoming key
        // alongside the current one during a rotation. Adding a key here is
        // deliberately harmless on its own — it becomes the signing key only
        // when a Super Admin activates it, and only once every scanner
        // device has confirmed it holds the matching public key.
        'private_keys' => json_decode((string) env('QR_SIGNING_PRIVATE_KEYS', '{}'), true) ?: [],
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

    // REVE Systems SMS gateway (smpp.revesms.com) — the Bangladesh SMS
    // vendor. Credentials are per-account and issued by REVE, so this stays
    // on FakeSmsDriver until `api_key`/`secret_key` are set; see
    // NotificationChannelResolver.
    //
    // ⚠️ **The host is per-account, and getting it wrong is silent.** REVE
    // licenses its platform to resellers who each run their own instance —
    // `smpp.revesms.com`, `smpp.ajuratech.com`, the `smsvaults.work`
    // white-labels, and others. Credentials are only valid on the instance
    // that issued them, and pointing at the wrong one answers **HTTP 200
    // with a completely empty body** rather than an auth error, which reads
    // as a parser fault instead of a misconfiguration. Set this to whatever
    // host you log in to. There is no useful default, so the one here is
    // only a placeholder.
    //
    // Every instance speaks the same `/sendtext`, `/getstatus`,
    // `/getmultistatus`, `/send` API on the same ports, so moving between
    // them is a URL change and nothing more:
    //
    //   https://<host>:7790   TLS
    //   http://<host>:7788    cleartext — never in production, the message
    //                         body and both keys travel in the clear
    //   http://api<host>      the cPanel-style host, no port
    //
    // Note the bare origin (`https://<host>` with no port) is the *billing
    // portal*, not the API — it answers 302 to a login page.
    //
    // `sender_id` is REVE's `callerID` — the approved masking/sender name on
    // the account. An unapproved one is rejected by the gateway, not
    // silently replaced, so this has no safe default.
    'revesms' => [
        'base_url' => env('REVESMS_BASE_URL', 'https://smpp.ajuratech.com:7790'),
        'api_key' => env('REVESMS_API_KEY'),
        'secret_key' => env('REVESMS_SECRET_KEY'),
        'sender_id' => env('REVESMS_SENDER_ID'),

        // How the two keys are carried: `body` (in the request body/query),
        // `path` (`/sendtext/{apikey}/{secretkey}`), or `basic` (HTTP Basic,
        // username=apikey password=secretkey). All three are in the vendor's
        // Postman collection and all three were confirmed working against a
        // live deployment, so the account decides rather than this code.
        'auth_style' => env('REVESMS_AUTH_STYLE', 'body'),

        // `post` sends JSON, `form` sends x-www-form-urlencoded, `get` puts
        // everything in the query string. POST is the default deliberately:
        // a GET puts the message body — a real person's name, a ticket
        // number — into every access log and proxy between here and the
        // gateway. Reach for `form` if something in front of the gateway
        // will not forward a JSON body.
        'method' => env('REVESMS_METHOD', 'post'),

        'timeout' => (int) env('REVESMS_TIMEOUT', 15),

        // What one segment costs, for the delivery-cost report. REVE bills
        // per segment against a prepaid balance and does not return a price
        // on the send response, so this is a local figure that has to match
        // the contracted rate — it is reporting, not billing.
        'cost_paisa_per_segment' => (int) env('REVESMS_COST_PAISA_PER_SEGMENT', 50),

        // Client id for the balance page (`smsClientBalance.jsp?client=...`).
        // Separate from `api_key`; REVE issues it with the account.
        'client_id' => env('REVESMS_CLIENT_ID'),

        // Shared secret for the DLR push REVE makes *to us* at
        // `POST /webhooks/sms/dlr`. The vendor authenticates that callback
        // with the same apikey/secretkey pair, so these default to the
        // sending credentials; override only if REVE issues a distinct pair
        // for the callback direction.
        'dlr_api_key' => env('REVESMS_DLR_API_KEY'),
        'dlr_secret_key' => env('REVESMS_DLR_SECRET_KEY'),

        // Source-IP allowlist for that callback, on top of the key check.
        // Named to match `EnsureIpnFromAllowlistedIp`'s convention
        // (`services.{gateway}.ipn_ip_allowlist`) so the same middleware
        // serves both this and the payment IPNs; same deliberate
        // no-op-when-empty, for the same reason — a guessed range silently
        // drops every real receipt.
        'ipn_ip_allowlist' => array_filter(array_map('trim', explode(',', (string) env('REVESMS_DLR_IP_ALLOWLIST', '')))),
    ],

];
