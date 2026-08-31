<?php

namespace App\Domain\Shared\Actions;

use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use Illuminate\Support\Str;

/**
 * A staff member changing their own password.
 *
 * Verifying the *current* password is the caller's job — it maps to a
 * field-level 422 and belongs with the request — but everything that
 * follows lives here, because a change of credentials has three
 * consequences that must not be able to happen apart:
 *
 * 1. Every other session is revoked. A bearer token outlives the moment it
 *    was issued, so a password changed because a laptop was lost achieves
 *    nothing while the tokens minted on it still work. The session doing the
 *    changing is kept, or the person is signed out by their own success.
 * 2. The lockout is cleared. Somebody who has just proved they know the
 *    current password is not the attacker the lockout exists for, and
 *    leaving it set means changing your password locks you out of using it.
 * 3. It is audited (D8: from the Action, so a console or job caller cannot
 *    skip it). The row records that it happened and how many sessions went
 *    with it — never the password, in any form. `activity_logs` is
 *    append-only, is rendered in the admin console, and lands in every
 *    nightly backup.
 */
class ChangeStaffPassword
{
    /**
     * The signed-in path: the person knew their current password, and the
     * session they used to prove it survives.
     *
     * @param  string|int|null  $keepTokenId  the session performing the change, which survives
     */
    public function execute(
        User $user,
        #[\SensitiveParameter] string $newPassword,
        string|int|null $keepTokenId = null,
        ?string $ip = null,
        ?string $requestId = null,
    ): int {
        return $this->apply($user, $newPassword, $keepTokenId, $ip, $requestId, 'password_changed', 'changed their own password');
    }

    /**
     * The forgotten-password path. Keeps nothing: whoever asked for the reset
     * proved only that they can read the mailbox, so every existing session is
     * revoked rather than one being trusted — including, if the account was
     * taken over, the attacker's. It is a distinct audit event for the same
     * reason: "changed while signed in" and "reset from an emailed link" are
     * different facts about how a credential moved.
     */
    public function afterReset(
        User $user,
        #[\SensitiveParameter] string $newPassword,
        ?string $ip = null,
        ?string $requestId = null,
    ): int {
        return $this->apply($user, $newPassword, null, $ip, $requestId, 'password_reset', 'reset their password from an emailed link');
    }

    private function apply(
        User $user,
        #[\SensitiveParameter] string $newPassword,
        string|int|null $keepTokenId,
        ?string $ip,
        ?string $requestId,
        string $event,
        string $description,
    ): int {
        $user->forceFill([
            'password' => $newPassword,
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        $query = $user->tokens();

        if ($keepTokenId !== null) {
            $query->where('id', '!=', $keepTokenId);
        }

        $revoked = $query->delete();

        ActivityLog::create([
            'log_name' => 'user',
            'event' => $event,
            'description' => "{$user->email} {$description}",
            'causer_type' => $user->getMorphClass(),
            'causer_id' => $user->id,
            'subject_type' => $user->getMorphClass(),
            'subject_id' => $user->id,
            'properties' => ['other_sessions_revoked' => $revoked],
            'ip_address' => $ip,
            'request_id' => $requestId ?? (string) Str::ulid(),
            'severity' => 'warning',
        ]);

        return $revoked;
    }
}
