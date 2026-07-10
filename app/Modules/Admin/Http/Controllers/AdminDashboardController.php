<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Services\AdminDashboardService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class AdminDashboardController extends Controller
{
    public function __construct(
        private readonly AdminDashboardService $dashboardService,
    ) {}

    #[OA\Get(
        path: '/admin/dashboard',
        operationId: 'adminDashboard',
        summary: 'Platform overview metrics',
        tags: ['Admin'],
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Dashboard stats')]
    )]
    public function index(): JsonResponse
    {
        return response()->json($this->dashboardService->stats());
    }
}
