<?php

namespace App\Modules\Health\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class HealthController extends Controller
{
    #[OA\Get(
        path: '/health',
        operationId: 'healthCheck',
        summary: 'API health check',
        tags: ['Health'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Service is healthy',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'ok'),
                        new OA\Property(property: 'app', type: 'string'),
                        new OA\Property(property: 'version', type: 'string', example: 'v1'),
                        new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                        new OA\Property(
                            property: 'checks',
                            type: 'object',
                            example: ['database' => 'ok']
                        ),
                    ]
                )
            ),
            new OA\Response(response: 503, description: 'One or more checks failed'),
        ]
    )]
    public function show(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
        ];

        $failed = in_array('fail', $checks, true);

        return response()->json([
            'status' => $failed ? 'degraded' : 'ok',
            'app' => config('app.name'),
            'version' => 'v1',
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
        ], $failed ? 503 : 200);
    }

    private function checkDatabase(): string
    {
        try {
            DB::connection()->getPdo();
            DB::connection()->select('select 1');

            return 'ok';
        } catch (\Throwable) {
            return 'fail';
        }
    }
}
