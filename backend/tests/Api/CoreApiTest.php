<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use Siiis\Database\Mongo;

final class CoreApiTest extends ApiTestCase
{
    public function testHealthOkCuandoMongoResponde(): void
    {
        $mongo = $this->createStub(Mongo::class);
        $mongo->method('ping')->willReturn(true);

        $response = $this->app([Mongo::class => $mongo])->handle($this->request('GET', '/api/v1/health'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'ok', 'db' => 'ok'], $this->json($response));
    }

    public function testHealth503CuandoMongoNoResponde(): void
    {
        $mongo = $this->createStub(Mongo::class);
        $mongo->method('ping')->willReturn(false);

        $response = $this->app([Mongo::class => $mongo])->handle($this->request('GET', '/api/v1/health'));

        $this->assertError($response, 503, 'DB_UNAVAILABLE');
    }

    public function testRutaInexistenteDevuelve404Json(): void
    {
        $response = $this->app()->handle($this->request('GET', '/api/v1/no-existe'));

        $this->assertError($response, 404, 'NOT_FOUND');
    }

    public function testMetodoNoPermitidoDevuelve405Json(): void
    {
        $response = $this->app()->handle($this->request('DELETE', '/api/v1/health'));

        $this->assertError($response, 405, 'METHOD_NOT_ALLOWED');
    }

    public function testErrorInternoSinTrazasCuandoDebugEsFalse(): void
    {
        $mongo = $this->createStub(Mongo::class);
        $mongo->method('ping')->willThrowException(new \RuntimeException('detalle interno secreto'));

        $response = $this->app([Mongo::class => $mongo])->handle($this->request('GET', '/api/v1/health'));

        $this->assertError($response, 500, 'INTERNAL_ERROR');
        self::assertStringNotContainsString('detalle interno secreto', (string) $response->getBody());
        self::assertArrayNotHasKey('debug', $this->json($response)['error']);
    }

    public function testErrorInternoConDetalleCuandoDebugEsTrue(): void
    {
        $mongo = $this->createStub(Mongo::class);
        $mongo->method('ping')->willThrowException(new \RuntimeException('detalle interno'));

        $app = $this->app([Mongo::class => $mongo], ['APP_DEBUG' => 'true']);
        $response = $app->handle($this->request('GET', '/api/v1/health'));

        $this->assertError($response, 500, 'INTERNAL_ERROR');
        self::assertSame('detalle interno', $this->json($response)['error']['debug']['message']);
    }

    public function testFalloDeConexionAMongoDevuelve503(): void
    {
        $mongo = $this->createStub(Mongo::class);
        $mongo->method('ping')->willThrowException(
            new \MongoDB\Driver\Exception\ConnectionTimeoutException('No suitable servers found'),
        );

        $response = $this->app([Mongo::class => $mongo])->handle($this->request('GET', '/api/v1/health'));

        $this->assertError($response, 503, 'DB_UNAVAILABLE');
    }

    public function testCorsPreflightEnDesarrollo(): void
    {
        $app = $this->app([], ['APP_ENV' => 'development']);
        $response = $app->handle($this->request('OPTIONS', '/api/v1/auth/login', null, [
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'POST',
        ]));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('http://localhost:5173', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringContainsString('Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }

    public function testCorsRechazaOrigenNoPermitido(): void
    {
        $app = $this->app([], ['APP_ENV' => 'development']);
        $response = $app->handle($this->request('OPTIONS', '/api/v1/health', null, ['Origin' => 'https://evil.example']));

        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testSinCorsFueraDeDesarrollo(): void
    {
        $mongo = $this->createStub(Mongo::class);
        $mongo->method('ping')->willReturn(true);

        $app = $this->app([Mongo::class => $mongo], ['APP_ENV' => 'production']);
        $response = $app->handle($this->request('GET', '/api/v1/health', null, ['Origin' => 'http://localhost:5173']));

        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }
}
