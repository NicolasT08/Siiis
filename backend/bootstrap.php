<?php

declare(strict_types=1);

/*
 * Arranque de la API: carga dependencias, .env y construye la aplicación Slim.
 * public/index.php (o public_html/api/index.php en Hostinger) solo hace require de este archivo.
 */

use Dotenv\Dotenv;
use Siiis\Config\Application;
use Siiis\Config\Settings;

require __DIR__ . '/vendor/autoload.php';

Dotenv::createImmutable(__DIR__)->safeLoad();

return Application::create(Settings::fromEnv($_ENV));
