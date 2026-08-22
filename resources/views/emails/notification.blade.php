{{--
    The design shell every outbound email is rendered inside.

    The *body* is whatever `notifications.body_rendered` holds — copy an
    Event Manager may edit in the admin console, interpolated from the
    template row. Everything structural around it (masthead, ticket card,
    QR panel, notes strip, call to action, footer) is code, deliberately:
    it carries the QR a ticket-holder is admitted with, and a mis-edited
    template must never be able to break or remove it.

    Palette is the public site's own design system verbatim
    (centennial-celebration/src/app/globals.css — brand/purple-600 #7c3aed,
    purple-700 #6d28d9, purple-100 #ede9fe, ink/heading #3d1d7a), so a
    confirmation email and the page it was bought on read as one product.

    Email HTML rules that look like clutter but are not:
      - Tables and inline styles, because Outlook renders through Word.
      - A <style> block is additive only (Gmail honours it, some clients
        drop it) — nothing load-bearing lives there except the one
        small-screen breakpoint.
      - Icons and the QR travel as inline CID parts, not remote URLs: a
        blocked remote image leaves a broken box where the design was, and
        a signed URL would expire long before this email is opened.
      - This design is committed to light. `color-scheme: light` asks
        clients not to auto-invert, because a scanner reads a dark module
        on a light quiet zone — an inverted QR will not scan at the gate.
--}}
@php
    // Memoised so an icon used twice does not travel twice.
    $cids = [];
    $icon = function (string $name) use ($message, $icons, &$cids): ?string {
        if (! isset($icons[$name])) {
            return null;
        }

        return $cids[$name] ??= $message->embedData($icons[$name], $name.'.png', 'image/png');
    };

    $qrSrc = $qrPng !== null ? $message->embedData($qrPng, 'ticket-qr.png', 'image/png') : null;
    // The counterfoil is the half that identifies the ticket; without a
    // number *and* without a code there is nothing to put in it, so the
    // stub takes the whole card rather than leaving a dangling perforation.
    $hasCounterfoil = $qrSrc !== null || $ticketId !== null;
    $hasCard = $hasCounterfoil || count($facts) > 0;

    $sans = "'Noto Sans Bengali','Hind Siliguri','Segoe UI',Roboto,Helvetica,Arial,sans-serif";
    $serif = "Georgia,'Times New Roman','Noto Serif Bengali',serif";
    $mono = "'SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace";
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $subject }}</title>
<!--[if mso]>
<noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
<![endif]-->
<style>
    :root { color-scheme: light; supported-color-schemes: light; }
    body, table, td, p, a, span { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table { border-collapse: collapse !important; }
    img { border: 0; line-height: 100%; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }

    /* The editable body: template copy arrives as bare <p>/<strong>/<a>. */
    .hero-copy p { margin: 0 0 10px; }
    .hero-copy p:last-child { margin-bottom: 0; }
    .hero-copy strong { color: #ffffff; font-weight: 700; }
    .hero-copy a { color: #c4b5fd; }
    .hero-copy ul, .hero-copy ol { margin: 0 0 10px; padding-left: 20px; }

    @media only screen and (max-width: 620px) {
        .shell { width: 100% !important; }
        .gutter { padding-left: 22px !important; padding-right: 22px !important; }
        .headline { font-size: 28px !important; }
        /* The ticket splits top/bottom instead of left/right, and the
           perforation turns with it. */
        .stack { display: block !important; width: 100% !important; }
        .perforation { border-left: 0 !important; border-top: 2px dashed #ded8ef !important; padding-left: 0 !important; padding-top: 24px !important; margin-top: 24px !important; }
        .note-cell { display: block !important; width: 100% !important; border-left: 0 !important; border-top: 1px solid #e6e0f7 !important; }
    }
</style>
</head>
<body style="margin:0; padding:0; width:100%; background-color:#f5f3fb;">

{{-- Inbox preview line. Kept off-screen so it never renders twice. --}}
<div style="display:none; font-size:1px; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden; mso-hide:all;">
    {{ $preheader }}&#8203;&#847;&#847;&#847;&#847;&#847;&#847;&#847;&#847;&#847;&#847;&#847;&#847;&#847;&#847;&#847;&#847;
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f5f3fb;">
<tr>
<td align="center" style="padding:26px 12px 34px;">

    <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" class="shell" style="width:600px; max-width:600px;">

        {{-- ── Hero ─────────────────────────────────────────────────── --}}
        <tr>
            <td class="gutter" bgcolor="#1c1033" style="background-color:#1c1033; background-image:linear-gradient(135deg,#241243 0%,#150b28 55%,#2a1257 100%); border-radius:{{ $hasCard ? '16px 16px 0 0' : '16px' }}; padding:30px 36px 32px;">

                <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        @if ($icon('mark') !== null)
                            <td width="44" style="width:44px; padding-right:12px;">
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="44" style="width:44px;">
                                    <tr>
                                        <td align="center" bgcolor="#7c3aed" height="44" style="background-color:#7c3aed; border-radius:12px; height:44px;">
                                            <img src="{{ $icon('mark') }}" width="22" height="22" alt="" style="display:block; width:22px; height:22px;">
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        @endif
                        <td style="vertical-align:middle;">
                            <p style="margin:0; font-family:{{ $sans }}; font-size:15px; line-height:1.3; font-weight:700; color:#ffffff;">{{ $eventName }}</p>
                            @if ($mastheadKicker !== null)
                                <p style="margin:3px 0 0; font-family:{{ $sans }}; font-size:11.5px; line-height:1.35; text-transform:uppercase; color:#a78bfa;">{{ $mastheadKicker }}</p>
                            @endif
                        </td>
                    </tr>
                </table>

                <p class="headline" style="margin:26px 0 0; font-family:{{ $serif }}; font-size:34px; line-height:1.18; font-weight:700; color:#ffffff;">
                    {{ $headline }}@if ($headlineAccent !== null)<br><span style="color:#b998fb;">{{ $headlineAccent }}</span>@endif
                </p>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 0;">
                    <tr><td bgcolor="#7c3aed" height="3" width="48" style="background-color:#7c3aed; height:3px; width:48px; line-height:3px; font-size:0; border-radius:2px;">&nbsp;</td></tr>
                </table>

                <div class="hero-copy" style="margin:18px 0 0; font-family:{{ $sans }}; font-size:14.5px; line-height:1.65; color:#c9c2dd;">
                    {!! $bodyHtml !!}
                </div>
            </td>
        </tr>

        @if ($hasCard)
            {{-- ── The ticket ───────────────────────────────────────── --}}
            <tr>
                <td class="gutter" bgcolor="#ffffff" style="background-color:#ffffff; border-radius:0 0 16px 16px; padding:30px 30px 32px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                        <tr>
                            {{-- Stub: what the ticket is for --}}
                            <td class="stack" width="{{ $hasCounterfoil ? '54%' : '100%' }}" style="width:{{ $hasCounterfoil ? '54%' : '100%' }}; vertical-align:top; padding-right:{{ $hasCounterfoil ? '24px' : '0' }};">

                                @if ($cardTitle !== null)
                                    <p style="margin:0; font-family:{{ $sans }}; font-size:11.5px; line-height:1.35; text-transform:uppercase; font-weight:700; color:#7c3aed;">{{ $cardEyebrow }}</p>
                                    <p style="margin:6px 0 0; font-family:{{ $serif }}; font-size:23px; line-height:1.25; font-weight:700; color:#3d1d7a;">{{ $cardTitle }}</p>
                                    @if ($cardSubtitle !== null)
                                        <p style="margin:7px 0 0; font-family:{{ $sans }}; font-size:14px; line-height:1.5; color:#6b7280;">{{ $cardSubtitle }}</p>
                                    @endif
                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 4px;"><tr>
                                        <td bgcolor="#ede9fe" height="1" width="56" style="background-color:#ede9fe; height:1px; width:56px; line-height:1px; font-size:0;">&nbsp;</td>
                                    </tr></table>
                                @endif

                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                    @foreach ($facts as $fact)
                                        <tr>
                                            <td width="40" style="width:40px; padding:12px 12px 0 0; vertical-align:top;">
                                                @if ($icon($fact['icon']) !== null)
                                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="38" style="width:38px;"><tr>
                                                        <td align="center" bgcolor="#f3efff" height="38" style="background-color:#f3efff; border-radius:10px; height:38px;">
                                                            <img src="{{ $icon($fact['icon']) }}" width="19" height="19" alt="" style="display:block; width:19px; height:19px;">
                                                        </td>
                                                    </tr></table>
                                                @endif
                                            </td>
                                            <td style="padding:12px 0 0; vertical-align:top;">
                                                <p style="margin:0; font-family:{{ $sans }}; font-size:11.5px; line-height:1.35; text-transform:uppercase; font-weight:700; color:#7c3aed;">{{ $fact['label'] }}</p>
                                                <p style="margin:4px 0 0; font-family:{{ $sans }}; font-size:14.5px; line-height:1.45; font-weight:700; color:#1f2937;">{{ $fact['value'] }}</p>
                                                @if (($fact['note'] ?? null) !== null)
                                                    <p style="margin:2px 0 0; font-family:{{ $sans }}; font-size:13px; line-height:1.45; color:#9ca3af;">{{ $fact['note'] }}</p>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            </td>

                            {{-- Counterfoil: the code that admits --}}
                            @if ($hasCounterfoil)
                            <td class="stack perforation" width="46%" style="width:46%; vertical-align:top; border-left:2px dashed #ded8ef; padding-left:24px;">
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #ece8f8; border-radius:14px;">
                                        @if ($ticketId !== null)
                                            <tr>
                                                <td align="center" bgcolor="#6d28d9" style="background-color:#6d28d9; background-image:linear-gradient(135deg,#7c3aed 0%,#5b21b6 100%); border-radius:13px 13px 0 0; padding:14px 12px 16px;">
                                                    <p style="margin:0; font-family:{{ $sans }}; font-size:11px; line-height:1.35; text-transform:uppercase; color:#ddd0ff;">{{ $ticketIdLabel }}</p>
                                                    <p style="margin:6px 0 0; font-family:{{ $mono }}; font-size:14px; line-height:1.3; font-weight:700; color:#ffffff; white-space:nowrap;">{{ $ticketId }}</p>
                                                </td>
                                            </tr>
                                        @endif
                                        @if ($qrSrc !== null)
                                            <tr>
                                                <td align="center" bgcolor="#ffffff" style="background-color:#ffffff; border-radius:{{ $ticketId !== null ? '0 0 13px 13px' : '13px' }}; padding:18px 16px 20px;">
                                                    <img src="{{ $qrSrc }}" width="184" height="184" alt="{{ $qrAlt }}" style="display:block; width:184px; height:184px;">
                                                </td>
                                            </tr>
                                        @endif
                                    </table>

                                    @if ($qrSrc !== null)
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 0;">
                                        <tr>
                                            <td width="38" style="width:38px; padding-right:12px; vertical-align:top;">
                                                @if ($icon('ticket') !== null)
                                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="38" style="width:38px;"><tr>
                                                        <td align="center" bgcolor="#f3efff" height="38" style="background-color:#f3efff; border-radius:19px; height:38px;">
                                                            <img src="{{ $icon('ticket') }}" width="19" height="19" alt="" style="display:block; width:19px; height:19px;">
                                                        </td>
                                                    </tr></table>
                                                @endif
                                            </td>
                                            <td style="vertical-align:top;">
                                                <p style="margin:0; font-family:{{ $sans }}; font-size:11.5px; line-height:1.35; text-transform:uppercase; font-weight:700; color:#7c3aed;">{{ $qrHeading }}</p>
                                                <p style="margin:4px 0 0; font-family:{{ $sans }}; font-size:13px; line-height:1.55; color:#6b7280;">{!! nl2br(e($qrCaption)) !!}</p>
                                            </td>
                                        </tr>
                                    </table>
                                    @endif
                            </td>
                            @endif
                        </tr>
                    </table>
                </td>
            </tr>
        @endif

        @if (count($notes) > 0)
            {{-- ── Before you travel ────────────────────────────────── --}}
            <tr>
                <td style="padding:18px 0 0;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f2eefc" style="background-color:#f2eefc; border-radius:16px;">
                        <tr>
                            @foreach ($notes as $index => $note)
                                <td class="note-cell" width="25%" align="center" style="width:25%; vertical-align:top; padding:22px 14px 24px; border-left:{{ $index === 0 ? '0' : '1px' }} solid #e6e0f7;">
                                    @if ($icon($note['icon']) !== null)
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="42" style="width:42px; margin:0 auto 10px;"><tr>
                                            <td align="center" bgcolor="#ffffff" height="42" style="background-color:#ffffff; border-radius:21px; height:42px;">
                                                <img src="{{ $icon($note['icon']) }}" width="20" height="20" alt="" style="display:block; width:20px; height:20px;">
                                            </td>
                                        </tr></table>
                                    @endif
                                    <p style="margin:0; font-family:{{ $sans }}; font-size:11.5px; line-height:1.4; text-transform:uppercase; font-weight:700; color:#6d28d9;">{{ $note['label'] }}</p>
                                    <p style="margin:6px 0 0; font-family:{{ $sans }}; font-size:12.5px; line-height:1.6; color:#4b5563;">{!! nl2br(e($note['text'])) !!}</p>
                                </td>
                            @endforeach
                        </tr>
                    </table>
                </td>
            </tr>
        @endif

        @if ($ctaUrl !== null)
            <tr>
                <td align="center" style="padding:26px 24px 0;">
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                        <tr>
                            <td align="center" bgcolor="#6d28d9" style="background-color:#6d28d9; border-radius:10px;">
                                <a href="{{ $ctaUrl }}" target="_blank" rel="noopener"
                                   style="display:inline-block; padding:15px 32px; font-family:{{ $sans }}; font-size:14.5px; font-weight:700; line-height:1; color:#ffffff; text-decoration:none; border-radius:10px;">{{ $ctaLabel }}</a>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        @endif

        @if ($supportLine !== null)
            <tr>
                <td align="center" style="padding:22px 24px 0;">
                    <p style="margin:0; font-family:{{ $sans }}; font-size:13.5px; line-height:1.4; font-weight:700; color:#3d1d7a;">{{ $supportHeading }}</p>
                    <p style="margin:5px 0 0; font-family:{{ $sans }}; font-size:13px; line-height:1.6; color:#6b7280;">{{ $supportLine }}</p>
                </td>
            </tr>
        @endif

        {{-- ── Footer ───────────────────────────────────────────────── --}}
        <tr>
            <td style="padding:26px 0 0;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#1c1033" style="background-color:#1c1033; border-radius:16px;">
                    <tr>
                        <td class="stack gutter" width="58%" style="width:58%; vertical-align:top; padding:24px 26px;">
                            <p style="margin:0; font-family:{{ $sans }}; font-size:14px; line-height:1.35; font-weight:700; color:#ffffff;">{{ $eventName }}</p>
                            <p style="margin:6px 0 0; font-family:{{ $sans }}; font-size:12px; line-height:1.55; color:#9d94b8;">{{ $footerTagline }}</p>
                        </td>
                        <td class="stack gutter" width="42%" style="width:42%; vertical-align:top; padding:24px 26px 24px 0;">
                            <p style="margin:0; font-family:{{ $sans }}; font-size:12px; line-height:1.6; color:#9d94b8;">&copy; {{ $year }} {{ $eventName }}<br>{{ $rightsLine }}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        <tr>
            <td align="center" style="padding:18px 24px 0;">
                <p style="margin:0; font-family:{{ $sans }}; font-size:11.5px; line-height:1.7; color:#8b8699;">
                    {!! nl2br(e($footerNote)) !!}<br>
                    {{ $footerAddressLine }}
                </p>
            </td>
        </tr>

    </table>

</td>
</tr>
</table>

</body>
</html>
