<?php

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStorageGrantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'clear' => ['sometimes', 'boolean'],
            'storage_limit_gb' => ['required_without:clear', 'nullable', 'numeric', 'min:1', 'max:102400'],
            // Back-compat with the earlier field name.
            'storage_quota_override_gb' => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:102400'],
        ];
    }
}
