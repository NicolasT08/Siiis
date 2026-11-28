<?php

declare(strict_types=1);

/*
 * Baja DEFINITIVA de cuentas, para mantenimiento de una sola vez (DEC-B73). La baja normal sigue siendo desactivar
 * la cuenta desde el panel (DEC-B22); este script no tiene equivalente en la API ni en el panel.
 *
 * Por defecto solo SIMULA: muestra todo lo relacionado con cada cuenta y si se puede borrar. Con --confirmar borra:
 * Credenciales, Usuarios y Sesiones; quita el id del arreglo `integrantes` de los documentos; quita `usuario_id` de
 * sus reseñas (conservan `nombre_visible`), y al final borra su foto de perfil en Cloudinary.
 *
 * Se niega con una cuenta activa, autora de algún documento, protegida (siiisweb@gmail.com) o si no quedaría ningún
 * superadministrador activo. Usa MONGO_DB_NAME (o MONGO_DB_NAME_TEST con --test) y solo muestra el nombre de la base.
 *
 * Uso: php scripts/eliminar_cuentas.php --correo=uno@uptc.edu.co [--correo=otro@uptc.edu.co] [--confirmar] [--test]
 * Salida: 0 si todo se pudo (simular o borrar), 1 si alguna cuenta se rechazó o falló, 2 si el uso es incorrecto.
 */

use Siiis\Repositories\MantenimientoCuentasRepository;
use Siiis\Services\CloudinaryService;
use Siiis\Services\EliminarCuentasService;

/** @var list<string> $argv */
$correos = [];
$confirmar = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--correo=')) {
        $correos[] = substr($arg, strlen('--correo='));
    } elseif ($arg === '--confirmar') {
        $confirmar = true;
    } elseif ($arg !== '--test') {
        fwrite(STDERR, 'Opción desconocida: ' . $arg . PHP_EOL);
        exit(2);
    }
}
if ($correos === []) {
    fwrite(STDERR, 'Uso: php scripts/eliminar_cuentas.php --correo=correo@uptc.edu.co [--correo=...] [--confirmar]' . PHP_EOL);
    exit(2);
}

/** @var array{0: \Siiis\Config\Settings, 1: \Siiis\Database\Mongo} $bootstrap */
$bootstrap = require __DIR__ . '/cli.php';
[$settings, $mongo] = $bootstrap;

$service = new EliminarCuentasService(
    new MantenimientoCuentasRepository($mongo),
    new CloudinaryService($settings),
    $settings->directorCorreo,
);

$salida = static function (string $linea = ''): void {
    fwrite(STDOUT, $linea . PHP_EOL);
};

$salida($confirmar ? 'Modo: BORRAR (--confirmar)' : 'Modo: SIMULACIÓN (no se borra nada; agrega --confirmar para borrar)');

try {
    $reportes = $service->revisar($correos);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error al consultar la base: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$listar = static function (array $documentos) use ($salida): void {
    foreach ($documentos as $d) {
        $salida(sprintf('      - [%s] %s (%s) id=%s', $d['categoria'], $d['titulo'], $d['estado'], $d['id']));
    }
};

foreach ($reportes as $r) {
    $salida();
    $salida('== ' . $r['correo']);
    if ($r['encontrada']) {
        $salida('   Credenciales._id: ' . $r['credencial_id'] . ' · estado_cuenta: ' . var_export($r['estado_cuenta'], true));
        if ($r['usuario_id'] !== null) {
            $salida(sprintf(
                '   Usuarios._id: %s · %s · rol: %s · estado: %s',
                $r['usuario_id'],
                $r['nombre'],
                $r['rol'],
                $r['estado'] === true ? 'activo' : 'inactivo',
            ));
        }
        $salida(sprintf('   Sesiones: %d (%d activas)', $r['sesiones']['total'], $r['sesiones']['activas']));
        $salida('   Foto de perfil en Cloudinary: ' . ($r['foto_public_id'] ?? 'no tiene'));
        $salida('   Documentos como autor: ' . count($r['documentos_autor']));
        $listar($r['documentos_autor']);
        $salida('   Documentos como integrante: ' . count($r['documentos_integrante']) . ' (se quitará de integrantes)');
        $listar($r['documentos_integrante']);
        $salida('   Reseñas con su usuario_id: ' . $r['resenas'] . ' (se conservan con su nombre_visible)');
        $salida(sprintf(
            '   Con el mismo correo (no se tocan, son datos guardados): %d inscripción(es), %d mensaje(s) de contacto',
            $r['inscripciones_con_correo'],
            $r['mensajes_con_correo'],
        ));
        $salida('   Superadministradores activos que quedarían: ' . $r['superadministradores_activos_restantes']);
    }
    foreach ($r['avisos'] as $aviso) {
        $salida('   Aviso: ' . $aviso);
    }
    foreach ($r['bloqueos'] as $bloqueo) {
        $salida('   NO SE PUEDE BORRAR: ' . $bloqueo);
    }
    if ($r['bloqueos'] === []) {
        $salida($confirmar ? '   → Se borrará.' : '   → Se puede borrar.');
    }
}

$rechazadas = count(array_filter($reportes, static fn (array $r): bool => $r['bloqueos'] !== []));
if (!$confirmar) {
    $salida();
    $salida(sprintf('Simulación terminada: %d cuenta(s) se pueden borrar, %d no.', count($reportes) - $rechazadas, $rechazadas));
    exit($rechazadas > 0 ? 1 : 0);
}

$fallos = $rechazadas;
$salida();
foreach ($service->eliminar($reportes) as $r) {
    if ($r['bloqueos'] !== []) {
        $salida('Omitida: ' . $r['correo']);
        continue;
    }
    if (isset($r['error'])) {
        ++$fallos;
        $salida('ERROR con ' . $r['correo'] . ': ' . $r['error'] . ' No se cambió nada de esta cuenta.');
        continue;
    }
    $res = $r['resultado'];
    $salida(sprintf(
        'Borrada: %s · credencial %d, usuario %d, sesiones %d, quitada de %d documento(s), %d reseña(s) desvinculada(s)',
        $r['correo'],
        $res['credencial'],
        $res['usuario'],
        $res['sesiones'],
        $res['integrantes'],
        $res['resenas'],
    ));
    if (isset($r['foto'])) {
        $salida('   Foto de perfil: ' . $r['foto']);
        if ($r['foto'] !== 'borrada') {
            ++$fallos;
        }
    }
}

exit($fallos > 0 ? 1 : 0);
