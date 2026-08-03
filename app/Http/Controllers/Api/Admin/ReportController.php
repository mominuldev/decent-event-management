<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Shared\Models\User;
use App\Http\Controllers\Controller;
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
        $requiredPermission = match ($request->validated('format')) {
            'pdf' => 'report.export_pdf',
            'xlsx' => 'report.export_excel',
            'csv' => 'report.export_csv',
            default => 'report.export_pdf',
        };

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
