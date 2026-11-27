<?php

declare(strict_types=1);

/*
 * Mantenimiento (spec 9 y 17.7): borra los contadores de rate limiting de storage/ratelimit/ cuya ventana ya
 * venció. Pensado para un cron de hPanel (p. ej. una vez al día); no usa MongoDB.
 *
 * Uso: php cron/limpiar_ratelimit.php [--segundos=N]
 *   --segundos=N  borra los contadores sin cambios desde hace más de N segundos. Por defecto, la ventana de
 *                 rate limiting más larga del .env; no se admite un valor menor (borraría límites vigentes).
 */

use Dotenv\Dotenv;
use Siiis\Config\Settings;
use Siiis\Security\RateLimiter;
use Siiis\Support\SystemClock;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo puede ejecutarse desde la línea de comandos.');
}

require dirname(__DIR__) . '/vendor/autoload.php';

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

try {
    $settings = Settings::fromEnv($_ENV);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error de configuración: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$minimo = $settings->ventanaRateLimitMaxima();
$segundos = $minimo;

/** @var list<string> $argv */
$argumentos = array_slice($argv, 1);
foreach ($argumentos as $argumento) {
    if (preg_match('/^--segundos=(\d+)$/', $argumento, $m) === 1) {
        $segundos = (int) $m[1];
        continue;
    }
    fwrite(STDERR, "Opción no reconocida: $argumento" . PHP_EOL . 'Uso: php cron/limpiar_ratelimit.php [--segundos=N]' . PHP_EOL);
    exit(1);
}

if ($segundos < $minimo) {
    fwrite(STDERR, sprintf(
        '--segundos no puede ser menor que la ventana de rate limiting más larga (%d s).%s',
        $minimo,
        PHP_EOL,
    ));
    exit(1);
}

$directorio = $settings->storagePath . '/ratelimit';
if (!is_dir($directorio)) {
    fwrite(STDOUT, 'No hay contadores de rate limiting.' . PHP_EOL);
    exit(0);
}

$borrados = (new RateLimiter($directorio, new SystemClock()))->purge($segundos);
fwrite(STDOUT, sprintf('Contadores borrados: %d (sin cambios desde hace más de %d s).%s', $borrados, $segundos, PHP_EOL));
