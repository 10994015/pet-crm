<?php

namespace Tests\Unit\Models;

use App\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    public static function phones(): array
    {
        return [
            'dashes' => ['0912-345-678', '0912345678'],
            'spaces' => ['0912 345 678', '0912345678'],
            'brackets' => ['(02) 2345-6789', '0223456789'],
            'country code keeps the plus' => ['+886 912 345 678', '+886912345678'],
            'already clean' => ['0912345678', '0912345678'],
            'letters dropped' => ['tel:0912345678', '0912345678'],
            'empty' => ['', ''],
            'null' => [null, ''],
        ];
    }

    #[DataProvider('phones')]
    public function test_phone_numbers_are_reduced_to_digits(?string $typed, string $stored): void
    {
        $this->assertSame($stored, Customer::normalizePhone($typed));
    }

    public function test_the_phone_attribute_is_stored_normalized_and_blank_becomes_null(): void
    {
        $this->assertSame('0912345678', (new Customer(['phone' => '0912-345-678']))->phone);
        $this->assertNull((new Customer(['phone' => ' - ']))->phone);
    }

    public function test_only_a_customer_with_a_password_is_a_member(): void
    {
        $customer = new Customer;
        $this->assertFalse($customer->isMember());

        $customer->forceFill(['password' => 'secret123']);
        $this->assertTrue($customer->isMember());
        $this->assertNotSame('secret123', $customer->getAttributes()['password'], 'the password is hashed');
    }

    private function withCode(string $code, Carbon $expires): Customer
    {
        return (new Customer)->forceFill([
            'activation_code' => Hash::make($code),
            'activation_expires_at' => $expires,
        ]);
    }

    public function test_the_activation_code_ignores_case_and_spaces(): void
    {
        $customer = $this->withCode('AB23CD', now()->addDay());

        $this->assertTrue($customer->activationCodeMatches('AB23CD'));
        $this->assertTrue($customer->activationCodeMatches(' ab23cd '));
        $this->assertFalse($customer->activationCodeMatches('AB23CE'));
        $this->assertFalse($customer->activationCodeMatches(''));
    }

    public function test_an_expired_or_missing_code_never_matches(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 12:00'));

        $this->assertFalse($this->withCode('AB23CD', now()->subSecond())->activationCodeMatches('AB23CD'));
        $this->assertFalse((new Customer)->activationCodeMatches('AB23CD'));
        $this->assertFalse((new Customer)->forceFill(['activation_code' => Hash::make('AB23CD')])->activationCodeMatches('AB23CD'),
            'a code without an expiry date is not trusted');
    }

    public function test_secrets_are_hidden_from_arrays_and_json(): void
    {
        $customer = (new Customer(['name' => '王小美']))->forceFill([
            'password' => 'secret123',
            'remember_token' => 'token',
            'activation_code' => 'hash',
        ]);

        $this->assertSame(['name' => '王小美'], array_intersect_key($customer->toArray(), array_flip(['name', 'password', 'remember_token', 'activation_code'])));
        $this->assertStringNotContainsString('token', $customer->toJson());
    }
}
