<?php

declare(strict_types=1);

/*
 * Arranque común de los scripts CLI. Se niega a ejecutarse desde la web.
 * Opción --test: usa MONGO_DB_NAME_TEST en lugar de MONGO_DB_NAME.
 */

use Dotenv\Dotenv;
use Siiis\Config\Settings;
use Siiis\Database\Mongo;

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

/** @var list<string> $argv */
$useTestDb = in_array('--test', $argv, true);
$dbName = $useTestDb ? $settings->mongoDbNameTest : $settings->mongoDbName;

if ($useTestDb && $dbName === $settings->mongoDbName) {
    fwrite(STDERR, 'MONGO_DB_NAME_TEST no puede ser igual a MONGO_DB_NAME.' . PHP_EOL);
    exit(1);
}

$mongo = new Mongo($settings->mongoUri, $dbName);
fwrite(STDOUT, sprintf('Base de datos: %s%s', $dbName, PHP_EOL));

return [$settings, $mongo];
