<?php

namespace App\Modules\StoragePlans\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignStoragePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'storage_plan_uuid' => ['required', 'uuid'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ];
    }
}
