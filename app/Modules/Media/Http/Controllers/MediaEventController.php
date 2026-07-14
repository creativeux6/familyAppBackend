<?php

namespace App\Modules\Media\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Media\Http\Requests\AssignMediaEventRequest;
use App\Modules\Media\Http\Requests\RenameMediaEventShareRequest;
use App\Modules\Media\Http\Requests\ShareMediaEventRequest;
use App\Modules\Media\Http\Requests\StoreMediaEventRequest;
use App\Modules\Media\Http\Requests\UpdateMediaEventRequest;
use App\Modules\Media\Services\MediaEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaEventController extends Controller
{
    public function __construct(
        private readonly MediaEventService $eventService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $scope = $request->query('scope');
        $scope = is_string($scope) && in_array($scope, ['private', 'gallery'], true)
            ? $scope
            : null;

        return response()->json([
            'events' => $this->eventService->listForUser($request->user(), $scope),
        ]);
    }

    public function store(StoreMediaEventRequest $request): JsonResponse
    {
        return response()->json(
            $this->eventService->create($request->user(), $request->validated()),
            201,
        );
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return response()->json($this->eventService->show($request->user(), $uuid));
    }

    public function update(UpdateMediaEventRequest $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->eventService->update($request->user(), $uuid, $request->validated()),
        );
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        return response()->json($this->eventService->delete($request->user(), $uuid));
    }

    public function share(ShareMediaEventRequest $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->eventService->registerShare(
                $request->user(),
                $uuid,
                (string) $request->validated('recipient_user_uuid'),
                (string) ($request->validated('access') ?? 'view'),
            ),
        );
    }

    public function renameShare(RenameMediaEventShareRequest $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->eventService->renameShareAlias(
                $request->user(),
                $uuid,
                (string) $request->validated('alias_title'),
            ),
        );
    }
}
