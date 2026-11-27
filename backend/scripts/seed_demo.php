<?php

declare(strict_types=1);

/*
 * Datos de demostración para la base de pruebas (fase 5, paso 3). Solo corre contra `siiis_test` y solo sube
 * archivos a la carpeta `siiis_test/demo` de Cloudinary.
 *
 *   php scripts/seed_demo.php --test --password=<contraseña>   siembra (idempotente: no duplica)
 *   php scripts/seed_demo.php --test --limpiar                 borra todo lo sembrado (Atlas y Cloudinary)
 *
 * --test usa MONGO_DB_NAME_TEST (ver scripts/cli.php). La contraseña es la misma para todas las cuentas y sigue
 * la política de DEC-B11. Si una cuenta ya existe, se le vuelve a poner esa contraseña. Datos en
 * scripts/demo/datos.php; archivos generados por scripts/demo/DemoArchivos.php.
 */

use Cloudinary\Cloudinary;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Siiis\Config\Settings;
use Siiis\Database\Collections;
use Siiis\Database\Mongo;
use Siiis\Repositories\CredencialesRepository;
use Siiis\Repositories\CuentasRepository;
use Siiis\Scripts\Demo\DemoArchivos;
use Siiis\Security\PasswordHasher;
use Siiis\Services\CloudinaryService;
use Siiis\Services\UserAccountService;
use Siiis\Support\SystemClock;
use Siiis\Validation\PasswordRules;

const BASE_PERMITIDA = 'siiis_test';
const CARPETA_DEMO = 'siiis_test/demo';
/** Correos de la demo: prefijo "demo." y dominio reservado example.com. */
const PATRON_CORREO_DEMO = '^demo\.[^@]+@example\.com$';

/** @var array{0: Settings, 1: Mongo} $bootstrap */
$bootstrap = require __DIR__ . '/cli.php';
[$settings, $mongo] = $bootstrap;
require_once __DIR__ . '/demo/DemoArchivos.php';

function fallar(string $mensaje): never
{
    fwrite(STDERR, 'Error: ' . $mensaje . PHP_EOL);
    exit(1);
}

function linea(string $texto = ''): void
{
    fwrite(STDOUT, $texto . PHP_EOL);
}

if ($mongo->databaseName() !== BASE_PERMITIDA) {
    fallar(sprintf(
        'este script solo corre contra la base "%s" y la configurada es "%s". Usa --test con MONGO_DB_NAME_TEST=%s.',
        BASE_PERMITIDA,
        $mongo->databaseName(),
        BASE_PERMITIDA,
    ));
}

/** @var list<string> $argv */
$limpiar = in_array('--limpiar', $argv, true);
$password = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--password=')) {
        $password = substr($arg, strlen('--password='));
    }
}

/**
 * @var array{
 *     usuarios: array<string, array{correo: string, rol: string, activo: bool, nombre: string, apellido: string,
 *         programa_academico: string, semestre: int|null, telefono: string|null, biografia: string|null}>,
 *     documentos: list<array{categoria: string, estado: string, visible: bool, autor: string, integrantes: list<string>,
 *         dias: int, titulo: string, descripcion: string, contenido: string}>,
 *     resenas: list<array{usuario: string|null, nombre_visible: string|null, calificacion: int|null, estado: string,
 *         dias: int, comentario: string}>,
 *     mensajes: list<array{nombre: string, correo: string, asunto: string, mensaje: string, estado: string, dias: int}>,
 *     inscripciones: list<array{nombre_completo: string, correo: string, telefono: string, programa_academico: string,
 *         semestre: int, dias: int, carta_motivacion: string, documentos: list<string>}>,
 *     imagenes: list<array{seccion: string, orden: int, desde: array{int, int, int}, hasta: array{int, int, int},
 *         descripcion: string}>,
 *     video: array{origen: string, seccion: string, orden: int, descripcion: string}
 * } $datos
 */
$datos = require __DIR__ . '/demo/datos.php';

$s = $settings;
if ($s->cloudinaryCloudName === '' || $s->cloudinaryApiKey === '' || $s->cloudinaryApiSecret === '') {
    fallar('faltan CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY o CLOUDINARY_API_SECRET en el .env.');
}
$cloudinary = new CloudinaryService($settings);
$sdk = new Cloudinary([
    'cloud' => ['cloud_name' => $s->cloudinaryCloudName, 'api_key' => $s->cloudinaryApiKey, 'api_secret' => $s->cloudinaryApiSecret],
    'url' => ['secure' => true],
    'api' => ['timeout' => 60, 'upload_timeout' => 120],
]);

