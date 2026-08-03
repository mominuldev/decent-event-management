<?php

namespace Database\Seeders;

use App\Domain\Shared\Models\EventSetting;
use Illuminate\Database\Seeder;

/**
 * Representative keys from docs/03 §3.23. Real values are entered by the
 * Super Admin during Phase 1 sign-off (docs/08 §9.4); these are sane
 * local-dev defaults so the app is usable out of the box.
 */
class EventSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['key' => 'event.name', 'group' => 'event', 'value' => 'School 100 Years Celebration', 'type' => 'string', 'is_public' => true, 'label' => 'Event name'],
            ['key' => 'event.date', 'group' => 'event', 'value' => now()->addMonths(6)->toDateString(), 'type' => 'datetime', 'is_public' => true, 'label' => 'Event date'],
            ['key' => 'event.venue', 'group' => 'event', 'value' => 'School Campus', 'type' => 'string', 'is_public' => true, 'label' => 'Event venue'],

            ['key' => 'registration.opens_at', 'group' => 'registration', 'value' => now()->toDateTimeString(), 'type' => 'datetime', 'is_public' => true, 'label' => 'Registration opens'],
            ['key' => 'registration.closes_at', 'group' => 'registration', 'value' => now()->addMonths(5)->toDateTimeString(), 'type' => 'datetime', 'is_public' => true, 'label' => 'Registration closes'],
            ['key' => 'registration.edit_cutoff_at', 'group' => 'registration', 'value' => now()->addMonths(5)->addWeek()->toDateTimeString(), 'type' => 'datetime', 'is_public' => true, 'label' => 'Attendee edit cutoff'],
            ['key' => 'registration.max_family_size', 'group' => 'registration', 'value' => '6', 'type' => 'int', 'is_public' => true, 'label' => 'Max family size'],

            ['key' => 'payment.intent_ttl_minutes', 'group' => 'payment', 'value' => '30', 'type' => 'int', 'is_public' => false, 'label' => 'Payment intent TTL (minutes)'],
            ['key' => 'payment.manual_verification_enabled', 'group' => 'payment', 'value' => '1', 'type' => 'bool', 'is_public' => false, 'label' => 'Manual payment verification enabled'],
            ['key' => 'payment.refund_cutoff_at', 'group' => 'payment', 'value' => now()->addMonths(5)->toDateTimeString(), 'type' => 'datetime', 'is_public' => true, 'label' => 'Refund cutoff'],

            ['key' => 'checkin.window_start', 'group' => 'checkin', 'value' => now()->addMonths(6)->setTime(6, 0)->toDateTimeString(), 'type' => 'datetime', 'is_public' => false, 'label' => 'Check-in window start'],
            ['key' => 'checkin.window_end', 'group' => 'checkin', 'value' => now()->addMonths(6)->setTime(20, 0)->toDateTimeString(), 'type' => 'datetime', 'is_public' => false, 'label' => 'Check-in window end'],
            ['key' => 'checkin.allow_manual_override', 'group' => 'checkin', 'value' => '1', 'type' => 'bool', 'is_public' => false, 'label' => 'Allow manual override at gate'],

            ['key' => 'qr.active_signing_key_id', 'group' => 'checkin', 'value' => 'key-1', 'type' => 'string', 'is_public' => false, 'label' => 'Active QR signing key'],

            ['key' => 'notification.email_enabled', 'group' => 'notification', 'value' => '1', 'type' => 'bool', 'is_public' => false, 'label' => 'Email channel enabled'],
            ['key' => 'notification.sms_enabled', 'group' => 'notification', 'value' => '1', 'type' => 'bool', 'is_public' => false, 'label' => 'SMS channel enabled'],
            ['key' => 'notification.whatsapp_enabled', 'group' => 'notification', 'value' => '1', 'type' => 'bool', 'is_public' => false, 'label' => 'WhatsApp channel enabled'],
        ];

        foreach ($settings as $setting) {
            EventSetting::updateOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
