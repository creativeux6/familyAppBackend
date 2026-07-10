<?php

namespace App\Modules\Profile\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMatchingInfoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'father' => ['sometimes', 'nullable', 'array'],
            'father.first_name' => ['nullable', 'string', 'max:255'],
            'father.last_name' => ['nullable', 'string', 'max:255'],
            'father.date_of_birth' => ['nullable', 'date'],
            'father.gender' => ['nullable', Rule::in(['male', 'female', 'other', 'unknown'])],
            'mother' => ['sometimes', 'nullable', 'array'],
            'mother.first_name' => ['nullable', 'string', 'max:255'],
            'mother.last_name' => ['nullable', 'string', 'max:255'],
            'mother.date_of_birth' => ['nullable', 'date'],
            'mother.gender' => ['nullable', Rule::in(['male', 'female', 'other', 'unknown'])],
            'mother.is_living' => ['sometimes', 'boolean'],
            'father.is_living' => ['sometimes', 'boolean'],
            'spouse' => ['sometimes', 'nullable', 'array'],
            'spouse.first_name' => ['nullable', 'string', 'max:255'],
            'spouse.last_name' => ['nullable', 'string', 'max:255'],
            'spouse.date_of_birth' => ['nullable', 'date'],
            'spouse.gender' => ['nullable', Rule::in(['male', 'female', 'other', 'unknown'])],
            'spouse.is_living' => ['sometimes', 'boolean'],
            'spouse_father' => ['sometimes', 'nullable', 'array'],
            'spouse_father.first_name' => ['nullable', 'string', 'max:255'],
            'spouse_father.last_name' => ['nullable', 'string', 'max:255'],
            'spouse_father.date_of_birth' => ['nullable', 'date'],
            'spouse_father.is_living' => ['sometimes', 'boolean'],
            'spouse_mother' => ['sometimes', 'nullable', 'array'],
            'spouse_mother.first_name' => ['nullable', 'string', 'max:255'],
            'spouse_mother.last_name' => ['nullable', 'string', 'max:255'],
            'spouse_mother.date_of_birth' => ['nullable', 'date'],
            'spouse_mother.is_living' => ['sometimes', 'boolean'],
            'children' => ['sometimes', 'nullable', 'array'],
            'children.*.uuid' => ['nullable', 'uuid'],
            'children.*.first_name' => ['nullable', 'string', 'max:255'],
            'children.*.last_name' => ['nullable', 'string', 'max:255'],
            'children.*.date_of_birth' => ['nullable', 'date'],
            'children.*.gender' => ['nullable', Rule::in(['male', 'female', 'other', 'unknown'])],
            'children.*.is_living' => ['sometimes', 'boolean'],
        ];
    }
}
