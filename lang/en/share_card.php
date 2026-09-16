<?php

/*
 * Every word drawn on the "I'm in!" share card
 * (resources/views/tickets/share-card.blade.php) that is not read off the
 * ticket itself. The attendee's name, batch, party size, registration
 * number and the event facts come from the record; the campaign copy —
 * the shout, the headline, the invitation — is here, per language.
 *
 * The card is drawn in the email channel's language
 * (`config/notifications.php`), which is Bangla by default, so this file
 * is the fallback rather than the usual case.
 */

return [

    'shout' => "I'm in!",
    'kicker' => 'The centennial reunion',
    'headline' => 'A hundred years, a thousand hearts,',
    'headline_accent' => 'friendships that never fade',

    'attendee' => 'Attendee',

    'participant' => [
        'former_student' => 'Alumnus',
        'current_student' => 'Current student',
        'teacher' => 'Teacher',
        'staff' => 'Staff',
        'guardian' => 'Guardian',
        'guest' => 'Guest',
        'sponsor' => 'Sponsor',
    ],

    'about_batch' => ':participant, SSC batch :year.',
    'about_no_batch' => ':participant.',
    'about_party' => 'Party of :count.',

    'fact' => [
        'date' => 'Date',
        'time' => 'Time',
        'venue' => 'Venue',
    ],
    'until' => 'until :time',

    'registration' => 'Registration no.',
    'stub_batch' => 'SSC batch',
    'stub_role' => 'Attending as',
    'admits' => 'Admits :count',

    'invite' => 'Join us. Tickets are on sale.',
    'organiser' => 'Namoshankarbati High School, Chapainawabganj',

];
