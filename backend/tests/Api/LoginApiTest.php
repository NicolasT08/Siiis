<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use MongoDB\BSON\UTCDateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use Siiis\Support\TokenGenerator;

final class LoginApiTest extends AuthTestCase
{
    public function testLoginExitoso(): void
    {
        [$credId, $userId] = $this->db->crearCuenta(self::CORREO, self::PASSWORD, ['intentos_fallidos' => 2]);

        $response = $this->login($this->authApp(), '  ANA@uptc.edu.co ', self::PASSWORD);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        self::assertSame(['token', 'expira_en', 'usuario'], array_keys($body));
        self::assertSame(28800, $body['expira_en']);
        self::assertSame([
            'id' => (string) $userId,
            'nombre' => 'Ana',
            'apellido' => 'Pérez',
            'rol' => 'superadministrador',
            'foto_perfil' => ['url' => 'https://res.cloudinary.com/demo/image/upload/ana.jpg'],
        ], $body['usuario']);

        // Sesión registrada con el hash del jti, nunca el JWT.
        self::assertCount(1, $this->db->sesiones);
        $sesion = array_values($this->db->sesiones)[0];
        self::assertSame((string) $userId, (string) $sesion['usuario_id']);
        self::assertTrue($sesion['activa']);
        self::assertSame('203.0.113.10', $sesion['ip_acceso']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $sesion['token']);
        self::assertStringNotContainsString($sesion['token'], $body['token']);
        self::assertInstanceOf(UTCDateTime::class, $sesion['fecha_expiracion']);
        self::assertSame(
            $this->clock->now()->getTimestamp() + 28800,
            $sesion['fecha_expiracion']->toDateTime()->getTimestamp(),
        );

        // Contador reiniciado y último acceso actualizado.
        self::assertSame(0, $this->db->credenciales[(string) $credId]['intentos_fallidos']);
        self::assertInstanceOf(UTCDateTime::class, $this->db->credenciales[(string) $credId]['ultimo_acceso']);
    }

    public function testRespuestaNoExponeSecretos(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);

        $raw = (string) $this->login($this->authApp())->getBody();

