<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use MongoDB\BSON\UTCDateTime;
use Siiis\Services\MailService;
use Siiis\Support\TokenGenerator;
use Siiis\Tests\Support\FakeMailService;
use Slim\App;

final class PasswordResetApiTest extends AuthTestCase
{
    private FakeMailService $mail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useMail(new FakeMailService());
    }

    private function useMail(FakeMailService $mail): void
    {
        $this->mail = $mail;
        $this->extraOverrides = [MailService::class => $mail];
    }

    /**
     * @param App<\Psr\Container\ContainerInterface> $app
     */
    private function forgot(App $app, string $correo): \Psr\Http\Message\ResponseInterface
    {
        return $app->handle($this->request('POST', '/api/v1/auth/forgot-password', ['correo' => $correo]));
    }

    /**
     * @param App<\Psr\Container\ContainerInterface> $app
     */
    private function reset(App $app, string $token, string $password): \Psr\Http\Message\ResponseInterface
    {
        return $app->handle($this->request('POST', '/api/v1/auth/reset-password', ['token' => $token, 'password' => $password]));
    }

    public function testForgotRespondeIgualExistaONoElCorreo(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp();

        $existe = $this->forgot($app, self::CORREO);
        $noExiste = $this->forgot($app, 'nadie@uptc.edu.co');

        self::assertSame(200, $existe->getStatusCode());
        self::assertSame(200, $noExiste->getStatusCode());
        self::assertSame((string) $existe->getBody(), (string) $noExiste->getBody());
        self::assertCount(1, $this->mail->enviados);
        self::assertSame(self::CORREO, $this->mail->enviados[0]['to']);
    }

    public function testForgotGuardaSoloElHashDelTokenConExpiracion(): void
    {
        [$credId] = $this->db->crearCuenta(self::CORREO, self::PASSWORD);

        $this->forgot($this->authApp(['PASSWORD_RESET_TTL_MINUTES' => '30']), self::CORREO);

        $token = $this->mail->ultimoToken();
        $cred = $this->db->credenciales[(string) $credId];
        self::assertSame(TokenGenerator::hash($token), $cred['token_recuperacion']);
        self::assertNotSame($token, $cred['token_recuperacion']);
        self::assertInstanceOf(UTCDateTime::class, $cred['token_expiracion']);
        self::assertSame(
            $this->clock->now()->getTimestamp() + 1800,
            $cred['token_expiracion']->toDateTime()->getTimestamp(),
        );
        self::assertStringContainsString(
            'http://localhost:5173/restablecer?token=' . $token,
            $this->mail->enviados[0]['html'],
        );
    }

    public function testCorreoDeRecuperacionInstitucional(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD, [], ['nombre' => 'Nicolás']);

        $this->forgot($this->authApp([
            'PASSWORD_RESET_TTL_MINUTES' => '30',
            'MAIL_LOGO_URL' => 'https://res.cloudinary.com/demo/image/upload/logo.png',
        ]), self::CORREO);

        $correo = $this->mail->enviados[0];
        $enlace = 'http://localhost:5173/restablecer?token=' . $this->mail->ultimoToken();
        self::assertSame('Restablecimiento de contraseña - Semillero SIIIS', $correo['subject']);
        foreach ([$correo['html'], $correo['text']] as $cuerpo) {
            self::assertStringContainsString('Estimado(a) Nicolás:', $cuerpo);
            self::assertStringContainsString('Restablecer contraseña', $cuerpo);
            self::assertStringContainsString($enlace, $cuerpo);
            self::assertStringContainsString('vence en 30 minutos y es de un solo uso', $cuerpo);
            self::assertStringContainsString('puede ignorar este correo', $cuerpo);
            self::assertStringContainsString('por favor no lo responda', $cuerpo);
        }
        self::assertStringContainsString('<img src="https://res.cloudinary.com/demo/image/upload/logo.png"', $correo['html']);
    }

    public function testForgotNoEnviaACuentasNoActivas(): void
    {
        $this->db->crearCuenta('bloqueada@uptc.edu.co', self::PASSWORD, ['estado_cuenta' => 'bloqueada']);
        $this->db->crearCuenta('inactivo@uptc.edu.co', self::PASSWORD, [], ['estado' => false]);
        $app = $this->authApp();

        self::assertSame(200, $this->forgot($app, 'bloqueada@uptc.edu.co')->getStatusCode());
        self::assertSame(200, $this->forgot($app, 'inactivo@uptc.edu.co')->getStatusCode());
        self::assertSame([], $this->mail->enviados);
    }

    public function testForgotRespondeGenericoAunqueFalleElSmtp(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $this->useMail(new FakeMailService(true));

        $response = $this->forgot($this->authApp(), self::CORREO);

        self::assertSame(200, $response->getStatusCode());
        $logs = implode('', array_map('file_get_contents', glob($this->storage . '/logs/*.log') ?: []));
        self::assertStringContainsString('No se pudo enviar el correo de recuperación', $logs);
    }

    public function testForgotCorreoInvalido400(): void
    {
        $this->assertError($this->forgot($this->authApp(), 'no-es-correo'), 400, 'VALIDATION_ERROR');
    }

    public function testForgotConArrayEsRechazado(): void
    {
        $response = $this->authApp()->handle($this->request('POST', '/api/v1/auth/forgot-password', ['correo' => ['$ne' => '']]));

        $this->assertError($response, 400, 'VALIDATION_ERROR');
    }

    public function testForgotRateLimit(): void
    {
        $app = $this->authApp(['RATE_LIMIT_FORGOT_MAX' => '1']);
        $this->forgot($app, 'a@uptc.edu.co');

        $this->assertError($this->forgot($app, 'b@uptc.edu.co'), 429, 'RATE_LIMITED');
    }

    public function testResetRateLimitAntesDeValidar(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp(['RATE_LIMIT_RESET_MAX' => '2', 'RATE_LIMIT_RESET_WINDOW_SECONDS' => '600']);
        $this->forgot($app, self::CORREO);
        $token = $this->mail->ultimoToken();

        $this->assertError($this->reset($app, str_repeat('a', 64), 'Nueva-Clave-2026'), 400, 'INVALID_OR_EXPIRED_TOKEN');
        $this->assertError($this->reset($app, 'corto', 'Nueva-Clave-2026'), 400, 'INVALID_OR_EXPIRED_TOKEN');

        // Tercer intento en la ventana: 429 aunque el token sea válido, y la contraseña no cambia.
        $response = $this->reset($app, $token, 'Nueva-Clave-2026');
        $this->assertError($response, 429, 'RATE_LIMITED');
        self::assertGreaterThan(0, (int) $response->getHeaderLine('Retry-After'));

        // Vencida la ventana (antes de que venza el token, a los 30 min), el mismo token funciona.
        $this->clock->advance(600);
        self::assertSame(200, $this->reset($app, $token, 'Nueva-Clave-2026')->getStatusCode());
    }

    public function testResetConTokenValido(): void
    {
        [$credId] = $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp();
        $tokenSesion = $this->tokenDe($app);
        $this->forgot($app, self::CORREO);

        $response = $this->reset($app, $this->mail->ultimoToken(), 'Nueva-Clave-2026');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $cred = $this->db->credenciales[(string) $credId];
        self::assertTrue(password_verify('Nueva-Clave-2026', $cred['password']));
        self::assertNull($cred['token_recuperacion']);
        self::assertNull($cred['token_expiracion']);

        // Todas las sesiones previas quedan desactivadas.
        $me = $app->handle($this->request('GET', '/api/v1/auth/me', null, ['Authorization' => "Bearer $tokenSesion"]));
        $this->assertError($me, 401, 'UNAUTHORIZED');

        // Login con la contraseña vieja falla y con la nueva funciona.
        $this->assertError($this->login($app), 401, 'INVALID_CREDENTIALS');
        self::assertSame(200, $this->login($app, self::CORREO, 'Nueva-Clave-2026')->getStatusCode());
    }

    public function testResetTokenEsDeUnSoloUso(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp();
        $this->forgot($app, self::CORREO);
        $token = $this->mail->ultimoToken();

        self::assertSame(200, $this->reset($app, $token, 'Nueva-Clave-2026')->getStatusCode());
        $this->assertError($this->reset($app, $token, 'Otra-Clave-2026'), 400, 'INVALID_OR_EXPIRED_TOKEN');
    }

    public function testResetConTokenInvalido(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp();

        $this->assertError($this->reset($app, str_repeat('a', 64), 'Nueva-Clave-2026'), 400, 'INVALID_OR_EXPIRED_TOKEN');
        $this->assertError($this->reset($app, 'corto', 'Nueva-Clave-2026'), 400, 'INVALID_OR_EXPIRED_TOKEN');
    }

    public function testResetConTokenExpirado(): void
    {
        [$credId] = $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp(['PASSWORD_RESET_TTL_MINUTES' => '30']);
        $this->forgot($app, self::CORREO);

        $this->clock->advance(30 * 60);

        $this->assertError($this->reset($app, $this->mail->ultimoToken(), 'Nueva-Clave-2026'), 400, 'INVALID_OR_EXPIRED_TOKEN');
        self::assertTrue(password_verify(self::PASSWORD, $this->db->credenciales[(string) $credId]['password']));
    }

    public function testResetValidaLaNuevaContrasena(): void
    {
        $app = $this->authApp(['PASSWORD_MIN_LENGTH' => '8']);

        $corta = $this->reset($app, str_repeat('a', 64), 'corta');
        $this->assertError($corta, 400, 'VALIDATION_ERROR');
        self::assertArrayHasKey('password', $this->json($corta)['error']['fields']);

        $larga = $this->reset($app, str_repeat('a', 64), str_repeat('x', 73));
        $this->assertError($larga, 400, 'VALIDATION_ERROR');

        foreach (['solo-letras', '12345678'] as $debil) {
            $response = $this->reset($app, str_repeat('a', 64), $debil);
            $this->assertError($response, 400, 'VALIDATION_ERROR');
            self::assertSame(
                'La contraseña debe tener al menos una letra y un número.',
                $this->json($response)['error']['fields']['password'],
            );
        }
    }

    public function testResetConContrasenaDebilNoConsumeElToken(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp();
        $this->forgot($app, self::CORREO);
        $token = $this->mail->ultimoToken();

        $this->assertError($this->reset($app, $token, 'sin-numeros'), 400, 'VALIDATION_ERROR');

        self::assertSame(200, $this->reset($app, $token, 'Nueva-Clave-2026')->getStatusCode());
    }

    public function testResetRechazaLaMismaContrasenaYNoConsumeElToken(): void
    {
        [$credId] = $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp();
        $this->forgot($app, self::CORREO);
        $token = $this->mail->ultimoToken();

        $response = $this->reset($app, $token, self::PASSWORD);

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertSame('La nueva contraseña debe ser distinta de la actual.', $this->json($response)['error']['fields']['password']);
        // La contraseña no cambió y el enlace sigue sirviendo para una distinta.
        self::assertSame(200, $this->login($app, self::CORREO, self::PASSWORD)->getStatusCode());
        self::assertNotNull($this->db->credenciales[(string) $credId]['token_recuperacion']);
        self::assertSame(200, $this->reset($app, $token, 'Otra-Clave-2027')->getStatusCode());
    }

    public function testResetConArraysEsRechazado(): void
    {
        $response = $this->authApp()->handle($this->request('POST', '/api/v1/auth/reset-password', [
            'token' => ['$ne' => ''],
            'password' => 'Nueva-Clave-2026',
        ]));

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertArrayHasKey('token', $this->json($response)['error']['fields']);
    }
}
