<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use Psr\Http\Message\ResponseInterface;
use Siiis\Repositories\CredencialesRepository;
use Siiis\Repositories\SesionesRepository;
use Siiis\Repositories\UsuariosRepository;
use Siiis\Support\Clock;
use Siiis\Tests\Support\FixedClock;
use Siiis\Tests\Support\InMemoryCredencialesRepository;
use Siiis\Tests\Support\InMemoryDatabase;
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
    protected FixedClock $clock;
    protected string $storage;

    /** @var array<string, mixed> */
    protected array $extraOverrides = [];

    protected function setUp(): void
    {
        $this->db = new InMemoryDatabase();
        $this->credenciales = new InMemoryCredencialesRepository($this->db);
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
        ], $this->storage);

        return \Siiis\Config\Application::create($settings, $this->extraOverrides + [
            CredencialesRepository::class => $this->credenciales,
            UsuariosRepository::class => new InMemoryUsuariosRepository($this->db),
            SesionesRepository::class => new InMemorySesionesRepository($this->db),
            Clock::class => $this->clock,
        ]);
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
