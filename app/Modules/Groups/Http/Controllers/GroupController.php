<?php

namespace App\Modules\Groups\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Groups\Http\Requests\AddGroupMembersRequest;
use App\Modules\Groups\Http\Requests\CreateDirectChatRequest;
use App\Modules\Groups\Http\Requests\CreateGroupRequest;
use App\Modules\Groups\Http\Requests\UpdateGroupRequest;
use App\Modules\Groups\Services\GroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class GroupController extends Controller
{
    public function __construct(
        private readonly GroupService $groupService,
    ) {}

    #[OA\Get(path: '/groups', operationId: 'groupsList', summary: 'List my groups', tags: ['Groups'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Group list')])]
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->groupService->listForUser($request->user()));
    }

    #[OA\Post(path: '/groups', operationId: 'groupsCreate', summary: 'Create a group with connected members', tags: ['Groups'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 201, description: 'Group created'), new OA\Response(response: 422, description: 'Validation error')])]
    public function store(CreateGroupRequest $request): JsonResponse
    {
        $result = $this->groupService->create(
            $request->user(),
            $request->validated('name'),
            $request->validated('description'),
            $request->validated('member_user_uuids'),
        );

        return response()->json($result, 201);
    }

    #[OA\Post(path: '/groups/direct', operationId: 'groupsDirectCreate', summary: 'Open or create a direct chat with a connected user', tags: ['Groups'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Direct chat'), new OA\Response(response: 201, description: 'Direct chat created'), new OA\Response(response: 422, description: 'Validation error')])]
    public function storeDirect(CreateDirectChatRequest $request): JsonResponse
    {
        return response()->json(
            $this->groupService->findOrCreateDirect(
                $request->user(),
                $request->validated('user_uuid'),
            )
        );
    }

    #[OA\Get(path: '/groups/{uuid}', operationId: 'groupsShow', summary: 'Get group details', tags: ['Groups'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Group detail')])]
    public function show(Request $request, string $uuid): JsonResponse
    {
        return response()->json($this->groupService->show($request->user(), $uuid));
    }

    #[OA\Patch(path: '/groups/{uuid}', operationId: 'groupsUpdate', summary: 'Update group', tags: ['Groups'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Updated')])]
    public function update(UpdateGroupRequest $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->groupService->update($request->user(), $uuid, $request->validated())
        );
    }

    #[OA\Post(path: '/groups/{uuid}/members', operationId: 'groupsAddMembers', summary: 'Add connected members', tags: ['Groups'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Members added')])]
    public function addMembers(AddGroupMembersRequest $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->groupService->addMembers($request->user(), $uuid, $request->validated('user_uuids'))
        );
    }

    #[OA\Delete(path: '/groups/{uuid}/members/{userUuid}', operationId: 'groupsRemoveMember', summary: 'Remove member or leave group', tags: ['Groups'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'userUuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Removed')])]
    public function removeMember(Request $request, string $uuid, string $userUuid): JsonResponse
    {
        return response()->json(
            $this->groupService->removeMember($request->user(), $uuid, $userUuid)
        );
    }

    #[OA\Delete(path: '/groups/{uuid}', operationId: 'groupsDelete', summary: 'Delete group', tags: ['Groups'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Deleted')])]
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        return response()->json($this->groupService->delete($request->user(), $uuid));
    }

    #[OA\Get(path: '/groups/realtime/config', operationId: 'groupsRealtimeConfig', summary: 'Global WebSocket connection info', tags: ['Groups'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Reverb config')])]
    public function realtimeConfigGlobal(Request $request): JsonResponse
    {
        return response()->json($this->groupService->reverbConnectionConfig());
    }

    #[OA\Get(path: '/groups/{uuid}/realtime', operationId: 'groupsRealtime', summary: 'WebSocket connection info', tags: ['Groups'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Reverb config')])]
    public function realtime(Request $request, string $uuid): JsonResponse
    {
        return response()->json($this->groupService->realtimeConfig($request->user(), $uuid));
    }
}
