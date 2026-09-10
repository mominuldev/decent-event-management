<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Reporting\Actions\ExportDailySales;
use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\Support\DailySalesReport;
use App\Domain\Shared\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DailySalesReportRequest;
use App\Http\Requests\Admin\ExportDailySalesRequest;
use App\Http\Requests\Admin\ExportReportRequest;
use App\Http\Resources\ReportExportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'Reports')]
class ReportController extends Controller
{
    #[OAT\Get(
        path: '/admin/reports/daily-sales',
        summary: 'Daily takings, split into online and offline sales',
        description: <<<'TEXT'
            One row per day in the window, plus totals and a per-payment-method breakdown.

            A sale is **online** when its payment went through a gateway (`payments.channel = online`)
            and **offline** otherwise — counter cash and any other manually-settled payment. A payment
            counts on the day it was paid, in the timezone named by the `report.timezone` setting, not
            the day the registration was created. Refunds are reported against the day they were
            processed rather than folded back into the sale, so a day's takings never change after
            the day has closed.

            Every date in the window is returned, including days with no sales. With no `from`/`to`
            the window is the last 30 days ending today.

            Requires `report.view_revenue`.
            TEXT,
        tags: ['Reports'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\QueryParameter(name: 'from', description: 'First day of the window, inclusive, in the reporting timezone.', required: false, schema: new OAT\Schema(type: 'string', format: 'date')),
            new OAT\QueryParameter(name: 'to', description: 'Last day of the window, inclusive.', required: false, schema: new OAT\Schema(type: 'string', format: 'date')),
            new OAT\QueryParameter(name: 'ssc_batch_year', description: 'Only sales made by attendees of this SSC batch.', required: false, schema: new OAT\Schema(type: 'integer')),
            new OAT\QueryParameter(name: 'ticket_type_ulid', description: 'Only sales against this ticket type.', required: false, schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Daily sales, newest day first.',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                properties: [
                                    new OAT\Property(
                                        property: 'filters',
                                        description: 'The window and filters actually applied, after defaults.',
                                        properties: [
                                            new OAT\Property(property: 'from', type: 'string', format: 'date'),
                                            new OAT\Property(property: 'to', type: 'string', format: 'date'),
                                            new OAT\Property(property: 'timezone', type: 'string', example: 'Asia/Dhaka'),
                                            new OAT\Property(property: 'ssc_batch_year', type: 'integer', nullable: true),
                                            new OAT\Property(property: 'ticket_type_ulid', type: 'string', nullable: true),
                                        ],
                                        type: 'object'
                                    ),
                                    new OAT\Property(property: 'totals', description: 'The same keys as a day row, summed across the window.', type: 'object'),
                                    new OAT\Property(
                                        property: 'methods',
                                        description: 'Takings per payment method, largest first.',
                                        type: 'array',
                                        items: new OAT\Items(properties: [
                                            new OAT\Property(property: 'method', type: 'string', example: 'cash'),
                                            new OAT\Property(property: 'channel', type: 'string', example: 'manual'),
                                            new OAT\Property(property: 'kind', type: 'string', enum: ['online', 'offline']),
                                            new OAT\Property(property: 'payments', type: 'integer'),
                                            new OAT\Property(property: 'paisa', type: 'integer'),
                                        ], type: 'object')
                                    ),
                                    new OAT\Property(
                                        property: 'days',
                                        type: 'array',
                                        items: new OAT\Items(properties: [
                                            new OAT\Property(property: 'date', type: 'string', format: 'date'),
                                            new OAT\Property(property: 'online_payments', type: 'integer'),
                                            new OAT\Property(property: 'online_paisa', type: 'integer'),
                                            new OAT\Property(property: 'online_persons', type: 'integer'),
                                            new OAT\Property(property: 'offline_payments', type: 'integer'),
                                            new OAT\Property(property: 'offline_paisa', type: 'integer'),
                                            new OAT\Property(property: 'offline_persons', type: 'integer'),
                                            new OAT\Property(property: 'total_payments', type: 'integer'),
                                            new OAT\Property(property: 'total_paisa', type: 'integer'),
                                            new OAT\Property(property: 'total_persons', type: 'integer'),
                                            new OAT\Property(property: 'refund_count', type: 'integer'),
                                            new OAT\Property(property: 'refunded_paisa', type: 'integer'),
                                            new OAT\Property(property: 'net_paisa', type: 'integer', description: 'Takings that day less refunds processed that day.'),
                                        ], type: 'object')
                                    ),
                                ],
                                type: 'object'
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 403, description: 'Missing report.view_revenue'),
            new OAT\Response(response: 422, description: 'Invalid dates, or a window longer than 366 days'),
        ]
    )]
    public function dailySales(DailySalesReportRequest $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('report.view_revenue'), Response::HTTP_FORBIDDEN);

        return response()->json([
            'data' => DailySalesReport::build($request->reportFilters()),
        ]);
    }

    #[OAT\Get(
        path: '/admin/reports/daily-sales/export',
        summary: 'Download the daily sales report as CSV, Excel or PDF',
        description: <<<'TEXT'
            Takes the same filters as `GET /admin/reports/daily-sales` and builds the file from
            exactly that report, so a download can never disagree with the screen it was launched
            from. Generated synchronously — the response *is* the file, not a queued job.

            The three formats are different documents, not one document three times:

            - **csv** — one header row and one row per day, nothing else. No totals row and no
              filter preamble: both would break a machine-readable file. Amounts are taka with two
              decimals. The window is in the filename.
            - **xlsx** — a provenance block (window, day boundary, filters), the day grid with a
              bold totals row, and a second sheet breaking takings down by payment method. Money
              cells are numbers so `SUM()` works.
            - **pdf** — a printed takings sheet. Days with no activity are omitted and the header
              says how many were left out.

            Requires `report.view_revenue` **and** the permission for the requested format
            (`report.export_csv`, `report.export_excel` or `report.export_pdf`). Both, because the
            format permission governs taking files out of the system and the revenue permission
            governs seeing this data at all.
            TEXT,
        tags: ['Reports'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\QueryParameter(name: 'format', description: 'File format.', required: true, schema: new OAT\Schema(type: 'string', enum: ['csv', 'xlsx', 'pdf'])),
            new OAT\QueryParameter(name: 'from', description: 'First day of the window, inclusive, in the reporting timezone.', required: false, schema: new OAT\Schema(type: 'string', format: 'date')),
            new OAT\QueryParameter(name: 'to', description: 'Last day of the window, inclusive.', required: false, schema: new OAT\Schema(type: 'string', format: 'date')),
            new OAT\QueryParameter(name: 'ssc_batch_year', description: 'Only sales made by attendees of this SSC batch.', required: false, schema: new OAT\Schema(type: 'integer')),
            new OAT\QueryParameter(name: 'ticket_type_ulid', description: 'Only sales against this ticket type.', required: false, schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'The file, as an attachment. The filename carries the window and the generation timestamp.',
                headers: [
                    new OAT\Header(header: 'Content-Disposition', description: 'attachment; filename="daily-sales-2026-08-13_2026-09-11-20260911-104500.csv"', schema: new OAT\Schema(type: 'string')),
                ],
                content: new OAT\MediaType(mediaType: 'application/octet-stream', schema: new OAT\Schema(type: 'string', format: 'binary'))
            ),
            new OAT\Response(response: 403, description: 'Missing report.view_revenue, or the permission for the requested format'),
            new OAT\Response(response: 422, description: 'Invalid format or dates, or a window longer than 366 days'),
        ]
    )]
    public function exportDailySales(ExportDailySalesRequest $request, ExportDailySales $action): Response
    {
        /** @var 'csv'|'xlsx'|'pdf' $format */
        $format = $request->validated('format');

        $user = $request->user();

        // Both checks, and the order matters for the message an operator
        // gets: not being allowed to see revenue at all is the more
        // fundamental refusal, so it is the one that answers first. The
        // format permission governs taking a file out of the system; the
        // revenue one governs seeing these numbers at all, and holding only
        // the former must not become a way to read what the screen hides.
        abort_unless($user instanceof User && $user->can('report.view_revenue'), Response::HTTP_FORBIDDEN);
        abort_unless($user->can(self::exportPermissionFor($format)), Response::HTTP_FORBIDDEN);

        return $action->execute(
            filters: $request->reportFilters(),
            format: $format,
            actor: $user,
            ipAddress: $request->ip(),
            requestId: $request->header('X-Request-Id'),
        )->response();
    }

    /**
     * The permission a given file format needs, shared by both export
     * endpoints so the two can never disagree about which one governs .xlsx.
     */
    public static function exportPermissionFor(string $format): string
    {
        return match ($format) {
            'pdf' => 'report.export_pdf',
            'xlsx' => 'report.export_excel',
            'csv' => 'report.export_csv',
            default => 'report.export_pdf',
        };
    }

    #[OAT\Get(
        path: '/admin/reports/{reportKey}',
        summary: 'Get report data by key',
        description: 'The required permission varies by reportKey: registrations_by_batch requires report.view_batch_breakdown, sales_by_type requires report.view_registrations, revenue_summary requires report.view_revenue. Any other reportKey falls back to report.view_registrations and returns an empty data array.',
        tags: ['Reports'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(
                name: 'reportKey',
                description: 'Report identifier. Unrecognized keys return an empty data array.',
                schema: new OAT\Schema(type: 'string', enum: ['registrations_by_batch', 'sales_by_type', 'revenue_summary'])
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Report data. Shape depends on reportKey: registrations_by_batch and sales_by_type return an array of rows, revenue_summary returns a single object, unrecognized keys return an empty array.',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'data', description: 'Array of rows or a single summary object, depending on reportKey'),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 403, description: 'Missing the permission required for this reportKey'),
        ]
    )]
    public function show(Request $request, string $reportKey): JsonResponse
    {
        $requiredPermission = match ($reportKey) {
            'registrations_by_batch' => 'report.view_batch_breakdown',
            'sales_by_type' => 'report.view_registrations',
            'revenue_summary' => 'report.view_revenue',
            default => 'report.view_registrations',
        };

        abort_unless((bool) $request->user()?->can($requiredPermission), Response::HTTP_FORBIDDEN);

        $data = match ($reportKey) {
            'registrations_by_batch' => DB::table('attendees')
                ->join('registrations', 'attendees.id', '=', 'registrations.attendee_id')
                ->select('attendees.ssc_batch_year', DB::raw('count(*) as total'))
                ->where('registrations.status', 'confirmed')
                ->groupBy('attendees.ssc_batch_year')
                ->orderBy('attendees.ssc_batch_year', 'desc')
                ->get(),

            'sales_by_type' => DB::table('ticket_types')
                ->select('name', 'code', 'quantity_sold', 'quantity_reserved', 'quantity_total')
                ->get(),

            'revenue_summary' => DB::table('payments')
                ->select(DB::raw('sum(amount_paid_paisa) as total_revenue_paisa'), DB::raw('sum(refunded_paisa) as total_refunded_paisa'))
                ->where('status', 'succeeded')
                ->first(),

            default => [],
        };

        return response()->json(['data' => $data]);
    }

    #[OAT\Post(
        path: '/admin/reports/{reportKey}/export',
        summary: 'Queue an async export of a report',
        description: 'The required permission varies by the requested format: pdf requires report.export_pdf, xlsx requires report.export_excel, csv requires report.export_csv. The export is generated asynchronously; poll the returned export record for status.',
        tags: ['Reports'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(
                name: 'reportKey',
                description: 'Report identifier to export',
                schema: new OAT\Schema(type: 'string', enum: ['registrations_by_batch', 'sales_by_type', 'revenue_summary'])
            ),
        ],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(
                            property: 'format',
                            type: 'string',
                            enum: ['pdf', 'xlsx', 'csv'],
                            required: ['format']
                        ),
                        new OAT\Property(
                            property: 'filters',
                            type: 'array',
                            items: new OAT\Items(type: 'string'),
                            description: 'Optional report filters',
                            nullable: true
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 202,
                description: 'Export queued successfully',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                properties: [
                                    new OAT\Property(property: 'ulid', type: 'string'),
                                    new OAT\Property(property: 'report_key', type: 'string'),
                                    new OAT\Property(property: 'format', type: 'string'),
                                    new OAT\Property(property: 'status', type: 'string'),
                                    new OAT\Property(property: 'row_count', type: 'integer', nullable: true),
                                    new OAT\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
                                    new OAT\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
                                    new OAT\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
                                    new OAT\Property(property: 'download_url', type: 'string', nullable: true, description: 'Populated only once status is completed'),
                                    new OAT\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
                                ],
                                type: 'object'
                            ),
                            new OAT\Property(property: 'message', type: 'string'),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 403, description: 'Missing the permission required for this format'),
            new OAT\Response(response: 422, description: 'Validation failed (format is required and must be one of pdf, xlsx, csv)'),
        ]
    )]
    public function export(ExportReportRequest $request, string $reportKey): JsonResponse
    {
        $requiredPermission = self::exportPermissionFor((string) $request->validated('format'));

        abort_unless((bool) $request->user()?->can($requiredPermission), Response::HTTP_FORBIDDEN);

        /** @var User $user */
        $user = $request->user();

        $export = ReportExport::create([
            'report_key' => $reportKey,
            'format' => $request->validated('format'),
            'filters' => $request->validated('filters') ?? [],
            'status' => 'queued',
            'requested_by_user_id' => $user->id,
        ]);

        return response()->json([
            'data' => new ReportExportResource($export),
            'message' => 'Report export queued successfully.',
        ], 202);
    }
}
