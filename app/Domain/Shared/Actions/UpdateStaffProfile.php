<?php

namespace App\Domain\Shared\Actions;

use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use Illuminate\Support\Str;

/**
 * A staff member editing their own name, email or phone.
 *
 * Separate from any admin-managed user CRUD (which does not exist yet, D10):
 * this one never touches roles, status or password, so holding a session is
 * the whole authorisation story and no permission is consulted. A user
 * cannot promote themselves through it because there is nothing here to
 * promote with.
 *
 * The audit row is written here rather than in the controller (D8), and only
 * when something actually changed — a form posted back unedited is not an
 * event, and an audit trail nobody can skim is one nobody reads. `email` is
 * the login identifier, which is the reason this is audited at all.
 */
class UpdateStaffProfile
{
    /**
     * @param  array{name?: string, email?: string, phone?: string|null}  $data
     */
    public function execute(User $user, array $data, ?string $ip = null, ?string $requestId = null): User
    {
        $before = $this->snapshot($user);

        $user->fill($data)->save();

        $after = $this->snapshot($user);

        if ($before === $after) {
            return $user;
        }

        ActivityLog::create([
            'log_name' => 'user',
            'event' => 'profile_updated',
            'description' => "{$user->email} updated their own profile",
            'causer_type' => $user->getMorphClass(),
            'causer_id' => $user->id,
            'subject_type' => $user->getMorphClass(),
            'subject_id' => $user->id,
            'properties' => [
                'before' => $before,
                'after' => $after,
                'changed' => array_keys(array_diff_assoc($after, $before)),
            ],
            'ip_address' => $ip,
            'request_id' => $requestId ?? (string) Str::ulid(),
            // The actor is the subject and nothing privileged moved, so this
            // is a record rather than something to review.
            'severity' => 'info',
        ]);

        return $user;
    }

    /**
     * @return array{name: string, email: string, phone: string|null}
     */
    private function snapshot(User $user): array
    {
        return [
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'phone' => $user->phone,
        ];
    }
}
