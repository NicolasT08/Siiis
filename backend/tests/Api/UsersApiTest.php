<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Slim\App;

final class UsersApiTest extends AuthTestCase
{
    private const CAMPOS_PUBLICOS = [
        'id', 'nombre', 'apellido', 'rol', 'programa_academico', 'semestre', 'foto_perfil', 'biografia',
    ];

    private const CAMPOS_ADMIN = [
        'id', 'correo', 'nombre', 'apellido', 'rol', 'programa_academico', 'semestre', 'biografia', 'telefono',
        'foto_perfil', 'estado', 'estado_cuenta', 'fecha_ingreso', 'fecha_salida', 'ultimo_acceso',
    ];

    /** @var App<\Psr\Container\ContainerInterface> */
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->authApp();
    }

    public function testListadoPublicoSoloActivosOrdenadosConVistaPublica(): void
    {
        $this->miembro('Zapata', 'Carlos');
        $this->miembro('Bernal', 'Luisa', ['rol' => 'docente_director']);
        $this->miembro('Bernal', 'Andrés');
        $this->miembro('Castro', 'Egresado', ['estado' => false]);

        $response = $this->get('/api/v1/users');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('public, no-cache', $response->getHeaderLine('Cache-Control'));
        self::assertSame('Authorization', $response->getHeaderLine('Vary'));
        $body = $this->json($response);
        self::assertSame(['Bernal Andrés', 'Bernal Luisa', 'Zapata Carlos'], self::nombres($body['data']));
        self::assertSame(['page' => 1, 'per_page' => 20, 'total' => 3, 'total_pages' => 1], $body['meta']);
        foreach ($body['data'] as $usuario) {
            self::assertSame(self::CAMPOS_PUBLICOS, array_keys($usuario));
        }
    }

    public function testVistaPublicaNoExponeDatosPrivados(): void
    {
        $this->miembro('Bernal', 'Luisa', [
            'telefono' => '+573001234567',
            'foto_perfil' => ['url' => 'https://res.cloudinary.com/demo/luisa.jpg', 'public_id' => 'siiis/perfiles/luisa'],
        ]);

        $raw = (string) $this->get('/api/v1/users')->getBody();

        foreach (['@uptc.edu.co', '+573001234567', 'public_id', 'siiis/perfiles', 'credencial_id', 'password', 'estado'] as $privado) {
            self::assertStringNotContainsString($privado, $raw);
        }
        self::assertStringContainsString('https://res.cloudinary.com/demo/luisa.jpg', $raw);
    }

    public function testPaginacion(): void
    {
        foreach (['Erazo', 'Avila', 'Duarte', 'Bernal', 'Castro'] as $apellido) {
            $this->miembro($apellido, 'X');
        }

        $body = $this->json($this->get('/api/v1/users?page=2&per_page=2'));
        self::assertSame(['Castro X', 'Duarte X'], self::nombres($body['data']));
        self::assertSame(['page' => 2, 'per_page' => 2, 'total' => 5, 'total_pages' => 3], $body['meta']);

        $ultima = $this->json($this->get('/api/v1/users?page=3&per_page=2'));
        self::assertSame(['Erazo X'], self::nombres($ultima['data']));

        $fuera = $this->json($this->get('/api/v1/users?page=9&per_page=2'));
        self::assertSame([], $fuera['data']);
        self::assertSame(5, $fuera['meta']['total']);
    }

    public function testSinIntegrantesDevuelveListaVacia(): void
    {
        $body = $this->json($this->get('/api/v1/users'));

        self::assertSame([], $body['data']);
        self::assertSame(['page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 0], $body['meta']);
    }

    public function testPerPagePorDefectoYMaximoConfigurables(): void
    {
        $app = $this->authApp(['USERS_PER_PAGE_DEFAULT' => '2', 'USERS_PER_PAGE_MAX' => '3']);
        foreach (['Bernal', 'Castro', 'Duarte', 'Erazo'] as $apellido) {
            $this->miembro($apellido, 'X');
        }

        $body = $this->json($app->handle($this->request('GET', '/api/v1/users')));
        self::assertCount(2, $body['data']);
        self::assertSame(2, $body['meta']['per_page']);

        $this->assertError($app->handle($this->request('GET', '/api/v1/users?per_page=4')), 400, 'VALIDATION_ERROR');
    }

    public function testFiltroPorRol(): void
    {
        $this->miembro('Bernal', 'Luisa', ['rol' => 'docente_director']);
        $this->miembro('Castro', 'Pedro');

        $body = $this->json($this->get('/api/v1/users?rol=docente_director'));

        self::assertSame(['Bernal Luisa'], self::nombres($body['data']));
        self::assertSame(1, $body['meta']['total']);
    }

    public function testElPublicoNoPuedeFiltrarInactivos(): void
    {
        $this->miembro('Bernal', 'Luisa');
        $this->miembro('Castro', 'Egresado', ['estado' => false]);

        $body = $this->json($this->get('/api/v1/users?estado=false'));

        self::assertSame(['Bernal Luisa'], self::nombres($body['data']));
    }

    public function testAdministradorVeVistaCompletaEInactivosSinNMas1(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador', ['apellido' => 'Admin']);
        $this->miembro('Bernal', 'Luisa', ['telefono' => '+573001234567']);
        $this->miembro('Castro', 'Egresado', ['estado' => false]);
        $consultasAntes = $this->credenciales->consultasCuentas;

        $response = $this->get('/api/v1/users', $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $body = $this->json($response);
        self::assertSame(['Admin Ana', 'Bernal Luisa', 'Castro Egresado'], self::nombres($body['data']));
        self::assertSame(1, $this->credenciales->consultasCuentas - $consultasAntes);

        $luisa = $body['data'][1];
        self::assertSame(self::CAMPOS_ADMIN, array_keys($luisa));
        self::assertSame('bernal-luisa@uptc.edu.co', $luisa['correo']);
        self::assertSame('+573001234567', $luisa['telefono']);
        self::assertTrue($luisa['estado']);
        self::assertSame('activa', $luisa['estado_cuenta']);
        self::assertSame('2026-01-15T00:00:00Z', $luisa['fecha_ingreso']);
        self::assertFalse($body['data'][2]['estado']);
        self::assertStringNotContainsString('public_id', (string) $response->getBody());
    }

    public function testAdministradorFiltraPorEstado(): void
    {
        [$token] = $this->sesionComo($this->app, 'superadministrador', ['apellido' => 'Admin']);
        $this->miembro('Bernal', 'Luisa');
        $this->miembro('Castro', 'Egresado', ['estado' => false]);

        $inactivos = $this->json($this->get('/api/v1/users?estado=false', $token));
        self::assertSame(['Castro Egresado'], self::nombres($inactivos['data']));

        $activos = $this->json($this->get('/api/v1/users?estado=true&rol=estudiante', $token));
        self::assertSame(['Bernal Luisa'], self::nombres($activos['data']));
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
    public function testRolesNoAdministrativosVenLaVistaPublica(string $rol): void
    {
        [$token] = $this->sesionComo($this->app, $rol, ['apellido' => 'Yo']);
        $this->miembro('Castro', 'Egresado', ['estado' => false]);

        $response = $this->get('/api/v1/users?estado=false', $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $body = $this->json($response);
        self::assertSame(['Yo Ana'], self::nombres($body['data']));
        self::assertSame(self::CAMPOS_PUBLICOS, array_keys($body['data'][0]));
    }

    public function testTokenInvalidoONoVigenteDaLaVistaPublica(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador', ['apellido' => 'Admin']);
        $this->miembro('Castro', 'Egresado', ['estado' => false]);

        foreach ([$token . 'x', 'no-es-un-jwt'] as $invalido) {
            $response = $this->get('/api/v1/users', $invalido);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $body = $this->json($response);
            self::assertSame(['Admin Ana'], self::nombres($body['data']));
            self::assertSame(self::CAMPOS_PUBLICOS, array_keys($body['data'][0]));
        }

        // Sesión cerrada: también vista pública, no 401.
        self::assertSame(204, $this->app->handle($this->request('POST', '/api/v1/auth/logout', null, self::bearer($token)))->getStatusCode());
        $body = $this->json($this->get('/api/v1/users', $token));
        self::assertSame(self::CAMPOS_PUBLICOS, array_keys($body['data'][0]));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function consultasInvalidas(): iterable
    {
        yield 'page 0' => ['page=0', ['page']];
        yield 'page negativa' => ['page=-1', ['page']];
        yield 'page texto' => ['page=abc', ['page']];
        yield 'page decimal' => ['page=1.5', ['page']];
        yield 'page enorme' => ['page=99999999999999999999', ['page']];
        yield 'page sobre el tope' => ['page=10001', ['page']];
        yield 'per_page 0' => ['per_page=0', ['per_page']];
        yield 'per_page sobre el máximo' => ['per_page=51', ['per_page']];
        yield 'rol inexistente' => ['rol=egresado', ['rol']];
        yield 'rol con mayúsculas' => ['rol=Estudiante', ['rol']];
        yield 'inyección en rol' => ['rol[$ne]=estudiante', ['rol']];
        yield 'inyección en page' => ['page[$gt]=0', ['page']];
        yield 'varios errores' => ['page=x&per_page=x&rol=x', ['page', 'per_page', 'rol']];
    }

    /**
     * @param list<string> $camposConError
     */
    #[DataProvider('consultasInvalidas')]
    public function testValidacionDeLaConsulta(string $query, array $camposConError): void
    {
        $response = $this->get('/api/v1/users?' . $query);

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertSame($camposConError, array_keys($this->json($response)['error']['fields']));
    }

    public function testEstadoInvalidoParaAdministrador(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');

        foreach (['estado=si', 'estado=1', 'estado[$ne]=true'] as $query) {
            $response = $this->get('/api/v1/users?' . $query, $token);

            $this->assertError($response, 400, 'VALIDATION_ERROR');
            self::assertSame(['estado'], array_keys($this->json($response)['error']['fields']));
        }
    }

    public function testParametrosVaciosUsanLosValoresPorDefecto(): void
    {
        $this->miembro('Bernal', 'Luisa');

        $response = $this->get('/api/v1/users?page=&per_page=&rol=');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['page' => 1, 'per_page' => 20, 'total' => 1, 'total_pages' => 1], $this->json($response)['meta']);
    }

    public function testDetallePublico(): void
    {
        $id = $this->miembro('Bernal', 'Luisa', [
            'programa_academico' => 'Ingeniería de Sistemas y Computación',
            'semestre' => 6,
            'biografia' => 'Me interesa la IA.',
            'telefono' => '+573001234567',
        ]);

        $response = $this->get('/api/v1/users/' . $id);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('public, no-cache', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['usuario' => [
            'id' => (string) $id,
            'nombre' => 'Luisa',
            'apellido' => 'Bernal',
            'rol' => 'estudiante',
            'programa_academico' => 'Ingeniería de Sistemas y Computación',
            'semestre' => 6,
            'foto_perfil' => null,
            'biografia' => 'Me interesa la IA.',
        ]], $this->json($response));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function idsInexistentes(): iterable
    {
        yield 'id con formato inválido' => ['no-es-un-id'];
        yield 'id de 24 caracteres no hexadecimales' => ['zzzzzzzzzzzzzzzzzzzzzzzz'];
        yield 'id que no existe' => ['650f1c2e8b3a4a0012345678'];
    }

    #[DataProvider('idsInexistentes')]
    public function testDetalleInexistenteResponde404(string $id): void
    {
        $this->assertError($this->get('/api/v1/users/' . $id), 404, 'NOT_FOUND');
    }

    public function testDetalleDeInactivoResponde404AlPublicoYAUnEstudiante(): void
    {
        $id = $this->miembro('Castro', 'Egresado', ['estado' => false]);
        [$token] = $this->sesionComo($this->app, 'estudiante');

        $this->assertError($this->get('/api/v1/users/' . $id), 404, 'NOT_FOUND');
        $this->assertError($this->get('/api/v1/users/' . $id, $token), 404, 'NOT_FOUND');
    }

    /** DEC-B71 revertida: el superadministrador activo es un integrante más (el director lo será); inactivo, no. */
    public function testSuperadministradorActivoApareceEnLaVistaPublicaEInactivoNo(): void
    {
        $director = $this->miembro('Director', 'Semillero', ['rol' => 'superadministrador']);
        $retirado = $this->miembro('Antiguo', 'Admin', ['rol' => 'superadministrador', 'estado' => false]);
        $this->miembro('Bernal', 'Luisa');

        self::assertSame(['Bernal Luisa', 'Director Semillero'], self::nombres($this->json($this->get('/api/v1/users'))['data']));
        self::assertSame(['Director Semillero'], self::nombres($this->json($this->get('/api/v1/users?rol=superadministrador'))['data']));

        $perfil = $this->get('/api/v1/users/' . $director);
        self::assertSame(200, $perfil->getStatusCode(), (string) $perfil->getBody());
        self::assertSame(self::CAMPOS_PUBLICOS, array_keys($this->json($perfil)['usuario']));
        $this->assertError($this->get('/api/v1/users/' . $retirado), 404, 'NOT_FOUND');
    }

    public function testElPublicoRevalidaSiempreParaNoMostrarDatosViejos(): void
    {
        $id = $this->miembro('Bernal', 'Luisa');

        foreach (['/api/v1/users', '/api/v1/users/' . $id] as $ruta) {
            $response = $this->get($ruta);
            self::assertSame('public, no-cache', $response->getHeaderLine('Cache-Control'), $ruta);
            self::assertStringNotContainsString('max-age', $response->getHeaderLine('Cache-Control'), $ruta);
        }
    }

    public function testAdministradorVeElDetalleCompletoDeUnInactivo(): void
    {
        $id = $this->miembro('Castro', 'Egresado', [
            'estado' => false,
            'fecha_salida' => new UTCDateTime(new \DateTimeImmutable('2026-06-30T00:00:00Z')),
        ]);
        [$token] = $this->sesionComo($this->app, 'administrador');

        $response = $this->get('/api/v1/users/' . $id, $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $usuario = $this->json($response)['usuario'];
        self::assertSame(self::CAMPOS_ADMIN, array_keys($usuario));
        self::assertSame('castro-egresado@uptc.edu.co', $usuario['correo']);
        self::assertFalse($usuario['estado']);
        self::assertSame('2026-06-30T00:00:00Z', $usuario['fecha_salida']);
    }

    public function testUnIntegranteVeSuPropioPerfilCompleto(): void
    {
        [$token, $id] = $this->sesionComo($this->app, 'estudiante', ['telefono' => '+573001234567']);

        $usuario = $this->json($this->get('/api/v1/users/' . $id, $token))['usuario'];

        self::assertSame(self::CAMPOS_ADMIN, array_keys($usuario));
        self::assertSame('+573001234567', $usuario['telefono']);
        self::assertSame('2026-09-25T12:00:00Z', $usuario['ultimo_acceso']);
    }

    public function testUnIntegranteVeElPerfilPublicoDeOtro(): void
    {
        $otro = $this->miembro('Bernal', 'Luisa', ['telefono' => '+573001234567']);
        [$token] = $this->sesionComo($this->app, 'estudiante_lider');

        $usuario = $this->json($this->get('/api/v1/users/' . $otro, $token))['usuario'];

        self::assertSame(self::CAMPOS_PUBLICOS, array_keys($usuario));
    }

    /**
     * Integrante activo (o inactivo) con correo `apellido-nombre@uptc.edu.co`.
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

    private function get(string $path, ?string $token = null): ResponseInterface
    {
        return $this->app->handle($this->request('GET', $path, null, $token === null ? [] : self::bearer($token)));
    }

    /**
     * @param list<array<string, mixed>> $usuarios
     *
     * @return list<string>
     */
    private static function nombres(array $usuarios): array
    {
        return array_map(static fn (array $u): string => $u['apellido'] . ' ' . $u['nombre'], $usuarios);
    }
}
