<?php

declare(strict_types=1);

namespace Siiis\Config;

use Siiis\Controllers\AuthController;
use Siiis\Controllers\DocumentosController;
use Siiis\Controllers\GoogleAuthController;
use Siiis\Controllers\HealthController;
use Siiis\Controllers\HomeController;
use Siiis\Controllers\InscriptionsController;
use Siiis\Controllers\MensajesContactoController;
use Siiis\Controllers\MultimediaController;
use Siiis\Controllers\ReviewsController;
use Siiis\Controllers\UsersController;
use Siiis\Middleware\AuthMiddleware;
use Siiis\Middleware\OptionalAuthMiddleware;
use Siiis\Middleware\RoleMiddleware;
use Siiis\Support\ModuloDocumentos;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Registro de rutas bajo /api/v1 (spec 11). El basePath /api lo fija Application.
 * POST /auth/google solo se registra si GOOGLE_CLIENT_ID está configurado (RF-17, DEC-B61); si no, responde 404.
 * En Slim el último middleware agregado corre primero: AuthMiddleware antes que RoleMiddleware.
 */
final class Routes
{
    /**
     * @param App<\Psr\Container\ContainerInterface> $app
     */
    public static function register(App $app): void
    {
        $settings = $app->getContainer()->get(Settings::class);
        $conGoogle = $settings instanceof Settings && $settings->googleClientId !== '';

        $app->group('/v1', function (RouteCollectorProxy $v1) use ($conGoogle): void {
            $v1->get('/health', HealthController::class);

            $v1->group('/auth', function (RouteCollectorProxy $auth) use ($conGoogle): void {
                $auth->post('/register', [AuthController::class, 'register'])
                    ->add(RoleMiddleware::administrativos())
                    ->add(AuthMiddleware::class);
                $auth->post('/login', [AuthController::class, 'login']);
                if ($conGoogle) {
                    $auth->post('/google', GoogleAuthController::class);
                }
                $auth->get('/me', [AuthController::class, 'me'])->add(AuthMiddleware::class);
                $auth->post('/logout', [AuthController::class, 'logout'])->add(AuthMiddleware::class);
                $auth->post('/forgot-password', [AuthController::class, 'forgotPassword']);
                $auth->post('/reset-password', [AuthController::class, 'resetPassword']);
                // PROPUESTA DEC-B23: cambio de contraseña con sesión iniciada.
                $auth->post('/change-password', [AuthController::class, 'changePassword'])->add(AuthMiddleware::class);
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

            foreach (ModuloDocumentos::cases() as $modulo) {
                self::documentos($v1, $modulo);
            }
            $v1->group('/home', function (RouteCollectorProxy $home): void {
                $home->get('/slider', [HomeController::class, 'slider']);
                $home->get('/videos', [HomeController::class, 'videos']);
                $home->get('/director', [HomeController::class, 'director']);
            });

            // Administración de las fotos y videos del inicio (DEC-B62): solo superadministrador y administrador.
            // PUT /multimedia/orden va antes de las rutas con {id} (FastRoute prioriza las rutas fijas igualmente).
            $v1->group('/multimedia', function (RouteCollectorProxy $multimedia): void {
                $multimedia->get('', [MultimediaController::class, 'index']);
                $multimedia->post('', [MultimediaController::class, 'store']);
                $multimedia->put('/orden', [MultimediaController::class, 'reorder']);
                $multimedia->patch('/{id}', [MultimediaController::class, 'update']);
                $multimedia->delete('/{id}', [MultimediaController::class, 'destroy']);
            })->add(RoleMiddleware::administrativos())->add(AuthMiddleware::class);

            // Reseñas (DEC-B43 a DEC-B45): públicas con sesión opcional; moderación con RoleMiddleware (DEC-B44).
            $v1->group('/reviews', function (RouteCollectorProxy $reviews): void {
                $reviews->get('', [ReviewsController::class, 'index']);
                $reviews->post('', [ReviewsController::class, 'store']);
            })->add(OptionalAuthMiddleware::class);
            $v1->patch('/reviews/{id}', [ReviewsController::class, 'update'])
                ->add(RoleMiddleware::moderadoresResenas())
                ->add(AuthMiddleware::class);
            $v1->delete('/reviews/{id}', [ReviewsController::class, 'destroy'])
                ->add(RoleMiddleware::moderadoresResenas())
                ->add(AuthMiddleware::class);

            // Inscripciones (DEC-B49 a DEC-B52): envío público; consulta y resolución para administrativos.
            $v1->post('/inscriptions', [InscriptionsController::class, 'store']);
            $v1->group('/inscriptions', function (RouteCollectorProxy $inscriptions): void {
                $inscriptions->get('', [InscriptionsController::class, 'index']);
                $inscriptions->get('/{id}', [InscriptionsController::class, 'show']);
                $inscriptions->patch('/{id}', [InscriptionsController::class, 'update']);
            })->add(RoleMiddleware::administrativos())->add(AuthMiddleware::class);

            // Contacto (DEC-B46): envío público (HomeController, spec 10.12) y buzón para administradores.
            $v1->post('/contact', [HomeController::class, 'contact']);
            $v1->get('/contact/messages', [MensajesContactoController::class, 'index'])
                ->add(RoleMiddleware::administrativos())
                ->add(AuthMiddleware::class);
            $v1->patch('/contact/messages/{id}', [MensajesContactoController::class, 'update'])
                ->add(RoleMiddleware::administrativos())
                ->add(AuthMiddleware::class);
        });
    }

    /**
     * CRUD de un módulo de contenido (spec 11.3; DEC-B30) y sus adjuntos (DEC-B39). El argumento `modulo` le dice a
     * DocumentosController qué categorías sirve la ruta.
     *
     * @param RouteCollectorProxy<\Psr\Container\ContainerInterface|null> $v1
     */
    private static function documentos(RouteCollectorProxy $v1, ModuloDocumentos $modulo): void
    {
        $base = '/' . $modulo->value;
        $v1->group($base, function (RouteCollectorProxy $group) use ($modulo): void {
            $group->get('', [DocumentosController::class, 'index'])->setArgument('modulo', $modulo->value);
            $group->get('/{id}', [DocumentosController::class, 'show'])->setArgument('modulo', $modulo->value);
        })->add(OptionalAuthMiddleware::class);
        // Fuera del grupo: con OptionalAuthMiddleware la sesión se validaría dos veces.
        $v1->post($base, [DocumentosController::class, 'store'])
            ->setArgument('modulo', $modulo->value)
            ->add(AuthMiddleware::class);
        $v1->patch($base . '/{id}', [DocumentosController::class, 'update'])
            ->setArgument('modulo', $modulo->value)
            ->add(AuthMiddleware::class);
        $v1->delete($base . '/{id}', [DocumentosController::class, 'destroy'])
            ->setArgument('modulo', $modulo->value)
            ->add(AuthMiddleware::class);
        // Adjuntos (DEC-B39): un archivo por petición.
        $v1->post($base . '/{id}/files', [DocumentosController::class, 'storeFile'])
            ->setArgument('modulo', $modulo->value)
            ->add(AuthMiddleware::class);
        $v1->delete($base . '/{id}/files/{archivo}', [DocumentosController::class, 'destroyFile'])
            ->setArgument('modulo', $modulo->value)
            ->add(AuthMiddleware::class);
    }
}
