<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Services\PhoneAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class SessionController extends Controller
{
    public function __construct(
        private readonly PhoneAuthService $phoneAuthService,
    ) {}

    #[OA\Post(
        path: '/auth/logout',
        operationId: 'authLogout',
        summary: 'Logout current session',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Logged out'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function logout(Request $request): JsonResponse
    {
        $this->phoneAuthService->logout($request->user());

        return response()->json(['message' => 'Logged out successfully.']);
    }

    #[OA\Post(
        path: '/auth/refresh',
        operationId: 'authRefresh',
        summary: 'Refresh access token',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'New token issued'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function refresh(Request $request): JsonResponse
    {
        $result = $this->phoneAuthService->refresh($request->user());

        return response()->json($result);
    }
}
