<?php

namespace App\Modules\Onboarding\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitQuestionnaireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $slots = [
            'self', 'father', 'mother',
            'spouse', 'spouse_father', 'spouse_mother',
            'child', 'sibling',
            'paternal_grandfather', 'paternal_grandmother',
            'maternal_grandfather', 'maternal_grandmother',
            'other_relative',
        ];

        return [
            'marital_status' => [
                'nullable',
                Rule::in(['single', 'married', 'widowed', 'divorced', 'other']),
            ],
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.relative_slot' => ['required', Rule::in($slots)],
            'answers.*.relation_index' => ['nullable', 'integer', 'min:0', 'max:20'],
            'answers.*.first_name' => ['nullable', 'string', 'max:255'],
            'answers.*.last_name' => ['nullable', 'string', 'max:255'],
            'answers.*.maiden_name' => ['nullable', 'string', 'max:255'],
            'answers.*.date_of_birth' => ['nullable', 'date'],
            'answers.*.date_of_death' => ['nullable', 'date'],
            'answers.*.birthplace' => ['nullable', 'string', 'max:255'],
            'answers.*.gender' => ['nullable', Rule::in(['male', 'female', 'other', 'unknown'])],
            'answers.*.is_living' => ['nullable', 'boolean'],
            'answers.*.relation_hint' => ['nullable', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $answers = $this->input('answers', []);
        $hasSelf = collect($answers)->contains(fn ($a) => ($a['relative_slot'] ?? null) === 'self');

        if (! $hasSelf) {
            $this->merge([
                'answers' => array_merge([[
                    'relative_slot' => 'self',
                    'relation_index' => 0,
                    'first_name' => $this->user()?->display_name,
                    'last_name' => 'Member',
                ]], $answers),
            ]);
        }
    }
}
