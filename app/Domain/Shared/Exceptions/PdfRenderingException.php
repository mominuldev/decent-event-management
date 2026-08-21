<?php

namespace App\Domain\Shared\Exceptions;

use RuntimeException;

class PdfRenderingException extends RuntimeException
{
    public static function noBinary(): self
    {
        return new self(
            'No Chrome/Chromium binary was found for PDF rendering. Set CHROME_BINARY, or install chromium '
            .'(the production image does this; see config/pdf.php for the paths tried).'
        );
    }

    public static function failed(string $reason): self
    {
        return new self("PDF rendering failed: {$reason}");
    }
}
