<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class IranianNationalId implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $code = (string) $value;
        if (! preg_match('/^\d{10}$/', $code) || preg_match('/^(\d)\1{9}$/', $code)) {
            $fail('کد ملی واردشده معتبر نیست.');

            return;
        }

        $sum = 0;
        for ($index = 0; $index < 9; $index++) {
            $sum += ((int) $code[$index]) * (10 - $index);
        }

        $remainder = $sum % 11;
        $checkDigit = $remainder < 2 ? $remainder : 11 - $remainder;
        if ($checkDigit !== (int) $code[9]) {
            $fail('کد ملی واردشده معتبر نیست.');
        }
    }
}
