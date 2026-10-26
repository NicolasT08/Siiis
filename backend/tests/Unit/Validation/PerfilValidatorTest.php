<?php

declare(strict_types=1);

namespace Siiis\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Siiis\Tests\Support\TestSettings;
use Siiis\Validation\PerfilValidator;

final class PerfilValidatorTest extends TestCase
{
    public function testCompletoExigeNombreYApellidoYRellenaOpcionalesConNull(): void
    {
        $errors = [];
        $perfil = (new PerfilValidator(TestSettings::make()))->validate(['nombre' => ' Ana ', 'apellido' => 'Pérez'], false, $errors);

        self::assertSame([], $errors);
        self::assertSame([
            'nombre' => 'Ana',
            'apellido' => 'Pérez',
            'programa_academico' => null,
            'semestre' => null,
            'biografia' => null,
            'telefono' => null,
        ], $perfil);
    }

    public function testCompletoSinNombreNiApellido(): void
    {
        $errors = [];
        (new PerfilValidator(TestSettings::make()))->validate([], false, $errors);

        self::assertSame(['nombre', 'apellido'], array_keys($errors));
    }

    public function testParcialSoloDevuelveLosCamposPresentes(): void
    {
        $errors = [];
        $perfil = (new PerfilValidator(TestSettings::make()))->validate(['semestre' => 3, 'otro' => 'x'], true, $errors);

        self::assertSame([], $errors);
        self::assertSame(['semestre' => 3], $perfil);
    }

    public function testParcialPermiteBorrarOpcionalesConNullOVacio(): void
    {
        $errors = [];
        $perfil = (new PerfilValidator(TestSettings::make()))->validate(
            ['biografia' => null, 'telefono' => '   ', 'programa_academico' => ''],
            true,
            $errors,
        );

        self::assertSame([], $errors);
        self::assertSame(['programa_academico' => null, 'biografia' => null, 'telefono' => null], $perfil);
    }

    public function testParcialNoPermiteBorrarNombre(): void
    {
        $errors = [];
        (new PerfilValidator(TestSettings::make()))->validate(['nombre' => null, 'apellido' => ''], true, $errors);

        self::assertSame(['nombre', 'apellido'], array_keys($errors));
    }

    public function testLimitesConfigurables(): void
    {
        $validator = new PerfilValidator(TestSettings::make([
            'PERFIL_NOMBRE_MAX_LENGTH' => '5',
            'PERFIL_PROGRAMA_MAX_LENGTH' => '4',
            'PERFIL_BIOGRAFIA_MAX_LENGTH' => '3',
            'PERFIL_SEMESTRE_MAX' => '12',
            'PERFIL_TELEFONO_MIN_DIGITS' => '3',
            'PERFIL_TELEFONO_MAX_DIGITS' => '4',
        ]));

        $errors = [];
        $perfil = $validator->validate([
            'nombre' => 'Ñañoo',
            'semestre' => 12,
            'telefono' => '123',
        ], true, $errors);
        self::assertSame([], $errors);
        self::assertSame(['nombre' => 'Ñañoo', 'semestre' => 12, 'telefono' => '123'], $perfil);

        $errors = [];
        $validator->validate([
            'nombre' => 'Ñañooo',
            'programa_academico' => 'abcde',
            'biografia' => 'abcd',
            'semestre' => 13,
            'telefono' => '12345',
        ], true, $errors);
        self::assertSame(['nombre', 'programa_academico', 'semestre', 'biografia', 'telefono'], array_keys($errors));
    }

    public function testRechazaBooleanosYArraysEnCamposDeTexto(): void
    {
        $errors = [];
        (new PerfilValidator(TestSettings::make()))->validate([
            'nombre' => true,
            'biografia' => ['$ne' => ''],
            'semestre' => true,
            'telefono' => false,
        ], true, $errors);

        self::assertSame(['nombre', 'semestre', 'biografia', 'telefono'], array_keys($errors));
    }

    public function testTelefonoAdmiteSoloUnMasInicial(): void
    {
        $validator = new PerfilValidator(TestSettings::make());

        foreach (['3001234567', '+573001234567', '300 123 4567', '(8) 740.5626'] as $valido) {
            $errors = [];
            $validator->validate(['telefono' => $valido], true, $errors);
            self::assertSame([], $errors, $valido);
        }
        foreach (['57+3001234567', '++573001234567', '300123456789012345678', 'tel:3001234567'] as $invalido) {
            $errors = [];
            $validator->validate(['telefono' => $invalido], true, $errors);
            self::assertArrayHasKey('telefono', $errors, $invalido);
        }
    }
}
