<?php

namespace App\Modules\Groups\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateDirectChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_uuid' => ['required', 'uuid'],
        ];
    }
}
