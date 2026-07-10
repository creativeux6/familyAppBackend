<?php

namespace App\Modules\Groups\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGroupKeyEnvelopesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'generation' => ['required', 'integer', 'min:1'],
            'encryption_version' => ['sometimes', 'integer', 'min:1'],
            'envelopes' => ['required', 'array', 'min:1'],
            'envelopes.*.recipient_user_uuid' => ['required', 'uuid', 'distinct'],
            'envelopes.*.wrapped_group_key' => ['required', 'string'],
        ];
    }
}
