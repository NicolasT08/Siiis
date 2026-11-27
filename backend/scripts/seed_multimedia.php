<?php

declare(strict_types=1);

/*
 * Inserta ítems de ejemplo en Multimedia (slider_principal y video_presentacion).
 * No duplica: un ítem con la misma (seccion, url) no se vuelve a insertar.
 *
 * Datos: scripts/data/multimedia_seed.json si existe (no versionado, para URLs reales);
 * si no, scripts/data/multimedia_seed.example.json (cloud público "demo" de Cloudinary).
 *
 * Uso: php scripts/seed_multimedia.php [--test] [--file=ruta.json]
 */

use MongoDB\BSON\UTCDateTime;
use Siiis\Database\Collections;
use Siiis\Database\Mongo;

/** @var array{0: \Siiis\Config\Settings, 1: Mongo} $bootstrap */
$bootstrap = require __DIR__ . '/cli.php';
[, $mongo] = $bootstrap;

const SECCIONES = ['slider_principal', 'video_presentacion', 'galeria'];
const TIPOS = ['imagen', 'video'];

$archivo = __DIR__ . '/data/multimedia_seed.json';
if (!is_file($archivo)) {
    $archivo = __DIR__ . '/data/multimedia_seed.example.json';
}
/** @var list<string> $argv */
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--file=')) {
        $archivo = substr($arg, 7);
    }
}

$items = json_decode((string) @file_get_contents($archivo), true);
if (!is_array($items)) {
    fwrite(STDERR, "No se pudo leer un arreglo JSON de $archivo" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, 'Datos: ' . $archivo . PHP_EOL);

$coleccion = $mongo->collection(Collections::MULTIMEDIA);
$insertados = 0;
$existentes = 0;

foreach ($items as $i => $item) {
    if (
        !is_array($item)
        || !in_array($item['seccion'] ?? null, SECCIONES, true)
        || !in_array($item['tipo'] ?? null, TIPOS, true)
        || !is_string($item['url'] ?? null) || !str_starts_with($item['url'], 'https://')
        || !is_int($item['orden'] ?? null)
    ) {
        fwrite(STDERR, "  Ítem $i inválido (seccion, tipo, url https y orden entero son obligatorios); se omite." . PHP_EOL);
        continue;
    }

    $resultado = $coleccion->updateOne(
        ['seccion' => $item['seccion'], 'url' => $item['url']],
        ['$setOnInsert' => [
            'tipo' => $item['tipo'],
            'url' => $item['url'],
            'public_id' => is_string($item['public_id'] ?? null) ? $item['public_id'] : '',
            'seccion' => $item['seccion'],
            'descripcion' => is_string($item['descripcion'] ?? null) ? $item['descripcion'] : '',
            'orden' => $item['orden'],
            'fecha_subida' => new UTCDateTime(),
        ]],
        ['upsert' => true],
    );

    if ($resultado->getUpsertedCount() > 0) {
        $insertados++;
        fwrite(STDOUT, sprintf('  +  %s #%d %s%s', $item['seccion'], $item['orden'], $item['url'], PHP_EOL));
    } else {
        $existentes++;
    }
}

fwrite(STDOUT, "Insertados: $insertados · ya existían: $existentes" . PHP_EOL);
