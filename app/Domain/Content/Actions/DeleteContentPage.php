<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Events\ContentChanged;
use App\Domain\Content\Models\ContentPage;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Soft-deletes a page. `content.delete` is Super-Admin-only (config/rbac.php),
 * matching every other `*.delete` in the catalogue, and the row survives so a
 * deleted slug's history stays auditable.
 */
class DeleteContentPage
{
    public function execute(ContentPage $page, User $actor, ?string $ip = null, ?string $requestId = null): void
    {
        DB::transaction(function () use ($page, $actor, $ip, $requestId): void {
            $wasLive = $page->isLive();

            $page->delete();

            ActivityLog::create([
                'log_name' => 'content',
                'event' => 'deleted',
                'description' => "Deleted content page {$page->slug}",
                'causer_type' => $actor->getMorphClass(),
                'causer_id' => $actor->id,
                'subject_type' => $page->getMorphClass(),
                'subject_id' => $page->id,
                'properties' => ['slug' => $page->slug, 'status' => $page->status, 'was_live' => $wasLive],
                'ip_address' => $ip,
                'request_id' => $requestId,
            ]);

            if ($wasLive) {
                ContentChanged::dispatch($page->slug, 'page.deleted');
            }
        });
    }
}
