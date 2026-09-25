<?php

declare(strict_types=1);

namespace Siiis\Config;

use Psr\Container\ContainerInterface;
use Siiis\Middleware\CorsMiddleware;
use Siiis\Middleware\ErrorHandler;
use Siiis\Middleware\JsonBodyMiddleware;
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
        $app->addRoutingMiddleware();
        $errorMiddleware = $app->addErrorMiddleware($settings->appDebug, false, false);
        $errorMiddleware->setDefaultErrorHandler($container->get(ErrorHandler::class));

        if ($settings->isDevelopment()) {
            $app->add(new CorsMiddleware($settings->frontendOrigin));
        }

        return $app;
    }
}
