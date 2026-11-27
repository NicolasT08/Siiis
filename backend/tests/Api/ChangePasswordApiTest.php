<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use MongoDB\BSON\UTCDateTime;
use Psr\Http\Message\ResponseInterface;
use Siiis\Validation\ChangePasswordValidator;
use Slim\App;

/**
 * POST /auth/change-password (PROPUESTA DEC-B23).
 */
final class ChangePasswordApiTest extends AuthTestCase
{
    private const NUEVA = 'Otra-Clave-2027';

    /**
     * @param App<\Psr\Container\ContainerInterface> $app
     */
    private function cambiar(
        App $app,
        ?string $token,
        mixed $body,
        string $ip = '203.0.113.10',
    ): ResponseInterface {
        return $app->handle($this->request(
            'POST',
            '/api/v1/auth/change-password',
            $body,
            $token === null ? [] : self::bearer($token),
            $ip,
        ));
    }

    /**
     * @param App<\Psr\Container\ContainerInterface> $app
     */
    private function me(App $app, string $token): ResponseInterface
    {
        return $app->handle($this->request('GET', '/api/v1/auth/me', null, self::bearer($token)));
    }

    /**
     * @return array<string, mixed>
     */
    private static function cuerpo(string $actual = self::PASSWORD, string $nueva = self::NUEVA): array
    {
        return ['password_actual' => $actual, 'password_nueva' => $nueva];
    }

    public function testCambiaLaContrasenaYMantieneSoloLaSesionActual(): void
    {
        [$credId] = $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp();
        $actual = $this->tokenDe($app);
        $otra = $this->tokenDe($app);

        $response = $this->cambiar($app, $actual, self::cuerpo());

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(
            'Tu contraseña fue actualizada. Se cerraron tus sesiones en otros dispositivos.',
            $this->json($response)['mensaje'],
        );
        $hash = $this->db->credenciales[(string) $credId]['password'];
        self::assertIsString($hash);
        self::assertTrue(password_verify(self::NUEVA, $hash));
        self::assertStringNotContainsString(self::NUEVA, (string) $response->getBody());

        self::assertSame(200, $this->me($app, $actual)->getStatusCode());
        $this->assertError($this->me($app, $otra), 401, 'UNAUTHORIZED');

        $this->assertError($this->login($app), 401, 'INVALID_CREDENTIALS');
        self::assertSame(200, $this->login($app, self::CORREO, self::NUEVA)->getStatusCode());
    }

    public function testNoCierraSesionesDeOtrosUsuarios(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp();
        [$ajeno] = $this->sesionComo($app, 'estudiante');

        self::assertSame(200, $this->cambiar($app, $this->tokenDe($app), self::cuerpo())->getStatusCode());

        self::assertSame(200, $this->me($app, $ajeno)->getStatusCode());
    }

    public function testInvalidaElEnlaceDeRecuperacionPendiente(): void
    {
        [$credId] = $this->db->crearCuenta(self::CORREO, self::PASSWORD, [
            'token_recuperacion' => str_repeat('a', 64),
            'token_expiracion' => new UTCDateTime(($this->clock->now()->getTimestamp() + 600) * 1000),
        ]);
        $app = $this->authApp();

        self::assertSame(200, $this->cambiar($app, $this->tokenDe($app), self::cuerpo())->getStatusCode());

        self::assertNull($this->db->credenciales[(string) $credId]['token_recuperacion']);
        self::assertNull($this->db->credenciales[(string) $credId]['token_expiracion']);
    }

    public function testContrasenaActualIncorrectaNoSumaIntentosFallidos(): void
    {
        [$credId] = $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp(['MAX_LOGIN_ATTEMPTS' => '1']);
        $token = $this->tokenDe($app);
        $hashAntes = $this->db->credenciales[(string) $credId]['password'];

        $response = $this->cambiar($app, $token, self::cuerpo('No-Es-La-2026'));

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertSame(
            ['password_actual' => 'La contraseña actual no es correcta.'],
            $this->json($response)['error']['fields'],
        );
        self::assertSame(0, $this->db->credenciales[(string) $credId]['intentos_fallidos']);
        self::assertSame($hashAntes, $this->db->credenciales[(string) $credId]['password']);
        self::assertSame(200, $this->me($app, $token)->getStatusCode());
        self::assertSame(200, $this->login($app)->getStatusCode());
    }

