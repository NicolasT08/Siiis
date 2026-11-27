<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Slim\App;

/**
 * DELETE /users/{id} (desactivación lógica) y PATCH {"estado"} (reactivación), DEC-B22 y DEC-B28.
 */
final class UsersDeactivationApiTest extends AuthTestCase
{
    /** @var App<\Psr\Container\ContainerInterface> */
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->authApp();
    }

    public function testAdministradorDesactivaAUnEstudiante(): void
    {
        $id = $this->miembro('Bernal', 'Luisa');
        $tokenLuisa = $this->tokenDeMiembro('Bernal', 'Luisa');
        [$token] = $this->sesionComo($this->app, 'administrador');

        $response = $this->delete((string) $id, $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $usuario = $this->json($response)['usuario'];
        self::assertFalse($usuario['estado']);
        self::assertSame('2026-09-25T12:00:00Z', $usuario['fecha_salida']);
        self::assertSame('2026-01-15T00:00:00Z', $usuario['fecha_ingreso']);
        // Borrado lógico: el documento sigue existiendo.
        self::assertArrayHasKey((string) $id, $this->db->usuarios);

        // Sus sesiones se cierran, no puede volver a entrar y deja de ser público.
        $this->assertError($this->me($tokenLuisa), 401, 'UNAUTHORIZED');
        $this->assertError($this->login($this->app, 'bernal-luisa@uptc.edu.co'), 403, 'ACCOUNT_INACTIVE');
        $this->assertError($this->app->handle($this->request('GET', '/api/v1/users/' . $id)), 404, 'NOT_FOUND');
        // Las del administrador siguen activas.
        self::assertSame(200, $this->me($token)->getStatusCode());
    }

    public function testDesactivarDosVecesEsIdempotente(): void
    {
        $id = $this->miembro('Bernal', 'Luisa');
        [$token] = $this->sesionComo($this->app, 'administrador');
        self::assertSame(200, $this->delete((string) $id, $token)->getStatusCode());
        $this->clock->advance(3600);

        $response = $this->delete((string) $id, $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('2026-09-25T12:00:00Z', $this->json($response)['usuario']['fecha_salida']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rolesNoAdministrativos(): iterable
    {
        yield 'docente_director' => ['docente_director'];
        yield 'estudiante_lider' => ['estudiante_lider'];
        yield 'estudiante' => ['estudiante'];
    }

    #[DataProvider('rolesNoAdministrativos')]
    public function testRolesNoAdministrativosNoDesactivan(string $rol): void
    {
        $otro = $this->miembro('Bernal', 'Luisa');
        [$token, $propio] = $this->sesionComo($this->app, $rol);

        $this->assertError($this->delete((string) $otro, $token), 403, 'FORBIDDEN');
        $this->assertError($this->delete((string) $propio, $token), 403, 'FORBIDDEN');
        $this->assertError($this->patch((string) $otro, ['estado' => false], $token), 403, 'FORBIDDEN');
        self::assertTrue($this->db->usuarios[(string) $otro]['estado']);
        self::assertTrue($this->db->usuarios[(string) $propio]['estado']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rolesAdministrativos(): iterable
    {
        yield 'superadministrador' => ['superadministrador'];
        yield 'administrador' => ['administrador'];
    }

    #[DataProvider('rolesAdministrativos')]
    public function testNadieSeDesactivaASiMismo(string $rol): void
    {
        $this->miembro('Bernal', 'Luisa', ['rol' => 'superadministrador']);
        [$token, $id] = $this->sesionComo($this->app, $rol);

        $this->assertError($this->delete((string) $id, $token), 403, 'FORBIDDEN');
        $this->assertError($this->delete(strtoupper((string) $id), $token), 403, 'FORBIDDEN');
        $this->assertError($this->patch((string) $id, ['estado' => false], $token), 403, 'FORBIDDEN');
        self::assertTrue($this->db->usuarios[(string) $id]['estado']);
        self::assertSame(200, $this->me($token)->getStatusCode());
    }

    public function testReenviarElPropioEstadoActivoNoEsUnCambio(): void
    {
        [$token, $id] = $this->sesionComo($this->app, 'estudiante');

        $response = $this->patch((string) $id, ['estado' => true, 'biografia' => 'Hola'], $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('Hola', $this->json($response)['usuario']['biografia']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fueraDeLaJerarquia(): iterable
    {
        yield 'superadministrador' => ['superadministrador'];
        yield 'administrador' => ['administrador'];
    }

    #[DataProvider('fueraDeLaJerarquia')]
    public function testAdministradorNoDesactivaNiReactivaFueraDeSuJerarquia(string $rolObjetivo): void
    {
        $activo = $this->miembro('Bernal', 'Luisa', ['rol' => $rolObjetivo]);
        $inactivo = $this->miembro('Castro', 'Pedro', ['rol' => $rolObjetivo, 'estado' => false]);
        [$token] = $this->sesionComo($this->app, 'administrador');

        $this->assertError($this->delete((string) $activo, $token), 403, 'FORBIDDEN');
        $this->assertError($this->patch((string) $inactivo, ['estado' => true], $token), 403, 'FORBIDDEN');
        self::assertTrue($this->db->usuarios[(string) $activo]['estado']);
        self::assertFalse($this->db->usuarios[(string) $inactivo]['estado']);
    }

    public function testSuperadministradorDesactivaAdministradoresYOtrosSuperadministradores(): void
    {
        $admin = $this->miembro('Bernal', 'Luisa', ['rol' => 'administrador']);
        $otroSuper = $this->miembro('Castro', 'Pedro', ['rol' => 'superadministrador']);
        [$token] = $this->sesionComo($this->app, 'superadministrador');

        foreach ([$admin, $otroSuper] as $id) {
            $response = $this->delete((string) $id, $token);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            self::assertFalse($this->json($response)['usuario']['estado']);
        }
    }

    public function testNoSeDesactivaAlUltimoSuperadministradorActivo(): void
    {
        $ultimo = $this->miembro('Bernal', 'Luisa', ['rol' => 'superadministrador']);
        [$token, $actorId] = $this->sesionComo($this->app, 'superadministrador');
        // Simula una carrera: el actor dejó de contar como superadministrador activo.
        $this->db->usuarios[(string) $actorId]['estado'] = false;

        $this->assertError($this->delete((string) $ultimo, $token), 409, 'LAST_SUPERADMIN');
        $this->assertError($this->patch((string) $ultimo, ['estado' => false], $token), 409, 'LAST_SUPERADMIN');
        self::assertTrue($this->db->usuarios[(string) $ultimo]['estado']);
    }

    public function testPatchConEstadoFalseEquivaleADelete(): void
    {
        $id = $this->miembro('Bernal', 'Luisa');
        $tokenLuisa = $this->tokenDeMiembro('Bernal', 'Luisa');
        [$token] = $this->sesionComo($this->app, 'administrador');

        $response = $this->patch((string) $id, ['estado' => false, 'biografia' => 'Desertó en 2026-2'], $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $usuario = $this->json($response)['usuario'];
        self::assertFalse($usuario['estado']);
        self::assertSame('2026-09-25T12:00:00Z', $usuario['fecha_salida']);
        self::assertSame('Desertó en 2026-2', $usuario['biografia']);
        $this->assertError($this->me($tokenLuisa), 401, 'UNAUTHORIZED');
    }

    public function testReactivacion(): void
    {
        $id = $this->miembro('Bernal', 'Luisa');
        $tokenAnterior = $this->tokenDeMiembro('Bernal', 'Luisa');
        [$token] = $this->sesionComo($this->app, 'administrador');
        self::assertSame(200, $this->delete((string) $id, $token)->getStatusCode());
        $this->clock->advance(3600);

        $response = $this->patch((string) $id, ['estado' => true], $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $usuario = $this->json($response)['usuario'];
        self::assertTrue($usuario['estado']);
        self::assertNull($usuario['fecha_salida']);
        self::assertSame('2026-01-15T00:00:00Z', $usuario['fecha_ingreso']);

        // Las sesiones cerradas no reviven, pero puede volver a iniciar sesión y vuelve a ser público.
        $this->assertError($this->me($tokenAnterior), 401, 'UNAUTHORIZED');
        self::assertSame(200, $this->login($this->app, 'bernal-luisa@uptc.edu.co')->getStatusCode());
        self::assertSame(200, $this->app->handle($this->request('GET', '/api/v1/users/' . $id))->getStatusCode());
    }

    public function testReactivarAUnActivoNoCambiaNada(): void
    {
        $id = $this->miembro('Bernal', 'Luisa');
        $tokenLuisa = $this->tokenDeMiembro('Bernal', 'Luisa');
        [$token] = $this->sesionComo($this->app, 'administrador');

        self::assertSame(200, $this->patch((string) $id, ['estado' => true], $token)->getStatusCode());
        self::assertSame(200, $this->me($tokenLuisa)->getStatusCode());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function estadosInvalidos(): iterable
    {
        yield 'texto' => ['true'];
        yield 'número' => [1];
        yield 'null' => [null];
        yield 'inyección' => [['$ne' => false]];
    }

    #[DataProvider('estadosInvalidos')]
    public function testEstadoDebeSerBooleano(mixed $estado): void
    {
        $id = $this->miembro('Bernal', 'Luisa');
        [$token] = $this->sesionComo($this->app, 'administrador');

        $response = $this->patch((string) $id, ['estado' => $estado], $token);

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertSame(['estado'], array_keys($this->json($response)['error']['fields']));
        self::assertTrue($this->db->usuarios[(string) $id]['estado']);
    }

    public function testDeleteSinTokenResponde401(): void
    {
        $id = $this->miembro('Bernal', 'Luisa');

        $this->assertError($this->delete((string) $id), 401, 'UNAUTHORIZED');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function idsInexistentes(): iterable
    {
        yield 'id con formato inválido' => ['no-es-un-id'];
        yield 'id que no existe' => ['650f1c2e8b3a4a0012345678'];
    }

    #[DataProvider('idsInexistentes')]
    public function testDeleteDeInexistenteResponde404(string $id): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');

        $this->assertError($this->delete($id, $token), 404, 'NOT_FOUND');
    }

    /**
     * Integrante activo con correo `apellido-nombre@uptc.edu.co`.
     *
     * @param array<string, mixed> $usuario campos que sobrescriben los por defecto
     */
    private function miembro(string $apellido, string $nombre, array $usuario = []): ObjectId
    {
        $correo = mb_strtolower($apellido . '-' . $nombre) . '@uptc.edu.co';
        [, $id] = $this->db->crearCuenta($correo, self::PASSWORD, [], $usuario + [
            'nombre' => $nombre,
            'apellido' => $apellido,
            'rol' => 'estudiante',
            'foto_perfil' => null,
            'fecha_ingreso' => new UTCDateTime(new \DateTimeImmutable('2026-01-15T00:00:00Z')),
            'fecha_salida' => null,
        ]);

        return $id;
    }

    private function tokenDeMiembro(string $apellido, string $nombre): string
    {
        $body = $this->json($this->login($this->app, mb_strtolower($apellido . '-' . $nombre) . '@uptc.edu.co'));
        self::assertIsString($body['token'] ?? null);

        return $body['token'];
    }

    private function delete(string $id, ?string $token = null): ResponseInterface
    {
        return $this->app->handle(
            $this->request('DELETE', '/api/v1/users/' . $id, null, $token === null ? [] : self::bearer($token)),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patch(string $id, array $body, string $token): ResponseInterface
    {
        return $this->app->handle($this->request('PATCH', '/api/v1/users/' . $id, $body, self::bearer($token)));
    }

    private function me(string $token): ResponseInterface
    {
        return $this->app->handle($this->request('GET', '/api/v1/auth/me', null, self::bearer($token)));
    }
}
