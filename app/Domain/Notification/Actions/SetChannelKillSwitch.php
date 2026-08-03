<?php

namespace App\Domain\Notification\Actions;

use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\EventSetting;
use App\Domain\Shared\Models\User;
use App\Jobs\SendNotificationJob;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Flips a per-channel `notification.{channel}_enabled` kill switch
 * (docs/06 §6.7). Read by {@see SendNotificationJob} at
 * send-time, not at outbox-write time, so a flip stops sends already
 * queued within 60 seconds rather than only affecting future ones.
 */
class SetChannelKillSwitch
{
    /** @var array<int, string> */
    private const array VALID_CHANNELS = ['email', 'sms', 'whatsapp'];

    public function execute(string $channel, bool $enabled, User $updatedBy, ?string $ip = null, ?string $requestId = null): EventSetting
    {
        if (! in_array($channel, self::VALID_CHANNELS, true)) {
            throw new InvalidArgumentException("Unsupported notification channel [{$channel}].");
        }

        return DB::transaction(function () use ($channel, $enabled, $updatedBy, $ip, $requestId): EventSetting {
            /** @var EventSetting $setting */
            $setting = EventSetting::query()->where('key', "notification.{$channel}_enabled")->firstOrFail();
            $before = $setting->value;

            $setting->update([
                'value' => $enabled ? '1' : '0',
                'updated_by_user_id' => $updatedBy->id,
            ]);

            ActivityLog::create([
                'log_name' => 'notification',
                'event' => 'kill_switch_updated',
                'description' => ($enabled ? 'Enabled' : 'Disabled')." the {$channel} notification channel",
                'causer_type' => $updatedBy->getMorphClass(),
                'causer_id' => $updatedBy->id,
                'subject_type' => $setting->getMorphClass(),
                'subject_id' => $setting->id,
                'properties' => [
                    'channel' => $channel,
                    'before' => $before,
                    'after' => $setting->value,
                ],
                'ip_address' => $ip,
                'request_id' => $requestId,
            ]);

            return $setting;
        });
    }
}
