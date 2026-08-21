<?php

namespace Database\Seeders;

use App\Domain\Notification\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

/**
 * Bilingual (EN/BN) draft copy for every (template_key, channel) pair in
 * the channel matrix (docs/01 §1.6) — flagged as draft, for the client
 * to refine, same caveat as {@see EventSettingSeeder}'s local-dev
 * defaults. WhatsApp rows carry `whatsapp_template_status =
 * pending_approval` since Meta approval is an unchecked external
 * dependency (CLAUDE.md) — swap to `approved` once it lands.
 */
class NotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $definition) {
            $key = $definition['key'];

            foreach ($definition['channels'] as $channel => $locales) {
                foreach ($locales as $locale => $content) {
                    NotificationTemplate::updateOrCreate(
                        ['key' => $key, 'channel' => $channel, 'locale' => $locale, 'version' => 1],
                        [
                            'subject' => $content['subject'] ?? null,
                            'body' => $content['body'],
                            'whatsapp_template_name' => $channel === 'whatsapp' ? $key : null,
                            'whatsapp_template_status' => $channel === 'whatsapp' ? 'pending_approval' : null,
                            'variables' => $definition['variables'],
                            'is_active' => true,
                        ],
                    );
                }
            }
        }
    }

    /**
     * @return array<int, array{key: string, variables: array<int, string>, channels: array<string, array<string, array{subject?: string, body: string}>>}>
     */
    private function templates(): array
    {
        return [
            [
                // Staff-facing, unlike every other template here — docs/06
                // §6.5 requires a key rotation to notify all Event Managers.
                // Email only: it is an audit-trail message with detail that
                // does not belong in an SMS, and no Event Manager is at a
                // gate relying on it to admit anybody.
                'key' => 'qr_signing_key_rotated',
                'variables' => ['key_id', 'rotated_by', 'rotated_at', 'device_warning'],
                'channels' => [
                    'email' => [
                        'en' => [
                            'subject' => 'QR signing key rotated ({{key_id}})',
                            'body' => '<p>The QR ticket signing key was rotated to <strong>{{key_id}}</strong> by {{rotated_by}} on {{rotated_at}}.</p><p>{{device_warning}}</p><p>Tickets issued before this rotation remain valid — the previous key is retired from signing but still published to devices for verification.</p>',
                        ],
                        'bn' => [
                            'subject' => 'QR স্বাক্ষর কী পরিবর্তন করা হয়েছে ({{key_id}})',
                            'body' => '<p>QR টিকিট স্বাক্ষর কী <strong>{{key_id}}</strong>-এ পরিবর্তন করেছেন {{rotated_by}}, {{rotated_at}} তারিখে।</p><p>{{device_warning}}</p><p>এই পরিবর্তনের আগে ইস্যু করা টিকিটগুলো বৈধ থাকবে — পুরোনো কী স্বাক্ষরের জন্য অবসরপ্রাপ্ত হলেও যাচাইয়ের জন্য ডিভাইসে প্রকাশিত থাকে।</p>',
                        ],
                    ],
                ],
            ],
            [
                'key' => 'registration_received',
                'variables' => ['full_name', 'registration_number', 'registration_ulid'],
                'channels' => [
                    'email' => [
                        'en' => ['subject' => 'We received your registration', 'body' => '<p>Dear {{full_name}},</p><p>Thank you for registering. Your registration number is <strong>{{registration_number}}</strong>. Reference: {{registration_ulid}}.</p>'],
                        'bn' => ['subject' => 'আপনার নিবন্ধন পেয়েছি', 'body' => '<p>প্রিয় {{full_name}},</p><p>আপনার নিবন্ধনের জন্য ধন্যবাদ। আপনার নিবন্ধন নম্বর <strong>{{registration_number}}</strong>। রেফারেন্স: {{registration_ulid}}।</p>'],
                    ],
                    'sms' => [
                        'en' => ['body' => 'Dear {{full_name}}, your registration {{registration_number}} was received. Complete payment to confirm your seat.'],
                        'bn' => ['body' => 'প্রিয় {{full_name}}, আপনার নিবন্ধন {{registration_number}} পেয়েছি। আসন নিশ্চিত করতে পেমেন্ট সম্পন্ন করুন।'],
                    ],
                    'whatsapp' => [
                        'en' => ['body' => 'Dear {{full_name}}, your registration {{registration_number}} was received. Complete payment to confirm your seat.'],
                        'bn' => ['body' => 'প্রিয় {{full_name}}, আপনার নিবন্ধন {{registration_number}} পেয়েছি। আসন নিশ্চিত করতে পেমেন্ট সম্পন্ন করুন।'],
                    ],
                ],
            ],
            [
                'key' => 'payment_succeeded',
                'variables' => ['full_name', 'payment_number', 'amount_bdt', 'method', 'gateway_transaction_id'],
                'channels' => [
                    'email' => [
                        'en' => ['subject' => 'Payment received — BDT {{amount_bdt}}', 'body' => '<p>Dear {{full_name}},</p><p>We received your payment of <strong>BDT {{amount_bdt}}</strong> via {{method}} (payment {{payment_number}}, transaction {{gateway_transaction_id}}). Your ticket is on its way.</p>'],
                        'bn' => ['subject' => 'পেমেন্ট পেয়েছি — {{amount_bdt}} টাকা', 'body' => '<p>প্রিয় {{full_name}},</p><p>আমরা {{method}}-এর মাধ্যমে <strong>{{amount_bdt}} টাকা</strong> পেমেন্ট পেয়েছি (পেমেন্ট {{payment_number}}, লেনদেন {{gateway_transaction_id}})। আপনার টিকিট শীঘ্রই আসছে।</p>'],
                    ],
                    'sms' => [
                        'en' => ['body' => 'Payment of BDT {{amount_bdt}} received ({{payment_number}}). Your ticket is being issued.'],
                        'bn' => ['body' => '{{amount_bdt}} টাকা পেমেন্ট পেয়েছি ({{payment_number}})। আপনার টিকিট ইস্যু করা হচ্ছে।'],
                    ],
                    'whatsapp' => [
                        'en' => ['body' => 'Payment of BDT {{amount_bdt}} received ({{payment_number}}). Your ticket is being issued.'],
                        'bn' => ['body' => '{{amount_bdt}} টাকা পেমেন্ট পেয়েছি ({{payment_number}})। আপনার টিকিট ইস্যু করা হচ্ছে।'],
                    ],
                ],
            ],
            [
                'key' => 'payment_manual_verified',
                'variables' => ['full_name', 'payment_number', 'amount_bdt'],
                'channels' => [
                    'email' => [
                        'en' => ['subject' => 'Your manual payment was verified', 'body' => '<p>Dear {{full_name}},</p><p>Your manual payment of <strong>BDT {{amount_bdt}}</strong> ({{payment_number}}) has been verified by our team. Your ticket is being issued.</p>'],
                        'bn' => ['subject' => 'আপনার ম্যানুয়াল পেমেন্ট যাচাই হয়েছে', 'body' => '<p>প্রিয় {{full_name}},</p><p>আপনার <strong>{{amount_bdt}} টাকা</strong> ({{payment_number}}) ম্যানুয়াল পেমেন্ট যাচাই করা হয়েছে। আপনার টিকিট ইস্যু করা হচ্ছে।</p>'],
                    ],
                    'sms' => [
                        'en' => ['body' => 'Your manual payment of BDT {{amount_bdt}} ({{payment_number}}) was verified. Ticket is being issued.'],
                        'bn' => ['body' => 'আপনার {{amount_bdt}} টাকা ({{payment_number}}) ম্যানুয়াল পেমেন্ট যাচাই হয়েছে। টিকিট ইস্যু করা হচ্ছে।'],
                    ],
                    'whatsapp' => [
                        'en' => ['body' => 'Your manual payment of BDT {{amount_bdt}} ({{payment_number}}) was verified. Ticket is being issued.'],
                        'bn' => ['body' => 'আপনার {{amount_bdt}} টাকা ({{payment_number}}) ম্যানুয়াল পেমেন্ট যাচাই হয়েছে। টিকিট ইস্যু করা হচ্ছে।'],
                    ],
                ],
            ],
            [
                'key' => 'payment_failed',
                'variables' => ['full_name', 'payment_number', 'amount_bdt', 'registration_ulid'],
                'channels' => [
                    'email' => [
                        'en' => ['subject' => 'Payment unsuccessful', 'body' => '<p>Dear {{full_name}},</p><p>Your payment of BDT {{amount_bdt}} ({{payment_number}}) was not successful. Please retry: registration {{registration_ulid}}.</p>'],
                        'bn' => ['subject' => 'পেমেন্ট ব্যর্থ হয়েছে', 'body' => '<p>প্রিয় {{full_name}},</p><p>আপনার {{amount_bdt}} টাকা ({{payment_number}}) পেমেন্ট সফল হয়নি। অনুগ্রহ করে আবার চেষ্টা করুন: নিবন্ধন {{registration_ulid}}।</p>'],
                    ],
                    'sms' => [
                        'en' => ['body' => 'Payment of BDT {{amount_bdt}} failed. Retry using registration {{registration_ulid}}.'],
                        'bn' => ['body' => '{{amount_bdt}} টাকা পেমেন্ট ব্যর্থ হয়েছে। নিবন্ধন {{registration_ulid}} ব্যবহার করে আবার চেষ্টা করুন।'],
                    ],
                ],
            ],
            [
                'key' => 'ticket_delivered',
                'variables' => ['full_name', 'ticket_number', 'admits_total'],
                'channels' => [
                    'email' => [
                        'en' => ['subject' => 'Your ticket is ready — {{ticket_number}}', 'body' => '<p>Dear {{full_name}},</p><p>Your ticket <strong>{{ticket_number}}</strong> (admits {{admits_total}}) is ready. Present the QR code at the gate.</p>'],
                        'bn' => ['subject' => 'আপনার টিকিট প্রস্তুত — {{ticket_number}}', 'body' => '<p>প্রিয় {{full_name}},</p><p>আপনার টিকিট <strong>{{ticket_number}}</strong> (প্রবেশাধিকার {{admits_total}} জন) প্রস্তুত। গেটে QR কোড দেখান।</p>'],
                    ],
                    'sms' => [
                        'en' => ['body' => 'Ticket {{ticket_number}} is ready, admits {{admits_total}}. View it in your account.'],
                        'bn' => ['body' => 'টিকিট {{ticket_number}} প্রস্তুত, প্রবেশাধিকার {{admits_total}} জন। আপনার অ্যাকাউন্টে দেখুন।'],
                    ],
                    'whatsapp' => [
                        'en' => ['body' => 'Ticket {{ticket_number}} is ready, admits {{admits_total}}. View it in your account.'],
                        'bn' => ['body' => 'টিকিট {{ticket_number}} প্রস্তুত, প্রবেশাধিকার {{admits_total}} জন। আপনার অ্যাকাউন্টে দেখুন।'],
                    ],
                ],
            ],
            [
                'key' => 'refund_issued',
                'variables' => ['full_name', 'refund_number', 'amount_bdt'],
                'channels' => [
                    'email' => [
                        'en' => ['subject' => 'Your refund has been issued', 'body' => '<p>Dear {{full_name}},</p><p>A refund of <strong>BDT {{amount_bdt}}</strong> ({{refund_number}}) has been issued to your original payment method.</p>'],
                        'bn' => ['subject' => 'আপনার রিফান্ড ইস্যু করা হয়েছে', 'body' => '<p>প্রিয় {{full_name}},</p><p><strong>{{amount_bdt}} টাকা</strong> ({{refund_number}}) রিফান্ড আপনার মূল পেমেন্ট পদ্ধতিতে ইস্যু করা হয়েছে।</p>'],
                    ],
                    'sms' => [
                        'en' => ['body' => 'Refund of BDT {{amount_bdt}} ({{refund_number}}) issued to your original payment method.'],
                        'bn' => ['body' => '{{amount_bdt}} টাকা ({{refund_number}}) রিফান্ড আপনার মূল পেমেন্ট পদ্ধতিতে ইস্যু করা হয়েছে।'],
                    ],
                ],
            ],
            ...$this->reminderTemplates(),
        ];
    }

    /**
     * @return array<int, array{key: string, variables: array<int, string>, channels: array<string, array<string, array{subject?: string, body: string}>>}>
     */
    private function reminderTemplates(): array
    {
        $windows = [
            'event_reminder_t7' => ['en' => '7 days', 'bn' => '৭ দিন'],
            'event_reminder_t1' => ['en' => '1 day', 'bn' => '১ দিন'],
            'event_reminder_t0' => ['en' => 'today', 'bn' => 'আজ'],
        ];

        $templates = [];

        foreach ($windows as $key => $when) {
            $templates[] = [
                'key' => $key,
                'variables' => ['full_name', 'event_name', 'event_venue', 'event_starts_at'],
                'channels' => [
                    'email' => [
                        'en' => ['subject' => "Reminder: {{event_name}} is in {$when['en']}", 'body' => "<p>Dear {{full_name}},</p><p>{{event_name}} is coming up in {$when['en']} at {{event_venue}} on {{event_starts_at}}. We look forward to seeing you.</p>"],
                        'bn' => ['subject' => "স্মরণিকা: {{event_name}} {$when['bn']} পরে", 'body' => "<p>প্রিয় {{full_name}},</p><p>{{event_name}} {$when['bn']} পরে {{event_venue}}-এ {{event_starts_at}} তারিখে অনুষ্ঠিত হবে। আপনাকে দেখার অপেক্ষায় রইলাম।</p>"],
                    ],
                    'sms' => [
                        'en' => ['body' => "Reminder: {{event_name}} is in {$when['en']} at {{event_venue}}, {{event_starts_at}}."],
                        'bn' => ['body' => "স্মরণিকা: {{event_name}} {$when['bn']} পরে {{event_venue}}-এ, {{event_starts_at}}।"],
                    ],
                    'whatsapp' => [
                        'en' => ['body' => "Reminder: {{event_name}} is in {$when['en']} at {{event_venue}}, {{event_starts_at}}."],
                        'bn' => ['body' => "স্মরণিকা: {{event_name}} {$when['bn']} পরে {{event_venue}}-এ, {{event_starts_at}}।"],
                    ],
                ],
            ];
        }

        return $templates;
    }
}
