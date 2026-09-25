<?php

declare(strict_types=1);

namespace Siiis\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Siiis\Support\ApiException;
use Siiis\Validation\LoginValidator;

final class LoginValidatorTest extends TestCase
{
    public function testNormalizaCorreoYConservaPasswordLiteral(): void
    {
        $result = (new LoginValidator())->validate(['correo' => '  Ana@UPTC.edu.co ', 'password' => ' con espacios ']);

        self::assertSame(['correo' => 'ana@uptc.edu.co', 'password' => ' con espacios '], $result);
    }

    public function testRechazaCuerpoNoArray(): void
    {
        try {
            (new LoginValidator())->validate(null);
            self::fail('Se esperaba ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status);
            self::assertSame(['correo', 'password'], array_keys($e->fields));
        }
    }

    public function testRechazaObjetosDondeSeEsperaTexto(): void
    {
        try {
            (new LoginValidator())->validate(['correo' => ['$gt' => ''], 'password' => ['$ne' => null]]);
            self::fail('Se esperaba ApiException');
        } catch (ApiException $e) {
            self::assertSame('Este campo debe ser texto.', $e->fields['correo']);
            self::assertSame('Este campo debe ser texto.', $e->fields['password']);
        }
    }

    public function testRechazaPasswordDemasiadoLarga(): void
    {
        $this->expectException(ApiException::class);

        (new LoginValidator())->validate(['correo' => 'a@b.co', 'password' => str_repeat('x', 257)]);
    }
}
