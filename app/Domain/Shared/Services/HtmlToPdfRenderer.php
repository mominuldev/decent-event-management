<?php

namespace App\Domain\Shared\Services;

use App\Domain\Shared\Exceptions\PdfRenderingException;
use Illuminate\Support\Str;
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
 */
class HtmlToPdfRenderer
{
    public function render(string $html): string
    {
        $binary = $this->binary();
        $workDir = $this->makeWorkDir();

        $htmlPath = $workDir.'/document.html';
        $pdfPath = $workDir.'/document.pdf';

        try {
            file_put_contents($htmlPath, $html);

            $process = new Process([
                $binary,
                '--headless',
                '--disable-gpu',
                // Required wherever the process cannot create a user
                // namespace, which is the normal case in a container. The
                // input is our own Blade output, never user-supplied markup
                // from the internet, so the sandbox is not the control doing
                // the work here.
                '--no-sandbox',
                // Containers default /dev/shm to 64MB; Chrome will crash
                // rendering a large document without this.
                '--disable-dev-shm-usage',

                // Deliberately NO --user-data-dir. Chrome's current headless
                // mode already isolates its own profile, and handing it an
                // explicit profile directory makes the process render the
                // PDF correctly and then never exit — measured here, and it
                // turns every render into a timeout. Concurrency is fine
                // without it: four simultaneous renders produced four
                // byte-identical files and all exited cleanly.

                // Quieting: nothing here should phone home, check for
                // updates, or look for a default browser on the way to
                // printing one page.
                '--no-first-run',
                '--no-default-browser-check',
                '--disable-extensions',
                '--disable-background-networking',
                '--disable-sync',
                '--disable-component-update',
                '--disable-default-apps',
                '--metrics-recording-only',
                '--mute-audio',

                '--allow-file-access-from-files',
                '--hide-scrollbars',
                // Deterministic glyph rasterisation, so the same input gives
                // the same bytes on a developer machine and in the image.
                '--font-render-hinting=none',
                // A cap on virtual time, not a sleep — it costs nothing and
                // stops a page being printed before its fonts resolve.
                '--virtual-time-budget='.config('pdf.virtual_time_budget'),
                '--no-pdf-header-footer',
                '--print-to-pdf='.$pdfPath,
                'file://'.$htmlPath,
            ], timeout: (float) config('pdf.timeout'));

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
            $this->deleteDirectory($workDir);
        }
    }

    /**
     * The `@font-face` block every PDF template includes. Fonts are
     * referenced as local files rather than inlined as data URIs so the
     * markup does not carry 2.5MB of base64 on every render.
     */
    public function fontFaceCss(): string
    {
        $latin = $this->fontUrl('latin');
        $bengali = $this->fontUrl('bengali');

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
        CSS;
    }

    private function fontUrl(string $key): string
    {
        return 'file://'.resource_path((string) config("pdf.fonts.{$key}"));
    }

    private function binary(): string
    {
        $configured = config('pdf.binary');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        /** @var list<string> $candidates */
        $candidates = (array) config('pdf.binary_candidates', []);

        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        throw PdfRenderingException::noBinary();
    }

    private function makeWorkDir(): string
    {
        $dir = sys_get_temp_dir().'/pdf-'.Str::random(16);

        if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw PdfRenderingException::failed("Could not create a working directory at {$dir}.");
        }

        return $dir;
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
