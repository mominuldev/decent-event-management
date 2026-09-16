<?php

namespace App\Domain\Shared\Services;

use App\Domain\Shared\Exceptions\ImageRenderingException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Renders HTML to a bitmap with headless Chrome — the share card an
 * attendee posts once their ticket is confirmed. The bitmap sibling of
 * {@see HtmlToPdfRenderer}, built on the same {@see HeadlessChrome}
 * invocation, and for the same reason: Chrome shapes Bangla with HarfBuzz,
 * and GD cannot shape Bangla at all.
 *
 * The document is laid out at exactly `$width × $height` CSS pixels and
 * captured at `$scale` device pixels per CSS pixel, so a template written
 * against a fixed frame comes out pixel-for-pixel as designed, sharp on a
 * phone.
 */
class HtmlToImageRenderer
{
    public function __construct(private readonly HeadlessChrome $chrome = new HeadlessChrome) {}

    /**
     * @return string PNG bytes
     */
    public function renderPng(string $html, int $width, int $height, int $scale = 2): string
    {
        $workDir = $this->chrome->makeWorkDir();

        $htmlPath = $workDir.'/document.html';
        $pngPath = $workDir.'/document.png';

        try {
            file_put_contents($htmlPath, $html);

            $process = new Process([
                ...$this->chrome->baseArguments(),
                "--window-size={$width},{$height}",
                "--force-device-scale-factor={$scale}",
                '--screenshot='.$pngPath,
                'file://'.$htmlPath,
            ], timeout: $this->chrome->timeout());

            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                throw ImageRenderingException::failed('Chrome timed out after '.config('pdf.timeout').'s.');
            }

            if (! is_file($pngPath)) {
                // Chrome's exit code is not reliable here — it returns 0 in
                // some failure modes — so the produced file is the test.
                throw ImageRenderingException::failed(trim($process->getErrorOutput()) ?: 'Chrome produced no output file.');
            }

            $png = file_get_contents($pngPath);

            if ($png === false || $png === '') {
                throw ImageRenderingException::failed('Chrome produced an empty file.');
            }

            return $png;
        } finally {
            $this->chrome->deleteDirectory($workDir);
        }
    }
}
