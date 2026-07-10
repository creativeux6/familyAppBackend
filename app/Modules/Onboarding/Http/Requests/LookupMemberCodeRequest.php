<?php

namespace App\Modules\Onboarding\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LookupMemberCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'member_code' => ['required', 'string', 'min:4', 'max:20'],
            'search_slot' => ['nullable', 'string', 'max:64'],
        ];
    }
}
