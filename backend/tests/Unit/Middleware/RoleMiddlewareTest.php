<?php

declare(strict_types=1);

namespace Siiis\Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Siiis\Middleware\AuthMiddleware;
use Siiis\Middleware\RoleMiddleware;
use Siiis\Support\ApiException;
use Siiis\Tests\Support\RecordingHandler;
use Slim\Psr7\Factory\ServerRequestFactory;

final class RoleMiddlewareTest extends TestCase
{
    public function testDejaPasarRolesAdministrativos(): void
    {
        foreach (['superadministrador', 'administrador'] as $rol) {
            $handler = new RecordingHandler();

            $response = RoleMiddleware::administrativos()->process($this->requestConRol($rol), $handler);

            self::assertSame(200, $response->getStatusCode());
            self::assertNotNull($handler->received, $rol);
        }
    }

    public function testRechazaRolesNoAdministrativosCon403(): void
    {
        foreach (['docente_director', 'estudiante_lider', 'estudiante', 'inventado'] as $rol) {
            $handler = new RecordingHandler();

            try {
                RoleMiddleware::administrativos()->process($this->requestConRol($rol), $handler);
                self::fail("El rol $rol no debía pasar");
            } catch (ApiException $e) {
                self::assertSame(403, $e->status);
                self::assertSame('FORBIDDEN', $e->errorCode);
            }
            self::assertNull($handler->received);
        }
    }

    public function testSinRolEnLaRequestResponde401(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/users');

        try {
            RoleMiddleware::administrativos()->process($request, new RecordingHandler());
            self::fail('Debía lanzar 401');
        } catch (ApiException $e) {
            self::assertSame(401, $e->status);
            self::assertSame('UNAUTHORIZED', $e->errorCode);
        }
    }

    public function testRolQueNoEsTextoResponde401(): void
    {
        $request = $this->requestConRol('x')->withAttribute(AuthMiddleware::ATTR_ROL, ['superadministrador']);

        $this->expectException(ApiException::class);
        RoleMiddleware::administrativos()->process($request, new RecordingHandler());
    }

    public function testListaPersonalizadaDeRoles(): void
    {
        $middleware = new RoleMiddleware(['estudiante']);

        self::assertSame(200, $middleware->process($this->requestConRol('estudiante'), new RecordingHandler())->getStatusCode());

        $this->expectException(ApiException::class);
        $middleware->process($this->requestConRol('superadministrador'), new RecordingHandler());
    }

    private function requestConRol(string $rol): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/users')
            ->withAttribute(AuthMiddleware::ATTR_ROL, $rol);
    }
}
