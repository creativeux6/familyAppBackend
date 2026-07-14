<?php

namespace App\Modules\Encryption\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Encryption\Http\Requests\StoreIdentityKeyRequest;
use App\Modules\Encryption\Http\Requests\StoreKeyBackupRequest;
use App\Modules\Encryption\Services\EncryptionKeyService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class IdentityKeyController extends Controller
{
    public function __construct(
        private readonly EncryptionKeyService $encryptionKeyService,
    ) {}

    #[OA\Post(
        path: '/encryption/identity-key',
        operationId: 'encryptionStoreIdentityKey',
        summary: 'Upload public encryption identity key',
        description: 'Stores X25519 public key for E2E key envelope distribution. Not cryptocurrency.',
        tags: ['Encryption'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['public_identity_key'],
                properties: [
                    new OA\Property(property: 'public_identity_key', type: 'string', description: 'Base64-encoded public key'),
                    new OA\Property(property: 'encryption_version', type: 'integer', example: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Key stored'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(StoreIdentityKeyRequest $request): JsonResponse
    {
        $record = $this->encryptionKeyService->storeIdentityKey(
            $request->user(),
            $request->validated('public_identity_key'),
            $request->integer('encryption_version', 1),
        );

        return response()->json([
            'message' => 'Identity key stored.',
            'encryption_version' => $record->encryption_version,
        ], 201);
    }

    #[OA\Get(
        path: '/encryption/identity-key/{userUuid}',
        operationId: 'encryptionGetIdentityKey',
        summary: 'Get user public encryption key',
        tags: ['Encryption'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'userUuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Public key returned'),
            new OA\Response(response: 404, description: 'User or key not found'),
        ]
    )]
    public function show(string $userUuid): JsonResponse
    {
        return response()->json(
            $this->encryptionKeyService->getPublicKey($userUuid)
        );
    }
}
