<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AdminAuditLogController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    #[OA\Get(
        path: '/admin/audit-logs',
        operationId: 'adminAuditLogsList',
        summary: 'List audit logs',
        tags: ['Admin'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'action', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'actor_user_uuid', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 50)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated audit logs')]
    )]
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->auditLogService->list(
            $request->query('action'),
            $request->query('actor_user_uuid'),
            max(1, (int) $request->query('page', 1)),
            min(50, max(1, (int) $request->query('per_page', 20))),
        ));
    }
}
