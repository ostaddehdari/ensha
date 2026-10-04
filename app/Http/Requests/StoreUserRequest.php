<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\IranianMobile;
use App\Rules\IranianNationalId;
use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
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
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'national_id' => ['required', new IranianNationalId, Rule::unique('users', 'national_id')],
            'phone' => ['required', new IranianMobile, Rule::unique('users', 'phone')],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()->symbols()],
            'role_id' => ['required', Rule::exists('roles', 'id')->where('is_active', true)],
            'centre_id' => ['nullable', Rule::exists('centres', 'id')->where('is_active', true)],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'first_name' => 'نام', 'last_name' => 'نام خانوادگی', 'national_id' => 'کد ملی',
            'phone' => 'شماره تلفن', 'password' => 'رمز عبور', 'role_id' => 'نقش', 'centre_id' => 'مرکز',
        ];
    }
}
