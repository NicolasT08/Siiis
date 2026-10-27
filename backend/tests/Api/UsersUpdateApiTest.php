<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Slim\App;

final class UsersUpdateApiTest extends AuthTestCase
{
    /** @var App<\Psr\Container\ContainerInterface> */
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->authApp();
    }

    public function testUnIntegranteEditaSuPropioPerfilParcialmente(): void
    {
        [$token, $id] = $this->sesionComo($this->app, 'estudiante', [
            'biografia' => 'Antes',
            'programa_academico' => 'Ingeniería de Sistemas y Computación',
            'foto_perfil' => ['url' => 'https://res.cloudinary.com/demo/yo.jpg', 'public_id' => 'siiis/perfiles/yo'],
        ]);

        $response = $this->patch((string) $id, [
            'nombre' => '  Ana María ',
            'telefono' => '+57 (300) 123-4567',
            'biografia' => '',
            'semestre' => 7,
            'estado' => true,
            'correo' => 'otro@uptc.edu.co',
            'foto_perfil' => ['url' => 'https://evil.example/x.jpg'],
        ], $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $usuario = $this->json($response)['usuario'];
        self::assertSame('Ana María', $usuario['nombre']);
        self::assertSame('Pérez', $usuario['apellido']);
        self::assertSame('+573001234567', $usuario['telefono']);
        self::assertNull($usuario['biografia']);
        self::assertSame(7, $usuario['semestre']);
        self::assertSame('Ingeniería de Sistemas y Computación', $usuario['programa_academico']);
        self::assertSame('estudiante', $usuario['rol']);
        // Reenviar el propio estado no es un cambio; los campos no editables se ignoran.
        self::assertTrue($usuario['estado']);
        self::assertStringStartsWith('estudiante-', $usuario['correo']);
        self::assertSame(['url' => 'https://res.cloudinary.com/demo/yo.jpg'], $usuario['foto_perfil']);
        foreach (['public_id', 'password', 'credencial_id', 'token'] as $privado) {
            self::assertStringNotContainsString($privado, (string) $response->getBody());
        }

        // Editar el perfil no cierra la sesión.
        self::assertSame(200, $this->me($token)->getStatusCode());
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
    public function testUnIntegranteNoEditaAOtros(string $rol): void
    {
        $otro = $this->miembro('Bernal', 'Luisa');
        [$token] = $this->sesionComo($this->app, $rol);

        $this->assertError($this->patch((string) $otro, ['nombre' => 'X'], $token), 403, 'FORBIDDEN');
        // Mismo 403 si el usuario no existe o el id no es válido: no revela qué ids existen.
        $this->assertError($this->patch('650f1c2e8b3a4a0012345678', ['nombre' => 'X'], $token), 403, 'FORBIDDEN');
        $this->assertError($this->patch('no-es-un-id', ['nombre' => 'X'], $token), 403, 'FORBIDDEN');
        self::assertSame('Luisa', $this->db->usuarios[(string) $otro]['nombre']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cambiosDeRolPropio(): iterable
    {
        yield 'estudiante a superadministrador' => ['estudiante', 'superadministrador'];
        yield 'administrador a superadministrador' => ['administrador', 'superadministrador'];
        yield 'superadministrador a estudiante' => ['superadministrador', 'estudiante'];
    }

    #[DataProvider('cambiosDeRolPropio')]
    public function testNadieCambiaSuPropioRol(string $rol, string $nuevo): void
    {
        [$token, $id] = $this->sesionComo($this->app, $rol);

        $this->assertError($this->patch((string) $id, ['rol' => $nuevo, 'nombre' => 'X'], $token), 403, 'FORBIDDEN');
        // El id en mayúsculas sigue siendo el propio.
        $this->assertError($this->patch(strtoupper((string) $id), ['rol' => $nuevo], $token), 403, 'FORBIDDEN');
        self::assertSame($rol, $this->db->usuarios[(string) $id]['rol']);
        self::assertSame('Ana', $this->db->usuarios[(string) $id]['nombre']);
    }

    public function testEnviarElRolActualNoEsUnCambio(): void
    {
        [$token, $id] = $this->sesionComo($this->app, 'estudiante');

        $response = $this->patch((string) $id, ['rol' => 'estudiante', 'nombre' => 'Ana María'], $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('Ana María', $this->json($response)['usuario']['nombre']);
        self::assertSame(200, $this->me($token)->getStatusCode());
    }

    public function testAdministradorEditaYCambiaElRolDeUnEstudianteYCierraSusSesiones(): void
    {
        $app = $this->app;
        [$tokenEstudiante, $id] = $this->sesionComo($app, 'estudiante');
        [$token] = $this->sesionComo($app, 'administrador');

        $response = $this->patch((string) $id, ['rol' => 'docente_director', 'semestre' => null], $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $usuario = $this->json($response)['usuario'];
        self::assertSame('docente_director', $usuario['rol']);
        self::assertNull($usuario['semestre']);
        self::assertSame('docente_director', $this->db->usuarios[(string) $id]['rol']);
        $this->assertError($this->me($tokenEstudiante), 401, 'UNAUTHORIZED');
        // Las sesiones del administrador siguen activas.
        self::assertSame(200, $this->me($token)->getStatusCode());
    }

    public function testCambiarSoloElPerfilDeOtroNoCierraSusSesiones(): void
    {
        [$tokenEstudiante, $id] = $this->sesionComo($this->app, 'estudiante');
        [$token] = $this->sesionComo($this->app, 'administrador');

        self::assertSame(200, $this->patch((string) $id, ['biografia' => 'Nueva'], $token)->getStatusCode());
        self::assertSame(200, $this->me($tokenEstudiante)->getStatusCode());
    }

    public function testAdministradorPuedeEditarAUnInactivo(): void
    {
        $id = $this->miembro('Castro', 'Egresado', ['estado' => false]);
        [$token] = $this->sesionComo($this->app, 'administrador');

        $response = $this->patch((string) $id, ['biografia' => 'Egresado en 2026'], $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertFalse($this->json($response)['usuario']['estado']);
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function fueraDeLaJerarquia(): iterable
    {
        yield 'administrador edita a un superadministrador' => ['administrador', 'superadministrador', ['nombre' => 'X']];
        yield 'administrador edita a otro administrador' => ['administrador', 'administrador', ['nombre' => 'X']];
        yield 'administrador asciende a administrador' => ['administrador', 'estudiante', ['rol' => 'administrador']];
        yield 'administrador asciende a superadministrador' => ['administrador', 'docente_director', ['rol' => 'superadministrador']];
    }

    /**
     * @param array<string, mixed> $cambios
     */
    #[DataProvider('fueraDeLaJerarquia')]
    public function testJerarquiaDeRoles(string $actor, string $rolObjetivo, array $cambios): void
    {
        $objetivo = $this->miembro('Bernal', 'Luisa', ['rol' => $rolObjetivo]);
        [$token] = $this->sesionComo($this->app, $actor);

        $this->assertError($this->patch((string) $objetivo, $cambios, $token), 403, 'FORBIDDEN');
        self::assertSame($rolObjetivo, $this->db->usuarios[(string) $objetivo]['rol']);
        self::assertSame('Luisa', $this->db->usuarios[(string) $objetivo]['nombre']);
    }

    public function testSuperadministradorGestionaTodosLosRoles(): void
    {
        $admin = $this->miembro('Bernal', 'Luisa', ['rol' => 'administrador']);
        $estudiante = $this->miembro('Castro', 'Pedro');
        $otroSuper = $this->miembro('Duarte', 'Sofía', ['rol' => 'superadministrador']);
        [$token] = $this->sesionComo($this->app, 'superadministrador');

        foreach ([[$admin, 'estudiante'], [$estudiante, 'administrador'], [$otroSuper, 'administrador']] as [$id, $rol]) {
            $response = $this->patch((string) $id, ['rol' => $rol], $token);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame($rol, $this->json($response)['usuario']['rol']);
        }
    }

    public function testNoSePuedeBajarDeRolAlUltimoSuperadministradorActivo(): void
    {
        $ultimo = $this->miembro('Bernal', 'Luisa', ['rol' => 'superadministrador']);
        [$token, $actorId] = $this->sesionComo($this->app, 'superadministrador');
        // Simula una carrera: el actor dejó de contar como superadministrador activo.
        $this->db->usuarios[(string) $actorId]['estado'] = false;

        $this->assertError($this->patch((string) $ultimo, ['rol' => 'administrador'], $token), 409, 'LAST_SUPERADMIN');
        self::assertSame('superadministrador', $this->db->usuarios[(string) $ultimo]['rol']);

        // Editar solo su perfil sí está permitido.
        self::assertSame(200, $this->patch((string) $ultimo, ['nombre' => 'Luisa F.'], $token)->getStatusCode());
    }

    public function testUnSuperadministradorInactivoNoCuentaComoUltimo(): void
    {
        $inactivo = $this->miembro('Bernal', 'Luisa', ['rol' => 'superadministrador', 'estado' => false]);
        [$token, $actorId] = $this->sesionComo($this->app, 'superadministrador');
        $this->db->usuarios[(string) $actorId]['estado'] = false;

        self::assertSame(200, $this->patch((string) $inactivo, ['rol' => 'estudiante'], $token)->getStatusCode());
    }

    public function testSinTokenResponde401(): void
    {
        $id = $this->miembro('Bernal', 'Luisa');

        $this->assertError($this->patch((string) $id, ['nombre' => 'X']), 401, 'UNAUTHORIZED');
        $this->assertError($this->patch((string) $id, ['nombre' => 'X'], 'no-es-un-jwt'), 401, 'UNAUTHORIZED');
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
    public function testAdministradorRecibe404SiNoExiste(string $id): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');

        $this->assertError($this->patch($id, ['nombre' => 'X'], $token), 404, 'NOT_FOUND');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function cuerposInvalidos(): iterable
    {
        yield 'nombre vacío' => [['nombre' => '  '], ['nombre']];
        yield 'apellido null' => [['apellido' => null], ['apellido']];
        yield 'semestre como texto' => [['semestre' => '6'], ['semestre']];
        yield 'rol inexistente' => [['rol' => 'egresado'], ['rol']];
        yield 'rol null' => [['rol' => null], ['rol']];
        yield 'inyección en rol' => [['rol' => ['$ne' => 'estudiante']], ['rol']];
        yield 'inyección en nombre' => [['nombre' => ['$gt' => '']], ['nombre']];
        yield 'varios errores' => [['nombre' => '', 'rol' => 'x', 'telefono' => '12'], ['rol', 'nombre', 'telefono']];
    }

    /**
     * @param array<string, mixed> $cuerpo
     * @param list<string>         $camposConError
     */
    #[DataProvider('cuerposInvalidos')]
    public function testValidacionDelCuerpo(array $cuerpo, array $camposConError): void
    {
        [$token] = $this->sesionComo($this->app, 'superadministrador');
        $id = $this->miembro('Bernal', 'Luisa');

        $response = $this->patch((string) $id, $cuerpo, $token);

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertSame($camposConError, array_keys($this->json($response)['error']['fields']));
        self::assertSame('Luisa', $this->db->usuarios[(string) $id]['nombre']);
    }

    public function testSinCamposEditablesResponde400(): void
    {
        [$token, $id] = $this->sesionComo($this->app, 'estudiante');

        foreach (['{}', '{"correo": "x@uptc.edu.co", "foto_perfil": null}'] as $cuerpo) {
            $response = $this->app->handle($this->request('PATCH', '/api/v1/users/' . $id, $cuerpo, self::bearer($token)));

            $this->assertError($response, 400, 'VALIDATION_ERROR');
            self::assertArrayNotHasKey('fields', $this->json($response)['error']);
        }
    }

    public function testJsonInvalido(): void
    {
        [$token, $id] = $this->sesionComo($this->app, 'estudiante');

        $response = $this->app->handle($this->request('PATCH', '/api/v1/users/' . $id, '[1]', self::bearer($token)));

        $this->assertError($response, 400, 'INVALID_JSON');
    }

    /**
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

    /**
     * @param array<string, mixed> $body
     */
    private function patch(string $id, array $body, ?string $token = null): ResponseInterface
    {
        return $this->app->handle(
            $this->request('PATCH', '/api/v1/users/' . $id, $body, $token === null ? [] : self::bearer($token)),
        );
    }

    private function me(string $token): ResponseInterface
    {
        return $this->app->handle($this->request('GET', '/api/v1/auth/me', null, self::bearer($token)));
    }
}
