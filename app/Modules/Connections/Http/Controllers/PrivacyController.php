<?php

namespace App\Modules\Connections\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Connections\Http\Requests\UpdateAnonymityRequest;
use App\Modules\Connections\Services\PrivacyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class PrivacyController extends Controller
{
    public function __construct(
        private readonly PrivacyService $privacyService,
    ) {}

    #[OA\Get(
        path: '/privacy',
        operationId: 'privacySettings',
        summary: 'Get privacy settings',
        tags: ['Privacy'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Privacy settings'),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->privacyService->settings($request->user()));
    }

    #[OA\Patch(
        path: '/privacy/anonymity',
        operationId: 'privacyUpdateAnonymity',
        summary: 'Toggle anonymity mode',
        tags: ['Privacy'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['is_anonymous'],
                properties: [new OA\Property(property: 'is_anonymous', type: 'boolean', example: true)]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Anonymity updated'),
        ]
    )]
    public function updateAnonymity(UpdateAnonymityRequest $request): JsonResponse
    {
        return response()->json(
            $this->privacyService->updateAnonymity(
                $request->user(),
                $request->boolean('is_anonymous'),
            )
        );
    }
}
