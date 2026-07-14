<?php

namespace App\Modules\Media\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InitiateUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:255'],
            'size_bytes' => ['required', 'integer', 'min:1'],
            'mime_type' => ['required', 'string', 'max:255'],
            'checksum_sha256' => ['required', 'string', 'size:64'],
            'encryption_version' => ['sometimes', 'integer', 'min:1'],
            'media_event_uuid' => ['sometimes', 'nullable', 'uuid'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'metadata.duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'metadata.original_mime_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'metadata.width' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'metadata.height' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'metadata.visibility' => ['sometimes', 'nullable', 'string', 'in:private,gallery'],
            'metadata.source' => ['sometimes', 'nullable', 'string', 'in:chat,gallery'],
            'metadata.file_nonce' => ['sometimes', 'nullable', 'string', 'max:512'],
            'metadata.group_uuid' => ['sometimes', 'nullable', 'uuid'],
            'metadata.storage_mode' => ['sometimes', 'nullable', 'string', 'in:sender,co_owner'],
        ];
    }
}
