@extends('pdf.layout')

@section('styles')
    body { font-size: 10.5pt; }
    .header { text-align: center; border-bottom: 2px solid #1a1a1a; padding-bottom: 8px; margin-bottom: 12px; }
    .event-name { font-size: 16pt; font-weight: 700; }
    .subtitle { font-size: 11pt; color: #444444; margin-top: 2px; }
    table.layout { width: 100%; border-collapse: collapse; }
    table.layout td { vertical-align: top; }
    .details { width: 100%; border-collapse: collapse; }
    .details td { padding: 3px 0; }
    .label { color: #555555; width: 40%; }
    /* Bold Bangla is safe now. Under mpdf it was not: its bundled
       FreeSerifBold.ttf has zero Bengali coverage, so bolding Bangla text
       did not degrade it, it removed it from the page. The bundled Noto
       Sans Bengali is a variable font carrying every weight. */
    .value { font-weight: 700; }
    .qr-cell { text-align: center; width: 40%; padding-left: 12px; }
    .qr-cell img { width: 130px; height: 130px; }
    .qr-caption { font-size: 8pt; color: #555555; margin-top: 4px; }
    .qr-missing { font-size: 9pt; color: #a12; border: 1px dashed #a12; padding: 10px 6px; }
    .photo { width: 70px; height: 70px; border: 1px solid #cccccc; margin-bottom: 8px; object-fit: cover; }
    .footer { margin-top: 16px; border-top: 1px solid #cccccc; padding-top: 6px; font-size: 8pt; color: #666666; }
    .ticket-number { font-size: 9pt; color: #777777; text-align: center; margin-top: 4px; }
@endsection

@section('content')
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
                    <td class="value">{{ $ticket->holder_name_bn ?: $ticket->holder_name }}</td>
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
            @if ($qrDataUri)
                <img src="{{ $qrDataUri }}" alt="QR code" width="130" height="130">
                <div class="qr-caption">Present this code at the gate<br>প্রবেশদ্বারে এই কোডটি দেখান</div>
            @else
                {{-- A ticket with no qr_codes row still renders, but it says so.
                     Rendering the <img> unconditionally puts a broken-image icon
                     where the QR belongs, which reads as a printing fault rather
                     than a ticket that cannot admit anyone. --}}
                <div class="qr-missing">
                    QR code unavailable<br>
                    কিউআর কোড পাওয়া যায়নি
                    <div class="qr-caption">Contact the organising committee before travelling.<br>ভ্রমণের আগে আয়োজক কমিটির সঙ্গে যোগাযোগ করুন।</div>
                </div>
            @endif
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
@endsection
