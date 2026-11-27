<?php

declare(strict_types=1);

namespace Siiis\Services;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Siiis\Repositories\CredencialesRepository;
use Siiis\Repositories\CuentasRepository;
use Siiis\Repositories\DuplicateKeyException;
use Siiis\Security\PasswordHasher;
use Siiis\Security\Roles;
use Siiis\Support\ApiException;
use Siiis\Support\Clock;
use Siiis\Support\UsuarioPresenter;

/**
 * Alta de cuentas: par `Credenciales` + `Usuarios` (spec 8.2, 8.3 y 10.13.4). Lo usan
 * POST /auth/register (RF-01, DEC-B16) y scripts/crear_superadmin.php (DEC-B24).
 */
class UserAccountService
{
    public function __construct(
        private readonly CuentasRepository $cuentas,
        private readonly CredencialesRepository $credenciales,
        private readonly PasswordHasher $hasher,
        private readonly Clock $clock,
    ) {
    }

    /**
     * POST /auth/register: el actor debe poder asignar el rol pedido (DEC-B17).
     *
     * @param array{correo: string, password: string, rol: string, perfil: array<string, string|int|null>} $input
     *
     * @return array<string, mixed> vista de administrador del usuario creado
     *
     * @throws ApiException 403 FORBIDDEN, 409 EMAIL_TAKEN
     */
    public function registrar(string $actorRol, array $input): array
    {
        if (!Roles::puedeGestionar($actorRol, $input['rol'])) {
            throw ApiException::forbidden(sprintf('No puedes crear usuarios con el rol %s.', $input['rol']));
        }

        ['credencial' => $credencial, 'usuario' => $usuario] = $this->crearCuenta(
            $input['correo'],
            $input['password'],
            $input['rol'],
            $input['perfil'],
        );

        return UsuarioPresenter::administrador($usuario, $credencial);
    }

    /**
     * Crea la cuenta activa sin comprobar permisos (uso interno y CLI). El correo llega normalizado.
     *
     * @param array<string, string|int|null> $perfil campos de PerfilValidator::CAMPOS
     *
     * @return array{credencial: array<string, mixed>, usuario: array<string, mixed>}
     *
     * @throws ApiException 409 EMAIL_TAKEN
     */
    public function crearCuenta(string $correo, string $password, string $rol, array $perfil): array
    {
        if ($this->credenciales->existsByCorreo($correo)) {
            throw self::correoEnUso();
        }

        // Los dos _id se generan antes para las referencias cruzadas 1—1.
        $credencialId = new ObjectId();
        $usuarioId = new ObjectId();
        $ahora = new UTCDateTime($this->clock->now());

        $credencial = [
            '_id' => $credencialId,
            'correo' => $correo,
            'password' => $this->hasher->hash($password),
            'usuario_id' => $usuarioId,
            'estado_cuenta' => 'activa',
            'token_recuperacion' => null,
            'token_expiracion' => null,
            'intentos_fallidos' => 0,
            'fecha_creacion' => $ahora,
            'ultimo_acceso' => null,
        ];
        $usuario = [
            '_id' => $usuarioId,
            'credencial_id' => $credencialId,
            'nombre' => $perfil['nombre'] ?? null,
            'apellido' => $perfil['apellido'] ?? null,
            'rol' => $rol,
            'programa_academico' => $perfil['programa_academico'] ?? null,
            'semestre' => $perfil['semestre'] ?? null,
            'foto_perfil' => null,
            'biografia' => $perfil['biografia'] ?? null,
            'telefono' => $perfil['telefono'] ?? null,
            'estado' => true,
            'fecha_ingreso' => $ahora,
            'fecha_salida' => null,
        ];

        try {
            $this->cuentas->crear($credencial, $usuario);
        } catch (DuplicateKeyException) {
            // Carrera entre la comprobación y la inserción: lo detecta el índice único de correo.
            throw self::correoEnUso();
        }

        return ['credencial' => $credencial, 'usuario' => $usuario];
    }

    private static function correoEnUso(): ApiException
    {
        return new ApiException(409, 'EMAIL_TAKEN', 'Ya existe una cuenta con ese correo.');
    }
}
