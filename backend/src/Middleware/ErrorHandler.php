<?php

declare(strict_types=1);

namespace Siiis\Middleware;

use MongoDB\Driver\Exception\ConnectionException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Siiis\Support\ApiException;
use Siiis\Support\JsonResponse;
use Siiis\Support\Logger;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Interfaces\ErrorHandlerInterface;
use Throwable;

/**
 * Manejador global de errores (spec 10.13.10): siempre JSON {"error": {"code", "message"}},
 * sin trazas cuando APP_DEBUG=false.
 */
final class ErrorHandler implements ErrorHandlerInterface
{
    public function __construct(
        private readonly Logger $logger,
        private readonly bool $debug,
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        if ($exception instanceof ApiException) {
            return JsonResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->fields,
            );
        }

        if ($exception instanceof HttpNotFoundException) {
            return JsonResponse::error('NOT_FOUND', 'El recurso solicitado no existe.', 404);
        }

        if ($exception instanceof HttpMethodNotAllowedException) {
            return JsonResponse::error('METHOD_NOT_ALLOWED', 'Método HTTP no permitido para esta ruta.', 405)
                ->withHeader('Allow', implode(', ', $exception->getAllowedMethods()));
        }

        if ($exception instanceof HttpException) {
            return JsonResponse::error('HTTP_ERROR', $exception->getDescription(), $exception->getCode());
        }

        if ($exception instanceof ConnectionException) {
            $this->logger->error('Base de datos no disponible', $this->context($request, $exception));

            return JsonResponse::error('DB_UNAVAILABLE', 'La base de datos no está disponible. Intenta más tarde.', 503);
        }

        $this->logger->error('Error no controlado', $this->context($request, $exception) + [
            'trace' => $exception->getTraceAsString(),
        ]);

        $response = JsonResponse::error('INTERNAL_ERROR', 'Ocurrió un error interno.', 500);
        if (!$this->debug) {
            return $response;
        }

        $body = [
            'error' => [
                'code' => 'INTERNAL_ERROR',
                'message' => 'Ocurrió un error interno.',
                'debug' => [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile() . ':' . $exception->getLine(),
                    'trace' => explode("\n", $exception->getTraceAsString()),
                ],
            ],
        ];

        return JsonResponse::ok($body, 500);
    }

    /**
     * @return array<string, string>
     */
    private function context(ServerRequestInterface $request, Throwable $exception): array
    {
        return [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile() . ':' . $exception->getLine(),
        ];
    }
}
