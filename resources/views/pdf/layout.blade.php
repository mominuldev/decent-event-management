{{--
    Shared chrome for every PDF this system renders (headless Chrome — see
    config/pdf.php for why it is not a PHP library).

    Page size and margins are declared here in `@page` rather than passed to
    the renderer, because that is where Chrome reads them; keeping them in
    two places is how they drift apart.
--}}
<!doctype html>
<html lang="{{ $lang ?? 'en' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'Document' }}</title>
    <style>
        {!! $fontFaceCss !!}

        @page {
            size: {{ $pageSize ?? 'A5' }};
            margin: {{ $pageMargin ?? '12mm' }};
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            /* Latin first, Bengali second: the Bengali face carries no Latin
               glyphs worth using, and this ordering keeps digits and roman
               names in one consistent face across both scripts. */
            font-family: 'AppSans', 'AppBengali', sans-serif;
            color: #1a1a1a;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        img { max-width: 100%; }

@yield('styles')
    </style>
</head>
<body>
@yield('content')
</body>
</html>
