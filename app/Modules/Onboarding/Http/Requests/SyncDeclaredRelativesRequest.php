<?php

namespace App\Modules\Onboarding\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncDeclaredRelativesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'relatives' => ['required', 'array'],
            'relatives.*.relation_type' => ['required', 'string', 'max:64'],
            'relatives.*.relation_index' => ['nullable', 'integer', 'min:0'],
            'relatives.*.first_name' => ['nullable', 'string', 'max:255'],
            'relatives.*.last_name' => ['nullable', 'string', 'max:255'],
            'relatives.*.date_of_birth' => ['nullable', 'date'],
            'relatives.*.gender' => ['nullable', 'string', 'in:male,female,other,unknown'],
            'relatives.*.is_living' => ['nullable', 'boolean'],
            'relatives.*.date_of_death' => ['nullable', 'date'],
        ];
    }
}
