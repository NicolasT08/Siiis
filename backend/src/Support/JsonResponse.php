<?php

declare(strict_types=1);

namespace Siiis\Support;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

/**
 * Respuestas JSON centralizadas (spec 10.13.1).
 */
final class JsonResponse
{
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public static function ok(mixed $data, int $status = 200): ResponseInterface
    {
        return self::json($data, $status);
    }

    /**
     * @param array<string, string> $fields
     */
    public static function error(string $code, string $message, int $status, array $fields = []): ResponseInterface
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        return self::json(['error' => $error], $status);
    }

    public static function noContent(): ResponseInterface
    {
        return new Response(204);
    }

    private static function json(mixed $data, int $status): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, self::FLAGS));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
