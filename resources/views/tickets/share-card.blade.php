{{--
    The "আমি থাকছি!" share card — an 878×713 image an attendee can post the
    moment their ticket is confirmed. Built to the Figma frame
    "Event Ticket — v7" (Centennial Celebration — Home Page, node 227:731);
    every dimension, colour and weight below is read off that frame.

    Rendered by headless Chrome through `HtmlToImageRenderer`, never shown
    in a browser: the layout is absolute and fixed-size on purpose, since
    the output is a bitmap and nothing here needs to reflow. Fonts and
    images are local files (`file://`), the same way the ticket PDF loads
    its fonts, so the card is identical on a developer's machine and in
    the production image.

    The die-cut ticket outline is the frame's own path. It is used three
    ways — as a `clip-path` on the paper and stub layers, and inline as
    SVG where a clipped div cannot do the job (the soft shadow, which has
    to follow the notches, and the ghost ticket's hairline inner stroke).
--}}
@php
    $dieCut = 'M539 0C539 9.38884 546.611 17 556 17C565.389 17 573 9.38884 573 0H762C771.941 1.19832e-05 780 8.05888 780 18V412C780 421.941 771.941 430 762 430H573C573 420.611 565.389 413 556 413C546.611 413 539 420.611 539 430H18C8.05889 430 0 421.941 0 412V18C0 8.05887 8.05887 2.05357e-07 18 0H539Z';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<title>{{ $registrationNumber }}</title>
<style>
    {!! $fontFaceCss !!}

    * { margin: 0; padding: 0; box-sizing: border-box; }
    html, body { width: 878px; height: 713px; overflow: hidden; }
    body {
        font-family: 'AppSerifBengali', 'AppBengali', 'AppSans', serif;
        font-variation-settings: 'wdth' 100;
        -webkit-font-smoothing: antialiased;
        text-rendering: geometricPrecision;
        background: linear-gradient(140.92deg, #0b2170 14.286%, #050e33 85.714%);
        position: relative;
        color: #ffffff;
    }
    .mono { font-family: 'AppMono', 'AppSans', monospace; }

    /* ── Backdrop ─────────────────────────────────────────────── */
    .spotlight {
        position: absolute; left: -10px; top: 30px; width: 900px; height: 620px;
        background: radial-gradient(50% 50% at 50% 50%, rgba(27, 78, 245, 0.35) 0%, rgba(11, 31, 98, 0) 100%);
    }
    .vignette {
        position: absolute; inset: 0;
        background: radial-gradient(381.74px 310px at 435.18px 353.4px, rgba(3, 10, 38, 0) 55%, rgba(3, 10, 38, 0.75) 100%);
    }

    /* ── Top and bottom lines ─────────────────────────────────── */
    .top-line, .bottom-line {
        position: absolute; left: 49px; width: 780px;
        display: flex; justify-content: space-between; white-space: nowrap;
    }
    .top-line { top: 50px; align-items: baseline; }
    .top-line .shout { font-size: 44px; line-height: 60px; font-weight: 700; color: #ffffff; }
    .top-line .kicker { font-size: 16px; line-height: 24px; font-weight: 500; color: rgba(255, 255, 255, 0.7); }
    .bottom-line { top: 638px; align-items: center; }
    .bottom-line .invite { font-size: 15px; line-height: 24px; font-weight: 600; color: #f8a449; }
    .bottom-line .organiser { font-size: 13px; line-height: 20px; font-weight: 400; color: rgba(255, 255, 255, 0.6); }

    /* ── The two tickets ──────────────────────────────────────── */
    .ghost, .ticket { position: absolute; width: 780px; height: 430px; left: 49px; }
    /* Centred at (439, 383) and (439, 365) on the frame — the ghost sits
       18px lower so its corner shows past the front ticket. */
    .ghost { top: 168px; transform: rotate(4.5deg); transform-origin: center; opacity: 0.95; }
    .ticket { top: 150px; transform: rotate(1.5deg); transform-origin: center; }
    /* A long name or venue wraps and the spacer above absorbs it; the
       frame's own copy fits on one line. */
    .attendee, .facts { white-space: normal; }
    .ghost svg, .ticket .shadow { position: absolute; display: block; }
    .ticket .shadow { left: -48px; top: -20px; width: 876px; height: 526px; }

    .layer { position: absolute; top: 0; height: 430px; clip-path: path('{{ $dieCut }}'); }
    .paper {
        left: 0; width: 780px;
        background: linear-gradient(180deg, #ffffff 0%, #f2f4fa 100%);
        box-shadow: inset 0 1px 0 0 #ffffff, inset 0 -1px 0 0 rgba(11, 31, 98, 0.1);
    }
    /* The stub layers share the paper's clip, so the path is offset back
       to the ticket's origin rather than re-cut for a 224px box. */
    .stub-fill, .satin, .stub {
        left: 0; width: 780px; padding-left: 556px;
    }
    .stub-fill {
        background: linear-gradient(124.78deg, #0f2a8a 14.286%, #1642d6 53.571%, #1b4ef5 85.714%) 556px 0 / 224px 430px no-repeat;
    }
    .satin {
        background: linear-gradient(132.19deg, rgba(255, 255, 255, 0.14) 7.3%, rgba(255, 255, 255, 0) 43.8%, rgba(255, 255, 255, 0.06) 80.3%) 556px 0 / 224px 430px no-repeat;
    }
    .edge-band { left: 0; width: 6px; background: #f6850c; }
    .perforation { position: absolute; left: 555px; top: 27px; width: 2px; height: 376px; display: block; }
    .printed-border {
        position: absolute; left: 14px; top: 14px; width: 528px; height: 402px;
        border: 1px solid rgba(11, 31, 98, 0.16); border-radius: 10px;
    }
    .stub-border {
        position: absolute; left: 570px; top: 14px; width: 196px; height: 402px;
        border: 1px solid rgba(255, 255, 255, 0.22); border-radius: 10px;
    }

    /* ── Main (left) ──────────────────────────────────────────── */
    .main {
        position: absolute; left: 0; top: 0; width: 556px; height: 430px;
        padding: 30px 36px 26px 40px;
        display: flex; flex-direction: column; align-items: flex-start;
        white-space: nowrap;
    }
    .wordmark { width: 178px; height: 48px; display: block; object-fit: contain; object-position: left center; }
    .headline { margin-top: 16px; font-size: 34px; line-height: 48px; font-weight: 700; color: #0b1f62; }
    .headline .accent { color: #1642d6; }
    .flex { flex: 1 0 0; min-height: 1px; width: 100%; }
    .label { font-size: 11.5px; line-height: 16px; font-weight: 500; color: #6b7280; }
    .attendee { display: flex; flex-direction: column; gap: 2px; }
    .attendee .name { font-size: 26px; line-height: 38px; font-weight: 600; color: #0b1f62; }
    .attendee .about { font-size: 14px; line-height: 22px; font-weight: 400; color: #5f6b85; }
    .hairline { margin: 12px 0; width: 100%; height: 1px; background: rgba(11, 31, 98, 0.14); }
    .facts { display: flex; align-items: flex-start; width: 100%; }
    .fact { flex: 1 0 0; min-width: 1px; display: flex; flex-direction: column; padding-right: 8px; overflow: hidden; }
    .fact + .vrule + .fact { padding-left: 18px; }
    .fact .value { font-size: 14.5px; line-height: 22px; font-weight: 600; color: #0b1f62; }
    .fact .note { font-size: 12px; line-height: 18px; font-weight: 400; color: #5f6b85; }
    .vrule { flex: 0 0 1px; width: 1px; height: 44px; background: rgba(11, 31, 98, 0.14); }

    /* ── Stub (right) ─────────────────────────────────────────── */
    .stub {
        display: flex; flex-direction: column; align-items: center; justify-content: space-between;
        padding: 22px 20px 26px 576px;
    }
    .registration {
        display: flex; flex-direction: column; align-items: center; gap: 1px;
        padding: 8px 18px 9px; border-radius: 8px; text-align: center; white-space: nowrap;
        background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.22);
    }
    .registration .label { color: rgba(255, 255, 255, 0.72); }
    .registration .number { font-size: 15px; line-height: 22px; font-weight: 500; letter-spacing: 0.6px; color: #ffffff; }
    .seal-box { width: 154px; height: 154px; display: flex; align-items: center; justify-content: center; }
    .seal {
        width: 150px; height: 150px; border-radius: 50%; overflow: hidden;
        background: #ffffff; border: 2px solid #144b9f;
        display: flex; align-items: center; justify-content: center;
        transform: rotate(-1.5deg);
    }
    .seal img { width: 127px; height: 132px; display: block; object-fit: contain; }
    .batch-admits { display: flex; flex-direction: column; align-items: center; gap: 10px; }
    .batch { display: flex; flex-direction: column; align-items: center; text-align: center; white-space: nowrap; }
    .batch .label { font-size: 12px; line-height: 18px; color: rgba(255, 255, 255, 0.75); }
    .batch .year { font-size: 32px; line-height: 42px; font-weight: 700; color: #ffffff; }
    .batch .year.long { font-size: 22px; line-height: 42px; }
    .admits {
        padding: 6px 14px; border-radius: 8px; background: #d06c0a;
        font-size: 14px; line-height: 20px; font-weight: 600; color: #ffffff; white-space: nowrap;
    }
</style>
</head>
<body>

<div class="spotlight"></div>
<div class="vignette"></div>

<div class="top-line">
    <p class="shout">{{ $shout }}</p>
    <p class="kicker">{{ $kicker }}</p>
</div>

{{-- The ticket behind the ticket: the frame's own SVG, so its hairline
     inner stroke follows the notches. --}}
<div class="ghost">
    <svg width="780" height="430" viewBox="0 0 780 430" fill="none" xmlns="http://www.w3.org/2000/svg">
        <mask id="ghost-inside" fill="white"><path d="{{ $dieCut }}"/></mask>
        <path d="{{ $dieCut }}" fill="#0a1b57"/>
        <path d="{{ $dieCut }}" stroke="white" stroke-opacity="0.08" stroke-width="2" mask="url(#ghost-inside)"/>
    </svg>
</div>

<div class="ticket">
    <svg class="shadow" viewBox="0 0 876 526" fill="none" xmlns="http://www.w3.org/2000/svg">
        <g filter="url(#ticket-shadow)">
            <path transform="translate(48 20)" d="{{ $dieCut }}" fill="#0b1f62"/>
        </g>
        <defs>
            <filter id="ticket-shadow" x="0" y="0" width="876" height="526" filterUnits="userSpaceOnUse" color-interpolation-filters="sRGB">
                <feFlood flood-opacity="0" result="bg"/>
                <feColorMatrix in="SourceAlpha" type="matrix" values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0" result="alpha"/>
                <feMorphology radius="8" operator="erode" in="alpha" result="eroded"/>
                <feOffset dy="28"/>
                <feGaussianBlur stdDeviation="28"/>
                <feColorMatrix type="matrix" values="0 0 0 0 0 0 0 0 0 0.0392157 0 0 0 0 0.180392 0 0 0 0.5 0"/>
                <feBlend mode="normal" in2="bg" result="drop"/>
                <feBlend mode="normal" in="SourceGraphic" in2="drop" result="shape"/>
            </filter>
        </defs>
    </svg>

    <div class="layer paper"></div>
    <div class="layer stub-fill"></div>
    <div class="layer satin"></div>
    <svg class="perforation" viewBox="0 0 2 376" xmlns="http://www.w3.org/2000/svg">
        <line x1="1" y1="1" x2="1" y2="375" stroke="#0b1f62" stroke-opacity="0.3" stroke-width="2" stroke-linecap="round" stroke-dasharray="6 8"/>
    </svg>
    <div class="printed-border"></div>
    <div class="stub-border"></div>
    <div class="layer edge-band"></div>

    <div class="main">
        <img class="wordmark" src="{{ $wordmarkUrl }}" alt="">
        <p class="headline">{{ $headline }}<br><span class="accent">{{ $headlineAccent }}</span></p>
        <div class="flex"></div>
        <div class="attendee">
            <p class="label">{{ $attendeeLabel }}</p>
            <p class="name">{{ $attendeeName }}</p>
            <p class="about">{{ $attendeeAbout }}</p>
        </div>
        @if (count($facts) > 0)
        <div class="hairline"></div>
        <div class="facts">
            @foreach ($facts as $index => $fact)
                @if ($index > 0)<div class="vrule"></div>@endif
                <div class="fact">
                    <p class="label">{{ $fact['label'] }}</p>
                    <p class="value">{{ $fact['value'] }}</p>
                    @if (($fact['note'] ?? null) !== null)
                        <p class="note">{{ $fact['note'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
        @endif
    </div>

    <div class="layer stub">
        <div class="registration">
            <p class="label">{{ $registrationLabel }}</p>
            <p class="number mono">{{ $registrationNumber }}</p>
        </div>
        <div class="seal-box">
            <div class="seal"><img src="{{ $logoUrl }}" alt=""></div>
        </div>
        <div class="batch-admits">
            <div class="batch">
                <p class="label">{{ $stubLabel }}</p>
                <p class="year {{ $stubValueIsLong ? 'long' : '' }}">{{ $stubValue }}</p>
            </div>
            <p class="admits">{{ $admits }}</p>
        </div>
    </div>
</div>

<div class="bottom-line">
    <p class="invite">{{ $invite }}</p>
    <p class="organiser">{{ $organiser }}</p>
</div>

</body>
</html>
