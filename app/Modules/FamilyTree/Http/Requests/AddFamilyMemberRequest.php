<?php

namespace App\Modules\FamilyTree\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddFamilyMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('member_uuid') && ! $this->filled('uuid')) {
            $this->merge(['uuid' => $this->input('member_uuid')]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'relation_type' => [
                'required',
                'string',
                Rule::in(['father', 'mother', 'spouse', 'child', 'sibling', 'spouse_father', 'spouse_mother']),
            ],
            'uuid' => ['nullable', 'uuid'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'date_of_death' => ['nullable', 'date'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other', 'unknown'])],
            'is_living' => ['sometimes', 'boolean'],
            'marriage_date' => ['nullable', 'date'],
            'member_uuid' => ['nullable', 'uuid'],
            'confirm_create_new' => ['sometimes', 'boolean'],
        ];
    }
}
