<?php

namespace App\Modules\Devices\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Devices\Http\Requests\StorePushTokenRequest;
use App\Modules\Devices\Services\DevicePushTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DevicePushTokenController extends Controller
{
    public function __construct(
        private readonly DevicePushTokenService $pushTokenService,
    ) {}

    public function store(StorePushTokenRequest $request): JsonResponse
    {
        $this->pushTokenService->register(
            $request->user(),
            $request->validated('token'),
            $request->validated('platform'),
        );

        return response()->json(['registered' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        $this->pushTokenService->remove($request->user(), $request->string('token')->toString());

        return response()->json(['removed' => true]);
    }
}
