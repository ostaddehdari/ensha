<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = $this->route('role');

        return $role instanceof Role && ($this->user()?->can('update', $role) ?? false);
    }

    public function rules(): array
    {
        /** @var Role $role */
        $role = $this->route('role');

        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/', Rule::in([$role->slug]), Rule::unique('roles', 'slug')->ignore($role)],
            'description' => ['nullable', 'string', 'max:500'],
            'scope' => ['required', Rule::in(['global', 'centre'])],
            'color' => ['required', Rule::in(['primary', 'success', 'warning', 'danger', 'info', 'slate', 'violet'])],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['distinct', Rule::exists('permissions', 'id')->where('is_active', true)],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'شناسه نقش باید با حرف انگلیسی آغاز شود و فقط شامل حروف کوچک، عدد و زیرخط باشد.',
            'slug.in' => 'شناسه فنی نقش پس از ایجاد قابل تغییر نیست.',
        ];
    }
}
