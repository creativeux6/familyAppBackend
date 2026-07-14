<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Family App API',
    description: 'Family networking platform API — phone auth, family graph, encrypted chat & media. Modules are grouped by tag in Swagger UI.',
)]
#[OA\Server(url: '/api/v1', description: 'API v1')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Sanctum',
    description: 'Use token from POST /auth/login or POST /auth/register'
)]
#[OA\Tag(name: 'Health', description: 'Service health checks')]
#[OA\Tag(name: 'Auth', description: 'Phone + password authentication')]
#[OA\Tag(name: 'Encryption', description: 'E2E encryption key envelopes (not cryptocurrency)')]
#[OA\Tag(name: 'Onboarding', description: 'Relative questionnaire and family matching')]
#[OA\Tag(name: 'Connections', description: 'Connect with family members')]
#[OA\Tag(name: 'Privacy', description: 'Anonymity and visibility settings')]
#[OA\Tag(name: 'FamilyTree', description: 'Family tree traversal and dynamic kinship labels')]
#[OA\Tag(name: 'Groups', description: 'WhatsApp-style groups with connected family members')]
#[OA\Tag(name: 'Chat', description: 'E2E encrypted group chat')]
#[OA\Tag(name: 'Media', description: 'E2E encrypted media on S3')]
#[OA\Tag(name: 'StoragePlans', description: 'Storage quota and plan catalog')]
#[OA\Tag(name: 'Admin', description: 'Admin-only APIs (requires admin role)')]
class OpenApiSpec
{
}
