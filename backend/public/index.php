<?php

declare(strict_types=1);

/*
 * Front controller de la API. En Hostinger se copia a public_html/api/index.php y se ajusta
 * BOOTSTRAP_PATH para apuntar al código fuera de public_html (ver docs/deployment.md).
 */

const BOOTSTRAP_PATH = __DIR__ . '/../bootstrap.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');

try {
    /** @var \Slim\App<\Psr\Container\ContainerInterface> $app */
    $app = require BOOTSTRAP_PATH;
} catch (Throwable $e) {
    error_log('[SIIIS] Error al arrancar la API: ' . $e->getMessage());
    $debug = in_array(strtolower((string) ($_ENV['APP_DEBUG'] ?? '')), ['1', 'true', 'yes', 'on'], true);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => [
            'code' => 'CONFIG_ERROR',
            'message' => $debug ? $e->getMessage() : 'El servidor no está configurado correctamente.',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return;
}

$app->run();
