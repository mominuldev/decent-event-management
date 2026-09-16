<?php

namespace App\Domain\Shared\Exceptions;

use RuntimeException;

/**
 * A headless-Chrome bitmap render that produced nothing usable. Shares
 * {@see PdfRenderingException::noBinary()} for the missing-binary case,
 * since it is the same binary.
 */
class ImageRenderingException extends RuntimeException
{
    public static function failed(string $reason): self
    {
        return new self("Image rendering failed: {$reason}");
    }
}
