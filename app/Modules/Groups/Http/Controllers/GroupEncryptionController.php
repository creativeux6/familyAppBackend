<?php

namespace App\Modules\Groups\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Groups\Http\Requests\StoreGroupKeyEnvelopesRequest;
use App\Modules\Groups\Services\GroupEncryptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class GroupEncryptionController extends Controller
{
    public function __construct(
        private readonly GroupEncryptionService $encryptionService,
    ) {}

    #[OA\Post(path: '/groups/{uuid}/encryption/envelopes', operationId: 'groupEncryptionStoreEnvelopes', summary: 'Store wrapped group keys', tags: ['Groups'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Stored')])]
    public function storeEnvelopes(StoreGroupKeyEnvelopesRequest $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->encryptionService->storeEnvelopes(
                $request->user(),
                $uuid,
                (int) $request->validated('generation'),
                $request->validated('envelopes'),
                (int) $request->input('encryption_version', 1),
            )
        );
    }

    #[OA\Get(path: '/groups/{uuid}/encryption/envelopes/me', operationId: 'groupEncryptionMyEnvelope', summary: 'Get my wrapped group key', tags: ['Groups'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'generation', in: 'query', schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Envelope')])]
    public function myEnvelope(Request $request, string $uuid): JsonResponse
    {
        $generation = $request->query('generation');

        return response()->json(
            $this->encryptionService->myEnvelope(
                $request->user(),
                $uuid,
                $generation !== null ? (int) $generation : null,
            )
        );
    }
}
