<?php

namespace App\Modules\Encryption\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreIdentityKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'public_identity_key' => ['required', 'string'],
            'encryption_version' => ['sometimes', 'integer', 'min:1', 'max:255'],
        ];
    }
}
