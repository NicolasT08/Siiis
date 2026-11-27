<?php

declare(strict_types=1);

namespace Siiis\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Siiis\Config\Settings;
use Siiis\Support\ClientIp;

/**
 * Calcula la IP del cliente una vez por petición (con `TRUSTED_PROXIES`) y la deja en el atributo
 * `ClientIp::ATTRIBUTE`, que leen el rate limiting y `Sesiones.ip_acceso`.
 */
final class ClientIpMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request->withAttribute(
            ClientIp::ATTRIBUTE,
            ClientIp::resolve($request, $this->settings->trustedProxies),
        ));
    }
}
