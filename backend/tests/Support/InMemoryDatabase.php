<?php

declare(strict_types=1);

namespace Siiis\Tests\Support;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * "Base de datos" en memoria compartida por los repositorios de prueba.
 */
final class InMemoryDatabase
{
    /** @var array<string, array<string, mixed>> */
    public array $credenciales = [];

    /** @var array<string, array<string, mixed>> */
    public array $usuarios = [];

    /** @var array<string, array<string, mixed>> */
    public array $sesiones = [];

    /** @var array<string, array<string, mixed>> */
    public array $multimedia = [];

    /** @var array<string, array<string, mixed>> */
    public array $documentos = [];

    /**
     * Crea un par Credenciales + Usuarios y devuelve [credencialId, usuarioId].
     *
     * @param array<string, mixed> $credencial campos que sobrescriben los por defecto
     * @param array<string, mixed> $usuario
     *
     * @return array{ObjectId, ObjectId}
     */
    public function crearCuenta(
        string $correo,
        string $password,
        array $credencial = [],
        array $usuario = [],
    ): array {
        $credencialId = new ObjectId();
        $usuarioId = new ObjectId();

        $this->credenciales[(string) $credencialId] = $credencial + [
            '_id' => $credencialId,
            'correo' => $correo,
            'password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]),
            'usuario_id' => $usuarioId,
            'estado_cuenta' => 'activa',
            'token_recuperacion' => null,
            'token_expiracion' => null,
            'intentos_fallidos' => 0,
            'fecha_creacion' => new UTCDateTime(),
            'ultimo_acceso' => null,
        ];
        $this->usuarios[(string) $usuarioId] = $usuario + [
            '_id' => $usuarioId,
            'credencial_id' => $credencialId,
            'nombre' => 'Ana',
            'apellido' => 'Pérez',
            'rol' => 'superadministrador',
            'foto_perfil' => ['url' => 'https://res.cloudinary.com/demo/image/upload/ana.jpg', 'public_id' => 'ana'],
            'estado' => true,
        ];

        return [$credencialId, $usuarioId];
    }
}
