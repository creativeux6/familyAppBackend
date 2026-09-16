<?php

namespace App\Modules\Groups\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ToggleMessageReactionRequest extends FormRequest
{
    /** @var list<string> */
    public const ALLOWED_EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'emoji' => ['required', 'string', 'max:16', Rule::in(self::ALLOWED_EMOJIS)],
        ];
    }
}
