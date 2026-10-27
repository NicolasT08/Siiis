<?php

declare(strict_types=1);

namespace Siiis\Config;

use Siiis\Controllers\AuthController;
use Siiis\Controllers\HealthController;
use Siiis\Controllers\HomeController;
use Siiis\Controllers\UsersController;
use Siiis\Middleware\AuthMiddleware;
use Siiis\Middleware\OptionalAuthMiddleware;
use Siiis\Middleware\RoleMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Registro de rutas bajo /api/v1 (spec 11). El basePath /api lo fija Application.
 * POST /auth/google queda sin registrar: fuera de las fases 1 y 2.
 * En Slim el último middleware agregado corre primero: AuthMiddleware antes que RoleMiddleware.
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
                $auth->post('/register', [AuthController::class, 'register'])
                    ->add(RoleMiddleware::administrativos())
                    ->add(AuthMiddleware::class);
                $auth->post('/login', [AuthController::class, 'login']);
                $auth->get('/me', [AuthController::class, 'me'])->add(AuthMiddleware::class);
                $auth->post('/logout', [AuthController::class, 'logout'])->add(AuthMiddleware::class);
                $auth->post('/forgot-password', [AuthController::class, 'forgotPassword']);
                $auth->post('/reset-password', [AuthController::class, 'resetPassword']);
            });

            $v1->group('/users', function (RouteCollectorProxy $users): void {
                $users->get('', [UsersController::class, 'index']);
                $users->get('/{id}', [UsersController::class, 'show']);
            })->add(OptionalAuthMiddleware::class);
            // Fuera del grupo: con OptionalAuthMiddleware la sesión se validaría dos veces.
            $v1->patch('/users/{id}', [UsersController::class, 'update'])->add(AuthMiddleware::class);
            $v1->post('/users/{id}/photo', [UsersController::class, 'photo'])->add(AuthMiddleware::class);
            $v1->delete('/users/{id}', [UsersController::class, 'destroy'])
                ->add(RoleMiddleware::administrativos())
                ->add(AuthMiddleware::class);

            $v1->group('/home', function (RouteCollectorProxy $home): void {
                $home->get('/slider', [HomeController::class, 'slider']);
                $home->get('/videos', [HomeController::class, 'videos']);
            });
        });
    }
}
