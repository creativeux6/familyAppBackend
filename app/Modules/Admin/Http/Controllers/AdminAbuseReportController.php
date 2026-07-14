<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\UpdateAbuseReportRequest;
use App\Modules\Admin\Services\AbuseReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AdminAbuseReportController extends Controller
{
    public function __construct(
        private readonly AbuseReportService $abuseReportService,
    ) {}

    #[OA\Get(
        path: '/admin/abuse-reports',
        operationId: 'adminAbuseReportsList',
        summary: 'List abuse reports',
        tags: ['Admin'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['open', 'reviewing', 'resolved', 'dismissed'])),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 50)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated abuse reports')]
    )]
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->abuseReportService->list(
            $request->query('status'),
            max(1, (int) $request->query('page', 1)),
            min(50, max(1, (int) $request->query('per_page', 20))),
        ));
    }

    #[OA\Patch(
        path: '/admin/abuse-reports/{uuid}',
        operationId: 'adminAbuseReportsUpdate',
        summary: 'Update abuse report status',
        tags: ['Admin'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Updated report')]
    )]
    public function update(UpdateAbuseReportRequest $request, string $uuid): JsonResponse
    {
        return response()->json([
            'report' => $this->abuseReportService->updateStatus(
                $request->user(),
                $uuid,
                $request->validated('status'),
                $request->ip(),
            ),
        ]);
    }
}
