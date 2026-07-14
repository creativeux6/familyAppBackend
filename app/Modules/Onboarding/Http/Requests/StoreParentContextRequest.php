<?php

namespace App\Modules\Onboarding\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreParentContextRequest extends FormRequest
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
            'parent_context' => ['required', 'array'],
            'parent_context.mother' => ['required', 'array'],
            'parent_context.mother.first_name' => $personRules['first_name'],
            'parent_context.mother.last_name' => $personRules['last_name'],
            'parent_context.mother.date_of_birth' => $personRules['date_of_birth'],
            'parent_context.mother.gender' => $personRules['gender'],
            'parent_context.mother.is_living' => $personRules['is_living'],
            'parent_context.mother.date_of_death' => $personRules['date_of_death'],
            'parent_context.father' => ['required', 'array'],
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
