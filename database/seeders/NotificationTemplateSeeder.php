<?php

namespace Database\Seeders;

use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Support\SmsSegmentCalculator;
use Illuminate\Database\Seeder;

/**
 * Bilingual (EN/BN) draft copy for every (template_key, channel) pair in
 * the channel matrix (docs/01 §1.6).
 *
 * **Re-running this never overwrites wording somebody edited.** Templates
 * are editable from the admin console, so `subject`, `body`, `is_active`
 * and `whatsapp_template_status` are seeded only for a row that does not
 * exist yet — the same admin-owns-the-value rule
 * {@see EventSettingSeeder} follows. `variables` is the exception and is
 * always refreshed: which placeholders exist is decided by the dispatching
 * listener, and a stale list is worse than none because the editor shows
 * it as the set that is safe to use. Which of the two a notification is
 * actually written in is `config/notifications.php`'s decision, not this
 * seeder's — Bangla by default. Both rows must stay complete: a missing
 * one falls back to the other locale mid-send, which is worse than a
 * translation nobody has polished yet.
 *
 * The bodies carry no gate details (ticket number, admits, venue, date):
 * the email shell renders those from the ticket itself, so an editor
 * cannot drop them and they are never stale relative to the record — flagged as draft, for the client
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
                    $template = NotificationTemplate::firstOrNew([
                        'key' => $key,
                        'channel' => $channel,
                        'locale' => $locale,
                        'version' => 1,
                    ]);

                    // Which `{{placeholders}}` exist is decided by the
                    // dispatching listener, not by whoever last edited the
                    // wording, so it is code-owned and always refreshed —
                    // and a stale list is actively harmful, because the
                    // editor renders it as the set of variables that are
                    // safe to use. `whatsapp_template_name` is derived from
                    // the key for the same reason.
                    $template->fill([
                        'variables' => $definition['variables'],
                        'whatsapp_template_name' => $channel === 'whatsapp' ? $key : null,
                    ]);

                    // The wording is admin-owned. Since templates became
                    // editable from the admin console (2026-08-22) this
                    // seeder runs on deployments where somebody has already
                    // rewritten a message, and `updateOrCreate` would have
                    // silently reverted it — the same trap
                    // `EventSettingSeeder` avoids, and the reason a deploy
                    // must never run this blind.
                    //
                    // `is_active` and `whatsapp_template_status` go with it:
                    // both record a decision somebody made (a message turned
                    // off, a Meta approval that landed), not a fact about
                    // this code.
                    if (! $template->exists) {
                        $template->fill([
                            'subject' => $content['subject'] ?? null,
                            'body' => $content['body'],
                            'is_active' => true,
                            'whatsapp_template_status' => $channel === 'whatsapp' ? 'pending_approval' : null,
                        ]);
                    }

                    // Measured from the body this row actually holds, not
                    // from the seeded one — otherwise an edited template
                    // would report the cost of the copy it replaced. Same
                    // helper the admin editor uses, so the two can never
                    // disagree; rendered first, because `{` and `}` are not
                    // GSM-7 and a raw body over-counts by up to 3x.
                    $template->estimated_segments = $channel === 'sms'
                        ? max(0, SmsSegmentCalculator::segmentCount(
                            SmsSegmentCalculator::renderForEstimate((string) $template->body),
                        ))
                        : null;

                    $template->save();
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
                // The attendee sign-in code.
                //
                // Sent only when somebody has no password yet, or has
                // forgotten it — an attendee who chose a password at
                // checkout never triggers this at all, which is the point of
                // the whole design. A six-digit code rather than a link: it
                // keeps the reader in the tab they started in, it is the
                // sign-in every Bangladeshi phone already understands, and
                // it fits one SMS segment where the link did not.
                //
                // No brand-name prefix and no marketing: the shorter this
                // is, the fewer segments it bills, and an OTP is read in a
                // notification preview rather than opened.
                'key' => 'attendee.login_link',
                'variables' => ['code', 'minutes'],
                'channels' => [
                    'sms' => [
                        'en' => ['body' => 'Centennial sign-in code: {{code}} (valid {{minutes}} min). Never share it.'],
                        'bn' => ['body' => 'শতবর্ষ সাইন-ইন কোড: {{code}} ({{minutes}} মিনিট)। কাউকে দেবেন না।'],
                    ],
                ],
            ],
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
                'variables' => ['full_name', 'full_name_bn', 'registration_number', 'registration_ulid'],
                'channels' => [
                    'email' => [
                        'en' => ['subject' => 'We received your registration', 'body' => '<p>Dear {{full_name}},</p><p>Thank you for registering. Your registration number is <strong>{{registration_number}}</strong>. Reference: {{registration_ulid}}.</p>'],
                        'bn' => ['subject' => 'আপনার নিবন্ধন পেয়েছি', 'body' => '<p>প্রিয় {{full_name_bn}},</p><p>আপনার নিবন্ধনের জন্য ধন্যবাদ। আপনার নিবন্ধন নম্বর <strong>{{registration_number}}</strong>। রেফারেন্স: {{registration_ulid}}।</p>'],
                    ],
                    'sms' => [
                        'en' => ['body' => 'Dear {{full_name}}, your registration {{registration_number}} was received. Complete payment to confirm your seat.'],
                        'bn' => ['body' => 'প্রিয় {{full_name_bn}}, আপনার নিবন্ধন {{registration_number}} পেয়েছি। আসন নিশ্চিত করতে পেমেন্ট সম্পন্ন করুন।'],
                    ],
                    'whatsapp' => [
                        'en' => ['body' => 'Dear {{full_name}}, your registration {{registration_number}} was received. Complete payment to confirm your seat.'],
                        'bn' => ['body' => 'প্রিয় {{full_name_bn}}, আপনার নিবন্ধন {{registration_number}} পেয়েছি। আসন নিশ্চিত করতে পেমেন্ট সম্পন্ন করুন।'],
                    ],
                ],
            ],
            [
                // The email rows are seeded but unsent since 2026-09-17: the
                // listener queues WhatsApp only, because the
                // registration-confirmed email that follows issuance is the
                // receipt. Kept so flipping the channel back needs no seed.
                'key' => 'payment_succeeded',
                'variables' => ['full_name', 'full_name_bn', 'payment_number', 'amount_bdt', 'method', 'gateway_transaction_id'],
                'channels' => [
                    'email' => [
                        'en' => ['subject' => 'Payment received — BDT {{amount_bdt}}', 'body' => '<p>Dear {{full_name}},</p><p>We received your payment of <strong>BDT {{amount_bdt}}</strong> via {{method}} (payment {{payment_number}}, transaction {{gateway_transaction_id}}). Your ticket is on its way.</p>'],
                        'bn' => ['subject' => 'পেমেন্ট পেয়েছি — {{amount_bdt}} টাকা', 'body' => '<p>প্রিয় {{full_name_bn}},</p><p>আমরা {{method}}-এর মাধ্যমে <strong>{{amount_bdt}} টাকা</strong> পেমেন্ট পেয়েছি (পেমেন্ট {{payment_number}}, লেনদেন {{gateway_transaction_id}})। আপনার টিকিট শীঘ্রই আসছে।</p>'],
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
                'variables' => ['full_name', 'full_name_bn', 'payment_number', 'amount_bdt'],
                'channels' => [
                    'email' => [
                        'en' => ['subject' => 'Your manual payment was verified', 'body' => '<p>Dear {{full_name}},</p><p>Your manual payment of <strong>BDT {{amount_bdt}}</strong> ({{payment_number}}) has been verified by our team. Your ticket is being issued.</p>'],
                        'bn' => ['subject' => 'আপনার ম্যানুয়াল পেমেন্ট যাচাই হয়েছে', 'body' => '<p>প্রিয় {{full_name_bn}},</p><p>আপনার <strong>{{amount_bdt}} টাকা</strong> ({{payment_number}}) ম্যানুয়াল পেমেন্ট যাচাই করা হয়েছে। আপনার টিকিট ইস্যু করা হচ্ছে।</p>'],
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
                'variables' => ['full_name', 'full_name_bn', 'payment_number', 'amount_bdt', 'registration_ulid'],
                'channels' => [
                    'email' => [
                        'en' => ['subject' => 'Payment unsuccessful', 'body' => '<p>Dear {{full_name}},</p><p>Your payment of BDT {{amount_bdt}} ({{payment_number}}) was not successful. Please retry: registration {{registration_ulid}}.</p>'],
                        'bn' => ['subject' => 'পেমেন্ট ব্যর্থ হয়েছে', 'body' => '<p>প্রিয় {{full_name_bn}},</p><p>আপনার {{amount_bdt}} টাকা ({{payment_number}}) পেমেন্ট সফল হয়নি। অনুগ্রহ করে আবার চেষ্টা করুন: নিবন্ধন {{registration_ulid}}।</p>'],
                    ],
                    'sms' => [
                        'en' => ['body' => 'Payment of BDT {{amount_bdt}} failed. Retry using registration {{registration_ulid}}.'],
                        'bn' => ['body' => '{{amount_bdt}} টাকা পেমেন্ট ব্যর্থ হয়েছে। নিবন্ধন {{registration_ulid}} ব্যবহার করে আবার চেষ্টা করুন।'],
                    ],
                ],
            ],
            [
                // Sent automatically the moment a ticket is issued — and it
                // is deliberately *not* the ticket. It says the seat is
                // theirs and carries the "আমি থাকছি!" share card, which the
                // shell embeds from the ticket; the QR, the ticket number
                // and the gate details are in `ticket_delivered`, which staff
                // send from the admin console when the organisers decide
                // to. The body may name the registration number but must
                // never name the ticket number — this is the email people
                // forward. It does not interpolate `{{event_name}}`: that
                // variable is the *English* name (it exists for the SMS),
                // and the masthead already names the event from settings
                // with a fallback, where the payload has none.
                //
                // It is also the receipt: `payment_succeeded` and
                // `payment_manual_verified` no longer send email, because a
                // settled payment leads here within seconds and two emails
                // for one event is noise. Hence `{{amount_bdt}}`.
                //
                // Email only: the registration line does not earn an SMS
                // segment; the SMS goes out with the ticket.
                'key' => 'registration_confirmed',
                'variables' => ['full_name', 'full_name_bn', 'registration_number', 'amount_bdt', 'event_name', 'event_date', 'event_time', 'venue'],
                'channels' => [
                    'email' => [
                        'en' => ['subject' => 'Your registration is confirmed — {{registration_number}}', 'body' => '<p>Dear {{full_name}},</p><p>Thank you for registering. We have received your payment of <strong>BDT {{amount_bdt}}</strong>, and your registration <strong>{{registration_number}}</strong> is complete — your seat is reserved.</p><p>Your admission ticket, with its QR code, will be sent to you in a separate email before the event. Please keep an eye on this inbox.</p><p>In the meantime, the card below is yours to share — let your friends and batchmates know you are coming.</p>'],
                        'bn' => ['subject' => 'আপনার নিবন্ধন সম্পন্ন হয়েছে — {{registration_number}}', 'body' => '<p>প্রিয় {{full_name_bn}},</p><p>নিবন্ধনের জন্য আপনাকে ধন্যবাদ। আমরা আপনার <strong>{{amount_bdt}} টাকা</strong> পেমেন্ট পেয়েছি এবং আপনার নিবন্ধন <strong>{{registration_number}}</strong> সম্পন্ন হয়েছে — আপনার আসন সংরক্ষিত রয়েছে।</p><p>আপনার প্রবেশ টিকিট, QR কোডসহ, অনুষ্ঠানের আগে আলাদা একটি ইমেইলে পাঠানো হবে। অনুগ্রহ করে এই ইনবক্সে নজর রাখুন।</p><p>ইতিমধ্যে, নিচের কার্ডটি আপনার শেয়ার করার জন্য — বন্ধু ও ব্যাচমেটদের জানিয়ে দিন আপনি আসছেন।</p>'],
                    ],
                ],
            ],
            [
                // The ticket itself. Since 2026-09-17 nothing sends this
                // automatically: staff send it from the admin console (one
                // ticket, a selection, or all of them), so the organisers
                // decide when the QR codes go out.
                'key' => 'ticket_delivered',
                'variables' => ['full_name', 'full_name_bn', 'ticket_number', 'admits_total', 'registration_number', 'amount_bdt', 'customer_name', 'ticket_id', 'event_name', 'event_date', 'event_time', 'venue'],
                'channels' => [
                    'email' => [
                        // Body copy only — the ticket number, admit count,
                        // session, venue and the QR itself are rendered by the
                        // email shell (resources/views/emails/notification.blade.php)
                        // from the ticket, not interpolated here. An editor
                        // rewriting this copy cannot remove the code the
                        // holder is admitted with.
                        'en' => ['subject' => 'Your ticket is confirmed — {{ticket_number}}', 'body' => '<p>Dear {{full_name}},</p><p>Thank you for registering. We are pleased to confirm that your ticket has been issued; the details are set out below.</p><p>The QR code in this message serves as your admission pass. Please present it at the gate on the day of the event, either on your phone or as a printed copy of this email.</p><p>Please retain this email for your records. We look forward to welcoming you.</p>'],
                        'bn' => ['subject' => 'আপনার টিকিট নিশ্চিত হয়েছে — {{ticket_number}}', 'body' => '<p>প্রিয় {{full_name_bn}},</p><p>নিবন্ধনের জন্য আপনাকে ধন্যবাদ। আপনার টিকিট ইস্যু করা হয়েছে; বিস্তারিত নিচে দেওয়া হলো।</p><p>এই বার্তার QR কোডটিই আপনার প্রবেশপত্র। অনুষ্ঠানের দিন গেটে অনুগ্রহ করে এটি ফোন থেকে দেখান, অথবা এই ইমেইলটি প্রিন্ট করে সঙ্গে আনুন।</p><p>এই ইমেইলটি সংরক্ষণ করে রাখুন। আপনাকে স্বাগত জানানোর অপেক্ষায় রইলাম।</p>'],
                    ],
                    // The only SMS a ticket purchase sends — booking,
                    // payment and registration confirmations are email-only,
                    // so this one carries what all of them used to. It goes
                    // out when staff send the ticket, alongside the email.
                    //
                    // Written to fit **one segment**, and it is close to the
                    // line: 146 of the 160 GSM-7 characters at the seeded
                    // event name and venue (measured with `CEN-00001`, a
                    // `j M Y` date and a `g:i A` time in the placeholders). Two things tip it into a second
                    // segment and double the bill on every ticket — a longer
                    // `event.name_en`/`event.venue_en`, and any character
                    // outside GSM-7. Emoji are the obvious ones; the
                    // surprise is that a plain `|` is not GSM-7 either, nor
                    // are { } [ ] ~ ^ \ €. The templates screen shows the
                    // live segment count for exactly this reason.
                    'sms' => [
                        'en' => ['body' => "Ticket confirmed: {{event_name}}\nID: {{ticket_id}}\n{{event_date}}, {{event_time}}, {{venue}}\nYour QR ticket has been emailed. Please present it at the gate."],
                        'bn' => ['body' => "টিকিট নিশ্চিত হয়েছে: {{event_name}}\nID: {{ticket_id}}\n{{event_date}}, {{event_time}}, {{venue}}\nQR টিকিট ইমেইলে পাঠানো হয়েছে। গেটে দেখান।"],
                    ],
                    'whatsapp' => [
                        'en' => ['body' => 'Your ticket {{ticket_number}} has been issued and admits {{admits_total}}. Please sign in to your account to view it.'],
                        'bn' => ['body' => 'আপনার টিকিট {{ticket_number}} ইস্যু করা হয়েছে; প্রবেশাধিকার {{admits_total}} জন। অনুগ্রহ করে আপনার অ্যাকাউন্টে সাইন ইন করে দেখুন।'],
                    ],
                ],
            ],
            [
                'key' => 'refund_issued',
                'variables' => ['full_name', 'full_name_bn', 'refund_number', 'amount_bdt'],
                'channels' => [
                    'email' => [
                        'en' => ['subject' => 'Your refund has been issued', 'body' => '<p>Dear {{full_name}},</p><p>A refund of <strong>BDT {{amount_bdt}}</strong> ({{refund_number}}) has been issued to your original payment method.</p>'],
                        'bn' => ['subject' => 'আপনার রিফান্ড ইস্যু করা হয়েছে', 'body' => '<p>প্রিয় {{full_name_bn}},</p><p><strong>{{amount_bdt}} টাকা</strong> ({{refund_number}}) রিফান্ড আপনার মূল পেমেন্ট পদ্ধতিতে ইস্যু করা হয়েছে।</p>'],
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
                'variables' => ['full_name', 'full_name_bn', 'event_name', 'event_venue', 'event_starts_at'],
                'channels' => [
                    'email' => [
                        'en' => ['subject' => "Reminder: {{event_name}} is in {$when['en']}", 'body' => "<p>Dear {{full_name}},</p><p>{{event_name}} is coming up in {$when['en']} at {{event_venue}} on {{event_starts_at}}. We look forward to seeing you.</p>"],
                        'bn' => ['subject' => "স্মরণিকা: {{event_name}} {$when['bn']} পরে", 'body' => "<p>প্রিয় {{full_name_bn}},</p><p>{{event_name}} {$when['bn']} পরে {{event_venue}}-এ {{event_starts_at}} তারিখে অনুষ্ঠিত হবে। আপনাকে দেখার অপেক্ষায় রইলাম।</p>"],
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
