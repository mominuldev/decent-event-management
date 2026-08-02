<?php

namespace App\Http\Middleware;

use App\Domain\Shared\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generic replay protection (docs/03 §3.26). A same-key/different-body
 * retry is rejected; a same-key/same-body retry replays the cached
 * response verbatim. Route usage: `->middleware('idempotent:payment.initiate')`.
 */
class EnsureIdempotency
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! $key) {
            return response()->json(['message' => 'Idempotency-Key header is required.'], 400);
        }

        $requestHash = hash('sha256', $request->getContent());

        $existing = IdempotencyKey::where('key', $key)->first();

        if ($existing) {
            if ($existing->request_hash !== $requestHash) {
                return response()->json(['message' => 'Idempotency-Key reused with a different request body.'], 409);
            }

            if ($existing->completed_at !== null) {
                return response()->json($existing->response_body, $existing->response_status ?? 200);
            }

            return response()->json(['message' => 'A request with this Idempotency-Key is already in progress.'], 409);
        }

        $record = IdempotencyKey::create([
            'key' => $key,
            'scope' => $scope,
            'request_hash' => $requestHash,
            'locked_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);

        /** @var Response $response */
        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $record->forceFill([
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getData(true),
                'completed_at' => now(),
            ])->save();
        }

        return $response;
    }
}
