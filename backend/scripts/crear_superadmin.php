<?php

declare(strict_types=1);

/*
 * Crea el par Credenciales + Usuarios con rol superadministrador, en una transacción de Atlas
 * (spec 10.13.4), con el mismo UserAccountService que POST /auth/register (DEC-B24).
 * Pide los datos por consola; la contraseña se lee sin eco. Falla si el correo ya existe.
 *
 * Uso: php scripts/crear_superadmin.php [--test]
 */

use Siiis\Database\Mongo;
use Siiis\Repositories\CredencialesRepository;
use Siiis\Repositories\CuentasRepository;
use Siiis\Security\PasswordHasher;
use Siiis\Security\Roles;
use Siiis\Services\UserAccountService;
use Siiis\Support\ApiException;
use Siiis\Support\SystemClock;
use Siiis\Validation\PasswordRules;

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
$passwordError = PasswordRules::error($password, $settings);
if ($passwordError !== null) {
    fallar($passwordError);
}
if (preguntarOculto('Repite la contraseña: ') !== $password) {
    fallar('las contraseñas no coinciden.');
}

$service = new UserAccountService(
    new CuentasRepository($mongo),
    new CredencialesRepository($mongo),
    new PasswordHasher(),
    new SystemClock(),
);

try {
    $cuenta = $service->crearCuenta($correo, $password, Roles::SUPERADMINISTRADOR, [
        'nombre' => $nombre,
        'apellido' => $apellido,
    ]);
} catch (ApiException $e) {
    fallar($e->errorCode === 'EMAIL_TAKEN' ? 'ya existe una cuenta con ese correo.' : $e->getMessage());
} catch (Throwable $e) {
    fallar('no se pudo crear el superadministrador: ' . $e->getMessage());
}

fwrite(STDOUT, sprintf('Superadministrador creado. Usuarios._id = %s%s', $cuenta['usuario']['_id'], PHP_EOL));
