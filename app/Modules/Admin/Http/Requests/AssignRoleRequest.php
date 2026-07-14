<?php

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AssignRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', 'max:64', Rule::in(['super_admin', 'admin', 'user'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $role = $this->input('role');
            $actor = $this->user();

            if ($role === 'super_admin' && $actor && ! $actor->hasRole('super_admin')) {
                $validator->errors()->add('role', 'Only a super admin can assign the super_admin role.');
            }
        });
    }
}
