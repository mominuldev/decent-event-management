<?php

/*
 * বাংলা — the language emails are written in by default
 * (`config/notifications.php`). See lang/en/emails.php for what each key
 * is and why the message body is not kept here.
 */

return [

    'kicker' => 'প্রবেশপত্র',
    'headline' => 'আপনার টিকিট',
    'headline_accent' => 'নিশ্চিত হয়েছে।',
    'card_eyebrow' => 'অনুষ্ঠান',

    'fact' => [
        'date' => 'তারিখ',
        'venue' => 'স্থান',
        'attendee' => 'অংশগ্রহণকারী',
        'ticket_type' => 'টিকিটের ধরন',
        'admits' => 'প্রবেশাধিকার',
    ],

    'batch' => ':year ব্যাচ',
    // Bangla does not inflect this noun for number, so both forms are one.
    'admits_count' => '{1} :count জন|[2,*] :count জন',

    'ticket_id_label' => 'আপনার টিকিট নম্বর',
    'qr_heading' => 'গেটে স্ক্যান করুন',
    'qr_alt' => ':number টিকিটের কিউআর কোড',
    'qr_caption' => 'প্রবেশদ্বারে এই কোডটি দেখান — ফোনে অথবা প্রিন্ট করে।',
    'qr_caption_generic' => 'প্রবেশদ্বারে এই কোডটি দেখান।',

    'notes' => [
        'id' => ['label' => 'পরিচয়পত্র আনুন', 'text' => 'টিকিটের সঙ্গে ছবিসহ পরিচয়পত্র আনুন।'],
        'transfer' => ['label' => 'হস্তান্তরযোগ্য নয়', 'text' => 'শুধু উল্লিখিত ব্যক্তিরাই প্রবেশ করতে পারবেন।'],
        'early' => ['label' => 'আগে আসুন', 'text' => 'গেটে প্রায় :minutes মিনিট আগে পৌঁছান।'],
        'keep' => ['label' => 'ইমেইলটি রাখুন', 'text' => 'উপরের কোডটিই আপনার টিকিট।'],
    ],

    'cta' => 'আপনার নিবন্ধন দেখুন',
    'cta_generic' => 'নিবন্ধন দেখুন',

    'support_heading' => 'সাহায্য দরকার?',
    'footer_note' => 'আপনি এই অনুষ্ঠানে নিবন্ধন করেছেন বলে এই বার্তাটি পাচ্ছেন।',
    'footer_reply' => 'এই ঠিকানায় উত্তর দেবেন না — এটি পর্যবেক্ষণ করা হয় না।',
    'footer_tagline' => 'একসঙ্গে শতবর্ষ উদ্‌যাপন।',
    'rights' => 'সর্বস্বত্ব সংরক্ষিত।',

];
