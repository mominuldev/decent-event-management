<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OAT;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'Roles')]
class RoleController extends Controller
{
    #[OAT\Get(
        path: '/admin/roles',
        summary: 'List the roles in the RBAC catalogue and their permissions',
        description: 'Read-only. Roles and permissions are seeded exclusively from config/rbac.php — there is no endpoint to create or edit them ad hoc.',
        tags: ['Roles'],
        security: [['bearerAuth' => []]],
        responses: [
            new OAT\Response(response: 200, description: 'List of roles'),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
            new OAT\Response(response: 403, description: 'Missing role.view_any permission'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can('role.view_any'), Response::HTTP_FORBIDDEN);

        $roles = Role::query()->with('permissions')->where('guard_name', config('rbac.guard'))->get();

        return RoleResource::collection($roles);
    }
}