/**
 * Hace `$dias` días, con `$minutos` de desfase para que dos registros del mismo día no empaten al ordenar.
 */
function haceDias(int $dias, int $minutos = 0): UTCDateTime
{
    return new UTCDateTime(new DateTimeImmutable(sprintf('-%d days -%d minutes', $dias, $minutos)));
}

function esDeLaDemo(mixed $publicId): bool
{
    return is_string($publicId) && str_starts_with($publicId, CARPETA_DEMO . '/');
}

// ---------------------------------------------------------------------------------------------------------------
// --limpiar
// ---------------------------------------------------------------------------------------------------------------

/**
 * @param array{resenas: list<array{comentario: string}>} $datos
 */
function limpiar(Mongo $mongo, CloudinaryService $cloudinary, Cloudinary $sdk, array $datos): void
{
    $correoDemo = ['$regex' => PATRON_CORREO_DEMO];
    $usuarioIds = [];
    foreach ($mongo->collection(Collections::CREDENCIALES)->find(['correo' => $correoDemo]) as $cred) {
        /** @var array<string, mixed> $cred */
        if ($cred['usuario_id'] instanceof ObjectId) {
            $usuarioIds[] = $cred['usuario_id'];
        }
    }

    // Archivos conocidos: adjuntos de documentos de la demo, documentos de inscripción y multimedia.
    $borrados = 0;
    $borrar = static function (mixed $publicId, bool $privado, string $tipo = 'image') use ($cloudinary, $sdk, &$borrados): void {
        if (!esDeLaDemo($publicId)) {
            return; // nunca se borra nada fuera de siiis_test/demo
        }
        /** @var string $publicId */
        try {
            if ($tipo === 'video') {
                $sdk->uploadApi()->destroy($publicId, ['resource_type' => 'video', 'invalidate' => true]);
            } else {
                $cloudinary->delete($publicId, $privado);
            }
            $borrados++;
        } catch (Throwable $e) {
            fwrite(STDERR, "  No se pudo borrar $publicId: {$e->getMessage()}" . PHP_EOL);
        }
    };

    $documentos = $mongo->collection(Collections::DOCUMENTOS);
    foreach ($documentos->find(['autor_id' => ['$in' => $usuarioIds]]) as $doc) {
        $adjuntos = is_array($doc) && is_array($doc['archivos_adjuntos'] ?? null) ? $doc['archivos_adjuntos'] : [];
        foreach ($adjuntos as $adjunto) {
            $borrar(is_array($adjunto) ? ($adjunto['public_id'] ?? null) : null, false);
        }
    }
    $inscripciones = $mongo->collection(Collections::INSCRIPCIONES);
    foreach ($inscripciones->find(['correo' => $correoDemo]) as $insc) {
        $archivos = is_array($insc) && is_array($insc['documentos_adjuntos'] ?? null) ? $insc['documentos_adjuntos'] : [];
        foreach ($archivos as $documento) {
            $borrar(is_array($documento) ? ($documento['public_id'] ?? null) : null, true);
        }
    }
    $multimedia = $mongo->collection(Collections::MULTIMEDIA);
    $filtroMultimedia = ['public_id' => ['$regex' => '^' . preg_quote(CARPETA_DEMO . '/', '/')]];
    foreach ($multimedia->find($filtroMultimedia) as $item) {
        /** @var array<string, mixed> $item */
        $borrar($item['public_id'] ?? null, false, ($item['tipo'] ?? null) === 'video' ? 'video' : 'image');
    }

    // Barrido de la carpeta: lo que haya quedado de una corrida interrumpida.
    $barridos = 0;
    foreach ([['image', 'upload'], ['image', 'authenticated'], ['video', 'upload']] as [$recurso, $tipo]) {
        try {
            $res = $sdk->adminApi()->deleteAssetsByPrefix(CARPETA_DEMO . '/', ['resource_type' => $recurso, 'type' => $tipo]);
            $barridos += is_array($res['deleted'] ?? null) ? count($res['deleted']) : 0;
        } catch (Throwable $e) {
            fwrite(STDERR, "  No se pudo barrer $recurso/$tipo en " . CARPETA_DEMO . ": {$e->getMessage()}" . PHP_EOL);
        }
    }

    $comentarios = array_column($datos['resenas'], 'comentario');
    $conteos = [
        'Documentos' => $documentos->deleteMany(['autor_id' => ['$in' => $usuarioIds]])->getDeletedCount(),
        'Reseñas' => $mongo->collection(Collections::RESENAS)->deleteMany(['$or' => [
            ['usuario_id' => ['$in' => $usuarioIds]],
            ['usuario_id' => null, 'comentario' => ['$in' => $comentarios]],
        ]])->getDeletedCount(),
        'Inscripciones' => $inscripciones->deleteMany(['correo' => $correoDemo])->getDeletedCount(),
        'Mensajes_Contacto' => $mongo->collection(Collections::MENSAJES_CONTACTO)->deleteMany(['correo' => $correoDemo])->getDeletedCount(),
        'Multimedia' => $multimedia->deleteMany($filtroMultimedia)->getDeletedCount(),
        'Sesiones' => $mongo->collection(Collections::SESIONES)->deleteMany(['usuario_id' => ['$in' => $usuarioIds]])->getDeletedCount(),
        'Usuarios' => $mongo->collection(Collections::USUARIOS)->deleteMany(['_id' => ['$in' => $usuarioIds]])->getDeletedCount(),
        'Credenciales' => $mongo->collection(Collections::CREDENCIALES)->deleteMany(['correo' => $correoDemo])->getDeletedCount(),
    ];

    linea('Borrado en Atlas:');
    foreach ($conteos as $coleccion => $n) {
        linea('  ' . $coleccion . str_repeat(' ', max(1, 19 - mb_strlen($coleccion))) . (int) $n);
    }
    linea(sprintf('Borrado en Cloudinary (%s): %d archivos registrados, %d más en el barrido.', CARPETA_DEMO, $borrados, $barridos));
}

