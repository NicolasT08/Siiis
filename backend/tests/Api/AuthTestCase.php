<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use Psr\Http\Message\ResponseInterface;
use Siiis\Repositories\CredencialesRepository;
use Siiis\Repositories\CuentasRepository;
use Siiis\Repositories\DocumentosRepository;
use Siiis\Repositories\InscripcionesRepository;
use Siiis\Repositories\MensajesContactoRepository;
use Siiis\Repositories\ResenasRepository;
use Siiis\Repositories\SesionesRepository;
use Siiis\Repositories\UsuariosRepository;
use Siiis\Support\Clock;
use Siiis\Tests\Support\FixedClock;
use Siiis\Tests\Support\InMemoryCredencialesRepository;
use Siiis\Tests\Support\InMemoryCuentasRepository;
use Siiis\Tests\Support\InMemoryDatabase;
use Siiis\Tests\Support\InMemoryDocumentosRepository;
use Siiis\Tests\Support\InMemoryInscripcionesRepository;
use Siiis\Tests\Support\InMemoryMensajesContactoRepository;
use Siiis\Tests\Support\InMemoryResenasRepository;
use Siiis\Tests\Support\InMemorySesionesRepository;
use Siiis\Tests\Support\InMemoryUsuariosRepository;
use Slim\App;

/**
 * Base de pruebas de auth: repositorios en memoria, reloj fijo y rate limit en directorio temporal.
 */
abstract class AuthTestCase extends ApiTestCase
{
    protected const CORREO = 'ana@uptc.edu.co';
    protected const PASSWORD = 'Clave-Segura-2026';

    protected InMemoryDatabase $db;
    protected InMemoryCredencialesRepository $credenciales;
    protected InMemoryUsuariosRepository $usuarios;
    protected InMemoryDocumentosRepository $documentos;
    protected InMemoryMensajesContactoRepository $mensajes;
    protected InMemoryInscripcionesRepository $inscripciones;
    protected FixedClock $clock;
    protected string $storage;

    /** @var array<string, mixed> */
    protected array $extraOverrides = [];

    protected function setUp(): void
    {
        $this->db = new InMemoryDatabase();
        $this->credenciales = new InMemoryCredencialesRepository($this->db);
        $this->usuarios = new InMemoryUsuariosRepository($this->db);
        $this->documentos = new InMemoryDocumentosRepository($this->db);
        $this->mensajes = new InMemoryMensajesContactoRepository($this->db);
        $this->inscripciones = new InMemoryInscripcionesRepository($this->db);
        $this->clock = new FixedClock();
        $this->storage = sys_get_temp_dir() . '/siiis-api-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storage . '/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->storage . '/*') ?: [] as $dir) {
            @rmdir($dir);
        }
        @rmdir($this->storage);
    }

    /**
     * @param array<string, string> $env
     *
     * @return App<\Psr\Container\ContainerInterface>
     */
    protected function authApp(array $env = []): App
    {
        $settings = \Siiis\Config\Settings::fromEnv($env + [
            'APP_ENV' => 'testing',
            'MONGO_URI' => 'mongodb://127.0.0.1:1',
            'JWT_SECRET' => \Siiis\Tests\Support\TestSettings::JWT_SECRET,
            'RATE_LIMIT_LOGIN_MAX' => '100',
            'RATE_LIMIT_FORGOT_MAX' => '100',
            'RATE_LIMIT_REVIEWS_MAX' => '100',
            'RATE_LIMIT_CONTACT_MAX' => '100',
            'RATE_LIMIT_INSCRIPTIONS_MAX' => '100',
        ], $this->storage);

        return \Siiis\Config\Application::create($settings, $this->extraOverrides + [
            CredencialesRepository::class => $this->credenciales,
            UsuariosRepository::class => $this->usuarios,
            DocumentosRepository::class => $this->documentos,
            ResenasRepository::class => new InMemoryResenasRepository($this->db),
            MensajesContactoRepository::class => $this->mensajes,
            InscripcionesRepository::class => $this->inscripciones,
            SesionesRepository::class => new InMemorySesionesRepository($this->db),
            CuentasRepository::class => new InMemoryCuentasRepository($this->db),
            Clock::class => $this->clock,
        ]);
    }

    /**
     * Crea una cuenta activa con el rol dado, inicia sesión y devuelve [token, usuarioId].
     *
     * @param App<\Psr\Container\ContainerInterface> $app
     * @param array<string, mixed>                   $usuario campos extra del perfil
     *
     * @return array{string, \MongoDB\BSON\ObjectId}
     */
    protected function sesionComo(App $app, string $rol, array $usuario = []): array
    {
        $correo = $rol . '-' . bin2hex(random_bytes(3)) . '@uptc.edu.co';
        [, $usuarioId] = $this->db->crearCuenta($correo, self::PASSWORD, [], ['rol' => $rol] + $usuario);

        $body = $this->json($this->login($app, $correo));
        self::assertIsString($body['token'] ?? null);

        return [$body['token'], $usuarioId];
    }

    /**
     * @return array<string, string>
     */
    protected static function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    /**
     * @param App<\Psr\Container\ContainerInterface> $app
     */
    protected function login(App $app, string $correo = self::CORREO, string $password = self::PASSWORD): ResponseInterface
    {
        return $app->handle($this->request('POST', '/api/v1/auth/login', ['correo' => $correo, 'password' => $password]));
    }

    /**
     * @param App<\Psr\Container\ContainerInterface> $app
     */
    protected function tokenDe(App $app): string
    {
        $body = $this->json($this->login($app));
        self::assertIsString($body['token'] ?? null);

        return $body['token'];
    }
}
