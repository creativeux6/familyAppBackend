<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Models\User;
use App\Modules\Admin\Services\SystemErrorLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ClientErrorReportController
{
    public function __construct(
        private readonly SystemErrorLogService $logService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status_code' => ['sometimes', 'nullable', 'integer', 'min:100', 'max:599'],
            'method' => ['sometimes', 'nullable', 'string', 'max:16'],
            'path' => ['sometimes', 'nullable', 'string', 'max:512'],
            'message' => ['required', 'string', 'max:2000'],
            'exception_class' => ['sometimes', 'nullable', 'string', 'max:255'],
            'detail' => ['sometimes', 'nullable', 'string', 'max:8000'],
        ]);

        // Optional auth: attach user when a Sanctum token is present.
        $user = Auth::guard('sanctum')->user() ?? $request->user();

        $this->logService->recordClientReport(
            $user instanceof User ? $user : null,
            $data,
            $request->ip(),
        );

        return response()->json(['status' => 'recorded']);
    }
}
