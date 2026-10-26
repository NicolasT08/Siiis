<?php

declare(strict_types=1);

namespace Siiis\Support;

/**
 * Formas JSON de un usuario (DEC-B19). Lista blanca de campos: nunca copia el documento entero.
 */
final class UsuarioPresenter
{
    private function __construct()
    {
    }

    /**
     * Vista de administrador: perfil completo + correo y estado de la cuenta (de `Credenciales`).
     *
     * @param array<string, mixed>      $usuario
     * @param array<string, mixed>|null $credencial
     *
     * @return array<string, mixed>
     */
    public static function administrador(array $usuario, ?array $credencial): array
    {
        $data = Serializer::serialize([
            '_id' => $usuario['_id'] ?? null,
            'correo' => $credencial['correo'] ?? null,
            'nombre' => $usuario['nombre'] ?? null,
            'apellido' => $usuario['apellido'] ?? null,
            'rol' => $usuario['rol'] ?? null,
            'programa_academico' => $usuario['programa_academico'] ?? null,
            'semestre' => $usuario['semestre'] ?? null,
            'biografia' => $usuario['biografia'] ?? null,
            'telefono' => $usuario['telefono'] ?? null,
            'foto_perfil' => self::foto($usuario),
            'estado' => $usuario['estado'] ?? null,
            'estado_cuenta' => $credencial['estado_cuenta'] ?? null,
            'fecha_ingreso' => $usuario['fecha_ingreso'] ?? null,
            'fecha_salida' => $usuario['fecha_salida'] ?? null,
            'ultimo_acceso' => $credencial['ultimo_acceso'] ?? null,
        ]);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $usuario
     *
     * @return array{url: string}|null solo la URL; el public_id de Cloudinary nunca sale
     */
    public static function foto(array $usuario): ?array
    {
        $foto = $usuario['foto_perfil'] ?? null;

        return is_array($foto) && is_string($foto['url'] ?? null) ? ['url' => $foto['url']] : null;
    }
}
