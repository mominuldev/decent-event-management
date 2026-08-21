{{--
    The attendee directory: three entries across, each a portrait beside
    Bangla-labelled details. Rendered by headless Chrome — see
    AttendeePdfExportWriter's docblock for what that changed, including why
    bold is used here again after being forbidden under mpdf.

    Previously three files (styles / header / row slices) because mpdf had to
    be fed the body in chunks. Chrome parses the document once, so it is one
    template.
--}}
@extends('pdf.layout')

@section('styles')
    body { font-size: 8pt; }

    .doc-title { font-size: 13pt; font-weight: 600; }
    .doc-meta { font-size: 7.5pt; color: #555555; margin-top: 2px; }
    .rule { border-bottom: 1.2px solid #1a1a1a; margin: 5px 0 7px 0; }

    /* Repeats on every printed page — Chrome paints a fixed element into each
       page box. It carries the document's identity rather than a page number:
       Chrome resolves counter(page) only inside `@page` margin boxes, which it
       does not implement. */
    .running-footer {
        position: fixed;
        /* Inside the page's content box, not in its margin: Chrome clips a
           fixed element positioned outside the content box, so a negative
           offset here renders nothing at all rather than sitting in the
           margin the way it would on screen. */
        bottom: 0;
        left: 0;
        right: 0;
        font-size: 6.5pt;
        color: #888888;
        display: flex;
        justify-content: space-between;
    }

    /* Clears the running footer, which is painted over the bottom of every
       page's content box — without this the last row on a full page prints
       underneath it. */
    table.grid { width: 100%; border-collapse: collapse; margin-bottom: 7mm; }
    table.grid td.cell {
        width: {{ $cellWidth }};
        vertical-align: top;
        /* A hairline under each row. Without it the entries in one row run
           straight into the next row's names, and on a page of 18 cards the
           reader loses track of which details belong to which portrait. */
        border-bottom: 0.4pt solid #d8dce2;
        padding: 5px 5px 6px 0;
    }

    /* An entry is never split across a page boundary — half a person's
       details at the foot of one page is worse than a little whitespace. */
    table.grid tr { page-break-inside: avoid; break-inside: avoid; }

    .entry { display: flex; gap: 3mm; }
    .portrait {
        width: 20mm;
        height: 26.7mm;
        flex: none;
        border: 0.5pt solid #7a7a7a;
        object-fit: cover;
    }
    .entry-details { min-width: 0; }

    .field { font-size: 7.5pt; line-height: 1.42; }
    .field-name { font-size: 9.5pt; line-height: 1.3; padding-bottom: 1px; }
    /* Bold is safe now (Noto Sans Bengali is a variable font carrying every
       weight), so the labels can carry the hierarchy instead of leaning
       entirely on size and colour. */
    .label { color: #4a4a4a; font-weight: 600; }
    .blank { color: #999999; }

    .empty-state { padding: 20px 6px; color: #666666; text-align: center; }
@endsection

@section('content')
<div class="running-footer">
    <span>{{ $eventName }} &middot; Attendee Directory</span>
    <span>{{ $generatedAt }}</span>
</div>

<div class="doc-title">{{ $eventName }} &middot; Attendee Directory</div>
<div class="doc-meta">
    {{ $total }} {{ Str::plural('attendee', $total) }} &middot; generated {{ $generatedAt }}
    @if (! empty($appliedFilters))
        &middot; filters &mdash;
        @foreach ($appliedFilters as $label => $value)
            {{ $label }}: {{ $value }}@if (! $loop->last) &middot; @endif
        @endforeach
    @endif
</div>
<div class="rule"></div>

@if ($total === 0)
    <div class="empty-state">No attendees match these filters.</div>
@else
<table class="grid">
    <tbody>
        @foreach ($rows as $row)
            <tr>
                @foreach ($row as $entry)
                    <td class="cell">
                        <div class="entry">
                            @if ($entry['photo'])
                                <img class="portrait" src="{{ $entry['photo'] }}" alt="">
                            @endif
                            <div class="entry-details">
                                <div class="field field-name">
                                    <span class="label">নাম :</span> {{ $entry['name'] }}
                                </div>
                                <div class="field">
                                    <span class="label">পিতার নাম :</span>
                                    {!! $entry['father_name'] ? e($entry['father_name']) : '<span class="blank">&mdash;</span>' !!}
                                </div>
                                <div class="field">
                                    <span class="label">পেশা :</span>
                                    {!! $entry['occupation'] ? e($entry['occupation']) : '<span class="blank">&mdash;</span>' !!}
                                </div>
                                <div class="field">
                                    <span class="label">পদবীসহ বর্তমান ঠিকানা :</span>
                                    {!! $entry['address'] ? e($entry['address']) : '<span class="blank">&mdash;</span>' !!}
                                </div>
                                <div class="field">
                                    <span class="label">ফোন/মোবাইল :</span>
                                    {!! $entry['mobile'] ? e($entry['mobile']) : '<span class="blank">&mdash;</span>' !!}
                                </div>
                            </div>
                        </div>
                    </td>
                @endforeach

                {{-- Keeps the last row's cards at the same width as every other
                     row's, instead of stretching two cards across the page. --}}
                @for ($i = count($row); $i < $columns; $i++)
                    <td class="cell"></td>
                @endfor
            </tr>
        @endforeach
    </tbody>
</table>
@endif
@endsection
