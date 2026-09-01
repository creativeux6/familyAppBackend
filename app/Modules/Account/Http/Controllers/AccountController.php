<?php

namespace App\Modules\Account\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Account\Services\AccountDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AccountController extends Controller
{
    public function __construct(
        private readonly AccountDeletionService $deletion,
    ) {}

    #[OA\Delete(
        path: '/account',
        operationId: 'accountDelete',
        summary: 'Permanently delete the signed-in account and personal data',
        tags: ['Account'],
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Deleted')]
    )]
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'confirmation' => ['required', 'string', 'max:64'],
        ]);

        $this->deletion->delete($request->user(), $data['confirmation']);

        return response()->json([
            'message' => 'Your Tijori account and personal data have been deleted.',
        ]);
    }
}
