<?php

declare(strict_types=1);

/*
 * Carga las fotos del slider y los videos de la página de inicio en la colección Multimedia (sección, orden y
 * descripción) para que el sitio no arranque vacío en producción (DEC-B60). Dos modos:
 *
 * 1. --carpeta=RUTA: sube a Cloudinary los archivos de una carpeta local (slider/, videos/, descripciones.json;
 *    reglas en scripts/multimedia/PlanMultimedia.php) y los registra.
 * 2. --existentes=RUTA: registra recursos que YA están en Cloudinary, sin subir nada (DEC-B66). Lee un JSON con las
 *    listas "slider" y "videos" (public_id, descripcion y orden opcional), consulta cada public_id en Cloudinary (solo
 *    lectura) para obtener su URL y comprobar que existe y que es imagen (slider) o video (videos).
 *    Nunca modifica, mueve ni borra nada en Cloudinary.
 *
 * Uso:
 *   php scripts/cargar_multimedia.php --carpeta=RUTA            base real (MONGO_DB_NAME) → CLOUDINARY_HOME_FOLDER (siiis/home)
 *   php scripts/cargar_multimedia.php --existentes=RUTA.json    base real; solo registra lo que ya está en Cloudinary
 *   ... --test                                                  base de pruebas (y, con --carpeta, carpeta siiis_test/home)
 *   ... --simular                                               solo muestra el plan; no sube ni escribe nada
 *   ... --si                                                    no pregunta (para usarlo en otro script)
 *
 * No duplica: un public_id que ya está en Multimedia (misma sección) no se vuelve a subir ni a registrar; si cambió su
 * orden o su descripción, solo se actualizan esos campos. Los registros que ya estaban y no vienen no se tocan.
 */

use Cloudinary\Api\Exception\NotFound;
use Cloudinary\Cloudinary;
use MongoDB\BSON\UTCDateTime;
use Siiis\Database\Collections;
use Siiis\Scripts\Multimedia\PlanMultimedia;

require_once __DIR__ . '/multimedia/PlanMultimedia.php';

/** @var array{0: \Siiis\Config\Settings, 1: \Siiis\Database\Mongo} $bootstrap */
$bootstrap = require __DIR__ . '/cli.php';
[$settings, $mongo] = $bootstrap;

/** @var list<string> $argv */
$opciones = ['carpeta' => '', 'existentes' => '', 'test' => false, 'simular' => false, 'si' => false];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--carpeta=')) {
        $opciones['carpeta'] = substr($arg, 10);
    } elseif (str_starts_with($arg, '--existentes=')) {
        $opciones['existentes'] = substr($arg, 13);
    } elseif (in_array($arg, ['--test', '--simular', '--si'], true)) {
        $opciones[substr($arg, 2)] = true;
    } else {
        fwrite(STDERR, "Opción desconocida: $arg" . PHP_EOL);
        exit(1);
    }
}
if (($opciones['carpeta'] === '') === ($opciones['existentes'] === '')) {
    fwrite(STDERR, 'Indica una (y solo una) de estas opciones: --carpeta=RUTA o --existentes=RUTA.json. Ver el encabezado del script.' . PHP_EOL);
    exit(1);
}
if ($settings->cloudinaryCloudName === '' || $settings->cloudinaryApiKey === '' || $settings->cloudinaryApiSecret === '') {
    fwrite(STDERR, 'Faltan CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY o CLOUDINARY_API_SECRET en el .env.' . PHP_EOL);
    exit(1);
}

$existentes = $opciones['existentes'] !== '';
// La base de pruebas nunca escribe en la carpeta de producción de Cloudinary.
$folder = $opciones['test'] ? 'siiis_test/home' : $settings->cloudinaryHomeFolder;
$base = $opciones['test'] ? $settings->mongoDbNameTest : $settings->mongoDbName;

// Cliente propio con más tiempo que el de la API (30 s): un video puede tardar varios minutos en subir.
$cloudinary = new Cloudinary([
    'cloud' => [
        'cloud_name' => $settings->cloudinaryCloudName,
        'api_key' => $settings->cloudinaryApiKey,
        'api_secret' => $settings->cloudinaryApiSecret,
    ],
    'url' => ['secure' => true],
    'api' => ['timeout' => 600, 'upload_timeout' => 600],
]);