if ($limpiar) {
    limpiar($mongo, $cloudinary, $sdk, $datos);
    exit(0);
}

// ---------------------------------------------------------------------------------------------------------------
// Siembra
// ---------------------------------------------------------------------------------------------------------------

if ($password === null) {
    fallar('falta --password=<contraseña> (la misma para todas las cuentas de la demo).');
}
$errorPassword = PasswordRules::error($password, $settings);
if ($errorPassword !== null) {
    fallar($errorPassword);
}

$hasher = new PasswordHasher();
$credenciales = new CredencialesRepository($mongo);
$cuentas = new UserAccountService(new CuentasRepository($mongo), $credenciales, $hasher, new SystemClock());
$usuariosCol = $mongo->collection(Collections::USUARIOS);

// Usuarios -------------------------------------------------------------------------------------------------------
/** @var array<string, ObjectId> $ids */
$ids = [];
$nuevos = 0;
foreach ($datos['usuarios'] as $clave => $u) {
    $existente = $credenciales->findByCorreo($u['correo']);
    if ($existente !== null && $existente['_id'] instanceof ObjectId && $existente['usuario_id'] instanceof ObjectId) {
        // Ya existe: se le pone la contraseña indicada para que la lista impresa al final sea cierta.
        $credenciales->cambiarPassword($existente['_id'], $hasher->hash($password));
        $mongo->collection(Collections::CREDENCIALES)->updateOne(
            ['_id' => $existente['_id']],
            ['$set' => ['estado_cuenta' => 'activa', 'intentos_fallidos' => 0]],
        );
        $ids[$clave] = $existente['usuario_id'];
    } else {
        $cuenta = $cuentas->crearCuenta($u['correo'], $password, $u['rol'], [
            'nombre' => $u['nombre'],
            'apellido' => $u['apellido'],
            'programa_academico' => $u['programa_academico'],
            'semestre' => $u['semestre'],
            'biografia' => $u['biografia'],
            'telefono' => $u['telefono'],
        ]);
        $usuarioId = $cuenta['usuario']['_id'];
        if (!$usuarioId instanceof ObjectId) {
            fallar('UserAccountService no devolvió el _id del usuario ' . $u['correo']);
        }
        $ids[$clave] = $usuarioId;
        $nuevos++;
    }
    $usuariosCol->updateOne(['_id' => $ids[$clave]], ['$set' => [
        'rol' => $u['rol'],
        'estado' => $u['activo'],
        'fecha_salida' => $u['activo'] ? null : haceDias(30),
    ]]);
}
linea(sprintf('Usuarios: %d nuevos, %d ya existían.', $nuevos, count($ids) - $nuevos));

