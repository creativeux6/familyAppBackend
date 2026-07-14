<?php

namespace App\Modules\StoragePlans\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\StoragePlans\Services\StoragePlanService;
use App\Modules\StoragePlans\Services\StorageQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class StorageQuotaController extends Controller
{
    public function __construct(
        private readonly StorageQuotaService $quotaService,
        private readonly StoragePlanService $planService,
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
}
