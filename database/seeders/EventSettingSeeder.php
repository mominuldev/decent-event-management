<?php

namespace Database\Seeders;

use App\Domain\Shared\Models\EventSetting;
use Illuminate\Database\Seeder;

/**
 * Representative keys from docs/03 §3.23. Real values are entered by the
 * Super Admin during Phase 1 sign-off (docs/08 §9.4); these are sane
 * local-dev defaults so the app is usable out of the box.
 *
 * The `description` on each row is not decoration — it is the only
 * explanation an admin gets on the Settings screen for what a key actually
 * controls, so it says what changing it *does*, not what it is named.
 */
class EventSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['key' => 'event.name', 'group' => 'event', 'value' => 'নামোশংকরবাটী উচ্চ বিদ্যালয় শতবর্ষ উদযাপন', 'type' => 'string', 'is_public' => true, 'label' => 'Event name', 'description' => 'Shown on the public site, in every email and SMS, and printed on each ticket.'],
            ['key' => 'event.date', 'group' => 'event', 'value' => now()->addMonths(6)->toDateString(), 'type' => 'datetime', 'is_public' => true, 'label' => 'Event date', 'description' => 'The headline date the public sees. Gate sessions have their own times — see Check-in.'],
            ['key' => 'event.venue', 'group' => 'event', 'value' => 'নামোশংকরবাটী উচ্চ বিদ্যালয় মাঠ প্রাঙ্গণ', 'type' => 'string', 'is_public' => true, 'label' => 'Event venue', 'description' => 'Venue name as it appears on the public site and on printed tickets.'],
            // English equivalents of the two above, used by the SMS channel.
            // Not a duplicate: `event.name`/`event.venue` hold Bangla, SMS is
            // English by decision (config/notifications.php), and mixing one
            // Bangla word into an English SMS drops the whole message from
            // 160 characters per segment to 70 — it would triple the bill for
            // the event's name alone.
            ['key' => 'event.name_en', 'group' => 'event', 'value' => 'NHS Centennial', 'type' => 'string', 'is_public' => true, 'label' => 'Event name (English)', 'description' => 'Used in English SMS. Keep it short: 160 characters fit in one segment and the ticket SMS is close to that line, so a longer name here doubles the cost of every ticket confirmation.'],
            ['key' => 'event.venue_en', 'group' => 'event', 'value' => 'School Campus', 'type' => 'string', 'is_public' => true, 'label' => 'Event venue (English)', 'description' => 'Used in English SMS when the ticket has no session venue of its own. Keep it short, for the same reason as the English event name.'],
            ['key' => 'event.venue_address', 'group' => 'event', 'value' => '', 'type' => 'string', 'is_public' => true, 'label' => 'Venue address', 'description' => 'The line under the venue name in ticket emails — city or road, not the full postal address.'],
            ['key' => 'event.tagline', 'group' => 'event', 'value' => 'একসাথে শতবর্ষ উদযাপন', 'type' => 'string', 'is_public' => true, 'label' => 'Event tagline', 'description' => 'One short line, shown in the footer of every email.'],
            // Seeded empty on purpose: a "contact us at" line with nothing
            // after it is worse than no line, so the email omits the whole
            // help block until somebody fills these in.
            ['key' => 'event.support_email', 'group' => 'event', 'value' => '', 'type' => 'string', 'is_public' => true, 'label' => 'Support email', 'description' => 'Where a ticket-holder writes for help. Shown in every email once set.'],
            ['key' => 'event.support_phone', 'group' => 'event', 'value' => '', 'type' => 'string', 'is_public' => true, 'label' => 'Support phone', 'description' => 'The number a ticket-holder may call for help. Shown in every email once set.'],

            ['key' => 'registration.opens_at', 'group' => 'registration', 'value' => now()->toDateTimeString(), 'type' => 'datetime', 'is_public' => true, 'label' => 'Registration opens', 'description' => 'Before this moment the public registration form is closed.'],
            ['key' => 'registration.closes_at', 'group' => 'registration', 'value' => now()->addMonths(5)->toDateTimeString(), 'type' => 'datetime', 'is_public' => true, 'label' => 'Registration closes', 'description' => 'No new registration can be started after this. Checkouts already in progress still finish.'],
            ['key' => 'registration.edit_cutoff_at', 'group' => 'registration', 'value' => now()->addMonths(5)->addWeek()->toDateTimeString(), 'type' => 'datetime', 'is_public' => true, 'label' => 'Attendee edit cutoff', 'description' => 'Last moment an attendee may correct their own details. Set it before badge printing starts.'],
            ['key' => 'registration.max_family_size', 'group' => 'registration', 'value' => '6', 'type' => 'int', 'is_public' => true, 'label' => 'Max family size', 'description' => 'Largest party one registration may hold, registrant included. A ticket type may cap it lower.'],

            ['key' => 'payment.intent_ttl_minutes', 'group' => 'payment', 'value' => '30', 'type' => 'int', 'is_public' => false, 'label' => 'Payment intent TTL (minutes)', 'description' => 'How long an unpaid checkout keeps holding its seats before they are released back to the pool.'],
            ['key' => 'payment.manual_verification_enabled', 'group' => 'payment', 'value' => '1', 'type' => 'bool', 'is_public' => false, 'label' => 'Manual payment verification enabled', 'description' => 'Allows staff to approve a payment sent to a personal wallet. Turn off to accept gateway payments only.'],
            ['key' => 'payment.refund_cutoff_at', 'group' => 'payment', 'value' => now()->addMonths(5)->toDateTimeString(), 'type' => 'datetime', 'is_public' => true, 'label' => 'Refund cutoff', 'description' => 'After this, refunds can no longer be requested. Staff-issued refunds are unaffected.'],

            ['key' => 'checkin.window_start', 'group' => 'checkin', 'value' => now()->addMonths(6)->setTime(6, 0)->toDateTimeString(), 'type' => 'datetime', 'is_public' => false, 'label' => 'Check-in window start', 'description' => 'Scanners refuse admission before this. Open it early enough for volunteers to test their devices.'],
            ['key' => 'checkin.window_end', 'group' => 'checkin', 'value' => now()->addMonths(6)->setTime(20, 0)->toDateTimeString(), 'type' => 'datetime', 'is_public' => false, 'label' => 'Check-in window end', 'description' => 'Scanners stop admitting after this.'],
            ['key' => 'checkin.allow_manual_override', 'group' => 'checkin', 'value' => '1', 'type' => 'bool', 'is_public' => false, 'label' => 'Allow manual override at gate', 'description' => 'Lets an Event Manager admit someone whose QR will not scan. Every override is recorded in the activity log.'],

            ['key' => 'qr.active_signing_key_id', 'group' => 'checkin', 'value' => 'key-1', 'type' => 'string', 'is_public' => false, 'label' => 'Active QR signing key', 'description' => 'Which key new tickets are signed with. Only change this after every scanner device has synced the new key.'],

            // SMS gateway (REVE Systems). The two credentials are the first
            // `is_encrypted` rows in this table — they are stored encrypted
            // under APP_KEY and never returned to any client, which is what
            // makes it safe for them to live here rather than only in .env
            // (CLAUDE.md's rule is against an *unencrypted* settings row).
            // Seeded empty: a real account's keys are entered on the settings
            // screen, and a placeholder would look like a working config while
            // failing every send.
            ['key' => 'sms.api_key', 'group' => 'sms', 'value' => null, 'type' => 'string', 'is_encrypted' => true, 'is_public' => false, 'label' => 'SMS API key', 'description' => 'REVE account apikey. Stored encrypted and never shown again — saving a new one replaces it. Leave every SMS field empty to keep using the values in .env.'],
            ['key' => 'sms.secret_key', 'group' => 'sms', 'value' => null, 'type' => 'string', 'is_encrypted' => true, 'is_public' => false, 'label' => 'SMS secret key', 'description' => 'REVE account secretkey. Stored encrypted and never shown again. Clear the field and save to remove it.'],
            ['key' => 'sms.masking_enabled', 'group' => 'sms', 'value' => '0', 'type' => 'bool', 'is_public' => false, 'label' => 'Use masking (branded sender name)', 'description' => 'Off: messages send from a number (non-masking) — this is what an account can do without operator approval. On: they send from an approved brand name. This only decides what shape the sender ID must be; a sender ID is required either way.'],
            ['key' => 'sms.sender_id', 'group' => 'sms', 'value' => null, 'type' => 'string', 'is_public' => false, 'label' => 'SMS sender ID (callerID)', 'description' => 'With masking off, the number messages send from — digits only, e.g. 8809612. With masking on, the brand name your operator approved, up to 11 characters. Required either way: the gateway refuses a send with no callerID.'],
            ['key' => 'sms.base_url', 'group' => 'sms', 'value' => 'https://smpp.ajuratech.com:7790', 'type' => 'string', 'is_public' => false, 'label' => 'SMS gateway URL', 'description' => 'Your REVE instance and its API port, e.g. https://smpp.ajuratech.com:7790. Keys only work on the instance that issued them; the wrong host returns an empty response, not an error. The address without a port is the billing portal, not the API.'],
            ['key' => 'sms.client_id', 'group' => 'sms', 'value' => null, 'type' => 'string', 'is_public' => false, 'label' => 'SMS client ID', 'description' => 'REVE issues this alongside the account. Only used by their balance page; sending works without it.'],
            ['key' => 'sms.cost_paisa_per_segment', 'group' => 'sms', 'value' => '50', 'type' => 'money', 'is_public' => false, 'label' => 'SMS cost per segment', 'description' => 'What one segment costs under your REVE contract. This is what the delivery-cost report multiplies — REVE returns no price on a send, so a wrong figure here makes every cost figure wrong.'],
            ['key' => 'sms.low_balance_threshold_paisa', 'group' => 'sms', 'value' => '200000', 'type' => 'money', 'is_public' => false, 'label' => 'Low balance warning at', 'description' => 'The settings screen warns below this. Set it above what a full send costs, or the first warning arrives after messages have already failed.'],
            ['key' => 'sms.recharge_url', 'group' => 'sms', 'value' => 'https://smpp.ajuratech.com', 'type' => 'string', 'is_public' => false, 'label' => 'Recharge portal URL', 'description' => 'Where the Recharge button opens — your billing portal login, e.g. https://smpp.ajuratech.com. REVE has no top-up API, so recharging happens there and the new balance shows here afterwards.'],

            ['key' => 'notification.email_enabled', 'group' => 'notification', 'value' => '1', 'type' => 'bool', 'is_public' => false, 'label' => 'Email channel enabled', 'description' => 'Kill switch. Turning this off also cancels emails already queued, not just new ones.'],
            ['key' => 'notification.sms_enabled', 'group' => 'notification', 'value' => '1', 'type' => 'bool', 'is_public' => false, 'label' => 'SMS channel enabled', 'description' => 'Kill switch. SMS is the costliest channel — turn it off first if a send goes wrong.'],
            ['key' => 'notification.whatsapp_enabled', 'group' => 'notification', 'value' => '1', 'type' => 'bool', 'is_public' => false, 'label' => 'WhatsApp channel enabled', 'description' => 'Kill switch. Turning this off also cancels messages already queued.'],
        ];

        foreach ($settings as $attributes) {
            $setting = EventSetting::firstOrNew(['key' => $attributes['key']]);

            // Metadata (label, description, type, visibility) is code-owned and
            // always refreshed. The *value* is admin-owned: seeding only
            // supplies it for a row that does not exist yet, so re-running the
            // seeder can never silently revert a date or a kill switch someone
            // set deliberately on the Settings screen.
            $seedValue = $attributes['value'];
            unset($attributes['value']);

            // Fill the metadata first: `castForStorage()` branches on
            // `is_encrypted`, so seeding a value before that flag is set
            // would write a credential to the table in plaintext.
            $setting->fill($attributes);

            if (! $setting->exists) {
                $setting->value = $setting->castForStorage($seedValue);
            }

            $setting->save();
        }
    }
}