// Documentos -----------------------------------------------------------------------------------------------------
$documentosCol = $mongo->collection(Collections::DOCUMENTOS);
$nuevos = 0;
foreach ($datos['documentos'] as $i => $d) {
    $autor = $ids[$d['autor']];
    $clave = ['categoria' => $d['categoria'], 'titulo' => $d['titulo'], 'autor_id' => $autor];
    if ($documentosCol->countDocuments($clave, ['limit' => 1]) > 0) {
        continue;
    }
    $fecha = haceDias($d['dias'], $i);
    $documentosCol->insertOne([
        '_id' => new ObjectId(),
        'categoria' => $d['categoria'],
        'titulo' => $d['titulo'],
        'descripcion' => $d['descripcion'],
        'contenido' => $d['contenido'],
        'autor_id' => $autor,
        'integrantes' => array_map(static fn (string $k): ObjectId => $ids[$k], $d['integrantes']),
        'archivos_adjuntos' => [],
        'estado' => $d['estado'],
        'visible' => $d['visible'],
        'fecha_creacion' => $fecha,
        'fecha_actualizacion' => $fecha,
    ]);
    $nuevos++;
}
linea(sprintf('Documentos: %d nuevos, %d ya existían.', $nuevos, count($datos['documentos']) - $nuevos));

// Reseñas --------------------------------------------------------------------------------------------------------
$resenasCol = $mongo->collection(Collections::RESENAS);
$nuevos = 0;
foreach ($datos['resenas'] as $i => $r) {
    $usuarioId = $r['usuario'] === null ? null : $ids[$r['usuario']];
    if ($resenasCol->countDocuments(['usuario_id' => $usuarioId, 'comentario' => $r['comentario']], ['limit' => 1]) > 0) {
        continue;
    }
    $u = $r['usuario'] === null ? null : $datos['usuarios'][$r['usuario']];
    $resenasCol->insertOne([
        '_id' => new ObjectId(),
        'usuario_id' => $usuarioId,
        // Como POST /reviews: un integrante publica con su nombre completo (DEC-B43).
        'nombre_visible' => $u === null ? (string) $r['nombre_visible'] : $u['nombre'] . ' ' . $u['apellido'],
        'comentario' => $r['comentario'],
        'calificacion' => $r['calificacion'],
        'estado' => $r['estado'],
        'fecha' => haceDias($r['dias'], $i),
    ]);
    $nuevos++;
}
linea(sprintf('Reseñas: %d nuevas, %d ya existían.', $nuevos, count($datos['resenas']) - $nuevos));

// Mensajes de contacto -------------------------------------------------------------------------------------------
$mensajesCol = $mongo->collection(Collections::MENSAJES_CONTACTO);
$nuevos = 0;
foreach ($datos['mensajes'] as $i => $m) {
    if ($mensajesCol->countDocuments(['correo' => $m['correo'], 'asunto' => $m['asunto']], ['limit' => 1]) > 0) {
        continue;
    }
    $mensajesCol->insertOne([
        '_id' => new ObjectId(),
        'nombre' => $m['nombre'],
        'correo' => $m['correo'],
        'asunto' => $m['asunto'],
        'mensaje' => $m['mensaje'],
        'estado' => $m['estado'],
        'fecha' => haceDias($m['dias'], $i),
    ]);
    $nuevos++;
}
linea(sprintf('Mensajes de contacto: %d nuevos, %d ya existían.', $nuevos, count($datos['mensajes']) - $nuevos));

// Inscripciones (PDF privados en Cloudinary) ---------------------------------------------------------------------
$inscripcionesCol = $mongo->collection(Collections::INSCRIPCIONES);
$nuevos = 0;
foreach ($datos['inscripciones'] as $i => $insc) {
    if ($inscripcionesCol->countDocuments(['correo' => $insc['correo']], ['limit' => 1]) > 0) {
        continue;
    }
    $subidos = [];
    try {
        foreach ($insc['documentos'] as $nombreArchivo) {
            $ruta = DemoArchivos::temporal(DemoArchivos::pdf(
                'Documento de demostración',
                [
                    'Archivo: ' . $nombreArchivo,
                    'Aspirante: ' . $insc['nombre_completo'],
                    'Programa: ' . $insc['programa_academico'] . ' - semestre ' . $insc['semestre'],
                    '',
                    'Generado por scripts/seed_demo.php para la base siiis_test.',
                    'No contiene datos reales.',
                ],
            ), 'pdf');
            try {
                $subido = $cloudinary->uploadPrivado($ruta, CARPETA_DEMO, CloudinaryService::FORMATOS_ADJUNTO);
            } finally {
                @unlink($ruta);
            }
            $subidos[] = $subido + ['nombre_archivo' => $nombreArchivo];
        }
        $inscripcionesCol->insertOne([
            '_id' => new ObjectId(),
            'nombre_completo' => $insc['nombre_completo'],
            'correo' => $insc['correo'],
            'telefono' => $insc['telefono'],
            'programa_academico' => $insc['programa_academico'],
            'semestre' => $insc['semestre'],
            'carta_motivacion' => $insc['carta_motivacion'],
            'documentos_adjuntos' => $subidos,
            'estado_solicitud' => 'pendiente',
            'fecha_solicitud' => haceDias($insc['dias'], $i),
        ]);
    } catch (Throwable $e) {
        foreach ($subidos as $subido) {
            try {
                $cloudinary->delete($subido['public_id'], true);
            } catch (Throwable) {
                // lo recoge el barrido de --limpiar
            }
        }
        fallar('no se pudo sembrar la inscripción de ' . $insc['correo'] . ': ' . $e->getMessage());
    }
    $nuevos++;
}
linea(sprintf('Inscripciones: %d nuevas, %d ya existían.', $nuevos, count($datos['inscripciones']) - $nuevos));

