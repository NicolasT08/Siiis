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
 * Para rutas públicas que muestran más datos a usuarios autenticados (p. ej. GET /users).
 * Sin token, o con un token inválido o vencido, la petición sigue como anónima: nunca da más
 * privilegios y no rompe la página pública de quien tiene la sesión caducada.
 */
final class OptionalAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly SessionAuthenticator $authenticator)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $authenticated = $this->authenticator->authenticate($request);
        } catch (ApiException) {
            $authenticated = null;
        }

        return $handler->handle($authenticated ?? $request);
    }
}
