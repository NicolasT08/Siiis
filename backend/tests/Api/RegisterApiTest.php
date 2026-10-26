<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Slim\App;

final class RegisterApiTest extends AuthTestCase
{
    private const NUEVO_CORREO = 'luis@uptc.edu.co';

    /** @var App<\Psr\Container\ContainerInterface> */
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->authApp();
    }

    public function testSinTokenResponde401(): void
    {
        $response = $this->app->handle($this->request('POST', '/api/v1/auth/register', $this->datos()));

        $this->assertError($response, 401, 'UNAUTHORIZED');
        self::assertSame([], $this->usuariosCon(self::NUEVO_CORREO));
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
    public function testRolesNoAdministrativosResponden403(string $rol): void
    {
        [$token] = $this->sesionComo($this->app, $rol);

        $response = $this->registrar($token, $this->datos(['rol' => 'estudiante']));

        $this->assertError($response, 403, 'FORBIDDEN');
        self::assertSame([], $this->usuariosCon(self::NUEVO_CORREO));
    }

    public function testAdministradorCreaEstudiante(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');

        $response = $this->registrar($token, $this->datos());

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $usuario = $this->json($response)['usuario'];
        self::assertMatchesRegularExpression('/^[a-f0-9]{24}$/', $usuario['id']);
        self::assertSame([
            'id' => $usuario['id'],
            'correo' => self::NUEVO_CORREO,
            'nombre' => 'Luis',
            'apellido' => 'Gómez',
            'rol' => 'estudiante',
            'programa_academico' => 'Ingeniería de Sistemas y Computación',
            'semestre' => 6,
            'biografia' => 'Me interesa la IA.',
            'telefono' => '+573001234567',
            'foto_perfil' => null,
            'estado' => true,
            'estado_cuenta' => 'activa',
            'fecha_ingreso' => '2026-09-25T12:00:00Z',
            'fecha_salida' => null,
            'ultimo_acceso' => null,
        ], $usuario);
    }

    public function testPersisteElParCredencialesUsuariosEnlazado(): void
    {
        [$token] = $this->sesionComo($this->app, 'superadministrador');

        $this->registrar($token, $this->datos());

        $credencial = $this->credencialDe(self::NUEVO_CORREO);
        self::assertNotNull($credencial);
        $usuario = $this->db->usuarios[(string) $credencial['usuario_id']] ?? null;
        self::assertIsArray($usuario);

        self::assertInstanceOf(ObjectId::class, $credencial['_id']);
        self::assertSame((string) $credencial['_id'], (string) $usuario['credencial_id']);
        self::assertTrue(password_verify(self::PASSWORD, $credencial['password']));
        self::assertSame('activa', $credencial['estado_cuenta']);
        self::assertSame(0, $credencial['intentos_fallidos']);
        self::assertNull($credencial['token_recuperacion']);
        self::assertInstanceOf(UTCDateTime::class, $credencial['fecha_creacion']);

        // Todos los campos oficiales de Usuarios (spec 8.2), sin campos extra.
        self::assertSame([
            '_id', 'credencial_id', 'nombre', 'apellido', 'rol', 'programa_academico', 'semestre',
            'foto_perfil', 'biografia', 'telefono', 'estado', 'fecha_ingreso', 'fecha_salida',
        ], array_keys($usuario));
        self::assertTrue($usuario['estado']);
        self::assertNull($usuario['fecha_salida']);
    }

    public function testElUsuarioCreadoPuedeIniciarSesion(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');
        $this->registrar($token, $this->datos());

        $login = $this->login($this->app, self::NUEVO_CORREO, self::PASSWORD);

        self::assertSame(200, $login->getStatusCode(), (string) $login->getBody());
        self::assertSame('estudiante', $this->json($login)['usuario']['rol']);
    }

    public function testNoExponeDatosSensibles(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');

        $body = (string) $this->registrar($token, $this->datos())->getBody();

        foreach (['password', 'token_recuperacion', 'public_id', 'credencial_id', '$2y$'] as $sensible) {
            self::assertStringNotContainsString($sensible, $body);
        }
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function jerarquia(): iterable
    {
        yield 'superadministrador → superadministrador' => ['superadministrador', 'superadministrador', true];
        yield 'superadministrador → administrador' => ['superadministrador', 'administrador', true];
        yield 'superadministrador → docente_director' => ['superadministrador', 'docente_director', true];
        yield 'administrador → superadministrador' => ['administrador', 'superadministrador', false];
        yield 'administrador → administrador' => ['administrador', 'administrador', false];
        yield 'administrador → docente_director' => ['administrador', 'docente_director', true];
        yield 'administrador → estudiante_lider' => ['administrador', 'estudiante_lider', true];
    }

    #[DataProvider('jerarquia')]
    public function testJerarquiaDeRoles(string $actor, string $rolNuevo, bool $permitido): void
    {
        [$token] = $this->sesionComo($this->app, $actor);

        $response = $this->registrar($token, $this->datos(['rol' => $rolNuevo]));

        if ($permitido) {
            self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame($rolNuevo, $this->json($response)['usuario']['rol']);
        } else {
            $this->assertError($response, 403, 'FORBIDDEN');
            self::assertNull($this->credencialDe(self::NUEVO_CORREO));
        }
    }

    public function testCorreoDuplicadoResponde409SinNormalizar(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');
        self::assertSame(201, $this->registrar($token, $this->datos())->getStatusCode());

        $response = $this->registrar($token, $this->datos(['correo' => '  LUIS@UPTC.edu.co ']));

        $this->assertError($response, 409, 'EMAIL_TAKEN');
        self::assertCount(1, $this->usuariosCon(self::NUEVO_CORREO));
    }

    public function testCamposMinimos(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');

        $response = $this->registrar($token, [
            'correo' => self::NUEVO_CORREO,
            'password' => self::PASSWORD,
            'rol' => 'estudiante',
            'nombre' => 'Luis',
            'apellido' => 'Gómez',
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $usuario = $this->json($response)['usuario'];
        self::assertNull($usuario['programa_academico']);
        self::assertNull($usuario['semestre']);
        self::assertNull($usuario['biografia']);
        self::assertNull($usuario['telefono']);
    }

    public function testIgnoraCamposQueNoSonDelRegistro(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');

        $response = $this->registrar($token, $this->datos([
            'estado' => false,
            'estado_cuenta' => 'bloqueada',
            'foto_perfil' => ['url' => 'https://evil.example/x.jpg', 'public_id' => 'x'],
            '_id' => '650f1c2e8b3a4a0012345678',
            'intentos_fallidos' => 99,
        ]));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $usuario = $this->json($response)['usuario'];
        self::assertTrue($usuario['estado']);
        self::assertSame('activa', $usuario['estado_cuenta']);
        self::assertNull($usuario['foto_perfil']);
        self::assertNotSame('650f1c2e8b3a4a0012345678', $usuario['id']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function datosInvalidos(): iterable
    {
        yield 'faltan todos' => [['correo' => null, 'password' => null, 'rol' => null, 'nombre' => null, 'apellido' => null], ['correo', 'password', 'rol', 'nombre', 'apellido']];
        yield 'correo inválido' => [['correo' => 'no-es-correo'], ['correo']];
        yield 'contraseña corta' => [['password' => 'corta'], ['password']];
        yield 'contraseña > 72 bytes' => [['password' => str_repeat('ñ', 37)], ['password']];
        yield 'rol inexistente' => [['rol' => 'egresado'], ['rol']];
        yield 'rol con mayúsculas' => [['rol' => 'Estudiante'], ['rol']];
        yield 'inyección en correo' => [['correo' => ['$ne' => '']], ['correo']];
        yield 'inyección en rol' => [['rol' => ['$in' => ['superadministrador']]], ['rol']];
        yield 'nombre vacío' => [['nombre' => '   '], ['nombre']];
        yield 'nombre largo' => [['nombre' => str_repeat('a', 81)], ['nombre']];
        yield 'apellido objeto' => [['apellido' => ['x' => 'y']], ['apellido']];
        yield 'semestre 0' => [['semestre' => 0], ['semestre']];
        yield 'semestre 11' => [['semestre' => 11], ['semestre']];
        yield 'semestre texto' => [['semestre' => '5'], ['semestre']];
        yield 'semestre decimal' => [['semestre' => 5.5], ['semestre']];
        yield 'teléfono con letras' => [['telefono' => '300-ABC-4567'], ['telefono']];
        yield 'teléfono corto' => [['telefono' => '12345'], ['telefono']];
        yield 'teléfono número JSON' => [['telefono' => 3001234567], ['telefono']];
        yield 'biografía larga' => [['biografia' => str_repeat('b', 1001)], ['biografia']];
        yield 'programa array' => [['programa_academico' => ['Sistemas']], ['programa_academico']];
    }

    /**
     * @param array<string, mixed> $cambios
     * @param list<string>         $camposConError
     */
    #[DataProvider('datosInvalidos')]
    public function testValidaciones(array $cambios, array $camposConError): void
    {
        [$token] = $this->sesionComo($this->app, 'superadministrador');

        $response = $this->registrar($token, $this->datos($cambios));

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        $fields = $this->json($response)['error']['fields'];
        foreach ($camposConError as $campo) {
            self::assertArrayHasKey($campo, $fields);
        }
        self::assertCount(count($camposConError), $fields, json_encode($fields, JSON_THROW_ON_ERROR));
        self::assertNull($this->credencialDe(self::NUEVO_CORREO));
    }

    public function testCuerpoNoJsonResponde400(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');

        $response = $this->app->handle($this->request('POST', '/api/v1/auth/register', '{no json', self::bearer($token)));

        $this->assertError($response, 400, 'INVALID_JSON');
    }

    public function testElRolSaleDelTokenNoDelCuerpo(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');

        // Un campo "rol_actor" o similar no cambia nada: el actor es administrador según su JWT.
        $response = $this->registrar($token, $this->datos([
            'rol' => 'superadministrador',
            'actor' => 'superadministrador',
        ]));

        $this->assertError($response, 403, 'FORBIDDEN');
    }

    public function testTelefonoSeNormaliza(): void
    {
        [$token] = $this->sesionComo($this->app, 'administrador');

        $response = $this->registrar($token, $this->datos(['telefono' => ' (+57) 300-123 4567 ']));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('+573001234567', $this->json($response)['usuario']['telefono']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function registrar(string $token, array $body): ResponseInterface
    {
        return $this->app->handle($this->request('POST', '/api/v1/auth/register', $body, self::bearer($token)));
    }

    /**
     * @param array<string, mixed> $cambios
     *
     * @return array<string, mixed>
     */
    private function datos(array $cambios = []): array
    {
        return $cambios + [
            'correo' => self::NUEVO_CORREO,
            'password' => self::PASSWORD,
            'rol' => 'estudiante',
            'nombre' => 'Luis',
            'apellido' => 'Gómez',
            'programa_academico' => 'Ingeniería de Sistemas y Computación',
            'semestre' => 6,
            'biografia' => 'Me interesa la IA.',
            'telefono' => '+57 300 123 4567',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function credencialDe(string $correo): ?array
    {
        foreach ($this->db->credenciales as $doc) {
            if ($doc['correo'] === $correo) {
                return $doc;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function usuariosCon(string $correo): array
    {
        return array_values(array_filter(
            $this->db->credenciales,
            static fn (array $doc): bool => $doc['correo'] === $correo,
        ));
    }
}
