<?php

declare(strict_types=1);

namespace Siiis\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Siiis\Security\Roles;

final class RolesTest extends TestCase
{
    public function testLosCincoRolesOficialesSonValidos(): void
    {
        self::assertSame(
            ['superadministrador', 'administrador', 'docente_director', 'estudiante_lider', 'estudiante'],
            Roles::TODOS,
        );
        foreach (Roles::TODOS as $rol) {
            self::assertTrue(Roles::esValido($rol));
        }
    }

    public function testRechazaRolesFueraDeLaListaBlanca(): void
    {
        foreach (['', 'Superadministrador', 'admin', 'docente', 'egresado', ' estudiante'] as $rol) {
            self::assertFalse(Roles::esValido($rol), $rol);
        }
    }

    public function testModeradoresDeResenasSonLosRevisoresDelContenido(): void
    {
        self::assertSame(Roles::REVISORES_CONTENIDO, Roles::MODERADORES_RESENAS);
        foreach (['superadministrador', 'administrador', 'docente_director'] as $rol) {
            self::assertTrue(Roles::esModeradorResenas($rol), $rol);
        }
        foreach (['estudiante_lider', 'estudiante', 'desconocido', null] as $rol) {
            self::assertFalse(Roles::esModeradorResenas($rol), (string) $rol);
        }
    }

    public function testSoloSuperadministradorYAdministradorSonAdministrativos(): void
    {
        self::assertTrue(Roles::esAdministrativo('superadministrador'));
        self::assertTrue(Roles::esAdministrativo('administrador'));
        self::assertFalse(Roles::esAdministrativo('docente_director'));
        self::assertFalse(Roles::esAdministrativo('estudiante_lider'));
        self::assertFalse(Roles::esAdministrativo('estudiante'));
        self::assertFalse(Roles::esAdministrativo('desconocido'));
    }

    public function testSuperadministradorAsignaTodosLosRoles(): void
    {
        self::assertSame(Roles::TODOS, Roles::asignablesPor('superadministrador'));
    }

    public function testAdministradorSoloAsignaRolesNoAdministrativos(): void
    {
        self::assertSame(
            ['docente_director', 'estudiante_lider', 'estudiante'],
            Roles::asignablesPor('administrador'),
        );
    }

    public function testLosDemasRolesNoAsignanNinguno(): void
    {
        foreach (['docente_director', 'estudiante_lider', 'estudiante', 'desconocido'] as $rol) {
            self::assertSame([], Roles::asignablesPor($rol), $rol);
        }
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function matrizDeGestion(): iterable
    {
        foreach (Roles::TODOS as $objetivo) {
            yield "superadministrador → $objetivo" => ['superadministrador', $objetivo, true];
        }

        yield 'administrador → superadministrador' => ['administrador', 'superadministrador', false];
        yield 'administrador → administrador' => ['administrador', 'administrador', false];
        yield 'administrador → docente_director' => ['administrador', 'docente_director', true];
        yield 'administrador → estudiante_lider' => ['administrador', 'estudiante_lider', true];
        yield 'administrador → estudiante' => ['administrador', 'estudiante', true];

        foreach (['docente_director', 'estudiante_lider', 'estudiante'] as $actor) {
            foreach (Roles::TODOS as $objetivo) {
                yield "$actor → $objetivo" => [$actor, $objetivo, false];
            }
        }

        yield 'superadministrador → rol inexistente' => ['superadministrador', 'egresado', false];
    }

    #[DataProvider('matrizDeGestion')]
    public function testMatrizDeGestion(string $actor, string $objetivo, bool $esperado): void
    {
        self::assertSame($esperado, Roles::puedeGestionar($actor, $objetivo));
    }
}
