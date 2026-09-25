<?php

declare(strict_types=1);

namespace Siiis\Config;

use Siiis\Controllers\AuthController;
use Siiis\Controllers\HealthController;
use Siiis\Middleware\AuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Registro de rutas bajo /api/v1 (spec 11). El basePath /api lo fija Application.
 * POST /auth/google queda sin registrar: fuera de la fase 1.
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

            $v1->group('/auth', function (RouteCollectorProxy $auth): void {
                $auth->post('/login', [AuthController::class, 'login']);
                $auth->get('/me', [AuthController::class, 'me'])->add(AuthMiddleware::class);
                $auth->post('/logout', [AuthController::class, 'logout'])->add(AuthMiddleware::class);
                $auth->post('/forgot-password', [AuthController::class, 'forgotPassword']);
                $auth->post('/reset-password', [AuthController::class, 'resetPassword']);
            });
        });
    }
}
