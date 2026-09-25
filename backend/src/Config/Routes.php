<?php

declare(strict_types=1);

namespace Siiis\Config;

use Siiis\Controllers\HealthController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Registro de rutas bajo /api/v1 (spec 11). El basePath /api lo fija Application.
 */
final class Routes
{
    /**
     * @param App<\Psr\Container\ContainerInterface> $app
     */
    public static function register(App $app): void
    {
        $app->group('/v1', function (RouteCollectorProxy $v1): void {
            $v1->get('/health', HealthController::class);
        });
    }
}
