<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\IranianMobile;
use App\Rules\IranianNationalId;
use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && ($this->user()?->can('update', $target) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => PhoneNormalizer::normalize((string) $this->input('phone')),
            'national_id' => PhoneNormalizer::normalizeDigits((string) $this->input('national_id')),
        ]);
    }

    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'national_id' => ['required', new IranianNationalId, Rule::unique('users', 'national_id')->ignore($user)],
            'phone' => ['required', new IranianMobile, Rule::unique('users', 'phone')->ignore($user)],
            'role_id' => ['required', Rule::exists('roles', 'id')->where('is_active', true)],
            'centre_id' => ['nullable', Rule::exists('centres', 'id')->where('is_active', true)],
        ];
    }

    public function attributes(): array
    {
        return [
            'first_name' => 'نام', 'last_name' => 'نام خانوادگی', 'national_id' => 'کد ملی',
            'phone' => 'شماره تلفن', 'role_id' => 'نقش', 'centre_id' => 'مرکز',
        ];
    }
}
