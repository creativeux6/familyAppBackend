<?php

namespace App\Modules\Auth\Http\Requests;

use App\Support\PersonName;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $first = trim((string) $this->input('first_name', ''));
        $last = trim((string) $this->input('last_name', ''));
        $display = trim((string) $this->input('display_name', ''));

        if ($first === '' && $last === '' && $display !== '') {
            [$first, $last] = PersonName::split($display);
        }

        if ($display === '' && ($first !== '' || $last !== '')) {
            $display = PersonName::display($first, $last);
        }

        $this->merge([
            'first_name' => $first !== '' ? $first : null,
            'last_name' => $last !== '' ? $last : null,
            'display_name' => $display !== '' ? $display : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:20', 'regex:/^\+[1-9]\d{6,14}$/'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'display_name' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Phone must be in E.164 format, e.g. +923001234567',
            'first_name.required' => 'First name is required.',
            'last_name.required' => 'Last name is required.',
        ];
    }
}
