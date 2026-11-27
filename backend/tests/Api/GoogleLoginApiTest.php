<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Siiis\Security\GoogleJwksProvider;
use Siiis\Support\Logger;
use Siiis\Tests\Support\GoogleTokens;
use Slim\App;

/**
 * POST /api/v1/auth/google (RF-17, DEC-B61) con id_token firmados por una llave de prueba.
 */
final class GoogleLoginApiTest extends AuthTestCase
{
    private int $descargas = 0;

    /**
     * @param array<string, string> $env
     *
     * @return App<\Psr\Container\ContainerInterface>
     */
    private function googleApp(array $env = []): App
    {
        $this->extraOverrides[GoogleJwksProvider::class] = new GoogleJwksProvider(
            $this->storage . '/cache/google_jwks.json',
            $this->clock,
            new Logger($this->storage . '/logs'),
            GoogleTokens::descargador($this->descargas),
        );

        return $this->authApp($env + ['GOOGLE_CLIENT_ID' => GoogleTokens::CLIENT_ID]);
    }

    /**
     * @param App<\Psr\Container\ContainerInterface> $app
     */
    private function google(App $app, mixed $idToken, string $ip = '203.0.113.10'): ResponseInterface
    {
        return $app->handle($this->request('POST', '/api/v1/auth/google', ['id_token' => $idToken], [], $ip));
    }

    private function ahora(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    public function testExitoEmiteElMismoJwtYSesionQueElLogin(): void
    {
        [$credId, $userId] = $this->db->crearCuenta(self::CORREO, self::PASSWORD, ['intentos_fallidos' => 3], ['rol' => 'estudiante_lider']);
        $app = $this->googleApp();

        $response = $this->google($app, GoogleTokens::token('ANA@uptc.edu.co', $this->ahora()));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $body = $this->json($response);
        self::assertSame(28800, $body['expira_en']);
        self::assertSame((string) $userId, $body['usuario']['id']);
        self::assertSame('estudiante_lider', $body['usuario']['rol']);
        self::assertCount(1, $this->db->sesiones);
        $sesion = array_values($this->db->sesiones)[0];
        self::assertSame((string) $userId, (string) $sesion['usuario_id']);
        self::assertSame('203.0.113.10', $sesion['ip_acceso']);
        // Registra el acceso como el login normal (reinicia intentos fallidos).
        self::assertSame(0, $this->db->credenciales[(string) $credId]['intentos_fallidos']);

        // El token sirve en rutas protegidas, como el del login con contraseña.
        $me = $app->handle($this->request('GET', '/api/v1/auth/me', null, ['Authorization' => 'Bearer ' . $body['token']]));
        self::assertSame(200, $me->getStatusCode());
        self::assertSame((string) $userId, $this->json($me)['usuario']['id']);
    }

    public function testLasLlavesSeGuardanEnCacheYSeRenuevanSiLlegaUnKidNuevo(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->googleApp();

        $this->google($app, GoogleTokens::token(self::CORREO, $this->ahora()));
        $this->google($app, GoogleTokens::token(self::CORREO, $this->ahora()));
        self::assertSame(1, $this->descargas, 'La segunda vez debe usar storage/cache/google_jwks.json.');
        self::assertFileExists($this->storage . '/cache/google_jwks.json');

        // Un kid que no está en la copia fuerza una descarga (rotación de llaves de Google).
        $this->google($app, GoogleTokens::token(self::CORREO, $this->ahora(), [], false, 'kid-nuevo'));
        self::assertSame(2, $this->descargas);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool, int, string}>
     */
    public static function tokensRechazados(): iterable
    {
        yield 'otro dominio' => [['email' => 'ana@gmail.com', 'hd' => null], false, 403, 'GOOGLE_DOMAIN_NOT_ALLOWED'];
        yield 'hd de otro Workspace' => [['hd' => 'otra.edu.co'], false, 403, 'GOOGLE_DOMAIN_NOT_ALLOWED'];
        yield 'sin hd (cuenta personal)' => [['hd' => null], false, 403, 'GOOGLE_DOMAIN_NOT_ALLOWED'];
        yield 'subdominio en hd' => [['hd' => 'alumnos.uptc.edu.co'], false, 403, 'GOOGLE_DOMAIN_NOT_ALLOWED'];
        yield 'correo no verificado' => [['email_verified' => false], false, 403, 'GOOGLE_DOMAIN_NOT_ALLOWED'];
        yield 'aud de otra aplicación' => [['aud' => 'otra-app.apps.googleusercontent.com'], false, 401, 'INVALID_GOOGLE_TOKEN'];
        yield 'emisor que no es Google' => [['iss' => 'https://evil.example'], false, 401, 'INVALID_GOOGLE_TOKEN'];
        yield 'vencido' => [['iat' => 1790000000 - 7200, 'exp' => 1790000000 - 3600], false, 401, 'INVALID_GOOGLE_TOKEN'];
        yield 'sin exp' => [['exp' => null], false, 401, 'INVALID_GOOGLE_TOKEN'];
        yield 'firmado con otra llave' => [[], true, 401, 'INVALID_GOOGLE_TOKEN'];
    }

    /**
     * @param array<string, mixed> $cambios
     */
    #[DataProvider('tokensRechazados')]
    public function testRechazaTokensQueNoCumplen(array $cambios, bool $otraLlave, int $status, string $codigo): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $this->db->crearCuenta('ana@gmail.com', self::PASSWORD);
        // FixedClock: 2026-09-25T12:00:00Z (1790337600). El caso "vencido" usa fechas fijas anteriores.
        $response = $this->google($this->googleApp(), GoogleTokens::token(self::CORREO, $this->ahora(), $cambios, $otraLlave));

        $this->assertError($response, $status, $codigo);
        self::assertSame([], $this->db->sesiones);
    }

