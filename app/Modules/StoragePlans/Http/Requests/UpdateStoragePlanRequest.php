<?php

namespace App\Modules\StoragePlans\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStoragePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'slug' => ['sometimes', 'string', 'max:255', 'alpha_dash'],
            'quota_bytes' => ['sometimes', 'integer', 'min:1'],
            'display_price_cents' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
