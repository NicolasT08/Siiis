<?php

declare(strict_types=1);

namespace Siiis\Tests\Unit\Middleware;

use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Siiis\Middleware\AuthMiddleware;
use Siiis\Middleware\OptionalAuthMiddleware;
use Siiis\Security\JwtService;
use Siiis\Security\SessionAuthenticator;
use Siiis\Support\TokenGenerator;
use Siiis\Tests\Support\FixedClock;
use Siiis\Tests\Support\InMemoryDatabase;
use Siiis\Tests\Support\InMemorySesionesRepository;
use Siiis\Tests\Support\RecordingHandler;
use Siiis\Tests\Support\TestSettings;
use Slim\Psr7\Factory\ServerRequestFactory;

final class OptionalAuthMiddlewareTest extends TestCase
{
    private InMemoryDatabase $db;
    private FixedClock $clock;
    private JwtService $jwt;
    private OptionalAuthMiddleware $middleware;

    protected function setUp(): void
    {
        $this->db = new InMemoryDatabase();
        $this->clock = new FixedClock();
        $this->jwt = new JwtService(TestSettings::JWT_SECRET, 3600, $this->clock);
        $this->middleware = new OptionalAuthMiddleware(
            new SessionAuthenticator($this->jwt, new InMemorySesionesRepository($this->db), $this->clock),
        );
    }

    public function testSinTokenSigueComoAnonima(): void
    {
        $handler = new RecordingHandler();

        $this->middleware->process($this->request(), $handler);

        self::assertNotNull($handler->received);
        self::assertNull($handler->received->getAttribute(AuthMiddleware::ATTR_USUARIO_ID));
        self::assertNull($handler->received->getAttribute(AuthMiddleware::ATTR_ROL));
    }

    public function testConSesionValidaAdjuntaIdentidadYRol(): void
    {
        $usuarioId = new ObjectId();
        $token = $this->sesionValida($usuarioId, 'administrador');
        $handler = new RecordingHandler();

        $this->middleware->process($this->request("Bearer $token"), $handler);

        self::assertNotNull($handler->received);
        self::assertSame((string) $usuarioId, $handler->received->getAttribute(AuthMiddleware::ATTR_USUARIO_ID));
        self::assertSame('administrador', $handler->received->getAttribute(AuthMiddleware::ATTR_ROL));
    }

    public function testTokenBasuraSigueComoAnonima(): void
    {
        $handler = new RecordingHandler();

        $response = $this->middleware->process($this->request('Bearer abc.def.ghi'), $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($handler->received?->getAttribute(AuthMiddleware::ATTR_ROL));
    }

    public function testTokenVencidoSigueComoAnonima(): void
    {
        $token = $this->sesionValida(new ObjectId(), 'superadministrador');
        $this->clock->advance(3601);
        $handler = new RecordingHandler();

        $this->middleware->process($this->request("Bearer $token"), $handler);

        self::assertNull($handler->received?->getAttribute(AuthMiddleware::ATTR_ROL));
    }

    public function testSesionCerradaSigueComoAnonima(): void
    {
        $usuarioId = new ObjectId();
        $token = $this->sesionValida($usuarioId, 'superadministrador');
        (new InMemorySesionesRepository($this->db))->desactivarTodas($usuarioId);
        $handler = new RecordingHandler();

        $this->middleware->process($this->request("Bearer $token"), $handler);

        self::assertNull($handler->received?->getAttribute(AuthMiddleware::ATTR_ROL));
    }

    private function sesionValida(ObjectId $usuarioId, string $rol): string
    {
        $issued = $this->jwt->issue((string) $usuarioId, $rol);
        (new InMemorySesionesRepository($this->db))->crear(
            $usuarioId,
            TokenGenerator::hash($issued->jti),
            $issued->issuedAt,
            $issued->expiresAt,
            '203.0.113.10',
        );

        return $issued->token;
    }

    private function request(?string $authorization = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/users');

        return $authorization === null ? $request : $request->withHeader('Authorization', $authorization);
    }
}