$plan = $existentes
    ? PlanMultimedia::desdeJson($opciones['existentes'])
    : PlanMultimedia::desdeCarpeta($opciones['carpeta'], $folder);
$errores = $plan['errores'];
$items = $plan['items'];

/**
 * Modo --existentes: consulta cada public_id en Cloudinary (Admin API, solo lectura) y guarda su secure_url.
 * Si no está como el tipo esperado, mira el otro tipo para dar un mensaje claro. Los que fallan se quitan del plan.
 */
$urls = [];
if ($existentes) {
    $verificados = [];
    foreach ($items as $item) {
        $esperado = $item['tipo'] === 'imagen' ? 'image' : 'video';
        $otro = $esperado === 'image' ? 'video' : 'image';
        try {
            $recurso = $cloudinary->adminApi()->asset($item['public_id'], ['resource_type' => $esperado]);
            $url = $recurso['secure_url'] ?? null;
            if (!is_string($url) || $url === '') {
                $errores[] = sprintf('%s: Cloudinary no devolvió su URL.', $item['public_id']);
                continue;
            }
            $urls[$item['public_id']] = $url;
            $verificados[] = $item;
        } catch (NotFound) {
            $esDelOtroTipo = false;
            try {
                $cloudinary->adminApi()->asset($item['public_id'], ['resource_type' => $otro]);
                $esDelOtroTipo = true;
            } catch (NotFound) {
                // No existe con ningún tipo.
            }
            $errores[] = $esDelOtroTipo
                ? sprintf('%s: existe, pero es %s y la sección "%s" espera %s.', $item['public_id'], $otro === 'image' ? 'una imagen' : 'un video', $item['seccion'], $item['tipo'])
                : sprintf('%s: no existe en la cuenta %s de Cloudinary.', $item['public_id'], $settings->cloudinaryCloudName);
        } catch (Throwable $e) {
            $errores[] = sprintf('%s: no se pudo consultar en Cloudinary (%s).', $item['public_id'], $e->getMessage());
        }
    }
    $items = $verificados;
}

foreach ($errores as $error) {
    fwrite(STDERR, "  ! $error" . PHP_EOL);
}
if ($items === []) {
    fwrite(STDERR, 'No hay elementos válidos para cargar.' . PHP_EOL);
    exit(1);
}

$coleccion = $mongo->collection(Collections::MULTIMEDIA);
$previos = [];
foreach ($coleccion->find(['public_id' => ['$in' => array_column($items, 'public_id')]]) as $doc) {
    /** @var array<string, mixed> $doc */
    $previos[(string) $doc['seccion'] . '|' . (string) $doc['public_id']] = $doc;
}

fwrite(STDOUT, $existentes
    ? sprintf('Cloudinary: cuenta %s (solo lectura: no se sube ni se cambia nada)%s', $settings->cloudinaryCloudName, PHP_EOL)
    : sprintf('Cloudinary: cuenta %s, carpeta %s%s', $settings->cloudinaryCloudName, $folder, PHP_EOL));
fwrite(STDOUT, 'Plan:' . PHP_EOL);
$nuevos = 0;
foreach ($items as $item) {
    $previo = $previos[$item['seccion'] . '|' . $item['public_id']] ?? null;
    $accion = $previo === null
        ? ($existentes ? 'REGISTRAR' : 'SUBIR')
        : (($previo['orden'] ?? null) !== $item['orden'] || ($previo['descripcion'] ?? null) !== $item['descripcion'] ? 'ACTUALIZAR orden/descripción' : 'sin cambios');
    $nuevos += $previo === null ? 1 : 0;
    fwrite(STDOUT, sprintf(
        '  %-20s #%d %-40s "%s"  → %s%s',
        $item['seccion'],
        $item['orden'],
        $existentes ? $item['public_id'] : $item['archivo'],
        $item['descripcion'],
        $accion,
        PHP_EOL,
    ));
}

if ($opciones['simular']) {
    fwrite(STDOUT, 'Simulación: no se subió ni se escribió nada.' . PHP_EOL);
    exit($errores === [] ? 0 : 1);
}

