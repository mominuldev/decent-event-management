<?php

namespace Tests\Feature\Config;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * config/cors.php reads its whole allowlist from the environment. Nothing is
 * hardcoded, so pointing the API at the live site is one .env value on the
 * host and a deploy can never overwrite it.
 *
 * The failure mode this guards is silent: a browser refuses the preflight and
 * says so only in its own console, while the server logs a perfectly ordinary
 * 200. Hence assertions on the resolved list rather than on a request.
 */
class CorsOriginsTest extends TestCase
{
    /**
     * @param  array<string, string|null>  $env
     * @return array{allowed_origins: list<string>, allowed_origins_patterns: list<string>}
     */
    private function corsConfigWith(array $env): array
    {
        $previous = [];

        foreach ($env as $key => $value) {
            $existing = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            $previous[$key] = is_string($existing) ? $existing : null;

            $this->putEnvValue($key, $value);
        }

        try {
            /** @var array{allowed_origins: list<string>, allowed_origins_patterns: list<string>} $config */
            $config = require config_path('cors.php');

            return $config;
        } finally {
            foreach ($previous as $key => $value) {
                $this->putEnvValue($key, $value);
            }
        }
    }

    /**
     * Laravel's env repository reads $_ENV, $_SERVER *and* getenv(), so all
     * three have to move together or the old value survives through the one
     * that was left behind.
     */
    private function putEnvValue(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            return;
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    #[Test]
    public function test_the_live_frontend_origin_comes_from_the_environment(): void
    {
        $config = $this->corsConfigWith([
            'APP_ENV' => 'production',
            'FRONTEND_URL' => 'https://100.nsbatihighschool.edu.bd',
            'FRONTEND_URLS' => null,
        ]);

        $this->assertSame(['https://100.nsbatihighschool.edu.bd'], $config['allowed_origins']);
    }

    #[Test]
    public function test_no_localhost_origin_is_hardcoded_in_production(): void
    {
        $config = $this->corsConfigWith([
            'APP_ENV' => 'production',
            'FRONTEND_URL' => 'https://100.nsbatihighschool.edu.bd',
            'FRONTEND_URLS' => null,
        ]);

        $this->assertNotContains('http://localhost:3000', $config['allowed_origins']);
        $this->assertSame([], $config['allowed_origins_patterns']);
    }

    #[Test]
    public function test_local_development_still_reaches_the_api_from_any_dev_port(): void
    {
        $config = $this->corsConfigWith([
            'APP_ENV' => 'local',
            'FRONTEND_URL' => 'http://localhost:3000',
            'FRONTEND_URLS' => null,
        ]);

        // `next dev` moves to 3001, 3002 … when 3000 is taken, which is what
        // the pattern is for — removing the hardcoded origin must not cost it.
        $this->assertMatchesRegularExpression($config['allowed_origins_patterns'][0], 'http://localhost:3007');
        $this->assertMatchesRegularExpression($config['allowed_origins_patterns'][0], 'http://127.0.0.1:3000');
    }

    /**
     * A browser's `Origin` header is scheme://host[:port] and nothing else, so
     * a trailing slash or a path in the env value would sit in the allowlist
     * matching nothing at all.
     */
    #[Test]
    public function test_a_trailing_slash_or_path_is_reduced_to_a_bare_origin(): void
    {
        $config = $this->corsConfigWith([
            'APP_ENV' => 'production',
            'FRONTEND_URL' => 'https://100.nsbatihighschool.edu.bd/tickets/',
            'FRONTEND_URLS' => null,
        ]);

        $this->assertSame(['https://100.nsbatihighschool.edu.bd'], $config['allowed_origins']);
    }

    #[Test]
    public function test_a_port_is_kept_because_it_is_part_of_the_origin(): void
    {
        $config = $this->corsConfigWith([
            'APP_ENV' => 'production',
            'FRONTEND_URL' => 'https://staging.example.com:8443/',
            'FRONTEND_URLS' => null,
        ]);

        $this->assertSame(['https://staging.example.com:8443'], $config['allowed_origins']);
    }

    #[Test]
    public function test_extra_origins_are_comma_separated_and_deduplicated(): void
    {
        $config = $this->corsConfigWith([
            'APP_ENV' => 'production',
            'FRONTEND_URL' => 'https://example.com',
            'FRONTEND_URLS' => 'https://www.example.com , https://example.com/ ,',
        ]);

        $this->assertSame(
            ['https://example.com', 'https://www.example.com'],
            $config['allowed_origins'],
        );
    }

    #[Test]
    public function test_an_unset_frontend_url_allows_nobody_rather_than_everybody(): void
    {
        $config = $this->corsConfigWith([
            'APP_ENV' => 'production',
            'FRONTEND_URL' => null,
            'FRONTEND_URLS' => null,
        ]);

        $this->assertSame([], $config['allowed_origins']);
        $this->assertSame([], $config['allowed_origins_patterns']);
    }
}
