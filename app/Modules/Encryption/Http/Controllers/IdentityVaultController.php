<?php

namespace App\Modules\Encryption\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Encryption\Http\Requests\StoreIdentityVaultRequest;
use App\Modules\Encryption\Services\EncryptionKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityVaultController extends Controller
{
    public function __construct(
        private readonly EncryptionKeyService $encryptionKeyService,
    ) {}

    public function store(StoreIdentityVaultRequest $request): JsonResponse
    {
        $this->encryptionKeyService->putVault(
            $request->user(),
            $request->validated('private_identity_key'),
            $request->integer('encryption_version', 1),
        );

        return response()->json(['message' => 'Identity vault stored.'], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $vault = $this->encryptionKeyService->revealVault($request->user());

        if (! $vault) {
            return response()->json(['message' => 'No identity vault found.'], 404);
        }

        return response()->json($vault);
    }
}
