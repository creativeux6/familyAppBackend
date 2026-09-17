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
        $personNameRules = fn (string $prefix) => [
            "{$prefix}.first_name" => [
                'nullable',
                'required_with:'.$prefix.'.last_name,'.$prefix.'.date_of_birth,'.$prefix.'.gender',
                'string',
                'max:255',
            ],
            "{$prefix}.last_name" => [
                'nullable',
                'required_with:'.$prefix.'.first_name,'.$prefix.'.date_of_birth,'.$prefix.'.gender',
                'string',
                'max:255',
            ],
        ];

        return array_merge(
            [
                'father' => ['sometimes', 'nullable', 'array'],
                'father.date_of_birth' => ['nullable', 'date'],
                'father.gender' => ['nullable', Rule::in(['male', 'female', 'other', 'unknown'])],
                'father.is_living' => ['sometimes', 'boolean'],
                'mother' => ['sometimes', 'nullable', 'array'],
                'mother.date_of_birth' => ['nullable', 'date'],
                'mother.gender' => ['nullable', Rule::in(['male', 'female', 'other', 'unknown'])],
                'mother.is_living' => ['sometimes', 'boolean'],
                'spouse' => ['sometimes', 'nullable', 'array'],
                'spouse.date_of_birth' => ['nullable', 'date'],
                'spouse.gender' => ['nullable', Rule::in(['male', 'female', 'other', 'unknown'])],
                'spouse.is_living' => ['sometimes', 'boolean'],
                'spouse_father' => ['sometimes', 'nullable', 'array'],
                'spouse_father.date_of_birth' => ['nullable', 'date'],
                'spouse_father.is_living' => ['sometimes', 'boolean'],
                'spouse_mother' => ['sometimes', 'nullable', 'array'],
                'spouse_mother.date_of_birth' => ['nullable', 'date'],
                'spouse_mother.is_living' => ['sometimes', 'boolean'],
                'children' => ['sometimes', 'nullable', 'array'],
                'children.*.uuid' => ['nullable', 'uuid'],
                'children.*.first_name' => [
                    'nullable',
                    'required_with:children.*.last_name,children.*.date_of_birth,children.*.gender',
                    'string',
                    'max:255',
                ],
                'children.*.last_name' => [
                    'nullable',
                    'required_with:children.*.first_name,children.*.date_of_birth,children.*.gender',
                    'string',
                    'max:255',
                ],
                'children.*.date_of_birth' => ['nullable', 'date'],
                'children.*.gender' => ['nullable', Rule::in(['male', 'female', 'other', 'unknown'])],
                'children.*.is_living' => ['sometimes', 'boolean'],
                'children.*.other_parent_uuid' => ['nullable', 'uuid'],
                'has_multiple_partners' => ['sometimes', 'boolean'],
                'spouses' => ['sometimes', 'nullable', 'array'],
                'spouses.*.uuid' => ['nullable', 'uuid'],
                'spouses.*.first_name' => [
                    'nullable',
                    'required_with:spouses.*.last_name,spouses.*.date_of_birth,spouses.*.gender',
                    'string',
                    'max:255',
                ],
                'spouses.*.last_name' => [
                    'nullable',
                    'required_with:spouses.*.first_name,spouses.*.date_of_birth,spouses.*.gender',
                    'string',
                    'max:255',
                ],
                'spouses.*.date_of_birth' => ['nullable', 'date'],
                'spouses.*.gender' => ['nullable', Rule::in(['male', 'female', 'other', 'unknown'])],
                'spouses.*.is_living' => ['sometimes', 'boolean'],
                'spouses.*.marriage_date' => ['nullable', 'date'],
                'spouses.*.father' => ['sometimes', 'nullable', 'array'],
                'spouses.*.mother' => ['sometimes', 'nullable', 'array'],
            ],
            $personNameRules('father'),
            $personNameRules('mother'),
            $personNameRules('spouse'),
            $personNameRules('spouse_father'),
            $personNameRules('spouse_mother'),
        );
    }
}
