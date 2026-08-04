<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_responses_carry_the_docs_06_header_set(): void
    {
        $response = $this->getJson('/api/v1/public/content/faqs');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->assertHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains; preload');
    }

    public function test_csp_is_present_in_testing_but_suppressed_in_local(): void
    {
        $this->getJson('/api/v1/public/content/faqs')
            ->assertHeader('Content-Security-Policy');

        $this->app->detectEnvironment(fn () => 'local');

        $this->getJson('/api/v1/public/content/faqs')
            ->assertHeaderMissing('Content-Security-Policy');

        $this->app->detectEnvironment(fn () => 'testing');
    }

    public function test_csp_is_nonce_based_with_no_unsafe_inline_script_src_outside_local(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $csp = (string) $this->getJson('/api/v1/public/content/faqs')
            ->headers->get('Content-Security-Policy');

        $this->app->detectEnvironment(fn () => 'testing');

        $this->assertNotSame('', $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]{40}'/", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    public function test_each_request_gets_a_fresh_nonce(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $first = $this->getJson('/api/v1/public/content/faqs')->headers->get('Content-Security-Policy');
        $second = $this->getJson('/api/v1/public/content/faqs')->headers->get('Content-Security-Policy');

        $this->app->detectEnvironment(fn () => 'testing');

        $this->assertNotSame($first, $second);
    }

    public function test_the_spa_shells_inline_script_carries_the_same_nonce_as_the_csp_header(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $response = $this->get('/login');
        $csp = (string) $response->headers->get('Content-Security-Policy');
        preg_match("/'nonce-([A-Za-z0-9]{40})'/", $csp, $matches);

        $this->app->detectEnvironment(fn () => 'testing');

        $this->assertNotEmpty($matches);
        $response->assertSee('nonce="'.$matches[1].'"', false);
    }
}
