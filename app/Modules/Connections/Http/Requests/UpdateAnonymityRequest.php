<?php

namespace App\Modules\Connections\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAnonymityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_anonymous' => ['required', 'boolean'],
        ];
    }
}
