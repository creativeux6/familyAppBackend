<?php

namespace App\Modules\FamilyTree\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MatchMemberCandidatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'relation_type' => [
                'nullable',
                'string',
                Rule::in(['father', 'mother', 'spouse', 'child', 'sibling', 'spouse_father', 'spouse_mother']),
            ],
            'exclude_uuid' => ['nullable', 'uuid'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other', 'unknown'])],
        ];
    }
}