    public function testSinCuentaRespondeNotAMemberYNoCreaNada(): void
    {
        $response = $this->google($this->googleApp(), GoogleTokens::token('nuevo@uptc.edu.co', $this->ahora()));

        $this->assertError($response, 403, 'NOT_A_MEMBER');
        self::assertStringContainsString('inscripción', $this->json($response)['error']['message']);
        self::assertSame([], $this->db->credenciales);
        self::assertSame([], $this->db->usuarios);
        self::assertSame([], $this->db->sesiones);
    }

    public function testCuentaInactivaYBloqueadaComoEnElLogin(): void
    {
        $this->db->crearCuenta('inactiva@uptc.edu.co', self::PASSWORD, [], ['estado' => false]);
        $this->db->crearCuenta('bloqueada@uptc.edu.co', self::PASSWORD, ['estado_cuenta' => 'bloqueada']);
        $this->db->crearCuenta('pendiente@uptc.edu.co', self::PASSWORD, ['estado_cuenta' => 'pendiente_verificacion']);
        $app = $this->googleApp();

        $this->assertError($this->google($app, GoogleTokens::token('inactiva@uptc.edu.co', $this->ahora())), 403, 'ACCOUNT_INACTIVE');
        $this->assertError($this->google($app, GoogleTokens::token('bloqueada@uptc.edu.co', $this->ahora())), 403, 'ACCOUNT_BLOCKED');
        $this->assertError($this->google($app, GoogleTokens::token('pendiente@uptc.edu.co', $this->ahora())), 403, 'ACCOUNT_PENDING');
        self::assertSame([], $this->db->sesiones);
    }

    public function testCuerpoInvalido(): void
    {
        $app = $this->googleApp();

        $this->assertError($this->google($app, null), 400, 'VALIDATION_ERROR');
        $this->assertError($this->google($app, ['$ne' => '']), 400, 'VALIDATION_ERROR');
        $this->assertError($this->google($app, 'no-es-un-jwt'), 400, 'VALIDATION_ERROR');
        $this->assertError($this->google($app, str_repeat('a', 5000) . '.b.c'), 400, 'VALIDATION_ERROR');
        self::assertSame(0, $this->descargas, 'Un cuerpo inválido no descarga llaves.');
    }

    public function testRateLimitingPorIpComoElLogin(): void
    {
        $app = $this->googleApp(['RATE_LIMIT_LOGIN_MAX' => '2']);
        $token = GoogleTokens::token('nadie@uptc.edu.co', $this->ahora());

        $this->assertError($this->google($app, $token), 403, 'NOT_A_MEMBER');
        $this->assertError($this->google($app, $token), 403, 'NOT_A_MEMBER');
        $response = $this->google($app, $token);
        $this->assertError($response, 429, 'RATE_LIMITED');
        self::assertNotSame('', $response->getHeaderLine('Retry-After'));
        // Otra IP no comparte el contador.
        $this->assertError($this->google($app, $token, '198.51.100.7'), 403, 'NOT_A_MEMBER');
    }

    public function testSinGoogleClientIdLaRutaNoExiste(): void
    {
        $response = $this->authApp()->handle($this->request('POST', '/api/v1/auth/google', ['id_token' => 'a.b.c']));

        $this->assertError($response, 404, 'NOT_FOUND');
    }

    public function testGoogleNoRespondeYNoHayCopia(): void
    {
        $this->extraOverrides[GoogleJwksProvider::class] = new GoogleJwksProvider(
            $this->storage . '/cache/google_jwks.json',
            $this->clock,
            new Logger($this->storage . '/logs'),
            static fn (): array => throw new \RuntimeException('sin red'),
        );
        $app = $this->authApp(['GOOGLE_CLIENT_ID' => GoogleTokens::CLIENT_ID]);
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);

        $this->assertError($this->google($app, GoogleTokens::token(self::CORREO, $this->ahora())), 503, 'GOOGLE_UNAVAILABLE');
    }

    public function testElLoginConContrasenaSigueIgual(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);

        self::assertSame(200, $this->login($this->googleApp(), self::CORREO, self::PASSWORD)->getStatusCode());
    }
}
