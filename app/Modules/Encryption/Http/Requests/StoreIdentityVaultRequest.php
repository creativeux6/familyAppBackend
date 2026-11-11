<?php

namespace App\Modules\Encryption\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreIdentityVaultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'private_identity_key' => ['required', 'string'],
            'encryption_version' => ['sometimes', 'integer', 'min:1', 'max:255'],
        ];
    }
}
