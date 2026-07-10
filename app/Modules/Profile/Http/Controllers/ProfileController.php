<?php

namespace App\Modules\Profile\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Profile\Http\Requests\UpdateProfileMemberRequest;
use App\Modules\Profile\Http\Requests\UpdateProfileRequest;
use App\Modules\Profile\Services\ProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(
        private readonly ProfileService $profileService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->profileService->show($request->user()));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        return response()->json(
            $this->profileService->updateUser($request->user(), $request->validated())
        );
    }

    public function updateMember(UpdateProfileMemberRequest $request): JsonResponse
    {
        return response()->json(
            $this->profileService->updateMember($request->user(), $request->validated())
        );
    }
}
