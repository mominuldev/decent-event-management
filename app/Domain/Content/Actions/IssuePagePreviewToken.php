<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentPage;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mints (or rotates) the shared secret that reveals an unpublished page to a
 * reviewer through the public read API.
 *
 * The token is write-only from the model's point of view — it is `$hidden`
 * and outside `$fillable` — so this is the single place it is set, and the
 * returned string is the only time it is ever readable. Rotating invalidates
 * every previously shared preview link, which is the point: a review link
 * handed to an outside reviewer should not stay valid forever.
 */
class IssuePagePreviewToken
{
    public function execute(ContentPage $page, User $actor, ?string $ip = null, ?string $requestId = null): string
    {
        return DB::transaction(function () use ($page, $actor, $ip, $requestId): string {
            $token = Str::random(32);
            $rotated = $page->preview_token !== null;

            $page->forceFill(['preview_token' => $token])->save();

            ActivityLog::create([
                'log_name' => 'content',
                'event' => $rotated ? 'preview_token_rotated' : 'preview_token_issued',
                'description' => ($rotated ? 'Rotated' : 'Issued')." the preview token for content page {$page->slug}",
                'causer_type' => $actor->getMorphClass(),
                'causer_id' => $actor->id,
                'subject_type' => $page->getMorphClass(),
                'subject_id' => $page->id,
                // The token itself is deliberately not logged — the audit
                // trail records that a link was minted, not the secret.
                'properties' => ['slug' => $page->slug, 'rotated' => $rotated],
                'ip_address' => $ip,
                'request_id' => $requestId,
            ]);

            return $token;
        });
    }
}
