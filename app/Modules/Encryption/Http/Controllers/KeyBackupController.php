<?php

namespace App\Modules\Encryption\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Encryption\Http\Requests\StoreKeyBackupRequest;
use App\Modules\Encryption\Services\EncryptionKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class KeyBackupController extends Controller
{
    public function __construct(
        private readonly EncryptionKeyService $encryptionKeyService,
    ) {}

    #[OA\Post(
        path: '/encryption/key-backup',
        operationId: 'encryptionStoreKeyBackup',
        summary: 'Store encrypted private key backup',
        description: 'Client-encrypted backup blob for account recovery. Server cannot decrypt.',
        tags: ['Encryption'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['encrypted_private_key_blob', 'salt'],
                properties: [
                    new OA\Property(property: 'encrypted_private_key_blob', type: 'string'),
                    new OA\Property(property: 'salt', type: 'string'),
                    new OA\Property(property: 'encryption_version', type: 'integer', example: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Backup stored'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function store(StoreKeyBackupRequest $request): JsonResponse
    {
        $this->encryptionKeyService->storeKeyBackup(
            $request->user(),
            $request->validated('encrypted_private_key_blob'),
            $request->validated('salt'),
            $request->integer('encryption_version', 1),
        );

        return response()->json(['message' => 'Key backup stored.'], 201);
    }

    #[OA\Get(
        path: '/encryption/key-backup',
        operationId: 'encryptionGetKeyBackup',
        summary: 'Fetch encrypted private key backup',
        description: 'Returns the active client-encrypted backup blob for account recovery.',
        tags: ['Encryption'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Backup found',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'encrypted_private_key_blob', type: 'string'),
                        new OA\Property(property: 'salt', type: 'string'),
                        new OA\Property(property: 'encryption_version', type: 'integer', example: 1),
                        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'No backup found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        $backup = $this->encryptionKeyService->getKeyBackup($request->user());

        if (! $backup) {
            return response()->json(['message' => 'No key backup found.'], 404);
        }

        return response()->json($backup);
    }
}
