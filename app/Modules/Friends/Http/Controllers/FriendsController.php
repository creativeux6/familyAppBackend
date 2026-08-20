<?php

namespace App\Modules\Friends\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Friends\Http\Requests\SyncFriendsRequest;
use App\Modules\Friends\Services\FriendsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class FriendsController extends Controller
{
    public function __construct(
        private readonly FriendsService $friendsService,
    ) {}

    #[OA\Post(
        path: '/friends/sync',
        operationId: 'friendsSync',
        summary: 'Sync hashed phone contacts and return registered matches',
        tags: ['Friends'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['phone_hashes'],
                properties: [
                    new OA\Property(
                        property: 'phone_hashes',
                        type: 'array',
                        items: new OA\Items(type: 'string'),
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Registered contacts on the app'),
            new OA\Response(response: 429, description: 'Too many sync attempts'),
        ]
    )]
    public function sync(SyncFriendsRequest $request): JsonResponse
    {
        return response()->json(
            $this->friendsService->sync(
                $request->user(),
                $request->validated('phone_hashes'),
            )
        );
    }

    #[OA\Get(
        path: '/friends',
        operationId: 'friendsList',
        summary: 'List registered matches from last contact sync',
        tags: ['Friends'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Registered contacts on the app'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->friendsService->list($request->user())
        );
    }
}
