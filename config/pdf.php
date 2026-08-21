<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PDF rendering engine
    |--------------------------------------------------------------------------
    |
    | Ticket and report PDFs are rendered by headless Chrome rather than a
    | PHP library. This is not a preference — it is a correctness
    | requirement. mpdf's own Bengali OTL engine maps every conjunct glyph
    | to a synthetic private-use codepoint (TTFontFile.php's 0xE000 fallback
    | for glyphs absent from the font cmap) and writes that into the PDF's
    | ToUnicode map, so extractors silently DROP the character: a real name
    | like "মোহাম্মদ রহিম উদ্দিন" came back out as "মাহাদ রিহম উিন".
    | Pre-base vowel signs additionally extracted in visual rather than
    | logical order, which no ToUnicode map can express at all — it needs
    | /ActualText, which mpdf cannot emit.
    |
    | Chrome shapes with HarfBuzz and writes a correct multi-character
    | ToUnicode map. Every case round-trips byte-for-byte (see
    | tests/Feature/Ticketing/BanglaPdfTextLayerTest.php).
    |
    */

    'binary' => env('CHROME_BINARY'),

    /**
     * Tried in order when CHROME_BINARY is unset — the Debian package name
     * the production image installs first, then the usual macOS location so
     * a developer needs no configuration.
     */
    'binary_candidates' => [
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/usr/bin/google-chrome',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
    ],

    /** Seconds before a render is abandoned. The attendee directory export is the slow case. */
    'timeout' => (int) env('PDF_RENDER_TIMEOUT', 120),

    /**
     * How long Chrome is allowed to settle before printing, in milliseconds.
     * Fonts here are local files and there is no script, so this only needs
     * to cover layout — but printing before a webfont resolves would silently
     * produce a page set in a fallback face, so it is not zero.
     */
    'virtual_time_budget' => (int) env('PDF_VIRTUAL_TIME_BUDGET', 5000),

    /*
    |--------------------------------------------------------------------------
    | Fonts
    |--------------------------------------------------------------------------
    |
    | Bundled in the repository rather than installed into the image, so a
    | ticket looks identical on a developer's Mac and in production. A
    | container base image's font package set is not something to leave to
    | chance on a document that gets printed and checked at a gate.
    |
    | Both are variable fonts: one file carries every weight, so bold Bangla
    | works. That is the second defect this replaces — mpdf's bundled
    | FreeSerifBold.ttf has zero Bengali coverage, so bold Bangla did not
    | degrade, it vanished from the page.
    |
    */

    'fonts' => [
        'latin' => 'fonts/NotoSans.ttf',
        'bengali' => 'fonts/NotoSansBengali.ttf',
    ],

];
