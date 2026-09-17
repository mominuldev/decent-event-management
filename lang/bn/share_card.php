<?php

/*
 * বাংলা — the language the share card is drawn in by default. See
 * lang/en/share_card.php for what each key is.
 */

return [

    'shout' => 'আমি থাকছি!',
    'kicker' => 'শতবর্ষের মিলনমেলা',
    'headline' => 'শতবর্ষে হাজার প্রাণ,',
    'headline_accent' => 'বন্ধুত্বে অম্লান',

    'attendee' => 'অংশগ্রহণকারী',

    'participant' => [
        'former_student' => 'প্রাক্তন শিক্ষার্থী',
        'current_student' => 'বর্তমান শিক্ষার্থী',
        'teacher' => 'শিক্ষক',
        'staff' => 'কর্মচারী',
        'guardian' => 'অভিভাবক',
        'guest' => 'অতিথি',
        'sponsor' => 'পৃষ্ঠপোষক',
    ],

    // "প্রাক্তন শিক্ষার্থী, এসএসসি ব্যাচ ১৯৯৮। পরিবারসহ ৩ জন।"
    'about_batch' => ':participant, এসএসসি ব্যাচ :year।',
    'about_no_batch' => ':participant।',
    'about_party' => 'পরিবারসহ :count জন।',

    'fact' => [
        'date' => 'তারিখ',
        'time' => 'সময়',
        'venue' => 'স্থান',
        // The time and the venue are fixed to the design (Figma "Event
        // Ticket — v6"), not read from the session or the settings.
        'time_value' => 'সকাল ৮:০০',
        'time_note' => 'রাত ১০:০০ পর্যন্ত',
        'venue_value' => 'বিদ্যালয় প্রাঙ্গণ',
        'venue_note' => 'চাঁপাইনবাবগঞ্জ',
    ],

    'registration' => 'রেজিস্ট্রেশন নং',
    'stub_batch' => 'এসএসসি ব্যাচ',
    // For a holder with no batch year — a teacher, a guardian, a guest.
    'stub_role' => 'পরিচয়',
    'admits' => ':count জনের প্রবেশ',

    'invite' => 'আপনিও আসুন। টিকিট সংগ্রহ চলছে।',
    'organiser' => 'নামোশংকরবাটী উচ্চ বিদ্যালয়, চাঁপাইনবাবগঞ্জ',

];
