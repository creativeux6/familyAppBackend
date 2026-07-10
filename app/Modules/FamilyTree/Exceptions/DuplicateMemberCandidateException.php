<?php

namespace App\Modules\FamilyTree\Exceptions;

use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DuplicateMemberCandidateException extends Exception
{
    /** @param  list<array<string, mixed>>  $candidates */
    public function __construct(
        public readonly array $candidates,
        string $message = 'A similar person already exists in your family.',
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): Response
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error_code' => 'duplicate_member_candidates',
            'candidates' => $this->candidates,
        ], 409);
    }
}
