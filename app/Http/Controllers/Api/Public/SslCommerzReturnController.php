<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'Public')]
class SslCommerzReturnController extends Controller
{
    private const array ALLOWED_STATUSES = ['success', 'fail', 'cancel'];

    #[OAT\Get(
        path: '/public/payments/sslcommerz/return/{status}',
        summary: 'Browser return target for a SSLCommerz payment session',
        description: 'SSLCommerz redirects (or form-POSTs) the payer\'s browser here after a hosted-page '
            .'session — never the IPN, which arrives separately at `/webhooks/sslcommerz`. This handler reads '
            .'nothing from the database and writes nothing: it only re-derives the frontend return page from '
            .'server-side config (`FRONTEND_URL`) and redirects the browser there, per docs/06 §6.6 — a browser '
            .'return proves nothing and must never transition a payment. The `next` query parameter is honoured '
            .'only when its host matches the configured frontend origin, so this cannot be turned into an open '
            .'redirect by a hand-crafted request.',
        tags: ['Public'],
        parameters: [
            new OAT\Parameter(
                name: 'status',
                in: 'path',
                required: true,
                description: 'Which return leg the gateway hit',
                schema: new OAT\Schema(type: 'string', enum: ['success', 'fail', 'cancel'])
            ),
            new OAT\Parameter(
                name: 'next',
                in: 'query',
                description: 'Frontend return URL, set server-side at session creation; honoured only if its host matches FRONTEND_URL',
                schema: new OAT\Schema(type: 'string')
            ),
        ],
        responses: [
            new OAT\Response(response: 302, description: 'Redirect to the frontend return page'),
        ]
    )]
    public function __invoke(Request $request, string $status): RedirectResponse
    {
        abort_unless(in_array($status, self::ALLOWED_STATUSES, true), Response::HTTP_NOT_FOUND);

        $next = $request->query('next');
        $target = is_string($next) && $this->isAllowedRedirectTarget($next) ? $next : $this->fallbackFrontendUrl();

        $separator = str_contains($target, '?') ? '&' : '?';

        return redirect()->away("{$target}{$separator}payment_status={$status}");
    }

    private function isAllowedRedirectTarget(string $url): bool
    {
        $frontendHost = parse_url((string) config('services.frontend.url'), PHP_URL_HOST);
        $targetHost = parse_url($url, PHP_URL_HOST);

        return is_string($frontendHost) && $frontendHost !== ''
            && is_string($targetHost) && $targetHost !== ''
            && strcasecmp($frontendHost, $targetHost) === 0;
    }

    private function fallbackFrontendUrl(): string
    {
        return rtrim((string) config('services.frontend.url'), '/');
    }
}
