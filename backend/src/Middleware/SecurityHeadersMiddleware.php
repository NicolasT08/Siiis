<?php

declare(strict_types=1);

namespace Siiis\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Cabeceras de seguridad en todas las respuestas de la API, también en las de error.
 *
 * - La API solo entrega JSON: no se interpreta como otro tipo, no se muestra en un iframe y no carga recursos.
 * - Sin `Cache-Control` propio, la respuesta es `no-store`: así un proxy o el navegador no guardan el token del
 *   login ni datos de la vista de administrador. Las rutas públicas que fijan su caché la conservan.
 * - HSTS solo en producción (en desarrollo se usa http://localhost).
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public const HSTS = 'max-age=15552000';

    public function __construct(private readonly bool $production)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'")
            ->withHeader('Referrer-Policy', 'no-referrer');

        if (!$response->hasHeader('Cache-Control')) {
            $response = $response->withHeader('Cache-Control', 'no-store');
        }
        if ($this->production) {
            $response = $response->withHeader('Strict-Transport-Security', self::HSTS);
        }

        return $response;
    }
}
