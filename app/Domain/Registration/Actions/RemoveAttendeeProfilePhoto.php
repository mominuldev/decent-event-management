<?php

namespace App\Domain\Registration\Actions;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\MediaFile;

/** Self-service photo removal — the attendee clears their own account photo. */
class RemoveAttendeeProfilePhoto
{
    public function execute(Attendee $attendee, ?string $ip = null, ?string $requestId = null): void
    {
        $previousMediaId = $attendee->profile_photo_media_id;

        if ($previousMediaId === null) {
            return;
        }

        // Queried by the FK directly, not the `profilePhoto` relation — see
        // the matching note in UpdateAttendeeProfilePhoto.
        $previous = MediaFile::find($previousMediaId);

        $attendee->forceFill(['profile_photo_media_id' => null])->save();

        // The thumbnail exists only to stand in for this file; removing the
        // photo without it would leave a small copy of the same photograph
        // still servable through its own signed URL.
        $previous?->thumbnail?->delete();
        $previous?->delete();

        // Audit lives in the Action, not the controller (D8).
        ActivityLog::create([
            'log_name' => 'attendee',
            'event' => 'attendee_photo_removed',
            'description' => "Attendee {$attendee->ulid} removed their profile photo",
            'causer_type' => $attendee->getMorphClass(),
            'causer_id' => $attendee->id,
            'subject_type' => $attendee->getMorphClass(),
            'subject_id' => $attendee->id,
            'properties' => [
                'removed_media_ulid' => $previous?->ulid,
            ],
            'ip_address' => $ip,
            'request_id' => $requestId,
        ]);
    }
}
