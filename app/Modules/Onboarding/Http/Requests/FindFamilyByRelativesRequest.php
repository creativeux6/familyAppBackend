<?php

namespace App\Modules\Onboarding\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FindFamilyByRelativesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $personRules = [
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'in:male,female,other,unknown'],
            'is_living' => ['nullable', 'boolean'],
            'date_of_death' => ['nullable', 'date'],
        ];

        return [
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.relative_slot' => ['required', 'string'],
            'answers.*.first_name' => $personRules['first_name'],
            'answers.*.last_name' => $personRules['last_name'],
            'answers.*.date_of_birth' => $personRules['date_of_birth'],
            'answers.*.gender' => $personRules['gender'],
            'answers.*.is_living' => $personRules['is_living'],
            'answers.*.date_of_death' => $personRules['date_of_death'],
            'answers.*.relation_index' => ['nullable', 'integer', 'min:0'],
            'parent_context' => ['nullable', 'array'],
            'parent_context.mother' => ['nullable', 'array'],
            'parent_context.mother.first_name' => $personRules['first_name'],
            'parent_context.mother.last_name' => $personRules['last_name'],
            'parent_context.mother.date_of_birth' => $personRules['date_of_birth'],
            'parent_context.mother.gender' => $personRules['gender'],
            'parent_context.mother.is_living' => $personRules['is_living'],
            'parent_context.mother.date_of_death' => $personRules['date_of_death'],
            'parent_context.father' => ['nullable', 'array'],
            'parent_context.father.first_name' => $personRules['first_name'],
            'parent_context.father.last_name' => $personRules['last_name'],
            'parent_context.father.date_of_birth' => $personRules['date_of_birth'],
            'parent_context.father.gender' => $personRules['gender'],
            'parent_context.father.is_living' => $personRules['is_living'],
            'parent_context.father.date_of_death' => $personRules['date_of_death'],
            'parent_context.spouse' => ['nullable', 'array'],
            'parent_context.spouse.first_name' => $personRules['first_name'],
            'parent_context.spouse.last_name' => $personRules['last_name'],
            'parent_context.spouse.date_of_birth' => $personRules['date_of_birth'],
            'parent_context.spouse.gender' => $personRules['gender'],
            'parent_context.spouse.is_living' => $personRules['is_living'],
            'parent_context.spouse.date_of_death' => $personRules['date_of_death'],
        ];
    }
}
