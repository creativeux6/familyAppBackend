<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'support_inbox_email' => AppSetting::getValue(
                'support_inbox_email',
                (string) config('mail.from.address'),
            ),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'support_inbox_email' => ['required', 'email', 'max:255'],
        ]);

        AppSetting::putValue('support_inbox_email', strtolower($validated['support_inbox_email']));

        return response()->json([
            'message' => 'Settings updated.',
            'support_inbox_email' => AppSetting::getValue('support_inbox_email'),
        ]);
    }
}
