<?php

namespace App\Modules\Media\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Media\Http\Requests\AssignMediaEventRequest;
use App\Modules\Media\Http\Requests\AssignGroupCoOwnersRequest;
use App\Modules\Media\Http\Requests\GrantMediaPermissionRequest;
use App\Modules\Media\Http\Requests\InitiateTransferRequest;
use App\Modules\Media\Http\Requests\InitiateUploadRequest;
use App\Modules\Media\Http\Requests\StoreMediaKeyEnvelopesRequest;
use App\Modules\Media\Services\MediaCoOwnerService;
use App\Modules\Media\Services\MediaEventService;
use App\Modules\Media\Services\MediaOwnershipService;
use App\Modules\Media\Services\MediaPermissionService;
use App\Modules\Media\Http\Requests\MarkMediaSharesSeenRequest;
use App\Modules\Media\Services\MediaShareInboxService;
use App\Modules\Media\Services\MediaStreamService;
use App\Modules\Media\Services\MediaUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class MediaController extends Controller
{
    public function __construct(
        private readonly MediaUploadService $uploadService,
        private readonly MediaPermissionService $permissionService,
        private readonly MediaOwnershipService $ownershipService,
        private readonly MediaEventService $eventService,
        private readonly MediaCoOwnerService $coOwnerService,
        private readonly MediaStreamService $streamService,
        private readonly MediaShareInboxService $shareInboxService,
    ) {}

    public function shareUnreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $this->shareInboxService->unreadCountForUser($request->user()),
        ]);
    }

    public function markSharesSeen(MarkMediaSharesSeenRequest $request): JsonResponse
    {
        return response()->json(
            $this->shareInboxService->markSeen(
                $request->user(),
                $request->validated('media_uuids'),
            )
        );
    }

    #[OA\Get(path: '/media', operationId: 'mediaList', summary: 'List owned and shared media', tags: ['Media'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Media list')])]
    public function index(Request $request): JsonResponse
    {
        $scope = $request->query('scope');
        $limit = $request->query('limit');
        $cursor = $request->query('cursor');

        return response()->json($this->uploadService->listForUser(
            $request->user(),
            is_string($scope) ? $scope : null,
            is_numeric($limit) ? (int) $limit : null,
            is_string($cursor) && $cursor !== '' ? $cursor : null,
        ));
    }

    #[OA\Post(path: '/media/uploads/initiate', operationId: 'mediaUploadInitiate', summary: 'Initiate encrypted upload', tags: ['Media'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 201, description: 'Upload initiated')])]
    public function initiate(InitiateUploadRequest $request): JsonResponse
    {
        $result = $this->uploadService->initiate(
            $request->user(),
            $request->validated('display_name'),
            (int) $request->validated('size_bytes'),
            $request->validated('mime_type'),
            $request->validated('checksum_sha256'),
            (int) $request->input('encryption_version', 1),
            $request->input('media_event_uuid'),
            $request->input('metadata'),
        );

        return response()->json($result, 201);
    }

    #[OA\Put(path: '/media/{uuid}/content', operationId: 'mediaUploadContent', summary: 'Upload ciphertext (local disk)', tags: ['Media'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Uploaded')])]
    public function uploadContent(Request $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->uploadService->uploadContent($request->user(), $uuid, $request->getContent())
        );
    }

    #[OA\Get(path: '/media/{uuid}/content', operationId: 'mediaDownloadContent', summary: 'Download ciphertext (local disk)', tags: ['Media'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'File bytes')])]
    public function downloadContent(Request $request, string $uuid)
    {
        return $this->uploadService->downloadContent($request->user(), $uuid);
    }

    public function uploadThumbnail(Request $request, string $uuid): JsonResponse
    {
        $nonce = $request->header('X-Media-Thumb-Nonce');
        $mime = $request->header('X-Media-Thumb-Mime', 'image/jpeg');

        return response()->json(
            $this->uploadService->uploadThumbnail(
                $request->user(),
                $uuid,
                $request->getContent(),
                is_string($nonce) ? $nonce : null,
                is_string($mime) ? $mime : 'image/jpeg',
            )
        );
    }

    public function downloadThumbnail(Request $request, string $uuid)
    {
        return $this->uploadService->downloadThumbnail($request->user(), $uuid);
    }

    public function storeStreamManifest(Request $request, string $uuid): JsonResponse
    {
        $manifest = $request->all();
        if ($manifest === []) {
            $manifest = json_decode($request->getContent(), true) ?? [];
        }

        return response()->json(
            $this->streamService->storeManifest($request->user(), $uuid, is_array($manifest) ? $manifest : [])
        );
    }

    public function storeStreamChunk(Request $request, string $uuid, int $index): JsonResponse
    {
        return response()->json(
            $this->streamService->storeChunk($request->user(), $uuid, $index, $request->getContent())
        );
    }

    public function streamManifest(Request $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->streamService->getManifest($request->user(), $uuid)
        );
    }

    public function streamChunk(Request $request, string $uuid, int $index)
    {
        return $this->streamService->downloadChunk($request->user(), $uuid, $index);
    }

    #[OA\Post(path: '/media/{uuid}/complete', operationId: 'mediaUploadComplete', summary: 'Complete upload', tags: ['Media'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Active')])]
    public function complete(Request $request, string $uuid): JsonResponse
    {
        return response()->json($this->uploadService->complete($request->user(), $uuid));
    }

    public function uploadStatus(Request $request, string $uuid): JsonResponse
    {
        return response()->json(
            app(\App\Modules\Media\Services\MediaChunkedUploadService::class)
                ->uploadStatus($request->user(), $uuid)
        );
    }

    public function uploadChunk(Request $request, string $uuid, int $partNumber): JsonResponse
    {
        return response()->json(
            app(\App\Modules\Media\Services\MediaChunkedUploadService::class)
                ->uploadChunk($request->user(), $uuid, $partNumber, $request->getContent())
        );
    }

    public function abortUpload(Request $request, string $uuid): JsonResponse
    {
        return response()->json(
            app(\App\Modules\Media\Services\MediaChunkedUploadService::class)
                ->abortUpload($request->user(), $uuid)
        );
    }

    #[OA\Get(path: '/media/{uuid}', operationId: 'mediaShow', summary: 'Get media metadata and download URL', tags: ['Media'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Media detail')])]
    public function show(Request $request, string $uuid): JsonResponse
    {
        return response()->json($this->uploadService->show($request->user(), $uuid));
    }

    public function assignEvent(AssignMediaEventRequest $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->eventService->assignFile(
                $request->user(),
                $uuid,
                $request->validated('media_event_uuid'),
            ),
        );
    }

    #[OA\Delete(path: '/media/{uuid}', operationId: 'mediaDelete', summary: 'Delete media', tags: ['Media'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Deleted')])]
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        return response()->json($this->uploadService->delete($request->user(), $uuid));
    }

    #[OA\Post(path: '/media/{uuid}/permissions', operationId: 'mediaGrantPermission', summary: 'Grant view access to connected user or group', tags: ['Media'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Granted')])]
    public function grantPermission(GrantMediaPermissionRequest $request, string $uuid): JsonResponse
    {
        if ($request->filled('group_uuid')) {
            $result = $this->permissionService->grantToGroup(
                $request->user(),
                $uuid,
                $request->validated('group_uuid'),
                $request->input('access', 'view'),
            );
        } else {
            $result = $this->permissionService->grantToUser(
                $request->user(),
                $uuid,
                $request->validated('user_uuid'),
                $request->input('access', 'view'),
                (bool) $request->boolean('notify', true),
            );
        }

        return response()->json($result);
    }

    public function assignGroupCoOwners(AssignGroupCoOwnersRequest $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->coOwnerService->assignGroupChatCoOwners(
                $request->user(),
                $uuid,
                $request->validated('group_uuid'),
            )
        );
    }

    #[OA\Delete(path: '/media/{uuid}/permissions/{permissionId}', operationId: 'mediaRevokePermission', summary: 'Revoke permission', tags: ['Media'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'permissionId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Revoked')])]
    public function revokePermission(Request $request, string $uuid, int $permissionId): JsonResponse
    {
        return response()->json(
            $this->permissionService->revoke($request->user(), $uuid, $permissionId)
        );
    }

    #[OA\Post(path: '/media/{uuid}/encryption/envelopes', operationId: 'mediaStoreEnvelopes', summary: 'Store wrapped content keys', tags: ['Media'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Stored')])]
    public function storeEnvelopes(StoreMediaKeyEnvelopesRequest $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->permissionService->storeEnvelopes(
                $request->user(),
                $uuid,
                $request->validated('envelopes'),
                (int) $request->input('encryption_version', 1),
            )
        );
    }

    #[OA\Get(path: '/media/{uuid}/encryption/envelopes/me', operationId: 'mediaMyEnvelope', summary: 'Get my wrapped content key', tags: ['Media'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Envelope')])]
    public function myEnvelope(Request $request, string $uuid): JsonResponse
    {
        return response()->json($this->permissionService->myEnvelope($request->user(), $uuid));
    }

    #[OA\Post(path: '/media/{uuid}/transfer', operationId: 'mediaTransferInitiate', summary: 'Initiate ownership transfer', tags: ['Media'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Transfer pending')])]
    public function initiateTransfer(InitiateTransferRequest $request, string $uuid): JsonResponse
    {
        $result = $this->ownershipService->initiate(
            $request->user(),
            $uuid,
            $request->validated('to_user_uuid'),
        );

        return response()->json($result, 201);
    }

    #[OA\Post(path: '/media/transfers/{transferUuid}/accept', operationId: 'mediaTransferAccept', summary: 'Accept ownership transfer', tags: ['Media'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'transferUuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Accepted')])]
    public function acceptTransfer(Request $request, string $transferUuid): JsonResponse
    {
        return response()->json($this->ownershipService->accept($request->user(), $transferUuid));
    }

    #[OA\Post(path: '/media/transfers/{transferUuid}/decline', operationId: 'mediaTransferDecline', summary: 'Decline ownership transfer', tags: ['Media'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'transferUuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Declined')])]
    public function declineTransfer(Request $request, string $transferUuid): JsonResponse
    {
        return response()->json($this->ownershipService->decline($request->user(), $transferUuid));
    }

    #[OA\Post(path: '/media/transfers/{transferUuid}/cancel', operationId: 'mediaTransferCancel', summary: 'Cancel ownership transfer', tags: ['Media'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'transferUuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Cancelled')])]
    public function cancelTransfer(Request $request, string $transferUuid): JsonResponse
    {
        return response()->json($this->ownershipService->cancel($request->user(), $transferUuid));
    }
}
