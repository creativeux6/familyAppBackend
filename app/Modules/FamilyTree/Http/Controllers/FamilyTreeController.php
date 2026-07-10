<?php

namespace App\Modules\FamilyTree\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FamilyTree\Enums\TreeViewMode;
use App\Modules\FamilyTree\Http\Requests\AddFamilyMemberRequest;
use App\Modules\FamilyTree\Http\Requests\MatchMemberCandidatesRequest;
use App\Modules\FamilyTree\Http\Requests\UpdateFamilyInfoRequest;
use App\Modules\FamilyTree\Services\FamilyTreeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class FamilyTreeController extends Controller
{
    public function __construct(
        private readonly FamilyTreeService $familyTreeService,
    ) {}

    #[OA\Get(
        path: '/family-tree',
        operationId: 'familyTreeShow',
        summary: 'Get family tree from viewer perspective',
        description: 'Returns members and edges with computed kinship labels. Anonymous members are hidden unless connected.',
        tags: ['FamilyTree'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'view_mode', in: 'query', schema: new OA\Schema(type: 'string', enum: ['blood', 'inlaws', 'all'], default: 'all')),
            new OA\Parameter(name: 'max_depth', in: 'query', schema: new OA\Schema(type: 'integer', default: 6, maximum: 8, minimum: 1)),
            new OA\Parameter(name: 'root_member_uuid', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Family tree'),
            new OA\Response(response: 422, description: 'Onboarding not complete'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->familyTreeService->getTree(
                $request->user(),
                $request->query('root_member_uuid'),
                TreeViewMode::fromRequest($request->query('view_mode')),
                (int) $request->query('max_depth', config('graph.max_tree_depth', 6)),
            )
        );
    }

    #[OA\Get(
        path: '/family-tree/members/{memberUuid}',
        operationId: 'familyTreeMember',
        summary: 'Get family member detail with kinship label',
        tags: ['FamilyTree'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'memberUuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'view_mode', in: 'query', schema: new OA\Schema(type: 'string', enum: ['blood', 'inlaws', 'all'], default: 'all')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Member detail'),
            new OA\Response(response: 422, description: 'Not found or not visible'),
        ]
    )]
    public function member(Request $request, string $memberUuid): JsonResponse
    {
        return response()->json(
            $this->familyTreeService->getMember(
                $request->user(),
                $memberUuid,
                TreeViewMode::fromRequest($request->query('view_mode')),
            )
        );
    }

    #[OA\Get(
        path: '/family-tree/kinship/{targetMemberUuid}',
        operationId: 'familyTreeKinship',
        summary: 'Resolve kinship label between viewer and target',
        tags: ['FamilyTree'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'targetMemberUuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'view_mode', in: 'query', schema: new OA\Schema(type: 'string', enum: ['blood', 'inlaws', 'all'], default: 'all')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Kinship label'),
        ]
    )]
    public function kinship(Request $request, string $targetMemberUuid): JsonResponse
    {
        return response()->json(
            $this->familyTreeService->getKinship(
                $request->user(),
                $targetMemberUuid,
                TreeViewMode::fromRequest($request->query('view_mode')),
            )
        );
    }

    public function familyInfo(Request $request): JsonResponse
    {
        return response()->json(
            $this->familyTreeService->getFamilyInfo($request->user())
        );
    }

    public function updateFamilyInfo(UpdateFamilyInfoRequest $request): JsonResponse
    {
        return response()->json(
            $this->familyTreeService->updateFamilyInfo(
                $request->user(),
                $request->validated(),
            )
        );
    }

    public function matchCandidates(MatchMemberCandidatesRequest $request): JsonResponse
    {
        return response()->json(
            $this->familyTreeService->findMemberCandidates(
                $request->user(),
                $request->validated(),
            )
        );
    }

    public function addMember(AddFamilyMemberRequest $request): JsonResponse
    {
        return response()->json(
            $this->familyTreeService->addMember(
                $request->user(),
                $request->validated(),
            ),
            201,
        );
    }
}
