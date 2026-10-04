<?php

namespace Tests\Unit;

use App\Rules\IranianMobile;
use App\Rules\IranianNationalId;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class IranianIdentityRulesTest extends TestCase
{
    public function test_valid_mobile_and_national_id_are_accepted(): void
    {
        $validator = Validator::make(
            ['phone' => '09123456789', 'national_id' => '1234567891'],
            ['phone' => [new IranianMobile], 'national_id' => [new IranianNationalId]],
        );

        $this->assertTrue($validator->passes());
    }

    public function test_invalid_identity_values_are_rejected(): void
    {
        $validator = Validator::make(
            ['phone' => '12345', 'national_id' => '0000000000'],
            ['phone' => [new IranianMobile], 'national_id' => [new IranianNationalId]],
        );

        $this->assertFalse($validator->passes());
    }
}
