<?php

namespace Tests;

use App\Domain\Shared\Services\HtmlToPdfRenderer;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakePdfRenderer;

abstract class TestCase extends BaseTestCase
{
    /**
     * Whether this test class needs headless Chrome to actually lay a
     * document out. Almost none do: a PDF is rendered as a *side effect* of
     * issuing a ticket, so a webhook or payment test would otherwise spend
     * several seconds in Chrome to assert nothing about the result.
     *
     * Set it to true only when the test reads the rendered bytes — the
     * Bangla text layer, an export's extracted text, a benchmark. See
     * {@see FakePdfRenderer} for what a faked render still exercises, and
     * why leaving this false everywhere else is what makes the suite
     * deterministic rather than merely faster.
     */
    protected bool $rendersRealPdfs = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->rendersRealPdfs) {
            $this->app->instance(HtmlToPdfRenderer::class, new FakePdfRenderer);
        }
    }
}
