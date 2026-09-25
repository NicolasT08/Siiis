<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use Firebase\JWT\JWT;
use Siiis\Tests\Support\TestSettings;

final class SessionApiTest extends AuthTestCase
{
    public function testMeConTokenValido(): void
    {
        [, $userId] = $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp();

        $response = $app->handle($this->request('GET', '/api/v1/auth/me', null, [
            'Authorization' => 'Bearer ' . $this->tokenDe($app),
        ]));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame((string) $userId, $this->json($response)['usuario']['id']);
        self::assertSame('superadministrador', $this->json($response)['usuario']['rol']);
    }

    public function testMeSinToken(): void
    {
        $this->assertError($this->authApp()->handle($this->request('GET', '/api/v1/auth/me')), 401, 'UNAUTHORIZED');
    }

    public function testMeConCabeceraMalFormada(): void
    {
        $response = $this->authApp()->handle($this->request('GET', '/api/v1/auth/me', null, ['Authorization' => 'Basic abc']));

        $this->assertError($response, 401, 'UNAUTHORIZED');
    }

    public function testMeConTokenBasura(): void
    {
        $response = $this->authApp()->handle($this->request('GET', '/api/v1/auth/me', null, ['Authorization' => 'Bearer abc.def.ghi']));

        $this->assertError($response, 401, 'UNAUTHORIZED');
    }

    public function testMeAceptaAuthorizationReenviadaPorHtaccess(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp();
        $token = $this->tokenDe($app);

        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/api/v1/auth/me', [
            'REMOTE_ADDR' => '203.0.113.10',
            'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        self::assertSame(200, $app->handle($request)->getStatusCode());
    }

    public function testMeConSesionDesactivada(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp();
        $token = $this->tokenDe($app);

        foreach (array_keys($this->db->sesiones) as $id) {
            $this->db->sesiones[$id]['activa'] = false;
        }

        $response = $app->handle($this->request('GET', '/api/v1/auth/me', null, ['Authorization' => "Bearer $token"]));
        $this->assertError($response, 401, 'UNAUTHORIZED');
    }

    public function testMeConTokenExpirado(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp();
        $token = $this->tokenDe($app);

        $this->clock->advance(28801);

        $response = $app->handle($this->request('GET', '/api/v1/auth/me', null, ['Authorization' => "Bearer $token"]));
        $this->assertError($response, 401, 'UNAUTHORIZED');
    }

    public function testMeConTokenValidoSinSesionRegistrada(): void
    {
        [, $userId] = $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $now = $this->clock->now()->getTimestamp();
        $token = JWT::encode(
            ['sub' => (string) $userId, 'rol' => 'superadministrador', 'jti' => 'inventado', 'iat' => $now, 'exp' => $now + 60],
            TestSettings::JWT_SECRET,
            'HS256',
        );

        $response = $this->authApp()->handle($this->request('GET', '/api/v1/auth/me', null, ['Authorization' => "Bearer $token"]));
        $this->assertError($response, 401, 'UNAUTHORIZED');
    }

    public function testMeConUsuarioDesactivadoDespuesDelLogin(): void
    {
        [, $userId] = $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp();
        $token = $this->tokenDe($app);

        $this->db->usuarios[(string) $userId]['estado'] = false;

        $response = $app->handle($this->request('GET', '/api/v1/auth/me', null, ['Authorization' => "Bearer $token"]));
        $this->assertError($response, 401, 'UNAUTHORIZED');
    }

    public function testLogoutInvalidaLaSesion(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp();
        $headers = ['Authorization' => 'Bearer ' . $this->tokenDe($app)];

        $logout = $app->handle($this->request('POST', '/api/v1/auth/logout', null, $headers));
        self::assertSame(204, $logout->getStatusCode());
        self::assertSame('', (string) $logout->getBody());
        self::assertFalse(array_values($this->db->sesiones)[0]['activa']);

        $me = $app->handle($this->request('GET', '/api/v1/auth/me', null, $headers));
        $this->assertError($me, 401, 'UNAUTHORIZED');
    }

    public function testLogoutSoloCierraLaSesionActual(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);
        $app = $this->authApp();
        $tokenA = $this->tokenDe($app);
        $tokenB = $this->tokenDe($app);

        $app->handle($this->request('POST', '/api/v1/auth/logout', null, ['Authorization' => "Bearer $tokenA"]));

        $me = $app->handle($this->request('GET', '/api/v1/auth/me', null, ['Authorization' => "Bearer $tokenB"]));
        self::assertSame(200, $me->getStatusCode());
    }

    public function testLogoutSinToken(): void
    {
        $this->assertError($this->authApp()->handle($this->request('POST', '/api/v1/auth/logout')), 401, 'UNAUTHORIZED');
    }

    public function testLoginConGoogleNoEstaRegistrado(): void
    {
        $response = $this->authApp()->handle($this->request('POST', '/api/v1/auth/google', ['id_token' => 'x']));

        $this->assertError($response, 404, 'NOT_FOUND');
    }
}
