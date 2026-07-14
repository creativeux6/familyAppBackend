<?php

namespace App\Modules\Encryption\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreKeyBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'encrypted_private_key_blob' => ['required', 'string'],
            'salt' => ['required', 'string'],
            'encryption_version' => ['sometimes', 'integer', 'min:1', 'max:255'],
        ];
    }
}
