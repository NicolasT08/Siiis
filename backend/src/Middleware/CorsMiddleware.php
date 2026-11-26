<?php

declare(strict_types=1);

namespace Siiis\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * CORS (spec 10.13.9): en desarrollo para FRONTEND_ORIGIN; en producción solo si CORS_ORIGIN está configurado
 * (plan B con la API en otro dominio). Acepta únicamente ese origen y responde el preflight OPTIONS con 204.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $allowedOrigin)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        $allowed = $origin !== '' && $origin === $this->allowedOrigin;

        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response(204);

            return $allowed ? $this->withCorsHeaders($response, $origin, true) : $response->withHeader('Vary', 'Origin');
        }

        $response = $handler->handle($request);

        return $allowed ? $this->withCorsHeaders($response, $origin, false) : $response->withAddedHeader('Vary', 'Origin');
    }

    private function withCorsHeaders(ResponseInterface $response, string $origin, bool $preflight): ResponseInterface
    {
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withAddedHeader('Vary', 'Origin');

        if (!$preflight) {
            // Para que el frontend pueda leer la espera de un 429 (RATE_LIMITED).
            return $response->withHeader('Access-Control-Expose-Headers', 'Retry-After');
        }

        return $response
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
            ->withHeader('Access-Control-Max-Age', '600');
    }
}
