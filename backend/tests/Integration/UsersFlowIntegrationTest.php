<?php

declare(strict_types=1);

namespace Siiis\Tests\Integration;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Psr\Http\Message\ResponseInterface;
use Siiis\Config\Application;
use Siiis\Database\Collections;
use Siiis\Database\Mongo;
use Siiis\Repositories\CuentasRepository;
use Siiis\Repositories\DuplicateKeyException;
use Siiis\Services\CloudinaryService;
use Siiis\Services\MailService;
use Siiis\Tests\Support\FakeCloudinaryService;
use Siiis\Tests\Support\FakeMailService;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Fase 2 de punta a punta contra Atlas (base de pruebas): transacción de registro, paginación real y
 * ciclo edición → desactivación → reactivación. Correo y Cloudinary simulados (Cloudinary real tiene
 * su propia prueba). Todo lo creado lleva el prefijo `$marca` y se borra al terminar.
 */
final class UsersFlowIntegrationTest extends IntegrationTestCase
{
    private const PASSWORD = 'Clave-Integracion-2026';

    private string $marca;

    /** @var App<\Psr\Container\ContainerInterface> */
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marca = 'it-flujo-' . bin2hex(random_bytes(4));
        $this->app = Application::create($this->settings(), [
            Mongo::class => $this->mongo(),
            MailService::class => new FakeMailService(),
            CloudinaryService::class => new FakeCloudinaryService(),
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->marca)) {
            return;
        }
        $regex = ['$regex' => '^' . preg_quote($this->marca, '/') . '-'];
        $usuarioIds = [];
        foreach ($this->mongo()->collection(Collections::CREDENCIALES)->find(['correo' => $regex]) as $c) {
            $usuarioIds[] = $c['usuario_id'];
        }
        $this->mongo()->collection(Collections::SESIONES)->deleteMany(['usuario_id' => ['$in' => $usuarioIds]]);
        $this->mongo()->collection(Collections::USUARIOS)->deleteMany(['$or' => [
            ['_id' => ['$in' => $usuarioIds]],
            ['nombre' => $regex],
        ]]);
        $this->mongo()->collection(Collections::CREDENCIALES)->deleteMany(['correo' => $regex]);
    }

    public function testRegistroCreaAmbosDocumentosYDuplicadoResponde409(): void
    {
        $token = $this->tokenSuperadmin();
        $correo = $this->marca . '-luis@example.test';
        $body = ['correo' => $correo, 'password' => self::PASSWORD, 'rol' => 'estudiante', 'nombre' => 'Luis', 'apellido' => 'Gómez'];

        $response = $this->call('POST', '/auth/register', $body, $token);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $cred = $this->mongo()->collection(Collections::CREDENCIALES)->findOne(['correo' => $correo]);
        self::assertIsArray($cred);
        $usuario = $this->mongo()->collection(Collections::USUARIOS)->findOne(['_id' => $cred['usuario_id']]);
        self::assertIsArray($usuario);
        self::assertSame((string) $cred['_id'], (string) $usuario['credencial_id']);
        self::assertSame(self::json($response)['usuario']['id'], (string) $usuario['_id']);

        // Mayúsculas: el correo se normaliza y sigue siendo duplicado.
        $this->assertError($this->call('POST', '/auth/register', ['correo' => strtoupper($correo)] + $body, $token), 409, 'EMAIL_TAKEN');
        self::assertSame(1, $this->mongo()->collection(Collections::CREDENCIALES)->countDocuments(['correo' => $correo]));
        self::assertSame(1, $this->mongo()->collection(Collections::USUARIOS)->countDocuments(['credencial_id' => $cred['_id']]));
    }

    public function testLaTransaccionRevierteCredencialesSiFallaUsuarios(): void
    {
        // Usuarios con un _id ya existente: falla la segunda inserción de la transacción.
        $usuarioExistente = new ObjectId();
        $this->mongo()->collection(Collections::USUARIOS)->insertOne([
            '_id' => $usuarioExistente, 'nombre' => $this->marca . '-ocupado', 'apellido' => 'X', 'rol' => 'estudiante', 'estado' => true,
        ]);
        $correo = $this->marca . '-revertido@example.test';

        try {
            (new CuentasRepository($this->mongo()))->crear(
                ['_id' => new ObjectId(), 'correo' => $correo, 'password' => 'hash', 'usuario_id' => $usuarioExistente],
                ['_id' => $usuarioExistente, 'nombre' => 'Otro', 'apellido' => 'X', 'rol' => 'estudiante', 'estado' => true],
            );
            self::fail('Se esperaba DuplicateKeyException');
        } catch (DuplicateKeyException) {
        }

        self::assertSame(0, $this->mongo()->collection(Collections::CREDENCIALES)->countDocuments(['correo' => $correo]), 'La credencial debió revertirse.');
        $usuario = $this->mongo()->collection(Collections::USUARIOS)->findOne(['_id' => $usuarioExistente]);
        self::assertIsArray($usuario);
        self::assertSame($this->marca . '-ocupado', $usuario['nombre']);
    }

    public function testPaginacionRealRecorreTodasLasPaginasSinRepetir(): void
    {
        $token = $this->tokenSuperadmin();
        // Rol y estado poco comunes para acotar el conjunto; puede haber otros documentos en siiis_test.
        foreach (['Zapata', 'bernal', 'Álvarez', 'Castro', 'Ébano'] as $apellido) {
            $this->crearCuenta(strtolower($apellido), 'docente_director', ['apellido' => $apellido, 'estado' => false]);
        }

        $primera = self::json($this->call('GET', '/users?rol=docente_director&estado=false&per_page=2', null, $token));
        $total = $primera['meta']['total'];
        self::assertGreaterThanOrEqual(5, $total);
        self::assertSame((int) ceil($total / 2), $primera['meta']['total_pages']);

        $ids = [];
        $apellidosPropios = [];
        for ($page = 1; $page <= $primera['meta']['total_pages']; ++$page) {
            $pagina = self::json($this->call('GET', "/users?rol=docente_director&estado=false&per_page=2&page=$page", null, $token));
            self::assertSame($total, $pagina['meta']['total']);
            foreach ($pagina['data'] as $u) {
                $ids[] = $u['id'];
                if (str_starts_with((string) $u['nombre'], $this->marca)) {
                    $apellidosPropios[] = $u['apellido'];
                }
            }
        }

        self::assertCount($total, $ids);
        self::assertSame($ids, array_values(array_unique($ids)), 'Un usuario apareció en dos páginas.');
        // Collation "es": ignora tildes y mayúsculas.
        self::assertSame(['Álvarez', 'bernal', 'Castro', 'Ébano', 'Zapata'], $apellidosPropios);
        self::assertSame([], self::json($this->call('GET', '/users?rol=docente_director&estado=false&per_page=2&page=' . ($primera['meta']['total_pages'] + 1), null, $token))['data']);
    }

    public function testEdicionDesactivacionYReactivacion(): void
    {
        $admin = $this->tokenSuperadmin();
        [$correo, $id] = $this->crearCuenta('ana', 'estudiante');
        $tokenAna = $this->login($correo);

        // Edición propia.
        $propia = $this->call('PATCH', "/users/$id", ['biografia' => 'Hola', 'telefono' => '+57 300 123 4567'], $tokenAna);
        self::assertSame(200, $propia->getStatusCode(), (string) $propia->getBody());
        self::assertSame('+573001234567', self::json($propia)['usuario']['telefono']);

        // Cambio de rol: cierra sus sesiones en Atlas.
        self::assertSame(200, $this->call('PATCH', "/users/$id", ['rol' => 'estudiante_lider'], $admin)->getStatusCode());
        $this->assertError($this->call('GET', '/auth/me', null, $tokenAna), 401, 'UNAUTHORIZED');
        self::assertSame(0, $this->mongo()->collection(Collections::SESIONES)->countDocuments(['usuario_id' => new ObjectId($id), 'activa' => true]));
        $tokenAna = $this->login($correo);

        // Desactivación lógica.
        $baja = $this->call('DELETE', "/users/$id", null, $admin);
        self::assertSame(200, $baja->getStatusCode(), (string) $baja->getBody());
        $doc = $this->mongo()->collection(Collections::USUARIOS)->findOne(['_id' => new ObjectId($id)]);
        self::assertIsArray($doc);
        self::assertFalse($doc['estado']);
        self::assertInstanceOf(UTCDateTime::class, $doc['fecha_salida']);
        self::assertInstanceOf(UTCDateTime::class, $doc['fecha_ingreso']);
        $this->assertError($this->call('GET', '/auth/me', null, $tokenAna), 401, 'UNAUTHORIZED');
        $this->assertError($this->call('POST', '/auth/login', ['correo' => $correo, 'password' => self::PASSWORD]), 403, 'ACCOUNT_INACTIVE');
        $this->assertError($this->call('GET', "/users/$id"), 404, 'NOT_FOUND');

        // Reactivación.
        $alta = $this->call('PATCH', "/users/$id", ['estado' => true], $admin);
        self::assertSame(200, $alta->getStatusCode(), (string) $alta->getBody());
        $doc = $this->mongo()->collection(Collections::USUARIOS)->findOne(['_id' => new ObjectId($id)]);
        self::assertIsArray($doc);
        self::assertTrue($doc['estado']);
        self::assertNull($doc['fecha_salida']);
        self::assertSame('estudiante_lider', $doc['rol']);
        self::assertSame(200, $this->call('GET', "/users/$id")->getStatusCode());
        $this->login($correo);
    }

    /**
     * Superadministrador sembrado directamente (no depende de los superadministradores de siiis_test).
     */
    private function tokenSuperadmin(): string
    {
        [$correo] = $this->crearCuenta('super', 'superadministrador');

        return $this->login($correo);
    }

    /**
     * @param array<string, mixed> $usuario
     *
     * @return array{string, string} [correo, usuario_id]
     */
    private function crearCuenta(string $alias, string $rol, array $usuario = []): array
    {
        $correo = $this->marca . '-' . $alias . '@example.test';
        $credId = new ObjectId();
        $usuarioId = new ObjectId();
        $this->mongo()->collection(Collections::CREDENCIALES)->insertOne([
            '_id' => $credId, 'correo' => $correo, 'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            'usuario_id' => $usuarioId, 'estado_cuenta' => 'activa', 'token_recuperacion' => null,
            'token_expiracion' => null, 'intentos_fallidos' => 0, 'fecha_creacion' => new UTCDateTime(), 'ultimo_acceso' => null,
        ]);
        $this->mongo()->collection(Collections::USUARIOS)->insertOne($usuario + [
            '_id' => $usuarioId, 'credencial_id' => $credId, 'nombre' => $this->marca . '-' . $alias, 'apellido' => 'IT',
            'rol' => $rol, 'programa_academico' => null, 'semestre' => null, 'foto_perfil' => null, 'biografia' => null,
            'telefono' => null, 'estado' => true, 'fecha_ingreso' => new UTCDateTime(), 'fecha_salida' => null,
        ]);

        return [$correo, (string) $usuarioId];
    }

    private function login(string $correo): string
    {
        $response = $this->call('POST', '/auth/login', ['correo' => $correo, 'password' => self::PASSWORD]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $token = self::json($response)['token'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function call(string $method, string $path, ?array $body = null, ?string $token = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, "/api/v1$path", ['REMOTE_ADDR' => '198.51.100.' . random_int(1, 254)]);
        if ($token !== null) {
            $request = $request->withHeader('Authorization', "Bearer $token");
        }
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream((string) json_encode($body)));
        }

        return $this->app->handle($request);
    }

    private function assertError(ResponseInterface $response, int $status, string $code): void
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($code, self::json($response)['error']['code'] ?? null);
    }

    /**
     * @return array<mixed>
     */
    private static function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        return $data;
    }
}
