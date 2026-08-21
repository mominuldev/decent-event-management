<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Attendee export
    |--------------------------------------------------------------------------
    |
    | The attendee export is generated synchronously, inside the request, so
    | that clicking "Export" in the admin console produces a file rather than
    | a job the operator then has to go and find. That trade has a ceiling:
    | both writers hold the whole document in memory before it is handed to
    | the browser, and every row may carry a decoded profile photo.
    |
    | The caps below were measured on this codebase, not guessed at, against a
    | deliberately adversarial fixture: *every* entry carrying a full-size
    | 900x1200 photograph of incompressible noise. A real roster is much
    | cheaper — most records have no photo at all, and the placeholder that
    | stands in for them is rendered once and reused.
    |
    |   xlsx, 5,000 entries —  8.3s, ~145MB peak, 12.9MB file   (mpdf era, unchanged code)
    |   pdf,    500 entries — 23.8s,  ~15MB peak,  7.4MB file   (re-measured after the Chrome move)
    |
    | Read the PDF row carefully before reacting to the wall clock: only
    | **2.8s of that 23.8s is Chrome**. The other 21s is decoding 500 full-size
    | photographs and scaling them down, which is the same work the mpdf
    | implementation did and is untouched by the engine change. Chrome's cost
    | is close to a flat ~2.5s per invocation — measured at 2.6s for 250
    | entries, 2.5s for 500 and 2.8s for 1,000, i.e. process startup, not a
    | per-entry cost. Budget an extra ~10s for the very first render in a
    | fresh container, where the binary and fonts are not yet in page cache.
    |
    | What the move bought here: peak memory fell from ~110MB to ~15MB,
    | because the document is laid out in a separate process instead of being
    | assembled in PHP's heap. That is the constraint that actually governs a
    | synchronous export running alongside other requests.
    |
    | Wall-clock figures are machine-bound and the two rows above were taken
    | on different days; treat the ratios as the signal, not the seconds.
    |
    | Past the cap the request is refused with a 422 that names the limit, so
    | an operator narrows the filters instead of hitting a timeout or an
    | out-of-memory kill halfway through a download.
    |
    | The PDF stays capped at 500. It is a document a person is meant to read
    | — 500 entries is already ~28 landscape pages of directory — and the
    | photo pipeline, not the renderer, is what makes a larger one slow.
    |
    | Raising either ceiling materially means moving the export onto the
    | `reports` queue lane, not just editing these numbers.
    | tests/Feature/Admin/PdfExportBenchmarkTest.php re-derives them:
    | `EXPORT_BENCHMARK=1 php artisan test --filter=PdfExportBenchmark`.
    |
    */

    'attendees' => [

        'max_rows' => [
            'xlsx' => (int) env('EXPORT_ATTENDEES_MAX_XLSX', 5000),
            'pdf' => (int) env('EXPORT_ATTENDEES_MAX_PDF', 500),
        ],

        /*
        | Longest edge, in pixels, of the photo embedded per attendee.
        |
        | The two formats want different things. The spreadsheet shows a small
        | square thumbnail on screen, so 96px is plenty and the stored 128px
        | thumbnail covers it without touching the original. The PDF prints a
        | ~20mm portrait, where 96px would be visibly soft — 200px is about
        | 250dpi at that size, and asking for it makes AttendeeExportPhoto
        | read the full-size original instead of the thumbnail.
        */
        'photo_px' => [
            'xlsx' => (int) env('EXPORT_ATTENDEES_PHOTO_PX_XLSX', 96),
            'pdf' => (int) env('EXPORT_ATTENDEES_PHOTO_PX_PDF', 200),
        ],

        /*
        | How many attendees to load per chunk while building the document.
        | Keeps the Eloquent result set bounded even though the document
        | itself is not.
        */
        'chunk_size' => (int) env('EXPORT_ATTENDEES_CHUNK', 250),
    ],

];
