{{--
    The daily takings sheet: summary tiles, the day grid, and the payment
    method breakdown. Rendered by headless Chrome via HtmlToPdfRenderer —
    see config/pdf.php for why it is not a PHP library.

    Everything here is Latin text and digits, so none of the Bengali
    constraints that govern the ticket and directory templates apply. The
    shared layout still loads both faces; that is deliberate, since a ticket
    type name may well be Bangla.
--}}
@extends('pdf.layout')

@section('styles')
    body { font-size: 8.5pt; }

    .doc-title { font-size: 14pt; font-weight: 600; }
    .doc-meta { font-size: 7.5pt; color: #555555; margin-top: 3px; line-height: 1.5; }
    .rule { border-bottom: 1.2px solid #1a1a1a; margin: 6px 0 9px 0; }

    /* Repeats on every printed page. Carries the document's identity rather
       than a page number: Chrome resolves counter(page) only inside `@page`
       margin boxes, which it does not implement. */
    .running-footer {
        position: fixed;
        /* Inside the content box, not the margin — Chrome clips a fixed
           element positioned outside it and renders nothing at all. */
        bottom: 0; left: 0; right: 0;
        font-size: 6.5pt;
        color: #888888;
        display: flex;
        justify-content: space-between;
    }

    .tiles { display: flex; gap: 4mm; margin-bottom: 7mm; }
    .tile {
        flex: 1;
        border: 0.5pt solid #d8dce2;
        border-radius: 2mm;
        padding: 3mm;
    }
    .tile-label { font-size: 6.5pt; text-transform: uppercase; color: #6b7280; }
    .tile-value { font-size: 12pt; font-weight: 600; margin-top: 1mm; }
    .tile-sub { font-size: 6.5pt; color: #6b7280; margin-top: 0.6mm; }
    .online { color: #0369a1; }
    .offline { color: #b45309; }

    table.grid { width: 100%; border-collapse: collapse; }
    table.grid th {
        font-size: 6.8pt;
        text-transform: uppercase;
        color: #4a4a4a;
        text-align: right;
        padding: 2mm 1.5mm;
        border-bottom: 0.8pt solid #1a1a1a;
        white-space: nowrap;
    }
    table.grid th.left, table.grid td.left { text-align: left; }
    table.grid td {
        text-align: right;
        padding: 1.6mm 1.5mm;
        border-bottom: 0.3pt solid #e5e7eb;
        white-space: nowrap;
    }
    /* A row is never split across a page boundary. */
    table.grid tr { page-break-inside: avoid; break-inside: avoid; }
    /* Repeats the column headings at the top of every printed page — without
       it, page two onward is a wall of unlabelled numbers. */
    table.grid thead { display: table-header-group; }

    tr.total td {
        font-weight: 600;
        border-top: 0.8pt solid #1a1a1a;
        border-bottom: none;
        padding-top: 2mm;
    }
    .muted { color: #9ca3af; }
    .negative { color: #b91c1c; }

    .section-title { font-size: 9.5pt; font-weight: 600; margin: 8mm 0 2mm 0; }
    table.methods { width: 60%; border-collapse: collapse; }
    table.methods td { padding: 1.4mm 1.5mm; border-bottom: 0.3pt solid #e5e7eb; }
    table.methods td.amount { text-align: right; white-space: nowrap; }
    .dot { display: inline-block; width: 1.6mm; height: 1.6mm; border-radius: 50%; margin-right: 1.4mm; }
    .dot-online { background: #0284c7; }
    .dot-offline { background: #d97706; }

    .empty-state { padding: 8mm 2mm; color: #666666; text-align: center; }
@endsection

@section('content')
<div class="running-footer">
    <span>{{ $eventName }} &middot; Daily Sales</span>
    <span>{{ $generatedAt }}</span>
</div>

<div class="doc-title">{{ $eventName }} &middot; Daily Sales</div>
<div class="doc-meta">
    {{ $reportFilters['from'] }} to {{ $reportFilters['to'] }}
    &middot; days close at midnight {{ $reportFilters['timezone'] }}
    &middot; generated {{ $generatedAt }}
    @if (! empty($appliedFilters))
        <br>
        Filters &mdash;
        @foreach ($appliedFilters as $label => $value)
            {{ $label }}: {{ $value }}@if (! $loop->last) &middot; @endif
        @endforeach
    @endif
    @if ($totalDays > count($days))
        <br>
        Showing {{ count($days) }} {{ Str::plural('day', count($days)) }} with activity; {{ $totalDays - count($days) }} with no sales omitted.
    @endif
</div>
<div class="rule"></div>

<div class="tiles">
    <div class="tile">
        <div class="tile-label">Online</div>
        <div class="tile-value online">{{ $money($totals['online_paisa']) }}</div>
        <div class="tile-sub">{{ number_format($totals['online_payments']) }} {{ Str::plural('payment', $totals['online_payments']) }} &middot; {{ number_format($totals['online_persons']) }} {{ Str::plural('person', $totals['online_persons']) }}</div>
    </div>
    <div class="tile">
        <div class="tile-label">Offline</div>
        <div class="tile-value offline">{{ $money($totals['offline_paisa']) }}</div>
        <div class="tile-sub">{{ number_format($totals['offline_payments']) }} {{ Str::plural('payment', $totals['offline_payments']) }} &middot; {{ number_format($totals['offline_persons']) }} {{ Str::plural('person', $totals['offline_persons']) }}</div>
    </div>
    <div class="tile">
        <div class="tile-label">Total taken</div>
        <div class="tile-value">{{ $money($totals['total_paisa']) }}</div>
        <div class="tile-sub">{{ number_format($totals['total_payments']) }} {{ Str::plural('payment', $totals['total_payments']) }} &middot; {{ number_format($totals['total_persons']) }} {{ Str::plural('person', $totals['total_persons']) }}</div>
    </div>
    <div class="tile">
        <div class="tile-label">Net of refunds</div>
        <div class="tile-value">{{ $money($totals['net_paisa']) }}</div>
        <div class="tile-sub">
            @if ($totals['refund_count'] > 0)
                {{ number_format($totals['refund_count']) }} refunded &middot; &minus;{{ $money($totals['refunded_paisa']) }}
            @else
                No refunds in window
            @endif
        </div>
    </div>
</div>

@if (count($days) === 0)
    <div class="empty-state">No sales in this window.</div>
@else
    <table class="grid">
        <thead>
            <tr>
                <th class="left">Date</th>
                <th>Online</th>
                <th>#</th>
                <th>Offline</th>
                <th>#</th>
                <th>Total</th>
                <th>People</th>
                <th>Refunds</th>
                <th>Net</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($days as $day)
                <tr>
                    <td class="left">{{ $day['date'] }}</td>
                    <td>{{ $money($day['online_paisa']) }}</td>
                    <td class="muted">{{ number_format($day['online_payments']) }}</td>
                    <td>{{ $money($day['offline_paisa']) }}</td>
                    <td class="muted">{{ number_format($day['offline_payments']) }}</td>
                    <td>{{ $money($day['total_paisa']) }}</td>
                    <td class="muted">{{ number_format($day['total_persons']) }}</td>
                    <td class="{{ $day['refunded_paisa'] > 0 ? 'negative' : 'muted' }}">
                        {{ $day['refunded_paisa'] > 0 ? '−'.$money($day['refunded_paisa']) : '—' }}
                    </td>
                    <td>{{ $money($day['net_paisa']) }}</td>
                </tr>
            @endforeach
            {{-- Totals span the whole window, including the empty days left
                 out of the grid above — they contribute nothing, so the sum
                 is unaffected and the reader is not asked to add up a column
                 that is missing rows. --}}
            <tr class="total">
                <td class="left">Total</td>
                <td>{{ $money($totals['online_paisa']) }}</td>
                <td>{{ number_format($totals['online_payments']) }}</td>
                <td>{{ $money($totals['offline_paisa']) }}</td>
                <td>{{ number_format($totals['offline_payments']) }}</td>
                <td>{{ $money($totals['total_paisa']) }}</td>
                <td>{{ number_format($totals['total_persons']) }}</td>
                <td class="{{ $totals['refunded_paisa'] > 0 ? 'negative' : '' }}">
                    {{ $totals['refunded_paisa'] > 0 ? '−'.$money($totals['refunded_paisa']) : '—' }}
                </td>
                <td>{{ $money($totals['net_paisa']) }}</td>
            </tr>
        </tbody>
    </table>
@endif

@if (count($methods) > 0)
    <div class="section-title">By payment method</div>
    <table class="methods">
        @foreach ($methods as $method)
            <tr>
                <td>
                    <span class="dot {{ $method['kind'] === 'online' ? 'dot-online' : 'dot-offline' }}"></span>{{ $method['method'] }}
                </td>
                <td class="muted">{{ $method['kind'] }}</td>
                <td class="amount muted">{{ number_format($method['payments']) }} {{ Str::plural('payment', $method['payments']) }}</td>
                <td class="amount">{{ $money($method['paisa']) }}</td>
            </tr>
        @endforeach
    </table>
@endif
@endsection
