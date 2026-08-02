<?php

namespace App\Http\Middleware;

use App\Domain\Shared\Models\EventSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * A volunteer token is only valid within `event_settings.checkin.window_*`
 * — docs/02 §2.2. Absent settings fail open in local/testing so the
 * scanner can be exercised before an Event Manager configures the event.
 */
class EnsureWithinCheckInWindow
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = EventSetting::where('key', 'checkin.window_start')->value('value');
        $end = EventSetting::where('key', 'checkin.window_end')->value('value');

        if ($start && $end && ! now()->between(Carbon::parse($start), Carbon::parse($end))) {
            return response()->json(['message' => 'Outside the check-in window.'], 403);
        }

        return $next($request);
    }
}
