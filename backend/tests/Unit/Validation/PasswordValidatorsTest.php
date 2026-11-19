<?php

declare(strict_types=1);

namespace Siiis\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Siiis\Support\ApiException;
use Siiis\Tests\Support\TestSettings;
use Siiis\Validation\ChangePasswordValidator;
use Siiis\Validation\ForgotPasswordValidator;
use Siiis\Validation\PasswordRules;
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
            ['token' => 'abc', 'password' => 'diez-char5'],
            $validator->validate(['token' => ' abc ', 'password' => 'diez-char5']),
        );
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function politica(): iterable
    {
        $complejidad = PasswordRules::COMPLEXITY_MESSAGE;

        yield 'mínimo exacto con letra y número' => ['abcdefg1', null];
        yield 'letras con tilde y ñ' => ['ñandú2026', null];
        yield 'solo letras' => ['abcdefgh', $complejidad];
        yield 'solo números' => ['12345678', $complejidad];
        yield 'solo símbolos' => ['!@#$%^&*', $complejidad];
        yield 'símbolos y número' => ['!@#$%^&1', $complejidad];
        yield 'corta aunque cumpla complejidad' => ['abc1', 'La contraseña debe tener al menos 8 caracteres.'];
    }

    #[DataProvider('politica')]
    public function testPoliticaDeContrasena(string $password, ?string $esperado): void
    {
        self::assertSame($esperado, PasswordRules::error($password, TestSettings::make()));
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

    public function testChangeAceptaDatosValidosSinRecortar(): void
    {
        $validator = new ChangePasswordValidator(TestSettings::make());

        self::assertSame(
            ['password_actual' => ' Vieja-2026 ', 'password_nueva' => ' Nueva-2026 '],
            $validator->validate(['password_actual' => ' Vieja-2026 ', 'password_nueva' => ' Nueva-2026 ']),
        );
    }

    /**
     * @return iterable<string, array{mixed, array<string, string>}>
     */
    public static function cambiosInvalidos(): iterable
    {
        $obligatorio = 'Este campo es obligatorio.';

        yield 'cuerpo vacío' => [[], ['password_actual' => $obligatorio, 'password_nueva' => $obligatorio]];
        yield 'cuerpo que no es objeto' => ['texto', ['password_actual' => $obligatorio, 'password_nueva' => $obligatorio]];
        yield 'actual como objeto (inyección)' => [
            ['password_actual' => ['$ne' => ''], 'password_nueva' => 'Nueva-2026'],
            ['password_actual' => 'Este campo debe ser texto.'],
        ];
        yield 'nueva como arreglo' => [
            ['password_actual' => 'Vieja-2026', 'password_nueva' => ['a']],
            ['password_nueva' => 'Este campo debe ser texto.'],
        ];
        yield 'nueva sin número' => [
            ['password_actual' => 'Vieja-2026', 'password_nueva' => 'solo-letras'],
            ['password_nueva' => PasswordRules::COMPLEXITY_MESSAGE],
        ];
        yield 'nueva corta' => [
            ['password_actual' => 'Vieja-2026', 'password_nueva' => 'a1'],
            ['password_nueva' => 'La contraseña debe tener al menos 8 caracteres.'],
        ];
        yield 'nueva de más de 72 bytes' => [
            ['password_actual' => 'Vieja-2026', 'password_nueva' => str_repeat('ñ', 36) . '1'],
            ['password_nueva' => 'La contraseña es demasiado larga (máximo 72 bytes).'],
        ];
        yield 'nueva igual a la actual' => [
            ['password_actual' => 'Misma-2026', 'password_nueva' => 'Misma-2026'],
            ['password_nueva' => ChangePasswordValidator::MISMA_PASSWORD],
        ];
        yield 'actual vacía y nueva válida' => [
            ['password_actual' => '', 'password_nueva' => 'Nueva-2026'],
            ['password_actual' => $obligatorio],
        ];
    }

    /**
     * @param array<string, string> $esperados
     */
    #[DataProvider('cambiosInvalidos')]
    public function testChangeRechazaDatosInvalidos(mixed $body, array $esperados): void
    {
        try {
            (new ChangePasswordValidator(TestSettings::make()))->validate($body);
            self::fail('Se esperaba VALIDATION_ERROR');
        } catch (ApiException $e) {
            self::assertSame('VALIDATION_ERROR', $e->errorCode);
            self::assertSame($esperados, $e->fields);
        }
    }
}
