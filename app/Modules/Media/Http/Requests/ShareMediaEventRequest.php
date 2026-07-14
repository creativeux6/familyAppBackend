<?php

namespace App\Modules\Media\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShareMediaEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipient_user_uuid' => ['required', 'uuid'],
            'access' => ['sometimes', 'string', 'in:view,owner'],
        ];
    }
}
