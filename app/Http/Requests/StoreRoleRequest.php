<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Role::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('roles', 'slug')],
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
        return ['slug.regex' => 'شناسه نقش باید با حرف انگلیسی آغاز شود و فقط شامل حروف کوچک، عدد و زیرخط باشد.'];
    }
}