        foreach (['password', 'token_recuperacion', 'public_id', '$2y$', 'credencial_id'] as $prohibido) {
            self::assertStringNotContainsString($prohibido, $raw);
        }
    }

    public function testRehashCuandoElHashEsViejo(): void
    {
        [$credId] = $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $hashViejo = $this->db->credenciales[(string) $credId]['password'];

        $this->login($this->authApp());

        $nuevo = $this->db->credenciales[(string) $credId]['password'];
        self::assertNotSame($hashViejo, $nuevo);
        self::assertTrue(password_verify(self::PASSWORD, $nuevo));
    }

    public function testCorreoInexistenteDevuelve401Generico(): void
    {
        $response = $this->login($this->authApp(), 'nadie@uptc.edu.co', 'loquesea');

        $this->assertError($response, 401, 'INVALID_CREDENTIALS');
    }

    public function testPasswordIncorrectaDevuelveMismo401EIncrementaContador(): void
    {
        [$credId] = $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp();

        $inexistente = $this->login($app, 'nadie@uptc.edu.co', 'x');
        $incorrecta = $this->login($app, self::CORREO, 'incorrecta');

        $this->assertError($incorrecta, 401, 'INVALID_CREDENTIALS');
        self::assertSame((string) $inexistente->getBody(), (string) $incorrecta->getBody());
        self::assertSame(1, $this->db->credenciales[(string) $credId]['intentos_fallidos']);
        self::assertCount(0, $this->db->sesiones);
    }

    public function testNoBloqueaAlSuperarElUmbral(): void
    {
        [$credId] = $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp(['MAX_LOGIN_ATTEMPTS' => '2']);

        for ($i = 0; $i < 3; $i++) {
            $this->login($app, self::CORREO, 'incorrecta');
        }

        self::assertSame(3, $this->db->credenciales[(string) $credId]['intentos_fallidos']);
        self::assertSame('activa', $this->db->credenciales[(string) $credId]['estado_cuenta']);
        self::assertSame(200, $this->login($app)->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function estadosDeCuenta(): iterable
    {
        yield 'bloqueada' => ['bloqueada', 'ACCOUNT_BLOCKED'];
        yield 'pendiente' => ['pendiente_verificacion', 'ACCOUNT_PENDING'];
        yield 'desconocido' => ['otro', 'ACCOUNT_BLOCKED'];
    }

    #[DataProvider('estadosDeCuenta')]
    public function testCuentaNoActivaDevuelve403(string $estado, string $codigo): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD, ['estado_cuenta' => $estado]);

        $this->assertError($this->login($this->authApp()), 403, $codigo);
        self::assertCount(0, $this->db->sesiones);
    }

    public function testCuentaBloqueadaConPasswordIncorrectaNoRevelaElEstado(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD, ['estado_cuenta' => 'bloqueada']);

        $this->assertError($this->login($this->authApp(), self::CORREO, 'incorrecta'), 401, 'INVALID_CREDENTIALS');
    }

    public function testUsuarioInactivoDevuelve403(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD, [], ['estado' => false]);

        $this->assertError($this->login($this->authApp()), 403, 'ACCOUNT_INACTIVE');
        self::assertCount(0, $this->db->sesiones);
    }

    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function payloadsInvalidos(): iterable
    {
        yield 'objeto vacío' => ['{}', ['correo', 'password']];
        yield 'correo inválido' => [['correo' => 'no-es-correo', 'password' => 'x'], ['correo']];
        yield 'password vacía' => [['correo' => 'a@b.co', 'password' => ''], ['password']];
        yield 'password null' => [['correo' => 'a@b.co', 'password' => null], ['password']];
        yield 'password bool' => [['correo' => 'a@b.co', 'password' => true], ['password']];
    }

    /**
     * @param list<string> $campos
     */
    #[DataProvider('payloadsInvalidos')]
    public function testPayloadInvalidoDevuelve400(mixed $payload, array $campos): void
    {
        $response = $this->authApp()->handle($this->request('POST', '/api/v1/auth/login', $payload));

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertSame($campos, array_keys($this->json($response)['error']['fields']));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function intentosDeInyeccion(): iterable
    {
        yield 'correo $ne' => [['correo' => ['$ne' => ''], 'password' => 'x'], 'correo'];
        yield 'correo $regex' => [['correo' => ['$regex' => '.*'], 'password' => 'x'], 'correo'];
        yield 'password $ne' => [['correo' => 'ana@uptc.edu.co', 'password' => ['$ne' => '']], 'password'];
        yield 'correo lista' => [['correo' => ['ana@uptc.edu.co'], 'password' => 'x'], 'correo'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('intentosDeInyeccion')]
    public function testPayloadConArraysSeRechazaSinConsultar(array $payload, string $campo): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);

        $response = $this->authApp()->handle($this->request('POST', '/api/v1/auth/login', $payload));

        $this->assertError($response, 400, 'VALIDATION_ERROR');
        self::assertArrayHasKey($campo, $this->json($response)['error']['fields']);
        self::assertSame([], $this->credenciales->consultas);
    }

    public function testJsonMalFormadoDevuelve400(): void
    {
        $response = $this->authApp()->handle($this->request('POST', '/api/v1/auth/login', '{"correo":'));

        $this->assertError($response, 400, 'INVALID_JSON');
    }

    public function testJsonQueNoEsObjetoDevuelve400(): void
    {
        $response = $this->authApp()->handle($this->request('POST', '/api/v1/auth/login', '["a","b"]'));

        $this->assertError($response, 400, 'INVALID_JSON');
    }

    public function testRateLimitDevuelve429ConRetryAfter(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp(['RATE_LIMIT_LOGIN_MAX' => '2', 'RATE_LIMIT_LOGIN_WINDOW_SECONDS' => '900']);

        $this->login($app, self::CORREO, 'x');
        $this->login($app, self::CORREO, 'x');
        $response = $this->login($app);

        $this->assertError($response, 429, 'RATE_LIMITED');
        self::assertSame('900', $response->getHeaderLine('Retry-After'));
    }

    public function testRememberMeSeIgnora(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);

        $response = $this->authApp()->handle($this->request('POST', '/api/v1/auth/login', [
            'correo' => self::CORREO,
            'password' => self::PASSWORD,
            'recordarme' => true,
        ]));

        self::assertSame(28800, $this->json($response)['expira_en']);
    }

    public function testSesionGuardaHashDelJti(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);

        $token = $this->tokenDe($this->authApp());
        $payload = json_decode((string) base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
        self::assertIsArray($payload);

        $sesion = array_values($this->db->sesiones)[0];
        self::assertSame(TokenGenerator::hash($payload['jti']), $sesion['token']);
    }
}
