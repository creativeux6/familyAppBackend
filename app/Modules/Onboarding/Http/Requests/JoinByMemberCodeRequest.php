<?php

namespace App\Modules\Onboarding\Http\Requests;

use App\Modules\FamilyTree\Services\JoinRelationOptionsResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class JoinByMemberCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $nameRules = fn (string $prefix) => [
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
                'member_code' => ['required', 'string', 'min:4', 'max:20'],
                'relation_to_member' => [
                    'required',
                    'string',
                    Rule::in(app(JoinRelationOptionsResolver::class)->allAllowedCodes()),
                ],
                'parent_context' => ['nullable', 'array'],
                'parent_context.mother' => ['nullable', 'array'],
                'parent_context.mother.date_of_birth' => ['nullable', 'date'],
                'parent_context.mother.gender' => ['nullable', 'string', 'in:male,female,other,unknown'],
                'parent_context.mother.is_living' => ['nullable', 'boolean'],
                'parent_context.mother.date_of_death' => ['nullable', 'date'],
                'parent_context.father' => ['nullable', 'array'],
                'parent_context.father.date_of_birth' => ['nullable', 'date'],
                'parent_context.father.gender' => ['nullable', 'string', 'in:male,female,other,unknown'],
                'parent_context.father.is_living' => ['nullable', 'boolean'],
                'parent_context.father.date_of_death' => ['nullable', 'date'],
                'parent_context.spouse' => ['nullable', 'array'],
                'parent_context.spouse.date_of_birth' => ['nullable', 'date'],
                'parent_context.spouse.gender' => ['nullable', 'string', 'in:male,female,other,unknown'],
                'parent_context.spouse.is_living' => ['nullable', 'boolean'],
                'parent_context.spouse.date_of_death' => ['nullable', 'date'],
                'first_name' => [
                    'nullable',
                    'required_with:last_name,gender',
                    'string',
                    'max:255',
                ],
                'last_name' => [
                    'nullable',
                    'required_with:first_name,gender',
                    'string',
                    'max:255',
                ],
                'gender' => ['nullable', 'string', 'in:male,female,other,unknown'],
            ],
            $nameRules('parent_context.mother'),
            $nameRules('parent_context.father'),
            $nameRules('parent_context.spouse'),
        );
    }
}
