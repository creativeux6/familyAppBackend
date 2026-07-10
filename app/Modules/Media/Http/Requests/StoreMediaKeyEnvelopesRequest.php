<?php

namespace App\Modules\Media\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMediaKeyEnvelopesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'encryption_version' => ['sometimes', 'integer', 'min:1'],
            'envelopes' => ['required', 'array', 'min:1'],
            'envelopes.*.recipient_user_uuid' => ['required', 'uuid', 'distinct'],
            'envelopes.*.wrapped_content_key' => ['required', 'string'],
        ];
    }
}
