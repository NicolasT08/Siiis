<?php

declare(strict_types=1);

/*
 * Respaldo de la base MONGO_DB_NAME en archivos locales (auditoría A-01). Solo lee MongoDB; no envía nada a
 * servicios externos. Cada colección queda en Extended JSON canónico (un documento por línea) dentro de
 * RESPALDO_DIR/AAAAMMDD-HHMMSS/ (UTC); se conservan los últimos RESPALDO_MAX respaldos. Ver docs/deployment.md
 * ("Respaldos") para programarlo con cron y para restaurar con mongoimport.
 *
 * Uso: php scripts/respaldar_base.php [--test]
 *   --test  respalda MONGO_DB_NAME_TEST en lugar de MONGO_DB_NAME.
 */

use Siiis\Database\Mongo;
use Siiis\Repositories\RespaldoRepository;
use Siiis\Services\RespaldoService;
use Siiis\Support\SystemClock;

/** @var array{0: \Siiis\Config\Settings, 1: Mongo} $bootstrap */
$bootstrap = require __DIR__ . '/cli.php';
[$settings, $mongo] = $bootstrap;

/** @var list<string> $argv */
$argumentos = array_slice($argv, 1);
foreach ($argumentos as $argumento) {
    if ($argumento !== '--test') {
        fwrite(STDERR, "Opción no reconocida: $argumento" . PHP_EOL . 'Uso: php scripts/respaldar_base.php [--test]' . PHP_EOL);
        exit(1);
    }
}

$servicio = new RespaldoService(new RespaldoRepository($mongo), new SystemClock(), $settings->respaldoDir, $settings->respaldoMax);

try {
    $resultado = $servicio->crear();
} catch (Throwable $e) {
    fwrite(STDERR, 'No se pudo crear el respaldo: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Respaldo creado en ' . $resultado['carpeta'] . PHP_EOL);
foreach ($resultado['colecciones'] as $coleccion => $total) {
    $relleno = str_repeat(' ', max(1, 21 - mb_strlen($coleccion)));
    fwrite(STDOUT, sprintf('  %s%s%d documentos%s', $coleccion, $relleno, $total, PHP_EOL));
}
foreach ($resultado['borrados'] as $carpeta) {
    fwrite(STDOUT, 'Respaldo antiguo borrado: ' . $carpeta . PHP_EOL);
}
fwrite(STDOUT, sprintf('Se conservan los últimos %d respaldos.%s', $settings->respaldoMax, PHP_EOL));
