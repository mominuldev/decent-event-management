<?php

namespace App\Domain\Ticketing\Contracts;

use DateTimeInterface;

/**
 * What Ticketing needs to know about the scanner fleet in order to decide
 * a key rotation is safe — declared here, implemented in CheckIn.
 *
 * Ticketing must not query check_in_devices itself (the module-boundary
 * rule in CLAUDE.md); an interface is the sanctioned way across, and it
 * keeps "what counts as a synced device" a CheckIn decision.
 */
interface ScannerFleetStatus
{
    /**
     * Devices that could turn a ticket-holder away if a key were activated
     * before they had it.
     *
     * @return array{total: int, synced: int, outstanding: list<array{device_code: string, device_name: string, last_sync_at: ?string}>}
     */
    public function syncStatusSince(DateTimeInterface $publishedAt): array;
}
