<?php

/*
 * Every word the email shell renders on its own account
 * (resources/views/emails/notification.blade.php and the presentations
 * that feed it). The message *body* is not here — that is editable
 * template copy in `notification_templates`, one row per locale.
 *
 * `config/notifications.php` decides which of these files a given
 * notification is rendered from; the language is a property of the
 * notification, not of the request that triggered it.
 */

return [

    'kicker' => 'Admission ticket',
    'headline' => 'Your ticket is',
    'headline_accent' => 'confirmed.',
    'card_eyebrow' => 'Event',

    'fact' => [
        'date' => 'Date',
        'venue' => 'Venue',
        'attendee' => 'Attendee',
        'ticket_type' => 'Ticket type',
        'admits' => 'Admits',
    ],

    'batch' => 'Batch :year',
    'admits_count' => '{1} :count person|[2,*] :count people',

    'ticket_id_label' => 'Your ticket number',
    'qr_heading' => 'Scan at the gate',
    'qr_alt' => 'QR code for ticket :number',
    'qr_caption' => 'Show this code at the entrance, on your phone or printed.',
    'qr_caption_generic' => 'Show this code at the entrance.',

    'notes' => [
        'id' => ['label' => 'Bring ID', 'text' => 'Bring a photo ID with this ticket.'],
        'transfer' => ['label' => 'Not transferable', 'text' => 'It admits only the party named above.'],
        'early' => ['label' => 'Arrive early', 'text' => 'Reach the gate about :minutes minutes ahead.'],
        'keep' => ['label' => 'Keep this email', 'text' => 'The code above is the ticket itself.'],
    ],

    'cta' => 'View your registration',
    'cta_generic' => 'Open your registration',

    'support_heading' => 'Need help?',
    'footer_note' => 'You are receiving this because you registered for this event.',
    'footer_reply' => 'Please do not reply to this address — it is not monitored.',
    'footer_tagline' => 'Celebrating a hundred years together.',
    'rights' => 'All rights reserved.',

];
