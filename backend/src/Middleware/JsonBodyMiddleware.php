<?php

declare(strict_types=1);

namespace Siiis\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Siiis\Support\ApiException;

/**
 * Decodifica cuerpos application/json a arreglo asociativo. JSON mal formado o que no sea un
 * objeto → 400 INVALID_JSON.
 */
final class JsonBodyMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $contentType = strtolower($request->getHeaderLine('Content-Type'));
        if (!str_contains($contentType, 'application/json')) {
            return $handler->handle($request);
        }

        $raw = trim((string) $request->getBody());
        if ($raw === '') {
            return $handler->handle($request->withParsedBody([]));
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException(400, 'INVALID_JSON', 'El cuerpo de la petición no es un JSON válido.');
        }

        // Solo se aceptan objetos JSON ({}), no listas ni escalares.
        if (!is_array($decoded) || !str_starts_with($raw, '{')) {
            throw new ApiException(400, 'INVALID_JSON', 'El cuerpo de la petición debe ser un objeto JSON.');
        }

        return $handler->handle($request->withParsedBody($decoded));
    }
}
