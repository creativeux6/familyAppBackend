<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Requests\RegisterRequest;
use App\Modules\Auth\Services\PhoneAuthService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    public function __construct(
        private readonly PhoneAuthService $phoneAuthService,
    ) {}

    #[OA\Post(
        path: '/auth/register',
        operationId: 'authRegister',
        summary: 'Register a new account',
        description: 'Creates a user with phone and password, returns a Sanctum Bearer token.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['phone', 'password', 'password_confirmation', 'display_name'],
                properties: [
                    new OA\Property(property: 'phone', type: 'string', example: '+923001234567'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'secret123'),
                    new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', example: 'secret123'),
                    new OA\Property(property: 'display_name', type: 'string', example: 'Ali Khan'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Registered',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'action', type: 'string', example: 'registered'),
                        new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                        new OA\Property(property: 'access_token', type: 'string'),
                        new OA\Property(property: 'user', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->phoneAuthService->register(
            $request->validated('phone'),
            $request->validated('display_name'),
            $request->validated('password'),
        );

        return response()->json($result, 201);
    }

    #[OA\Post(
        path: '/auth/login',
        operationId: 'authLogin',
        summary: 'Login with phone and password',
        description: 'Authenticates an existing user and returns a Sanctum Bearer token.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['phone', 'password'],
                properties: [
                    new OA\Property(property: 'phone', type: 'string', example: '+923001234567'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'secret123'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Authenticated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'action', type: 'string', example: 'logged_in'),
                        new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                        new OA\Property(property: 'access_token', type: 'string'),
                        new OA\Property(property: 'user', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 422, description: 'Invalid credentials or validation error'),
        ]
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->phoneAuthService->login(
            $request->validated('phone'),
            $request->validated('password'),
        );

        return response()->json($result);
    }
}
