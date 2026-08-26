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
            'storage_limit_bytes' => ['sometimes', 'integer', 'min:1'],
            'monthly_access_limit_bytes' => ['sometimes', 'integer', 'min:1'],
            'streaming_limit_bytes' => ['sometimes', 'integer', 'min:0'],
            'download_limit_bytes' => ['sometimes', 'integer', 'min:0'],
            'file_view_limit_bytes' => ['sometimes', 'integer', 'min:0'],
            'is_shared' => ['sometimes', 'boolean'],
            'max_shared_members' => ['sometimes', 'integer', 'min:0'],
            'max_users' => ['sometimes', 'integer', 'min:1'],
            'warning_percentage' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'soft_limit_percentage' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'hard_limit_percentage' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'display_price_cents' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'billing_period' => ['sometimes', 'string', 'in:monthly,yearly'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
