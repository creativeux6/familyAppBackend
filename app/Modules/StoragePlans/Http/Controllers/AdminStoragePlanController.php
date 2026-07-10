<?php

namespace App\Modules\StoragePlans\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\StoragePlans\Http\Requests\AssignStoragePlanRequest;
use App\Modules\StoragePlans\Http\Requests\CreateStoragePlanRequest;
use App\Modules\StoragePlans\Http\Requests\UpdateStoragePlanRequest;
use App\Modules\StoragePlans\Services\PlanAssignmentService;
use App\Modules\StoragePlans\Services\StoragePlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AdminStoragePlanController extends Controller
{
    public function __construct(
        private readonly StoragePlanService $planService,
        private readonly PlanAssignmentService $assignmentService,
    ) {}

    #[OA\Get(path: '/admin/storage/plans', operationId: 'adminStoragePlansList', summary: 'List all storage plans', tags: ['Admin'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Plans')])]
    public function index(): JsonResponse
    {
        return response()->json($this->planService->listAll());
    }

    #[OA\Post(path: '/admin/storage/plans', operationId: 'adminStoragePlansCreate', summary: 'Create storage plan', tags: ['Admin'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 201, description: 'Created')])]
    public function store(CreateStoragePlanRequest $request): JsonResponse
    {
        return response()->json($this->planService->create($request->validated()), 201);
    }

    #[OA\Patch(path: '/admin/storage/plans/{uuid}', operationId: 'adminStoragePlansUpdate', summary: 'Update storage plan', tags: ['Admin'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Updated')])]
    public function update(UpdateStoragePlanRequest $request, string $uuid): JsonResponse
    {
        return response()->json($this->planService->update($uuid, $request->validated()));
    }

    #[OA\Get(path: '/admin/storage/users/{userUuid}/assignment', operationId: 'adminStorageUserAssignment', summary: 'Get user plan assignment', tags: ['Admin'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'userUuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Assignment')])]
    public function userAssignment(string $userUuid): JsonResponse
    {
        $user = $this->planService->requireUser($userUuid);

        return response()->json($this->assignmentService->assignmentForUser($user));
    }

    #[OA\Post(path: '/admin/storage/users/{userUuid}/assign', operationId: 'adminStorageAssignPlan', summary: 'Assign plan to user', tags: ['Admin'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'userUuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Assigned')])]
    public function assign(AssignStoragePlanRequest $request, string $userUuid): JsonResponse
    {
        $user = $this->planService->requireUser($userUuid);
        $plan = $this->planService->requirePlan($request->validated('storage_plan_uuid'));

        $startsAt = $request->date('starts_at') ?? now();
        $endsAt = $request->date('ends_at');

        $assignment = $this->assignmentService->assign(
            $user,
            $plan,
            $request->user(),
            'admin_manual',
            $startsAt,
            $endsAt,
        );

        return response()->json($this->assignmentService->formatAssignment($assignment->load('plan')));
    }

    #[OA\Post(path: '/admin/storage/assignments/{id}/revoke', operationId: 'adminStorageRevokeAssignment', summary: 'Revoke plan assignment', tags: ['Admin'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Revoked')])]
    public function revoke(int $id): JsonResponse
    {
        $assignment = $this->assignmentService->revoke($id);

        return response()->json([
            'message' => 'Assignment revoked.',
            'assignment' => $this->assignmentService->formatAssignment($assignment->load('plan')),
        ]);
    }
}
