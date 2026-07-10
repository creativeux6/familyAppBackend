<?php

namespace App\Modules\Connections\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_uuid' => ['required', 'uuid'],
        ];
    }
}
