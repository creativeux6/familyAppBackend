<?php

namespace App\Modules\Groups\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Groups\Http\Requests\MarkGroupReadRequest;
use App\Modules\Groups\Http\Requests\SendMessageRequest;
use App\Modules\Groups\Http\Requests\UpdateMessageRequest;
use App\Modules\Groups\Services\GroupMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class GroupMessageController extends Controller
{
    public function __construct(
        private readonly GroupMessageService $messageService,
    ) {}

    #[OA\Get(path: '/groups/{uuid}/messages', operationId: 'groupMessagesList', summary: 'List encrypted messages', tags: ['Chat'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'cursor', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 30))], responses: [new OA\Response(response: 200, description: 'Message page')])]
    public function index(Request $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->messageService->list(
                $request->user(),
                $uuid,
                $request->query('cursor'),
                (int) $request->query('limit', 30),
            )
        );
    }

    #[OA\Post(path: '/groups/{uuid}/messages', operationId: 'groupMessagesSend', summary: 'Send encrypted message', description: 'Broadcasts message.sent on private-group.{uuid} via Reverb.', tags: ['Chat'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Message sent'), new OA\Response(response: 422, description: 'Validation error')])]
    public function store(SendMessageRequest $request, string $uuid): JsonResponse
    {
        $result = $this->messageService->send(
            $request->user(),
            $uuid,
            $request->validated('ciphertext'),
            $request->validated('nonce'),
            (int) $request->validated('encryption_generation'),
            (int) $request->input('encryption_version', 1),
            $request->input('type', 'text'),
            $request->validated('media_file_uuid'),
        );

        return response()->json($result, 201);
    }

    public function markRead(MarkGroupReadRequest $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->messageService->markRead(
                $request->user(),
                $uuid,
                $request->validated('message_uuid'),
            )
        );
    }

    public function update(UpdateMessageRequest $request, string $uuid, string $messageUuid): JsonResponse
    {
        return response()->json(
            $this->messageService->update(
                $request->user(),
                $uuid,
                $messageUuid,
                $request->validated('ciphertext'),
                $request->validated('nonce'),
            )
        );
    }

    public function destroy(Request $request, string $uuid, string $messageUuid): JsonResponse
    {
        return response()->json(
            $this->messageService->delete(
                $request->user(),
                $uuid,
                $messageUuid,
            )
        );
    }
}
