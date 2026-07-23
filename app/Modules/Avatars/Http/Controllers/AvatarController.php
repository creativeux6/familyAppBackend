<?php

namespace App\Modules\Avatars\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\FamilyMember;
use App\Modules\Avatars\Services\AvatarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AvatarController extends Controller
{
    public function __construct(
        private readonly AvatarService $avatars,
    ) {}

    public function uploadProfile(Request $request): JsonResponse
    {
        $request->validate([
            'master' => ['required', 'file', 'image', 'max:2048'],
            'thumb' => ['required', 'file', 'image', 'max:256'],
        ]);

        return response()->json(
            $this->avatars->uploadUserAvatar(
                $request->user(),
                $request->file('master'),
                $request->file('thumb'),
            )
        );
    }

    public function deleteProfile(Request $request): JsonResponse
    {
        return response()->json(
            $this->avatars->deleteUserAvatar($request->user())
        );
    }

    public function uploadMember(Request $request, string $memberUuid): JsonResponse
    {
        $request->validate([
            'master' => ['required', 'file', 'image', 'max:2048'],
            'thumb' => ['required', 'file', 'image', 'max:256'],
        ]);

        $member = FamilyMember::query()->where('uuid', $memberUuid)->firstOrFail();

        return response()->json(
            $this->avatars->uploadMemberAvatar(
                $request->user(),
                $member,
                $request->file('master'),
                $request->file('thumb'),
            )
        );
    }

    public function deleteMember(Request $request, string $memberUuid): JsonResponse
    {
        $member = FamilyMember::query()->where('uuid', $memberUuid)->firstOrFail();

        return response()->json(
            $this->avatars->deleteMemberAvatar($request->user(), $member)
        );
    }

    public function show(Request $request, string $subjectType, string $subjectUuid, string $variant): StreamedResponse
    {
        if (! in_array($subjectType, ['users', 'members'], true)) {
            abort(404);
        }

        if (! in_array($variant, ['thumb', 'master'], true)) {
            abort(404);
        }

        return $this->avatars->stream($subjectType, $subjectUuid, $variant);
    }
}
