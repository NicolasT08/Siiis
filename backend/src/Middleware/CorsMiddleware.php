<?php

declare(strict_types=1);

namespace Siiis\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * CORS solo para desarrollo (spec 10.13.9): en producción frontend y API comparten dominio.
 * Acepta únicamente FRONTEND_ORIGIN y responde el preflight OPTIONS con 204.
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
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
            ->withHeader('Access-Control-Max-Age', '600');
    }
}
