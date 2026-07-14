<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\AssignRoleRequest;
use App\Modules\Admin\Http\Requests\UpdateAdminUserRequest;
use App\Modules\Admin\Services\AdminUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AdminUserController extends Controller
{
    public function __construct(
        private readonly AdminUserService $userService,
    ) {}

    #[OA\Get(
        path: '/admin/users',
        operationId: 'adminUsersList',
        summary: 'List users',
        tags: ['Admin'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'include_trashed', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 50)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated users')]
    )]
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->userService->list(
            $request->query('search'),
            filter_var($request->query('include_trashed'), FILTER_VALIDATE_BOOLEAN),
            max(1, (int) $request->query('page', 1)),
            min(50, max(1, (int) $request->query('per_page', 20))),
        ));
    }

    #[OA\Get(
        path: '/admin/users/{uuid}',
        operationId: 'adminUsersShow',
        summary: 'Get user detail',
        tags: ['Admin'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'User detail')]
    )]
    public function show(string $uuid): JsonResponse
    {
        return response()->json($this->userService->show($uuid));
    }

    #[OA\Patch(
        path: '/admin/users/{uuid}',
        operationId: 'adminUsersUpdate',
        summary: 'Update user',
        tags: ['Admin'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Updated user')]
    )]
    public function update(UpdateAdminUserRequest $request, string $uuid): JsonResponse
    {
        return response()->json($this->userService->update(
            $request->user(),
            $uuid,
            $request->validated(),
            $request->ip(),
        ));
    }

    #[OA\Delete(
        path: '/admin/users/{uuid}',
        operationId: 'adminUsersBan',
        summary: 'Ban user (soft delete)',
        tags: ['Admin'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'User banned')]
    )]
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        return response()->json($this->userService->ban($request->user(), $uuid, $request->ip()));
    }

    #[OA\Post(
        path: '/admin/users/{uuid}/restore',
        operationId: 'adminUsersRestore',
        summary: 'Restore banned user',
        tags: ['Admin'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'User restored')]
    )]
    public function restore(Request $request, string $uuid): JsonResponse
    {
        return response()->json($this->userService->restore($request->user(), $uuid, $request->ip()));
    }

    #[OA\Post(
        path: '/admin/users/{uuid}/roles',
        operationId: 'adminUsersAssignRole',
        summary: 'Assign role to user',
        tags: ['Admin'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Role assigned')]
    )]
    public function assignRole(AssignRoleRequest $request, string $uuid): JsonResponse
    {
        return response()->json($this->userService->assignRole(
            $request->user(),
            $uuid,
            $request->validated('role'),
            $request->ip(),
        ));
    }

    #[OA\Delete(
        path: '/admin/users/{uuid}/roles/{role}',
        operationId: 'adminUsersRemoveRole',
        summary: 'Remove role from user',
        tags: ['Admin'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'role', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Role removed')]
    )]
    public function removeRole(Request $request, string $uuid, string $role): JsonResponse
    {
        return response()->json($this->userService->removeRole(
            $request->user(),
            $uuid,
            $role,
            $request->ip(),
        ));
    }
}
