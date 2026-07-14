<?php

namespace App\Modules\Groups\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ciphertext' => ['required', 'string'],
            'nonce' => ['required', 'string'],
            'encryption_generation' => ['required', 'integer', 'min:1'],
            'encryption_version' => ['sometimes', 'integer', 'min:1'],
            'type' => ['sometimes', 'in:text,media_reference,system'],
            'media_file_uuid' => ['nullable', 'uuid'],
        ];
    }
}
