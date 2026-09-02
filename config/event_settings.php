<?php

/**
 * The catalogue of every setting the admin console can show and save.
 *
 * **This file is the definition, not `EventSettingSeeder`.** The Settings
 * screen renders this catalogue whether or not a matching `event_settings`
 * row exists, and saving one is what creates the row. That ordering is the
 * point: before it, a setting only appeared once somebody had remembered to
 * re-run the seeder on that environment, so a key added in a release was
 * invisible on production — the deploy runs migrations and no seeders — and
 * there was no way to add it from the console either, because
 * `PATCH /admin/settings/{key}` answered 404 for a row that did not exist.
 *
 * The database stores the *value*; this file owns everything else. A stored
 * row's `label`, `description`, `type`, `group` and visibility are refreshed
 * from here on read, so improving the wording of a description reaches every
 * environment on deploy rather than on the next seed.
 *
 * **`default` is what a not-yet-saved setting reads as.** A `datetime`
 * default may be written relative (`'+6 months'`, `'+5 months +1 week'`) —
 * `EventSetting::castForStorage()` already runs it through `Carbon::parse`,
 * so it resolves when it is used rather than when this file is loaded. That
 * matters because `config:cache` freezes this array: a `now()->addMonths(6)`
 * here would have been frozen to whatever the date was when the cache was
 * built, silently, on every deploy.
 *
 * The `description` on each row is not decoration — it is the only
 * explanation an admin gets on the Settings screen for what a key actually
 * controls, so it says what changing it *does*, not what it is named. Keep
 * it under 255 characters and the label under 120: those are the column
 * widths, and an over-long description fails the save with a raw SQL error
 * (it has broken `db:seed` on a fresh deployment before now).
 *
 * Read by App\Domain\Shared\Support\EventSettingCatalogue.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Event
    |--------------------------------------------------------------------------
    */

    'event.name' => [
        'group' => 'event',
        'type' => 'string',
        'is_public' => true,
        'label' => 'Event name',
        'description' => 'Shown on the public site, in every email and SMS, and printed on each ticket.',
        'default' => 'নামোশংকরবাটী উচ্চ বিদ্যালয় শতবর্ষ উদযাপন',
    ],

    'event.date' => [
        'group' => 'event',
        'type' => 'datetime',
        'is_public' => true,
        'label' => 'Event date',
        'description' => 'The headline date the public sees. Gate sessions have their own times — see Check-in.',
        'default' => '+6 months 00:00',
    ],

    'event.venue' => [
        'group' => 'event',
        'type' => 'string',
        'is_public' => true,
        'label' => 'Event venue',
        'description' => 'Venue name as it appears on the public site and on printed tickets.',
        'default' => 'নামোশংকরবাটী উচ্চ বিদ্যালয় মাঠ প্রাঙ্গণ',
    ],

    // English equivalents of the two above, used by the SMS channel. Not a
    // duplicate: `event.name`/`event.venue` hold Bangla, SMS is English by
    // decision (config/notifications.php), and mixing one Bangla word into an
    // English SMS drops the whole message from 160 characters per segment to
    // 70 — it would triple the bill for the event's name alone.
    'event.name_en' => [
        'group' => 'event',
        'type' => 'string',
        'is_public' => true,
        'label' => 'Event name (English)',
        'description' => 'Used in English SMS. Keep it short: 160 characters fit in one segment and the ticket SMS is close to that line, so a longer name here doubles the cost of every ticket confirmation.',
        'default' => 'NHS Centennial',
    ],

    'event.venue_en' => [
        'group' => 'event',
        'type' => 'string',
        'is_public' => true,
        'label' => 'Event venue (English)',
        'description' => 'Used in English SMS when the ticket has no session venue of its own. Keep it short, for the same reason as the English event name.',
        'default' => 'School Campus',
    ],

    'event.venue_address' => [
        'group' => 'event',
        'type' => 'string',
        'is_public' => true,
        'label' => 'Venue address',
        'description' => 'The line under the venue name in ticket emails — city or road, not the full postal address.',
        'default' => '',
    ],

    'event.tagline' => [
        'group' => 'event',
        'type' => 'string',
        'is_public' => true,
        'label' => 'Event tagline',
        'description' => 'One short line, shown in the footer of every email.',
        'default' => 'একসাথে শতবর্ষ উদযাপন',
    ],

    // Defaulted empty on purpose: a "contact us at" line with nothing after it
    // is worse than no line, so the email omits the whole help block until
    // somebody fills these in.
    'event.support_email' => [
        'group' => 'event',
        'type' => 'string',
        'is_public' => true,
        'label' => 'Support email',
        'description' => 'Where a ticket-holder writes for help. Shown in every email once set.',
        'default' => '',
    ],

    'event.support_phone' => [
        'group' => 'event',
        'type' => 'string',
        'is_public' => true,
        'label' => 'Support phone',
        'description' => 'The number a ticket-holder may call for help. Shown in every email once set.',
        'default' => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    */

    'registration.opens_at' => [
        'group' => 'registration',
        'type' => 'datetime',
        'is_public' => true,
        'label' => 'Registration opens',
        'description' => 'Before this moment the public registration form is closed.',
        'default' => 'now',
    ],

    'registration.closes_at' => [
        'group' => 'registration',
        'type' => 'datetime',
        'is_public' => true,
        'label' => 'Registration closes',
        'description' => 'No new registration can be started after this. Checkouts already in progress still finish.',
        'default' => '+5 months',
    ],

    'registration.edit_cutoff_at' => [
        'group' => 'registration',
        'type' => 'datetime',
        'is_public' => true,
        'label' => 'Attendee edit cutoff',
        'description' => 'Last moment an attendee may correct their own details. Set it before badge printing starts.',
        'default' => '+5 months +1 week',
    ],

    'registration.max_family_size' => [
        'group' => 'registration',
        'type' => 'int',
        'is_public' => true,
        'label' => 'Max family size',
        'description' => 'Largest party one registration may hold, registrant included. A ticket type may cap it lower.',
        'default' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Payments
    |--------------------------------------------------------------------------
    */

    'payment.intent_ttl_minutes' => [
        'group' => 'payment',
        'type' => 'int',
        'is_public' => false,
        'label' => 'Payment intent TTL (minutes)',
        'description' => 'How long an unpaid checkout keeps holding its seats before they are released back to the pool.',
        'default' => 30,
    ],

    'payment.manual_verification_enabled' => [
        'group' => 'payment',
        'type' => 'bool',
        'is_public' => false,
        'label' => 'Manual payment verification enabled',
        'description' => 'Allows staff to approve a payment sent to a personal wallet. Turn off to accept gateway payments only.',
        'default' => true,
    ],

    'payment.refund_cutoff_at' => [
        'group' => 'payment',
        'type' => 'datetime',
        'is_public' => true,
        'label' => 'Refund cutoff',
        'description' => 'After this, refunds can no longer be requested. Staff-issued refunds are unaffected.',
        'default' => '+5 months',
    ],

    /*
    |--------------------------------------------------------------------------
    | Check-in
    |--------------------------------------------------------------------------
    */

    'checkin.window_start' => [
        'group' => 'checkin',
        'type' => 'datetime',
        'is_public' => false,
        'label' => 'Check-in window start',
        'description' => 'Scanners refuse admission before this. Open it early enough for volunteers to test their devices.',
        'default' => '+6 months 06:00',
    ],

    'checkin.window_end' => [
        'group' => 'checkin',
        'type' => 'datetime',
        'is_public' => false,
        'label' => 'Check-in window end',
        'description' => 'Scanners stop admitting after this.',
        'default' => '+6 months 20:00',
    ],

    'checkin.allow_manual_override' => [
        'group' => 'checkin',
        'type' => 'bool',
        'is_public' => false,
        'label' => 'Allow manual override at gate',
        'description' => 'Lets an Event Manager admit someone whose QR will not scan. Every override is recorded in the activity log.',
        'default' => true,
    ],

    'qr.active_signing_key_id' => [
        'group' => 'checkin',
        'type' => 'string',
        'is_public' => false,
        'label' => 'Active QR signing key',
        'description' => 'Which key new tickets are signed with. Only change this after every scanner device has synced the new key.',
        'default' => 'key-1',
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS gateway (REVE Systems)
    |--------------------------------------------------------------------------
    |
    | The two credentials are the only `is_encrypted` rows in this table —
    | stored encrypted under APP_KEY and never returned to any client, which
    | is what makes it safe for them to live here rather than only in .env
    | (CLAUDE.md's rule is against an *unencrypted* settings row).
    |
    | `auth_style` and `method` are deliberately absent: they describe how the
    | account was provisioned, not something an operator tunes, and a wrong
    | value takes SMS off the air entirely. See SmsGatewayConfig::KEY_MAP.
    |
    */

    'sms.api_key' => [
        'group' => 'sms',
        'type' => 'string',
        'is_encrypted' => true,
        'is_public' => false,
        'label' => 'SMS API key',
        'description' => 'REVE account apikey. Stored encrypted and never shown again — saving a new one replaces it. Leave every SMS field empty to keep using the values in .env.',
        'default' => null,
    ],

    'sms.secret_key' => [
        'group' => 'sms',
        'type' => 'string',
        'is_encrypted' => true,
        'is_public' => false,
        'label' => 'SMS secret key',
        'description' => 'REVE account secretkey. Stored encrypted and never shown again. Clear the field and save to remove it.',
        'default' => null,
    ],

    'sms.masking_enabled' => [
        'group' => 'sms',
        'type' => 'bool',
        'is_public' => false,
        'label' => 'Use masking (branded sender name)',
        'description' => 'Off: messages send from a number (non-masking) — this is what an account can do without operator approval. On: they send from an approved brand name. This only decides what shape the sender ID must be; a sender ID is required either way.',
        'default' => false,
    ],

    'sms.sender_id' => [
        'group' => 'sms',
        'type' => 'string',
        'is_public' => false,
        'label' => 'SMS sender ID (callerID)',
        'description' => 'With masking off, the number messages send from — digits only, e.g. 8809612. With masking on, the brand name your operator approved, up to 11 characters. Required either way: the gateway refuses a send with no callerID.',
        'default' => null,
    ],

    'sms.base_url' => [
        'group' => 'sms',
        'type' => 'string',
        'is_public' => false,
        'label' => 'SMS gateway URL',
        'description' => 'Your REVE instance and its API port, e.g. https://smpp.ajuratech.com:7790. Keys only work on the instance that issued them; the wrong host returns an empty response, not an error. The address without a port is the billing portal, not the API.',
        'default' => 'https://smpp.ajuratech.com:7790',
    ],

    'sms.client_id' => [
        'group' => 'sms',
        'type' => 'string',
        'is_public' => false,
        'label' => 'SMS client ID',
        'description' => 'REVE issues this alongside the account. Only used by their balance page; sending works without it.',
        'default' => null,
    ],

    'sms.cost_paisa_per_segment' => [
        'group' => 'sms',
        'type' => 'money',
        'is_public' => false,
        'label' => 'SMS cost per segment',
        'description' => 'What one segment costs under your REVE contract. This is what the delivery-cost report multiplies — REVE returns no price on a send, so a wrong figure here makes every cost figure wrong.',
        'default' => 50,
    ],

    'sms.low_balance_threshold_paisa' => [
        'group' => 'sms',
        'type' => 'money',
        'is_public' => false,
        'label' => 'Low balance warning at',
        'description' => 'The settings screen warns below this. Set it above what a full send costs, or the first warning arrives after messages have already failed.',
        'default' => 200000,
    ],

    'sms.recharge_url' => [
        'group' => 'sms',
        'type' => 'string',
        'is_public' => false,
        'label' => 'Recharge portal URL',
        'description' => 'Where the Recharge button opens — your billing portal login, e.g. https://smpp.ajuratech.com. REVE has no top-up API, so recharging happens there and the new balance shows here afterwards.',
        'default' => 'https://smpp.ajuratech.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification kill switches
    |--------------------------------------------------------------------------
    */

    'notification.email_enabled' => [
        'group' => 'notification',
        'type' => 'bool',
        'is_public' => false,
        'label' => 'Email channel enabled',
        'description' => 'Kill switch. Turning this off also cancels emails already queued, not just new ones.',
        'default' => true,
    ],

    'notification.sms_enabled' => [
        'group' => 'notification',
        'type' => 'bool',
        'is_public' => false,
        'label' => 'SMS channel enabled',
        'description' => 'Kill switch. SMS is the costliest channel — turn it off first if a send goes wrong.',
        'default' => true,
    ],

    'notification.whatsapp_enabled' => [
        'group' => 'notification',
        'type' => 'bool',
        'is_public' => false,
        'label' => 'WhatsApp channel enabled',
        'description' => 'Kill switch. Turning this off also cancels messages already queued.',
        'default' => true,
    ],

];
