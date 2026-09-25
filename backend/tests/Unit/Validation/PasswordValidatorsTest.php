<?php

declare(strict_types=1);

namespace Siiis\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Siiis\Support\ApiException;
use Siiis\Tests\Support\TestSettings;
use Siiis\Validation\ForgotPasswordValidator;
use Siiis\Validation\ResetPasswordValidator;

final class PasswordValidatorsTest extends TestCase
{
    public function testForgotNormalizaCorreo(): void
    {
        self::assertSame(['correo' => 'ana@uptc.edu.co'], (new ForgotPasswordValidator())->validate(['correo' => ' ANA@uptc.edu.co']));
    }

    public function testForgotRechazaFaltante(): void
    {
        $this->expectException(ApiException::class);

        (new ForgotPasswordValidator())->validate([]);
    }

    public function testResetAceptaDatosValidos(): void
    {
        $validator = new ResetPasswordValidator(TestSettings::make(['PASSWORD_MIN_LENGTH' => '10']));

        self::assertSame(
            ['token' => 'abc', 'password' => 'diez-chars'],
            $validator->validate(['token' => ' abc ', 'password' => 'diez-chars']),
        );
    }

    public function testResetUsaLongitudMinimaConfigurable(): void
    {
        $validator = new ResetPasswordValidator(TestSettings::make(['PASSWORD_MIN_LENGTH' => '10']));

        try {
            $validator->validate(['token' => 'abc', 'password' => 'nueve-chr']);
            self::fail('Se esperaba ApiException');
        } catch (ApiException $e) {
            self::assertStringContainsString('10 caracteres', $e->fields['password']);
        }
    }

    public function testResetLimitaA72Bytes(): void
    {
        $validator = new ResetPasswordValidator(TestSettings::make());

        // 37 caracteres "ñ" = 74 bytes.
        $this->expectException(ApiException::class);
        $validator->validate(['token' => 'abc', 'password' => str_repeat('ñ', 37)]);
    }
}
