<?php

declare(strict_types=1);

namespace Siiis\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Siiis\Security\SessionAuthenticator;
use Siiis\Support\ApiException;

/**
 * Protege rutas con JWT + sesión activa en `Sesiones` (spec 10.13.6). Adjunta a la request
 * usuario_id, rol (del token firmado, nunca del cuerpo) y el hash de la sesión.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public const ATTR_USUARIO_ID = SessionAuthenticator::ATTR_USUARIO_ID;
    public const ATTR_ROL = SessionAuthenticator::ATTR_ROL;
    public const ATTR_SESSION_HASH = SessionAuthenticator::ATTR_SESSION_HASH;

    public function __construct(private readonly SessionAuthenticator $authenticator)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authenticated = $this->authenticator->authenticate($request);
        if ($authenticated === null) {
            throw ApiException::unauthorized();
        }

        return $handler->handle($authenticated);
    }
}
