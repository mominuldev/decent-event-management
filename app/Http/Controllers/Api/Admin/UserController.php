<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignRoleRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'Users')]
class UserController extends Controller
{
    #[OAT\Get(
        path: '/admin/users',
        summary: 'List staff users',
        tags: ['Users'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(name: 'status', in: 'query', description: 'Filter by status', schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'role', in: 'query', description: 'Filter by role name', schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'per_page', in: 'query', description: 'Results per page, capped at 100', schema: new OAT\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'Paginated list of staff users'),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
            new OAT\Response(response: 403, description: 'Missing user.view_any permission'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can('user.view_any'), Response::HTTP_FORBIDDEN);

        $query = User::query()->with('roles');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('role')) {
            $roleName = (string) $request->input('role');
            $query->whereHas('roles', function ($q) use ($roleName): void {
                $q->where('name', $roleName);
            });
        }

        $perPage = min((int) $request->input('per_page', 15), 100);

        return UserResource::collection($query->paginate($perPage));
    }

    #[OAT\Get(
        path: '/admin/users/{user}',
        summary: 'Get a single staff user by ULID',
        tags: ['Users'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'user', description: 'User ULID', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'User details'),
            new OAT\Response(response: 403, description: 'Missing user.view_any permission'),
            new OAT\Response(response: 404, description: 'User not found'),
        ]
    )]
    public function show(Request $request, User $user): UserResource
    {
        abort_unless((bool) $request->user()?->can('user.view_any'), Response::HTTP_FORBIDDEN);

        $user->load('roles');

        return new UserResource($user);
    }

    #[OAT\Post(
        path: '/admin/users/{user}/assign-role',
        summary: 'Replace a staff user\'s role',
        description: 'Roles come only from the versioned catalogue in config/rbac.php — this endpoint assigns an existing role, it never creates one.',
        tags: ['Users'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'user', description: 'User ULID', schema: new OAT\Schema(type: 'string')),
        ],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(property: 'role', type: 'string', required: ['role']),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 200, description: 'Role assigned'),
            new OAT\Response(response: 403, description: 'Missing user.assign_role permission'),
            new OAT\Response(response: 404, description: 'User not found'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function assignRole(AssignRoleRequest $request, User $user): UserResource
    {
        $validated = $request->validated();

        $oldRoles = $user->roles()->pluck('name')->all();

        $user->syncRoles([$validated['role']]);

        $requestId = substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26);

        ActivityLog::create([
            'log_name' => 'user',
            'event' => 'role_assigned',
            'description' => 'User role assigned',
            'causer_type' => $request->user()?->getMorphClass(),
            'causer_id' => $request->user()?->id,
            'subject_type' => $user->getMorphClass(),
            'subject_id' => $user->id,
            'properties' => ['old_roles' => $oldRoles, 'new_roles' => [$validated['role']]],
            'ip_address' => $request->ip(),
            'request_id' => $requestId,
        ]);

        return new UserResource($user->load('roles'));
    }
}
