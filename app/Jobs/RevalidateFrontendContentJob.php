<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * POSTs an ISR revalidation request to the public Next.js site.
 *
 * On the `notifications` lane, not `reports`: an editor watching the live
 * site expects the change within seconds, which is that lane's <60s budget.
 * A short retry ladder rather than the notification schedule — a revalidation
 * that is hours late is worthless, and the next edit will re-trigger it.
 */
class RevalidateFrontendContentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly ?string $slug,
        public readonly string $reason,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $url = config('services.frontend.revalidate_url');

        if (! is_string($url) || $url === '') {
            return;
        }

        $secret = config('services.frontend.revalidate_secret');

        Http::asJson()
            ->timeout(10)
            // A shared secret, not a signature: the frontend's only job is to
            // refuse revalidation floods from strangers, and it has no
            // per-request payload worth signing.
            ->withHeaders(is_string($secret) && $secret !== '' ? ['X-Revalidate-Secret' => $secret] : [])
            ->post($url, [
                'slug' => $this->slug,
                'reason' => $this->reason,
            ])
            ->throw();
    }
}
