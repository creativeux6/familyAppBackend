<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Services\SystemErrorLogService;
use App\Modules\Admin\Services\WebSocketHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSystemLogController extends Controller
{
    public function __construct(
        private readonly SystemErrorLogService $logService,
        private readonly WebSocketHealthService $webSocketHealthService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->logService->list(
            $request->query('path'),
            $request->query('status_code') !== null && $request->query('status_code') !== ''
                ? (int) $request->query('status_code')
                : null,
            $request->query('user_uuid'),
            $request->query('from'),
            $request->query('to'),
            $request->query('q') ?? $request->query('search'),
            max(1, (int) $request->query('page', 1)),
            min(20, max(1, (int) $request->query('per_page', 20))),
        ));
    }

    public function statusCodes(): JsonResponse
    {
        return response()->json([
            'status_codes' => $this->logService->distinctStatusCodes(),
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        return response()->json($this->logService->show($uuid));
    }

    public function websocketHealth(): JsonResponse
    {
        return response()->json($this->webSocketHealthService->check());
    }
}
