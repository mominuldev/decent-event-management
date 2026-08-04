<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Listeners\RevalidateFrontendContent;
use App\Jobs\RevalidateFrontendContentJob;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * {@see RevalidateFrontendContent} is covered
 * by ContentRevalidationTest via `Queue::fake()`, which proves the job gets
 * *queued* with the right slug/reason but never actually runs `handle()`.
 * This covers the job itself: the HTTP call it makes, the shared-secret
 * header, and that a no-op stays a no-op even if a straggler job somehow
 * gets dispatched while the hook is unconfigured.
 */
class RevalidateFrontendContentJobTest extends TestCase
{
    public function test_it_posts_the_slug_and_reason_with_the_shared_secret_header(): void
    {
        config([
            'services.frontend.revalidate_url' => 'https://site.example.com/api/revalidate',
            'services.frontend.revalidate_secret' => 'sh4red-secret',
        ]);

        Http::fake(['site.example.com/*' => Http::response(['revalidated' => true])]);

        (new RevalidateFrontendContentJob('about-us', 'page.published'))->handle();

        Http::assertSent(function (ClientRequest $request): bool {
            return $request->url() === 'https://site.example.com/api/revalidate'
                && $request['slug'] === 'about-us'
                && $request['reason'] === 'page.published'
                && $request->hasHeader('X-Revalidate-Secret', 'sh4red-secret');
        });
    }

    public function test_a_null_slug_is_sent_as_is_for_shared_collections(): void
    {
        config(['services.frontend.revalidate_url' => 'https://site.example.com/api/revalidate']);

        Http::fake(['site.example.com/*' => Http::response(['revalidated' => true])]);

        (new RevalidateFrontendContentJob(null, 'sponsor.created'))->handle();

        Http::assertSent(fn (ClientRequest $request): bool => $request['slug'] === null && $request['reason'] === 'sponsor.created');
    }

    public function test_no_secret_header_is_sent_when_none_is_configured(): void
    {
        config([
            'services.frontend.revalidate_url' => 'https://site.example.com/api/revalidate',
            'services.frontend.revalidate_secret' => null,
        ]);

        Http::fake(['site.example.com/*' => Http::response(['revalidated' => true])]);

        (new RevalidateFrontendContentJob('about-us', 'page.published'))->handle();

        Http::assertSent(fn (ClientRequest $request): bool => ! $request->hasHeader('X-Revalidate-Secret'));
    }

    public function test_it_is_a_no_op_when_the_hook_is_unconfigured(): void
    {
        config(['services.frontend.revalidate_url' => null]);

        Http::fake();

        (new RevalidateFrontendContentJob('about-us', 'page.published'))->handle();

        Http::assertNothingSent();
    }

    /**
     * `->throw()` on a failed response is what makes the queue's retry
     * ladder (3 tries, 10s/60s backoff) actually kick in — swallow the
     * failure here and a broken frontend endpoint would silently never
     * revalidate again.
     */
    public function test_a_failed_response_throws_so_the_queue_retries(): void
    {
        config(['services.frontend.revalidate_url' => 'https://site.example.com/api/revalidate']);

        Http::fake(['site.example.com/*' => Http::response(['error' => 'boom'], 500)]);

        $this->expectException(RequestException::class);

        (new RevalidateFrontendContentJob('about-us', 'page.published'))->handle();
    }
}
