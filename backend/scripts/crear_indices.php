<?php

declare(strict_types=1);

/*
 * Crea los índices oficiales (spec 8.4 y 10.13.5). Idempotente: se puede ejecutar varias veces.
 *
 * Uso: php scripts/crear_indices.php [--test]
 */

use Siiis\Database\Collections;
use Siiis\Database\Mongo;

/** @var array{0: \Siiis\Config\Settings, 1: Mongo} $bootstrap */
$bootstrap = require __DIR__ . '/cli.php';
[, $mongo] = $bootstrap;

/** @var list<array{string, array<string, int>, array<string, mixed>}> $indices */
$indices = [
    [Collections::CREDENCIALES, ['correo' => 1], ['unique' => true, 'name' => 'correo_unico']],
    [Collections::USUARIOS, ['credencial_id' => 1], ['unique' => true, 'name' => 'credencial_id_unico']],
    [Collections::DOCUMENTOS, ['categoria' => 1, 'estado' => 1], ['name' => 'categoria_estado']],
    [Collections::INSCRIPCIONES, ['estado_solicitud' => 1], ['name' => 'estado_solicitud']],
    [Collections::SESIONES, ['token' => 1], ['name' => 'token']],
    // TTL: Atlas borra las sesiones cuando vence fecha_expiracion (sustituye la limpieza por cron).
    [Collections::SESIONES, ['fecha_expiracion' => 1], ['expireAfterSeconds' => 0, 'name' => 'fecha_expiracion_ttl']],
];

$errores = 0;
foreach ($indices as [$coleccion, $claves, $opciones]) {
    try {
        $nombre = $mongo->collection($coleccion)->createIndex($claves, $opciones);
        fwrite(STDOUT, sprintf('  OK  %s.%s%s', $coleccion, $nombre, PHP_EOL));
    } catch (Throwable $e) {
        $errores++;
        fwrite(STDERR, sprintf('  ERROR %s %s: %s%s', $coleccion, json_encode($claves), $e->getMessage(), PHP_EOL));
    }
}

fwrite(STDOUT, $errores === 0 ? 'Índices listos.' . PHP_EOL : "Terminado con $errores error(es)." . PHP_EOL);
exit($errores === 0 ? 0 : 1);
