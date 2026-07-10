<?php

namespace App\Modules\Connections\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Connections\Http\Requests\BulkConnectionRequest;
use App\Modules\Connections\Http\Requests\SendConnectionRequest;
use App\Modules\Connections\Services\ConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ConnectionController extends Controller
{
    public function __construct(
        private readonly ConnectionService $connectionService,
    ) {}

    #[OA\Get(
        path: '/connections/suggestions',
        operationId: 'connectionsSuggestions',
        summary: 'List connectable family members',
        tags: ['Connections'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Suggestions with connection status'),
            new OA\Response(response: 422, description: 'Onboarding not complete'),
        ]
    )]
    public function suggestions(Request $request): JsonResponse
    {
        return response()->json($this->connectionService->suggestions($request->user()));
    }

    #[OA\Get(
        path: '/connections',
        operationId: 'connectionsList',
        summary: 'List my connections',
        tags: ['Connections'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'connected', 'rejected', 'disconnected', 'blocked'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Connection list'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->connectionService->listConnections($request->user(), $request->query('status'))
        );
    }

    #[OA\Post(
        path: '/connections',
        operationId: 'connectionsSend',
        summary: 'Send connection request',
        tags: ['Connections'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['user_uuid'],
                properties: [new OA\Property(property: 'user_uuid', type: 'string', format: 'uuid')]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Request sent'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(SendConnectionRequest $request): JsonResponse
    {
        $result = $this->connectionService->sendRequest(
            $request->user(),
            $request->validated('user_uuid'),
        );

        return response()->json($result, 201);
    }

    #[OA\Post(
        path: '/connections/bulk',
        operationId: 'connectionsBulk',
        summary: 'Send connection requests to selected members',
        tags: ['Connections'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['user_uuids'],
                properties: [
                    new OA\Property(property: 'user_uuids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Bulk result'),
        ]
    )]
    public function bulk(BulkConnectionRequest $request): JsonResponse
    {
        return response()->json(
            $this->connectionService->sendBulkRequests(
                $request->user(),
                $request->validated('user_uuids'),
            )
        );
    }

    #[OA\Post(
        path: '/connections/connect-all',
        operationId: 'connectionsConnectAll',
        summary: 'Send connection requests to all family members',
        tags: ['Connections'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Bulk result'),
        ]
    )]
    public function connectAll(Request $request): JsonResponse
    {
        return response()->json($this->connectionService->connectAll($request->user()));
    }

    #[OA\Post(
        path: '/connections/{uuid}/accept',
        operationId: 'connectionsAccept',
        summary: 'Accept a pending connection request',
        tags: ['Connections'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Connection accepted'),
            new OA\Response(response: 422, description: 'Invalid state'),
        ]
    )]
    public function accept(Request $request, string $uuid): JsonResponse
    {
        return response()->json($this->connectionService->accept($request->user(), $uuid));
    }

    #[OA\Post(
        path: '/connections/{uuid}/reject',
        operationId: 'connectionsReject',
        summary: 'Reject a pending connection request',
        tags: ['Connections'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Connection rejected'),
        ]
    )]
    public function reject(Request $request, string $uuid): JsonResponse
    {
        return response()->json($this->connectionService->reject($request->user(), $uuid));
    }

    #[OA\Post(
        path: '/connections/{uuid}/disconnect',
        operationId: 'connectionsDisconnect',
        summary: 'Disconnect an active connection',
        tags: ['Connections'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Disconnected'),
        ]
    )]
    public function disconnect(Request $request, string $uuid): JsonResponse
    {
        return response()->json($this->connectionService->disconnect($request->user(), $uuid));
    }

    #[OA\Post(
        path: '/connections/{uuid}/block',
        operationId: 'connectionsBlock',
        summary: 'Block a user',
        tags: ['Connections'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'User blocked'),
        ]
    )]
    public function block(Request $request, string $uuid): JsonResponse
    {
        return response()->json($this->connectionService->block($request->user(), $uuid));
    }
}