if (!$opciones['si']) {
    if (!$opciones['test']) {
        fwrite(STDOUT, PHP_EOL . "ATENCIÓN: vas a escribir en la base REAL \"$base\"" . ($existentes ? '.' : " y en la carpeta $folder de Cloudinary.") . PHP_EOL);
    }
    fwrite(STDOUT, sprintf('Se %s %d elemento(s). Escribe "si" para continuar: ', $existentes ? 'registrarán' : 'subirán', $nuevos));
    $respuesta = strtolower(trim((string) fgets(STDIN)));
    if ($respuesta !== 'si' && $respuesta !== 'sí') {
        fwrite(STDOUT, 'Cancelado: no se hizo ningún cambio.' . PHP_EOL);
        exit(0);
    }
}

$registrados = 0;
$actualizados = 0;
$fallos = 0;
foreach ($items as $item) {
    $previo = $previos[$item['seccion'] . '|' . $item['public_id']] ?? null;

    if ($previo !== null) {
        $resultado = $coleccion->updateOne(
            ['_id' => $previo['_id']],
            ['$set' => ['orden' => $item['orden'], 'descripcion' => $item['descripcion']]],
        );
        $actualizados += $resultado->getModifiedCount();
        continue;
    }

    if ($existentes) {
        $url = $urls[$item['public_id']];
        $publicId = $item['public_id'];
    } else {
        $esImagen = $item['tipo'] === 'imagen';
        try {
            // public_id fijo y overwrite=false: si una corrida anterior subió el archivo pero no alcanzó a guardarlo
            // en MongoDB, Cloudinary devuelve el existente en lugar de duplicarlo.
            $res = $cloudinary->uploadApi()->upload($item['ruta'], [
                'resource_type' => $esImagen ? 'image' : 'video',
                'folder' => dirname($item['public_id']),
                'public_id' => basename($item['public_id']),
                'overwrite' => false,
                'unique_filename' => false,
                // Imágenes del slider: ancho máximo 1920 px, sin agrandar, con calidad automática (como DEC-B42).
                ...($esImagen ? ['transformation' => ['width' => 1920, 'crop' => 'limit', 'quality' => $settings->cloudinaryCalidad]] : []),
            ]);
        } catch (Throwable $e) {
            $fallos++;
            fwrite(STDERR, sprintf('  x %s: no se pudo subir (%s)%s', $item['archivo'], $e->getMessage(), PHP_EOL));
            continue;
        }
        $url = $res['secure_url'] ?? null;
        $publicId = $res['public_id'] ?? null;
        if (!is_string($url) || !is_string($publicId)) {
            $fallos++;
            fwrite(STDERR, sprintf('  x %s: respuesta de Cloudinary sin secure_url o public_id%s', $item['archivo'], PHP_EOL));
            continue;
        }
    }

    try {
        $coleccion->updateOne(
            ['seccion' => $item['seccion'], 'public_id' => $publicId],
            [
                '$setOnInsert' => [
                    'tipo' => $item['tipo'],
                    'url' => $url,
                    'public_id' => $publicId,
                    'seccion' => $item['seccion'],
                    'fecha_subida' => new UTCDateTime(),
                ],
                '$set' => ['orden' => $item['orden'], 'descripcion' => $item['descripcion']],
            ],
            ['upsert' => true],
        );
    } catch (Throwable $e) {
        $fallos++;
        fwrite(STDERR, sprintf('  x %s: no se guardó en MongoDB: %s. Vuelve a correr el script.%s', $publicId, $e->getMessage(), PHP_EOL));
        continue;
    }

    $registrados++;
    fwrite(STDOUT, sprintf('  + %s → %s%s', $existentes ? $publicId : $item['archivo'], $url, PHP_EOL));
}

fwrite(STDOUT, sprintf(
    'Listo: %d %s, %d actualizados, %d con error.%s',
    $registrados,
    $existentes ? 'registrados' : 'subidos',
    $actualizados,
    $fallos + count($errores),
    PHP_EOL,
));
exit($fallos > 0 || $errores !== [] ? 1 : 0);
