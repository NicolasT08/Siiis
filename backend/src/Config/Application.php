<?php

declare(strict_types=1);

namespace Siiis\Config;

use Psr\Container\ContainerInterface;
use Siiis\Middleware\ClientIpMiddleware;
use Siiis\Middleware\CorsMiddleware;
use Siiis\Middleware\ErrorHandler;
use Siiis\Middleware\JsonBodyMiddleware;
use Siiis\Middleware\SecurityHeadersMiddleware;
use Slim\App;
use Slim\Factory\AppFactory;

/**
 * Construye la aplicación Slim: basePath /api, middleware y rutas (spec 10.1).
 */
final class Application
{
    /**
     * @param array<string, mixed> $overrides definiciones del contenedor para pruebas
     *
     * @return App<ContainerInterface>
     */
    public static function create(Settings $settings, array $overrides = []): App
    {
        $container = Container::build($settings, $overrides);
        $app = AppFactory::createFromContainer($container);
        $app->setBasePath('/api');

        Routes::register($app);

        // Slim ejecuta primero el último middleware añadido.
        $app->add(JsonBodyMiddleware::class);
        $app->add(ClientIpMiddleware::class);
        $app->addRoutingMiddleware();
        $errorMiddleware = $app->addErrorMiddleware($settings->appDebug, false, false);
        $errorMiddleware->setDefaultErrorHandler($container->get(ErrorHandler::class));

        // Envuelve también las respuestas de error.
        $app->add(new SecurityHeadersMiddleware($settings->isProduction()));

        // CORS: en desarrollo para FRONTEND_ORIGIN; fuera de desarrollo solo si CORS_ORIGIN está configurado
        // (plan B, API en otro dominio). En Hostinger, con frontend y API en el mismo dominio, no hay CORS.
        if ($settings->origenCors() !== '') {
            $app->add(new CorsMiddleware($settings->origenCors()));
        }

        return $app;
    }
}
