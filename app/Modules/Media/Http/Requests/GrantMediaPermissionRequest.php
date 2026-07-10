<?php

namespace App\Modules\Media\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GrantMediaPermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_uuid' => ['required_without:group_uuid', 'uuid'],
            'group_uuid' => ['required_without:user_uuid', 'uuid'],
            'access' => ['sometimes', 'in:view,owner'],
        ];
    }
}
