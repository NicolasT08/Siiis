<?php

declare(strict_types=1);

/*
 * Crea el par Credenciales + Usuarios con rol superadministrador, en una transacción de Atlas
 * (spec 10.13.4). Pide los datos por consola; la contraseña se lee sin eco.
 * Falla si el correo ya existe.
 *
 * Uso: php scripts/crear_superadmin.php [--test]
 */

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Session;
use Siiis\Database\Collections;
use Siiis\Database\Mongo;
use Siiis\Security\PasswordHasher;

use function MongoDB\with_transaction;

/** @var array{0: \Siiis\Config\Settings, 1: Mongo} $bootstrap */
$bootstrap = require __DIR__ . '/cli.php';
[$settings, $mongo] = $bootstrap;

// Se evalúa antes de leer: stream_isatty() descarta datos si STDIN ya tiene búfer.
define('STDIN_ES_TERMINAL', stream_isatty(STDIN));

function preguntar(string $etiqueta): string
{
    fwrite(STDOUT, $etiqueta);
    $linea = fgets(STDIN);

    return $linea === false ? '' : trim($linea);
}

function preguntarOculto(string $etiqueta): string
{
    fwrite(STDOUT, $etiqueta);

    // Entrada redirigida (tubería/archivo): no hay eco que ocultar.
    if (STDIN_ES_TERMINAL !== true) {
        $linea = fgets(STDIN);
        fwrite(STDOUT, PHP_EOL);

        return $linea === false ? '' : rtrim($linea, "\r\n");
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $comando = 'powershell -NoProfile -Command "$p = Read-Host -AsSecureString; '
            . '[Runtime.InteropServices.Marshal]::PtrToStringAuto('
            . '[Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"';
        $valor = shell_exec($comando);
        fwrite(STDOUT, PHP_EOL);

        return is_string($valor) ? rtrim($valor, "\r\n") : '';
    }

    shell_exec('stty -echo');
    $linea = fgets(STDIN);
    shell_exec('stty echo');
    fwrite(STDOUT, PHP_EOL);

    return $linea === false ? '' : rtrim($linea, "\r\n");
}

function fallar(string $mensaje): never
{
    fwrite(STDERR, 'Error: ' . $mensaje . PHP_EOL);
    exit(1);
}

$correo = mb_strtolower(preguntar('Correo: '));
if (filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
    fallar('correo no válido.');
}
$nombre = preguntar('Nombre: ');
$apellido = preguntar('Apellido: ');
if ($nombre === '' || $apellido === '') {
    fallar('nombre y apellido son obligatorios.');
}

$password = preguntarOculto('Contraseña: ');
if (mb_strlen($password) < $settings->passwordMinLength) {
    fallar(sprintf('la contraseña debe tener al menos %d caracteres.', $settings->passwordMinLength));
}
if (strlen($password) > 72) {
    fallar('la contraseña no puede superar 72 bytes (límite de bcrypt).');
}
if (preguntarOculto('Repite la contraseña: ') !== $password) {
    fallar('las contraseñas no coinciden.');
}

$credenciales = $mongo->collection(Collections::CREDENCIALES);
$usuarios = $mongo->collection(Collections::USUARIOS);

if ($credenciales->countDocuments(['correo' => $correo], ['limit' => 1]) > 0) {
    fallar('ya existe una cuenta con ese correo.');
}

$credencialId = new ObjectId();
$usuarioId = new ObjectId();
$ahora = new UTCDateTime();
$hash = (new PasswordHasher())->hash($password);

try {
    $session = $mongo->client()->startSession();
    with_transaction($session, static function (Session $session) use (
        $credenciales,
        $usuarios,
        $credencialId,
        $usuarioId,
        $correo,
        $hash,
        $nombre,
        $apellido,
        $ahora,
    ): void {
        $credenciales->insertOne([
            '_id' => $credencialId,
            'correo' => $correo,
            'password' => $hash,
            'usuario_id' => $usuarioId,
            'estado_cuenta' => 'activa',
            'token_recuperacion' => null,
            'token_expiracion' => null,
            'intentos_fallidos' => 0,
            'fecha_creacion' => $ahora,
            'ultimo_acceso' => null,
        ], ['session' => $session]);

        $usuarios->insertOne([
            '_id' => $usuarioId,
            'credencial_id' => $credencialId,
            'nombre' => $nombre,
            'apellido' => $apellido,
            'rol' => 'superadministrador',
            'programa_academico' => null,
            'semestre' => null,
            'foto_perfil' => null,
            'biografia' => null,
            'telefono' => null,
            'estado' => true,
            'fecha_ingreso' => $ahora,
            'fecha_salida' => null,
        ], ['session' => $session]);
    });
} catch (MongoDB\Driver\Exception\BulkWriteException $e) {
    fallar(str_contains($e->getMessage(), 'E11000') ? 'ya existe una cuenta con ese correo.' : $e->getMessage());
} catch (Throwable $e) {
    fallar('no se pudo crear el superadministrador: ' . $e->getMessage());
}

fwrite(STDOUT, sprintf('Superadministrador creado. Usuarios._id = %s%s', $usuarioId, PHP_EOL));
