<?php

namespace App\Modules\Groups\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadGroupAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'master' => ['required', 'file', 'image', 'max:10240'],
            'thumb' => ['required', 'file', 'image', 'max:2048'],
        ];
    }
}
