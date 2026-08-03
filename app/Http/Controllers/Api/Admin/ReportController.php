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

class ReportController extends Controller
{
    public function show(Request $request, string $reportKey): JsonResponse
    {
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

    public function export(ExportReportRequest $request, string $reportKey): JsonResponse
    {
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
