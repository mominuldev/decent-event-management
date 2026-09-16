<?php

namespace App\Domain\Shared\Services;

use App\Domain\Shared\Exceptions\PdfRenderingException;
use Illuminate\Support\Str;

/**
 * What {@see HtmlToPdfRenderer} and {@see HtmlToImageRenderer} share: which
 * Chrome binary to run, the flags every headless invocation needs, and a
 * throwaway working directory for the document and its output.
 *
 * Kept as one class so the two renderers cannot drift on the flags that
 * matter — `--no-sandbox` in a container, no `--user-data-dir` (Chrome
 * renders and then never exits with one; measured, not guessed), and
 * `--font-render-hinting=none` for byte-identical output across machines.
 */
final class HeadlessChrome
{
    public function binary(): string
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

    /**
     * The flags common to every render, before the output-specific ones.
     *
     * @return list<string>
     */
    public function baseArguments(): array
    {
        return [
            $this->binary(),
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
            // output correctly and then never exit — measured here, and it
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
            // stops a page being rendered before its fonts resolve.
            '--virtual-time-budget='.config('pdf.virtual_time_budget'),
        ];
    }

    public function timeout(): float
    {
        return (float) config('pdf.timeout');
    }

    public function makeWorkDir(): string
    {
        $dir = sys_get_temp_dir().'/chrome-'.Str::random(16);

        if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw PdfRenderingException::failed("Could not create a working directory at {$dir}.");
        }

        return $dir;
    }

    public function deleteDirectory(string $dir): void
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
