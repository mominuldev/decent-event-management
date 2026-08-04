<style>
    body { font-family: freeserif; font-size: 10.5pt; color: #1a1a1a; }
    .header { text-align: center; border-bottom: 2px solid #1a1a1a; padding-bottom: 8px; margin-bottom: 12px; }
    .event-name { font-size: 16pt; font-weight: bold; }
    .subtitle { font-size: 11pt; color: #444444; margin-top: 2px; }
    table.layout { width: 100%; border-collapse: collapse; }
    table.layout td { vertical-align: top; }
    .details td { padding: 3px 0; }
    .label { color: #555555; width: 40%; }
    .value { font-weight: bold; }
    /* FreeSerifBold.ttf (mpdf's bundled bold weight) has zero glyph coverage
       for the Bengali block — bolding Bangla text does not degrade it, it
       makes it disappear outright (confirmed via hb-shape: every codepoint
       maps to .notdef). holder_name_bn is the one dynamic field here that
       is genuinely Bangla script, so it opts back out of the bold weight. */
    .value.bn-value { font-weight: normal; }
    .qr-cell { text-align: center; width: 40%; padding-left: 12px; }
    .qr-cell img { width: 130px; height: 130px; }
    .qr-caption { font-size: 8pt; color: #555555; margin-top: 4px; }
    .photo { width: 70px; height: 70px; border: 1px solid #cccccc; margin-bottom: 8px; }
    .footer { margin-top: 16px; border-top: 1px solid #cccccc; padding-top: 6px; font-size: 8pt; color: #666666; }
    .ticket-number { font-size: 9pt; color: #777777; text-align: center; margin-top: 4px; }
</style>

<div class="header">
    <div class="event-name">{{ $eventName }}</div>
    <div class="subtitle">Admission Ticket &middot; প্রবেশপত্র</div>
</div>

<table class="layout">
    <tr>
        <td>
            @if ($photoDataUri)
                <img class="photo" src="{{ $photoDataUri }}" alt="" width="70" height="70">
            @endif
            <table class="details">
                <tr>
                    <td class="label">Holder &middot; ধারণকারী</td>
                    <td class="value{{ $ticket->holder_name_bn ? ' bn-value' : '' }}">{{ $ticket->holder_name_bn ?: $ticket->holder_name }}</td>
                </tr>
                @if ($ticket->holder_batch_year)
                    <tr>
                        <td class="label">Batch &middot; ব্যাচ</td>
                        <td class="value">{{ $ticket->holder_batch_year }}</td>
                    </tr>
                @endif
                <tr>
                    <td class="label">Ticket type &middot; টিকিটের ধরন</td>
                    <td class="value">{{ $ticketTypeName }}</td>
                </tr>
                <tr>
                    <td class="label">Admits &middot; প্রবেশাধিকার</td>
                    <td class="value">{{ $ticket->admits_total }}</td>
                </tr>
                @if ($sessionName)
                    <tr>
                        <td class="label">Session &middot; অধিবেশন</td>
                        <td class="value">{{ $sessionName }}</td>
                    </tr>
                @endif
                @if ($sessionWhen)
                    <tr>
                        <td class="label">When &middot; কখন</td>
                        <td class="value">{{ $sessionWhen }}</td>
                    </tr>
                @endif
                @if ($sessionVenue)
                    <tr>
                        <td class="label">Venue &middot; স্থান</td>
                        <td class="value">{{ $sessionVenue }}</td>
                    </tr>
                @endif
            </table>
        </td>
        <td class="qr-cell">
            <img src="{{ $qrDataUri }}" alt="QR code" width="130" height="130">
            <div class="qr-caption">Present this code at the gate<br>প্রবেশদ্বারে এই কোডটি দেখান</div>
        </td>
    </tr>
</table>

<div class="ticket-number">{{ $ticket->ticket_number }}</div>

<div class="footer">
    This ticket is not transferable. It must be presented, digitally or printed, at the gate for admission.
    Report a lost or damaged ticket to the organising committee for reissue.
    <br>
    এই টিকিট হস্তান্তরযোগ্য নয়। প্রবেশের জন্য গেটে এটি (ডিজিটাল বা প্রিন্ট করা) দেখাতে হবে।
    হারিয়ে গেলে বা নষ্ট হলে পুনরায় ইস্যুর জন্য আয়োজক কমিটিকে জানান।
</div>
