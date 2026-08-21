<?php

namespace App\Domain\CheckIn\Services;

use App\Domain\CheckIn\Models\CheckInDevice;
use App\Domain\Ticketing\Contracts\ScannerFleetStatus;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;

class CheckInDeviceFleetStatus implements ScannerFleetStatus
{
    /**
     * A device counts as holding a key once it has completed a manifest
     * fetch after that key was published — the manifest publishes every
     * known public key in `meta.keys`, so any completed sync since then
     * delivered it.
     *
     * "Completed" is load-bearing: ManifestController only stamps
     * last_sync_at once the last row has been written, so a device whose
     * connection dropped mid-sync correctly still counts as outstanding.
     *
     * Only `active` devices are counted. A revoked or retired device is not
     * going to sync again and must not hold a rotation open forever.
     *
     * @return array{total: int, synced: int, outstanding: list<array{device_code: string, device_name: string, last_sync_at: ?string}>}
     */
    public function syncStatusSince(DateTimeInterface $publishedAt): array
    {
        /** @var Collection<int, CheckInDevice> $devices */
        $devices = CheckInDevice::query()
            ->where('status', 'active')
            ->orderBy('device_code')
            ->get(['device_code', 'device_name', 'last_sync_at']);

        $outstanding = [];

        foreach ($devices as $device) {
            $lastSync = $device->last_sync_at;

            if ($lastSync !== null && $lastSync->greaterThanOrEqualTo($publishedAt)) {
                continue;
            }

            $outstanding[] = [
                'device_code' => $device->device_code,
                'device_name' => $device->device_name,
                'last_sync_at' => $lastSync?->toISOString(),
            ];
        }

        return [
            'total' => $devices->count(),
            'synced' => $devices->count() - count($outstanding),
            'outstanding' => $outstanding,
        ];
    }
}
