<?php

namespace App\Domain\Shared\Services;

use App\Domain\Shared\Exceptions\PdfRenderingException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Renders HTML to PDF with headless Chrome. The single place this system
 * turns markup into a PDF — see config/pdf.php for why it is Chrome and not
 * a PHP library (short version: mpdf silently dropped Bengali conjuncts from
 * the extractable text layer, and no ToUnicode map can fix pre-base vowel
 * reordering).
 *
 * Page size and margins come from the document's own `@page` CSS rather
 * than from arguments here, because that is where Chrome reads them and
 * splitting the page setup across two places is how they drift.
 *
 * The Chrome invocation itself (binary, common flags, working directory)
 * lives in {@see HeadlessChrome}, shared with the bitmap renderer.
 */
class HtmlToPdfRenderer
{
    public function __construct(private readonly HeadlessChrome $chrome = new HeadlessChrome) {}

    public function render(string $html): string
    {
        $workDir = $this->chrome->makeWorkDir();

        $htmlPath = $workDir.'/document.html';
        $pdfPath = $workDir.'/document.pdf';

        try {
            file_put_contents($htmlPath, $html);

            $process = new Process([
                ...$this->chrome->baseArguments(),
                '--no-pdf-header-footer',
                '--print-to-pdf='.$pdfPath,
                'file://'.$htmlPath,
            ], timeout: $this->chrome->timeout());

            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                throw PdfRenderingException::failed('Chrome timed out after '.config('pdf.timeout').'s.');
            }

            if (! is_file($pdfPath)) {
                // Chrome's exit code is not reliable here — it returns 0 in
                // some failure modes — so the produced file is the test.
                throw PdfRenderingException::failed(trim($process->getErrorOutput()) ?: 'Chrome produced no output file.');
            }

            $pdf = file_get_contents($pdfPath);

            if ($pdf === false || $pdf === '') {
                throw PdfRenderingException::failed('Chrome produced an empty file.');
            }

            return $pdf;
        } finally {
            $this->chrome->deleteDirectory($workDir);
        }
    }

    /**
     * The `@font-face` block every Chrome-rendered template includes. Fonts
     * are referenced as local files rather than inlined as data URIs so the
     * markup does not carry several megabytes of base64 on every render.
     *
     * Declaring a face costs nothing until a rule uses it, so this is the
     * one registry for every bundled font — the PDFs use the two sans
     * faces, the share card the serif and the mono.
     */
    public function fontFaceCss(): string
    {
        $latin = $this->fontUrl('latin');
        $bengali = $this->fontUrl('bengali');
        $bengaliSerif = $this->fontUrl('bengali_serif');
        $mono = $this->fontUrl('mono');

        return <<<CSS
        @font-face {
            font-family: 'AppSans';
            src: url('{$latin}') format('truetype');
            font-weight: 100 900;
            font-style: normal;
        }
        @font-face {
            font-family: 'AppBengali';
            src: url('{$bengali}') format('truetype');
            font-weight: 100 900;
            font-style: normal;
        }
        @font-face {
            font-family: 'AppSerifBengali';
            src: url('{$bengaliSerif}') format('truetype');
            font-weight: 100 900;
            font-style: normal;
        }
        @font-face {
            font-family: 'AppMono';
            src: url('{$mono}') format('truetype');
            font-weight: 100 800;
            font-style: normal;
        }
        CSS;
    }

    private function fontUrl(string $key): string
    {
        return 'file://'.resource_path((string) config("pdf.fonts.{$key}"));
    }
}
