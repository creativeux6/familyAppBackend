<?php

namespace App\Modules\FamilyTree\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFamilyInfoRequest extends FormRequest
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
            'father.uuid' => ['nullable', 'uuid'],
            'father.confirm_create_new' => ['sometimes', 'boolean'],
            'father.first_name' => ['nullable', 'string', 'max:255'],
            'father.last_name' => ['nullable', 'string', 'max:255'],
            'father.date_of_birth' => ['nullable', 'date'],
            'father.date_of_death' => ['nullable', 'date'],
            'father.gender' => ['nullable', Rule::in(['male', 'female', 'other', 'unknown'])],
            'father.is_living' => ['sometimes', 'boolean'],
            'mother' => ['sometimes', 'nullable', 'array'],
            'mother.uuid' => ['nullable', 'uuid'],
            'mother.confirm_create_new' => ['sometimes', 'boolean'],
            'mother.first_name' => ['nullable', 'string', 'max:255'],
            'mother.last_name' => ['nullable', 'string', 'max:255'],
            'mother.date_of_birth' => ['nullable', 'date'],
            'mother.date_of_death' => ['nullable', 'date'],
            'mother.gender' => ['nullable', Rule::in(['male', 'female', 'other', 'unknown'])],
            'mother.is_living' => ['sometimes', 'boolean'],
            'spouse' => ['sometimes', 'nullable', 'array'],
            'spouse.uuid' => ['nullable', 'uuid'],
            'spouse.confirm_create_new' => ['sometimes', 'boolean'],
            'spouse.first_name' => ['nullable', 'string', 'max:255'],
            'spouse.last_name' => ['nullable', 'string', 'max:255'],
            'spouse.date_of_birth' => ['nullable', 'date'],
            'spouse.date_of_death' => ['nullable', 'date'],
            'spouse.gender' => ['nullable', Rule::in(['male', 'female', 'other', 'unknown'])],
            'spouse.is_living' => ['sometimes', 'boolean'],
            'spouse_father' => ['sometimes', 'nullable', 'array'],
            'spouse_father.uuid' => ['nullable', 'uuid'],
            'spouse_father.confirm_create_new' => ['sometimes', 'boolean'],
            'spouse_father.first_name' => ['nullable', 'string', 'max:255'],
            'spouse_father.last_name' => ['nullable', 'string', 'max:255'],
            'spouse_father.date_of_birth' => ['nullable', 'date'],
            'spouse_father.date_of_death' => ['nullable', 'date'],
            'spouse_father.is_living' => ['sometimes', 'boolean'],
            'spouse_mother' => ['sometimes', 'nullable', 'array'],
            'spouse_mother.uuid' => ['nullable', 'uuid'],
            'spouse_mother.confirm_create_new' => ['sometimes', 'boolean'],
            'spouse_mother.first_name' => ['nullable', 'string', 'max:255'],
            'spouse_mother.last_name' => ['nullable', 'string', 'max:255'],
            'spouse_mother.date_of_birth' => ['nullable', 'date'],
            'spouse_mother.date_of_death' => ['nullable', 'date'],
            'spouse_mother.is_living' => ['sometimes', 'boolean'],
            'children' => ['sometimes', 'nullable', 'array'],
            'children.*.uuid' => ['nullable', 'uuid'],
            'children.*.confirm_create_new' => ['sometimes', 'boolean'],
            'children.*.first_name' => ['nullable', 'string', 'max:255'],
            'children.*.last_name' => ['nullable', 'string', 'max:255'],
            'children.*.date_of_birth' => ['nullable', 'date'],
            'children.*.date_of_death' => ['nullable', 'date'],
            'children.*.gender' => ['nullable', Rule::in(['male', 'female', 'other', 'unknown'])],
            'children.*.is_living' => ['sometimes', 'boolean'],
            'siblings' => ['sometimes', 'nullable', 'array'],
            'siblings.*.uuid' => ['nullable', 'uuid'],
            'siblings.*.confirm_create_new' => ['sometimes', 'boolean'],
            'siblings.*.first_name' => ['nullable', 'string', 'max:255'],
            'siblings.*.last_name' => ['nullable', 'string', 'max:255'],
            'siblings.*.date_of_birth' => ['nullable', 'date'],
            'siblings.*.date_of_death' => ['nullable', 'date'],
            'siblings.*.gender' => ['nullable', Rule::in(['male', 'female', 'other', 'unknown'])],
            'siblings.*.is_living' => ['sometimes', 'boolean'],
            'marriage_date' => ['nullable', 'date'],
        ];
    }
}
