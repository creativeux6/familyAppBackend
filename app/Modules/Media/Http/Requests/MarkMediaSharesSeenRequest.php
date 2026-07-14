<?php

namespace App\Modules\Media\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkMediaSharesSeenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'media_uuids' => ['sometimes', 'array'],
            'media_uuids.*' => ['uuid'],
        ];
    }
}
