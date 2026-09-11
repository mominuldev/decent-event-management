<?php

namespace App\Domain\Notification\Support;

use App\Domain\Shared\Models\EventSetting;

/**
 * Whether a channel is switched on — `notification.{channel}_enabled`.
 *
 * The switch is enforced at **send** time by `SendNotificationJob`, not at
 * enqueue time, so flipping it off cancels rows that are already queued
 * (Phase 5). That stays the single enforcement point; this class exists
 * because a caller sometimes needs to *report* the state as well — an
 * operator resending a ticket while email is off would otherwise press a
 * button, see "queued", and watch the row turn up `cancelled` with
 * nothing on screen explaining why.
 *
 * Reporting is all it does. It must never be used to skip queueing: the
 * row is the record that somebody asked for the message, and the
 * cancellation is the record that the switch stopped it.
 */
class ChannelKillSwitch
{
    public static function key(string $channel): string
    {
        return "notification.{$channel}_enabled";
    }

    public function enabled(string $channel): bool
    {
        $setting = EventSetting::query()->where('key', self::key($channel))->first();

        // No kill-switch row for this channel means nothing is gating it.
        return $setting === null || $setting->typedValue() === true;
    }

    /**
     * @param  array<int, string>  $channels
     * @return array<int, string> the subset that is switched off
     */
    public function disabledAmong(array $channels): array
    {
        return array_values(array_filter($channels, fn (string $channel): bool => ! $this->enabled($channel)));
    }
}
