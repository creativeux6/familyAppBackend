<?php

namespace App\Modules\Groups\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'member_user_uuids' => ['required', 'array', 'min:1'],
            'member_user_uuids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
