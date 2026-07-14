<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:32'],
            'token' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