// Multimedia -----------------------------------------------------------------------------------------------------
$multimediaCol = $mongo->collection(Collections::MULTIMEDIA);
$prefijo = ['$regex' => '^' . preg_quote(CARPETA_DEMO . '/', '/')];
$nuevos = 0;
foreach ($datos['imagenes'] as $i => $img) {
    $clave = ['seccion' => $img['seccion'], 'descripcion' => $img['descripcion'], 'public_id' => $prefijo];
    if ($multimediaCol->countDocuments($clave, ['limit' => 1]) > 0) {
        continue;
    }
    $ruta = DemoArchivos::temporal(DemoArchivos::png(1280, 720, $img['desde'], $img['hasta'], 2026 + $i), 'png');
    try {
        $subido = $cloudinary->upload(
            $ruta,
            CARPETA_DEMO,
            CloudinaryService::FORMATOS_IMAGEN,
            CloudinaryService::transformacionImagenAdjunta($settings),
        );
    } catch (Throwable $e) {
        fallar('no se pudo subir la imagen "' . $img['descripcion'] . '": ' . $e->getMessage());
    } finally {
        @unlink($ruta);
    }
    $multimediaCol->insertOne([
        '_id' => new ObjectId(),
        'tipo' => 'imagen',
        'url' => $subido['url'],
        'public_id' => $subido['public_id'],
        'seccion' => $img['seccion'],
        'descripcion' => $img['descripcion'],
        'orden' => $img['orden'],
        'fecha_subida' => new UTCDateTime(),
    ]);
    $nuevos++;
}

$video = $datos['video'];
$claveVideo = ['seccion' => $video['seccion'], 'descripcion' => $video['descripcion'], 'public_id' => $prefijo];
if ($multimediaCol->countDocuments($claveVideo, ['limit' => 1]) === 0) {
    try {
        $res = $sdk->uploadApi()->upload($video['origen'], [
            'resource_type' => 'video',
            'folder' => CARPETA_DEMO,
            'unique_filename' => true,
            'use_filename' => false,
            'overwrite' => false,
        ]);
    } catch (Throwable $e) {
        fallar('no se pudo copiar el video de ejemplo a Cloudinary: ' . $e->getMessage());
    }
    if (!is_string($res['secure_url'] ?? null) || !esDeLaDemo($res['public_id'] ?? null)) {
        fallar('Cloudinary no devolvió la URL del video de ejemplo.');
    }
    $multimediaCol->insertOne([
        '_id' => new ObjectId(),
        'tipo' => 'video',
        'url' => $res['secure_url'],
        'public_id' => $res['public_id'],
        'seccion' => $video['seccion'],
        'descripcion' => $video['descripcion'],
        'orden' => $video['orden'],
        'fecha_subida' => new UTCDateTime(),
    ]);
    $nuevos++;
}
linea(sprintf('Multimedia: %d nuevos (en %s), %d ya existían.', $nuevos, CARPETA_DEMO, count($datos['imagenes']) + 1 - $nuevos));

// Resumen --------------------------------------------------------------------------------------------------------
linea();
linea('Cuentas de la demo (misma contraseña para todas, la de --password):');
foreach ($datos['usuarios'] as $u) {
    linea(sprintf(
        '  %-30s %-20s %s%s',
        $u['correo'],
        $u['rol'],
        $u['nombre'] . ' ' . $u['apellido'],
        $u['activo'] ? '' : '  (inactivo: no puede iniciar sesión)',
    ));
}
linea();
linea('Para borrar todo: php scripts/seed_demo.php --test --limpiar');
