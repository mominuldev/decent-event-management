<?php

namespace App\Domain\Ticketing\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;

/**
 * Renders the signed QR payload as a PNG (docs/08 Phase 6: "ECC level M,
 * 512px, generous quiet zone" — a version-7-ish symbol at this size and
 * error-correction level scans reliably from a cracked screen or a print).
 */
class RenderTicketQrImage
{
    private const int SIZE_PX = 512;

    private const int MARGIN_MODULES = 4;

    public function render(string $payload): string
    {
        $renderer = new GDLibRenderer(self::SIZE_PX, self::MARGIN_MODULES, 'png');
        $writer = new Writer($renderer);

        return $writer->writeString($payload, Encoder::DEFAULT_BYTE_MODE_ENCODING, ErrorCorrectionLevel::M());
    }
}
