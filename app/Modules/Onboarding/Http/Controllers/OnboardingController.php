<?php

namespace App\Modules\Onboarding\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Onboarding\Http\Requests\ConfirmFamilyRequest;
use App\Modules\Onboarding\Http\Requests\FindFamilyByRelativesRequest;
use App\Modules\Onboarding\Http\Requests\JoinByMemberCodeRequest;
use App\Modules\Onboarding\Http\Requests\LookupMemberCodeRequest;
use App\Modules\Onboarding\Http\Requests\StoreParentContextRequest;
use App\Modules\Onboarding\Http\Requests\SubmitQuestionnaireRequest;
use App\Modules\Onboarding\Http\Requests\SyncDeclaredRelativesRequest;
use App\Modules\FamilyTree\Services\DeclaredRelativeService;
use App\Modules\FamilyTree\Services\FamilyJoinService;
use App\Modules\Onboarding\Services\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class OnboardingController extends Controller
{
    public function __construct(
        private readonly OnboardingService $onboardingService,
        private readonly FamilyJoinService $familyJoinService,
        private readonly DeclaredRelativeService $declaredRelatives,
    ) {}

    public function status(Request $request): JsonResponse
    {
        return response()->json(
            $this->familyJoinService->status($request->user())
        );
    }

    public function startSolo(Request $request): JsonResponse
    {
        return response()->json(
            $this->familyJoinService->ensureSoloFamily($request->user()),
            201,
        );
    }

    public function lookupMemberCode(LookupMemberCodeRequest $request): JsonResponse
    {
        return response()->json(
            $this->familyJoinService->lookupByMemberCode(
                $request->user(),
                $request->validated('member_code'),
                $request->validated('search_slot'),
            )
        );
    }

    public function joinByMemberCode(JoinByMemberCodeRequest $request): JsonResponse
    {
        return response()->json(
            $this->familyJoinService->joinByMemberCode(
                $request->user(),
                $request->validated(),
            )
        );
    }

    public function findByRelatives(FindFamilyByRelativesRequest $request): JsonResponse
    {
        return response()->json(
            $this->familyJoinService->findFamiliesByRelatives(
                $request->user(),
                $request->validated('answers'),
                (array) ($request->validated('parent_context') ?? []),
            )
        );
    }

    public function storeParentContext(StoreParentContextRequest $request): JsonResponse
    {
        $user = $request->user();
        $parentContext = (array) $request->validated('parent_context');
        $this->declaredRelatives->storeParentContext($user, $parentContext);

        return response()->json([
            'parent_context' => $this->declaredRelatives->getParentContext($user),
            'has_parent_anchors' => $this->declaredRelatives->hasParentAnchors($user),
            'declared_relatives' => $this->declaredRelatives->listDeclaredRelatives($user),
        ]);
    }

    public function declaredRelatives(Request $request): JsonResponse
    {
        return response()->json([
            'relatives' => $this->declaredRelatives->listDeclaredRelatives($request->user()),
        ]);
    }

    public function syncDeclaredRelatives(SyncDeclaredRelativesRequest $request): JsonResponse
    {
        $relatives = $this->declaredRelatives->syncDeclaredRelatives(
            $request->user(),
            $request->validated('relatives'),
        );

        return response()->json(['relatives' => $relatives]);
    }

    #[OA\Post(
        path: '/onboarding/questionnaire',
        operationId: 'onboardingSubmitQuestionnaire',
        summary: 'Submit relative questionnaire',
        description: 'Creates family member stubs, builds graph edges, runs family matching.',
        tags: ['Onboarding'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['answers'],
                properties: [
                    new OA\Property(
                        property: 'answers',
                        type: 'array',
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'relative_slot', type: 'string', example: 'father'),
                                new OA\Property(property: 'first_name', type: 'string', example: 'Ahmed'),
                                new OA\Property(property: 'last_name', type: 'string', example: 'Khan'),
                                new OA\Property(property: 'date_of_birth', type: 'string', format: 'date', example: '1965-01-10'),
                                new OA\Property(property: 'birthplace', type: 'string'),
                                new OA\Property(property: 'gender', type: 'string', enum: ['male', 'female', 'other', 'unknown']),
                                new OA\Property(property: 'is_living', type: 'boolean'),
                            ]
                        )
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Questionnaire processed'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function submitQuestionnaire(SubmitQuestionnaireRequest $request): JsonResponse
    {
        $result = $this->onboardingService->submitQuestionnaire(
            $request->user(),
            $request->validated('answers'),
            $request->validated('marital_status'),
        );

        return response()->json($result, 201);
    }

    #[OA\Get(
        path: '/onboarding/match-result',
        operationId: 'onboardingMatchResult',
        summary: 'Get latest onboarding match result',
        tags: ['Onboarding'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Match result'),
            new OA\Response(response: 404, description: 'No session'),
        ]
    )]
    public function matchResult(Request $request): JsonResponse
    {
        return response()->json(
            $this->onboardingService->latestSession($request->user())
        );
    }

    #[OA\Post(
        path: '/onboarding/confirm-family',
        operationId: 'onboardingConfirmFamily',
        summary: 'Confirm or reject family affiliation',
        tags: ['Onboarding'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['confirmed'],
                properties: [
                    new OA\Property(property: 'confirmed', type: 'boolean', example: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Confirmation processed'),
            new OA\Response(response: 422, description: 'No match to confirm'),
        ]
    )]
    public function confirmFamily(ConfirmFamilyRequest $request): JsonResponse
    {
        return response()->json(
            $this->onboardingService->confirmFamily(
                $request->user(),
                $request->boolean('confirmed'),
            )
        );
    }
}
