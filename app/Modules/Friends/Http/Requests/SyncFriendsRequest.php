<?php

namespace App\Modules\Friends\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncFriendsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone_hashes' => ['required', 'array', 'max:2000'],
            'phone_hashes.*' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ];
    }
}
