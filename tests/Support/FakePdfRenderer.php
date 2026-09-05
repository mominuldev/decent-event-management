<?php

namespace Tests\Support;

use App\Domain\Shared\Services\HtmlToPdfRenderer;
use Tests\TestCase;

/**
 * Stands in for headless Chrome in the tests that do not assert anything
 * about a rendered PDF.
 *
 * **Why this exists.** Issuing a ticket dispatches `GenerateTicketAssetsJob`,
 * and the suite runs on the `sync` queue driver, so *every* test that puts a
 * registration through payment rendered a real PDF — 32 Chrome invocations in
 * one run, for perhaps three tests that look at the bytes. That is most of
 * the suite's wall clock, and it was the source of its only real flakiness:
 * a render that takes 2.9s alone was measured at 23.6s with eight paratest
 * workers and MySQL competing for the same eight cores, and twice tripped
 * `config('pdf.timeout')` outright, failing a webhook test with a Chrome
 * timeout that had nothing to do with webhooks.
 *
 * Everything except the bytes is still exercised: the job runs, the media
 * rows are written, the signed URLs are minted. What a test loses is proof
 * that Chrome can lay the document out — which is precisely what the three
 * files carrying `$rendersRealPdfs = true` are for, and where the risk
 * actually lives (the Bangla text layer).
 *
 * @see TestCase::$rendersRealPdfs
 */
class FakePdfRenderer extends HtmlToPdfRenderer
{
    /**
     * The HTML each render was handed, so a test can still assert on the
     * document without paying for Chrome to lay it out.
     *
     * @var list<string>
     */
    public array $rendered = [];

    /**
     * A real, minimal, one-page PDF — not a placeholder string. Anything
     * downstream that sniffs the magic bytes, records a size, or hands the
     * file to a browser behaves exactly as it would with the real thing.
     */
    private const string MINIMAL_PDF = <<<'PDF'
        %PDF-1.4
        1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj
        2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj
        3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 420 595]>>endobj
        trailer<</Root 1 0 R>>
        %%EOF
        PDF;

    public function render(string $html): string
    {
        $this->rendered[] = $html;

        return self::MINIMAL_PDF;
    }
}
