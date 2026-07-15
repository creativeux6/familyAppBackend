<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Private-channel auth for mobile/API clients using Sanctum bearer tokens.
 * The default /broadcasting/auth web route is session-oriented and returns 403
 * for bearer-only app requests, which breaks Reverb subscriptions.
 */
class BroadcastingAuthController extends Controller
{
    public function __invoke(Request $request)
    {
        try {
            return Broadcast::auth($request);
        } catch (AccessDeniedHttpException $exception) {
            return response()->json([
                'message' => $exception->getMessage() ?: 'Forbidden',
            ], 403);
        }
    }
}
