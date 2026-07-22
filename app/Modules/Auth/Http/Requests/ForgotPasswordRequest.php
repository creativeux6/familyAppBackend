<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:20', 'regex:/^\+[1-9]\d{6,14}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Phone must be in E.164 format, e.g. +923001234567',
        ];
    }
}
