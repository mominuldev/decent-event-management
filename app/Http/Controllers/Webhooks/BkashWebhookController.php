<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Payment\Actions\ProcessGatewayWebhook;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BkashWebhookController extends Controller
{
    public function __invoke(Request $request, ProcessGatewayWebhook $processGatewayWebhook): JsonResponse
    {
        $processGatewayWebhook->handle('bkash', $request);

        return response()->json(['status' => 'received']);
    }
}
