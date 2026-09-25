<?php

declare(strict_types=1);

namespace Siiis\Controllers;

use Psr\Http\Message\ResponseInterface;
use Siiis\Database\Mongo;
use Siiis\Support\JsonResponse;

/**
 * GET /api/v1/health — estado de la API y ping a MongoDB Atlas (spec 10.1 y 27).
 */
final class HealthController
{
    public function __construct(private readonly Mongo $mongo)
    {
    }

    public function __invoke(): ResponseInterface
    {
        if (!$this->mongo->ping()) {
            return JsonResponse::error('DB_UNAVAILABLE', 'La base de datos no está disponible.', 503);
        }

        return JsonResponse::ok(['status' => 'ok', 'db' => 'ok'])
            ->withHeader('Cache-Control', 'no-store');
    }
}
