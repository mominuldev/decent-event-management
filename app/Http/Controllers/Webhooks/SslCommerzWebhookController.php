<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Payment\Actions\ProcessGatewayWebhook;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SslCommerzWebhookController extends Controller
{
    public function __invoke(Request $request, ProcessGatewayWebhook $processGatewayWebhook): JsonResponse
    {
        $processGatewayWebhook->handle('sslcommerz', $request);

        return response()->json(['status' => 'received']);
    }
}
