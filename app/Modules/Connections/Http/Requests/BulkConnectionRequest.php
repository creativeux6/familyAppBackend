<?php

namespace App\Modules\Connections\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_uuids' => ['required', 'array', 'min:1'],
            'user_uuids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
