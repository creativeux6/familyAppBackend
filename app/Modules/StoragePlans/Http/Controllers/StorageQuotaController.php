<?php

namespace App\Modules\StoragePlans\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\StoragePlans\Services\PlanAssignmentService;
use App\Modules\StoragePlans\Services\PlanBillingService;
use App\Modules\StoragePlans\Services\StoragePlanService;
use App\Modules\StoragePlans\Services\StoragePoolService;
use App\Modules\StoragePlans\Services\StorageQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class StorageQuotaController extends Controller
{
    public function __construct(
        private readonly StorageQuotaService $quotaService,
        private readonly StoragePlanService $planService,
        private readonly StoragePoolService $poolService,
        private readonly PlanAssignmentService $assignmentService,
        private readonly PlanBillingService $billingService,
    ) {}

    #[OA\Get(path: '/storage/quota', operationId: 'storageQuota', summary: 'Get my storage quota and usage', tags: ['StoragePlans'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Quota summary')])]
    public function quota(Request $request): JsonResponse
    {
        return response()->json($this->quotaService->summary($request->user()));
    }

    #[OA\Get(path: '/storage/plans', operationId: 'storagePlansCatalog', summary: 'List active storage plans', tags: ['StoragePlans'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Plan catalog')])]
    public function plans(): JsonResponse
    {
        return response()->json($this->planService->listActive());
    }

    #[OA\Get(path: '/storage/members', operationId: 'storageMembers', summary: 'List current-cycle plan members', tags: ['StoragePlans'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Members')])]
    public function members(Request $request): JsonResponse
    {
        return response()->json($this->poolService->membershipPayload($request->user()));
    }

    #[OA\Post(path: '/storage/members', operationId: 'storageAddMember', summary: 'Add a locked member to the current cycle', tags: ['StoragePlans'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 201, description: 'Added')])]
    public function addMember(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_uuid' => ['required', 'uuid'],
        ]);
        $member = $this->planService->requireUser($data['user_uuid']);
        $this->poolService->addMember($request->user(), $member);

        return response()->json($this->poolService->membershipPayload($request->user()), 201);
    }

    #[OA\Get(path: '/storage/billing', operationId: 'storageBilling', summary: 'Billing status for current plan', tags: ['StoragePlans'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Billing')])]
    public function billing(Request $request): JsonResponse
    {
        $assignment = $this->assignmentService->activeAssignment($request->user());

        return response()->json([
            'billing_status' => $assignment?->billing_status ?? 'active',
            'can_retry' => in_array($assignment?->billing_status, ['past_due', 'media_locked'], true),
            'assignment' => $assignment ? $this->assignmentService->formatAssignment($assignment) : null,
        ]);
    }

    #[OA\Post(path: '/storage/payments/retry', operationId: 'storageRetryPayment', summary: 'Retry a failed plan payment', tags: ['StoragePlans'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Retried')])]
    public function retryPayment(Request $request): JsonResponse
    {
        $assignment = $this->billingService->retryNow($request->user());

        return response()->json([
            'message' => 'Payment succeeded.',
            'assignment' => $this->assignmentService->formatAssignment($assignment),
        ]);
    }

    #[OA\Post(path: '/storage/plan-change', operationId: 'storageChangePlan', summary: 'Request upgrade now or downgrade next cycle', tags: ['StoragePlans'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Changed')])]
    public function changePlan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'storage_plan_uuid' => ['required', 'uuid'],
        ]);
        $plan = $this->planService->requirePlan($data['storage_plan_uuid']);
        $assignment = $this->assignmentService->changePlan($request->user(), $plan, $request->user(), 'payment');

        return response()->json($this->assignmentService->formatAssignment($assignment));
    }

    #[OA\Post(path: '/storage/plan-change/cancel', operationId: 'storageCancelPlanChange', summary: 'Cancel a pending downgrade', tags: ['StoragePlans'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Cancelled')])]
    public function cancelPlanChange(Request $request): JsonResponse
    {
        $assignment = $this->assignmentService->cancelPendingChange($request->user());

        return response()->json($this->assignmentService->formatAssignment($assignment));
    }
}
