<?php

namespace Tests\Support;

use App\Domain\Shared\Services\HtmlToImageRenderer;
use Tests\TestCase;

/**
 * Stands in for headless Chrome in the tests that do not look at the
 * rendered share card — the bitmap counterpart of {@see FakePdfRenderer},
 * and there for the same reason: issuing a ticket draws the card as a side
 * effect, and a payment test should not spend seconds in Chrome to assert
 * nothing about a picture.
 *
 * Returns a real, tiny PNG rather than a placeholder string, so the JPEG
 * re-encode, the media row and the CID part downstream all behave as they
 * would with the real thing.
 *
 * @see TestCase::$rendersRealImages
 */
class FakeImageRenderer extends HtmlToImageRenderer
{
    /**
     * The HTML each render was handed, so a test can assert on the
     * document without paying for Chrome to lay it out.
     *
     * @var list<string>
     */
    public array $rendered = [];

    /** A 4×4 opaque PNG in the card's own navy. */
    private const string TINY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAIAAAAmkwkpAAAAFElEQVR4nGPkVixggAEmBiSAmwMAHxcApIqeawoAAAAASUVORK5CYII=';

    public function renderPng(string $html, int $width, int $height, int $scale = 2): string
    {
        $this->rendered[] = $html;

        return (string) base64_decode(self::TINY_PNG, true);
    }
}
