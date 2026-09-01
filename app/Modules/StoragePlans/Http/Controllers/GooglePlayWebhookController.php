<?php

namespace App\Modules\StoragePlans\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\StoragePlans\Services\PlayBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class GooglePlayWebhookController extends Controller
{
    public function __invoke(Request $request, PlayBillingService $playBilling): JsonResponse
    {
        $expected = config('google_play.rtdn_token');
        if (is_string($expected) && $expected !== '') {
            $provided = (string) ($request->query('token') ?: $request->bearerToken() ?: '');
            if ($provided === '' || ! hash_equals($expected, $provided)) {
                throw new AccessDeniedHttpException('Invalid RTDN token.');
            }
        }

        $playBilling->handleRtdn($request->all());

        return response()->json(['received' => true]);
    }
}
