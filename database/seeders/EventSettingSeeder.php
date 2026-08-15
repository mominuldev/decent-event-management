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
            ['key' => 'event.name', 'group' => 'event', 'value' => 'School 100 Years Celebration', 'type' => 'string', 'is_public' => true, 'label' => 'Event name', 'description' => 'Shown on the public site, in every email and SMS, and printed on each ticket.'],
            ['key' => 'event.date', 'group' => 'event', 'value' => now()->addMonths(6)->toDateString(), 'type' => 'datetime', 'is_public' => true, 'label' => 'Event date', 'description' => 'The headline date the public sees. Gate sessions have their own times — see Check-in.'],
            ['key' => 'event.venue', 'group' => 'event', 'value' => 'School Campus', 'type' => 'string', 'is_public' => true, 'label' => 'Event venue', 'description' => 'Venue name as it appears on the public site and on printed tickets.'],

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
            if (! $setting->exists) {
                $setting->value = $attributes['value'];
            }

            unset($attributes['value']);

            $setting->fill($attributes)->save();
        }
    }
}
