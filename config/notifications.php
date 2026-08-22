<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Language
    |--------------------------------------------------------------------------
    |
    | Which language a queued notification is written in. This picks the
    | `notification_templates` row *and* the language the email shell renders
    | its own chrome in (`lang/{locale}/emails.php`) — an email whose body is
    | Bangla and whose gate details are English is the failure this avoids.
    |
    | Bangla is the default because that is the language the audience reads.
    |
    | A channel may override it. Note before setting `sms` to Bangla or back:
    | GSM-7 fits 160 characters per segment and Unicode only 70, so Bangla SMS
    | costs roughly two to three times as many segments for the same message
    | (see `Notification\Support\SmsSegmentCalculator`, which already counts
    | it correctly). That is a billing decision, not a typographic one.
    |
    */

    'locales' => [
        'default' => env('NOTIFICATION_LOCALE', 'bn'),

        // SMS is English by explicit decision (2026-08-22), and it is a
        // billing decision as much as a language one: GSM-7 fits 160
        // characters per segment, and a *single* Bangla character forces the
        // whole message to Unicode at 70. The same sign-in message measures
        // 1 segment in English against 3 in Bangla — three times the bill
        // for every send. Email and WhatsApp are unaffected and stay Bangla.
        'sms' => env('NOTIFICATION_SMS_LOCALE', 'en'),

        // 'whatsapp' => 'en',
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback language
    |--------------------------------------------------------------------------
    |
    | `QueueNotification` writes no outbox row at all when it cannot find a
    | template, so a single missing translation would silently stop a whole
    | class of notification. If the requested locale has no row for a
    | (key, channel) pair, this one is tried before giving up.
    |
    */

    'fallback_locale' => env('NOTIFICATION_FALLBACK_LOCALE', 'en'),

];
