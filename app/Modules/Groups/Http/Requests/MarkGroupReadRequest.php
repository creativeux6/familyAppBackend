<?php

namespace App\Modules\Groups\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkGroupReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message_uuid' => ['nullable', 'uuid'],
        ];
    }
}