    public function testNuevaIgualALaActual(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp();

        $response = $this->cambiar($app, $this->tokenDe($app), self::cuerpo(self::PASSWORD, self::PASSWORD));

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertSame(
            ['password_nueva' => ChangePasswordValidator::MISMA_PASSWORD],
            $this->json($response)['error']['fields'],
        );
    }

    public function testNuevaQueNoCumpleLaPoliticaNoCambiaNada(): void
    {
        [$credId] = $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp();
        $token = $this->tokenDe($app);
        $otra = $this->tokenDe($app);
        $hashAntes = $this->db->credenciales[(string) $credId]['password'];

        $response = $this->cambiar($app, $token, self::cuerpo(self::PASSWORD, 'sinnumeros'));

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertArrayHasKey('password_nueva', $this->json($response)['error']['fields']);
        self::assertSame($hashAntes, $this->db->credenciales[(string) $credId]['password']);
        self::assertSame(200, $this->me($app, $otra)->getStatusCode());
    }

    public function testRechazaObjetosEnLugarDeTexto(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp();

        $response = $this->cambiar($app, $this->tokenDe($app), [
            'password_actual' => ['$ne' => ''],
            'password_nueva' => self::NUEVA,
        ]);

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertSame(
            ['password_actual' => 'Este campo debe ser texto.'],
            $this->json($response)['error']['fields'],
        );
    }

    public function testJsonInvalido(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp();

        $this->assertError($this->cambiar($app, $this->tokenDe($app), '{"password_actual":'), 400, 'INVALID_JSON');
    }

    public function testSinTokenOConSesionCerrada(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp();

        $this->assertError($this->cambiar($app, null, self::cuerpo()), 401, 'UNAUTHORIZED');
        $this->assertError($this->cambiar($app, 'abc.def.ghi', self::cuerpo()), 401, 'UNAUTHORIZED');

        $token = $this->tokenDe($app);
        $app->handle($this->request('POST', '/api/v1/auth/logout', null, self::bearer($token)));
        $this->assertError($this->cambiar($app, $token, self::cuerpo()), 401, 'UNAUTHORIZED');
    }

    public function testUsuarioDesactivadoConSesionAbierta(): void
    {
        [, $usuarioId] = $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp();
        $token = $this->tokenDe($app);
        $this->db->usuarios[(string) $usuarioId]['estado'] = false;

        $this->assertError($this->cambiar($app, $token, self::cuerpo()), 401, 'UNAUTHORIZED');
    }

    public function testCuentaBloqueadaConSesionAbierta(): void
    {
        [$credId] = $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp();
        $token = $this->tokenDe($app);
        $this->db->credenciales[(string) $credId]['estado_cuenta'] = 'bloqueada';
        $hashAntes = $this->db->credenciales[(string) $credId]['password'];

        $this->assertError($this->cambiar($app, $token, self::cuerpo()), 403, 'ACCOUNT_BLOCKED');
        self::assertSame($hashAntes, $this->db->credenciales[(string) $credId]['password']);
    }

    public function testRateLimitPorIpCuentaTambienLosIntentosInvalidos(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp([
            'RATE_LIMIT_CHANGE_PASSWORD_MAX' => '2',
            'RATE_LIMIT_CHANGE_PASSWORD_WINDOW_SECONDS' => '900',
        ]);
        $token = $this->tokenDe($app);

        $this->cambiar($app, $token, self::cuerpo('Mal-2026'));
        $this->cambiar($app, $token, ['password_nueva' => 'x']);
        $response = $this->cambiar($app, $token, self::cuerpo());

        $this->assertError($response, 429, 'RATE_LIMITED');
        self::assertSame('900', $response->getHeaderLine('Retry-After'));
        self::assertSame(200, $this->cambiar($app, $token, self::cuerpo(), '198.51.100.7')->getStatusCode());
    }

    public function testSinTokenNoConsumeElLimite(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp(['RATE_LIMIT_CHANGE_PASSWORD_MAX' => '1']);

        $this->assertError($this->cambiar($app, null, self::cuerpo()), 401, 'UNAUTHORIZED');
        $this->assertError($this->cambiar($app, null, self::cuerpo()), 401, 'UNAUTHORIZED');

        self::assertSame(200, $this->cambiar($app, $this->tokenDe($app), self::cuerpo())->getStatusCode());
    }

    public function testGetNoEstaPermitido(): void
    {
        $response = $this->authApp()->handle($this->request('GET', '/api/v1/auth/change-password'));

        $this->assertError($response, 405, 'METHOD_NOT_ALLOWED');
    }
}
