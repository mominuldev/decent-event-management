<?php

namespace App\Http\Concerns;

use App\Domain\Shared\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The three things every audited admin write needs to hand its Action: who,
 * from where, and under which request id. Repeated inline in the older admin
 * controllers; new code pulls it from here instead.
 */
trait ResolvesRequestContext
{
    /**
     * `activity_logs.request_id` is a 26-char column, so a long or absent
     * upstream header still yields a usable correlation id.
     */
    protected function requestId(Request $request): string
    {
        return substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26);
    }

    /**
     * The acting staff member. Routes reaching this are behind
     * `auth:web-admin`, so a missing user is a routing mistake, not a
     * client error — 401 rather than a null deref further down.
     */
    protected function actor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        return $user;
    }
}
