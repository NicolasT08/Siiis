<?php

declare(strict_types=1);

namespace Siiis\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Siiis\Repositories\SesionesRepository;
use Siiis\Security\InvalidTokenException;
use Siiis\Security\JwtService;
use Siiis\Support\ApiException;
use Siiis\Support\Clock;
use Siiis\Support\TokenGenerator;

/**
 * Protege rutas con JWT + sesión activa en `Sesiones` (spec 10.13.6). Adjunta a la request
 * usuario_id, rol (del token firmado, nunca del cuerpo) y el hash de la sesión.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public const ATTR_USUARIO_ID = 'usuario_id';
    public const ATTR_ROL = 'rol';
    public const ATTR_SESSION_HASH = 'session_hash';

    public function __construct(
        private readonly JwtService $jwt,
        private readonly SesionesRepository $sesiones,
        private readonly Clock $clock,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $this->bearerToken($request);
        if ($token === null) {
            throw ApiException::unauthorized();
        }

        try {
            $claims = $this->jwt->decode($token);
        } catch (InvalidTokenException) {
            throw ApiException::unauthorized();
        }

        $sessionHash = TokenGenerator::hash($claims->jti);
        $sesion = $this->sesiones->findActiva($sessionHash, $this->clock->now());
        if ($sesion === null || (string) ($sesion['usuario_id'] ?? '') !== $claims->sub) {
            throw ApiException::unauthorized();
        }

        return $handler->handle($request
            ->withAttribute(self::ATTR_USUARIO_ID, $claims->sub)
            ->withAttribute(self::ATTR_ROL, $claims->rol)
            ->withAttribute(self::ATTR_SESSION_HASH, $sessionHash));
    }

    private function bearerToken(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if ($header === '') {
            // LiteSpeed/Apache pueden entregar la cabecera reenviada por .htaccess con otro nombre.
            $server = $request->getServerParams();
            $header = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
