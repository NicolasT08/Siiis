<?php

declare(strict_types=1);

namespace Siiis\Security;

/**
 * Roles oficiales de `Usuarios.rol` (spec 8.2) y jerarquía para gestionar usuarios (DEC-B17, PROPUESTA).
 */
final class Roles
{
    public const SUPERADMINISTRADOR = 'superadministrador';
    public const ADMINISTRADOR = 'administrador';
    public const DOCENTE_DIRECTOR = 'docente_director';
    public const ESTUDIANTE_LIDER = 'estudiante_lider';
    public const ESTUDIANTE = 'estudiante';

    /** Lista blanca de roles. */
    public const TODOS = [
        self::SUPERADMINISTRADOR,
        self::ADMINISTRADOR,
        self::DOCENTE_DIRECTOR,
        self::ESTUDIANTE_LIDER,
        self::ESTUDIANTE,
    ];

    /** Roles que pueden administrar usuarios. */
    public const ADMINISTRATIVOS = [self::SUPERADMINISTRADOR, self::ADMINISTRADOR];

    /** Roles que aprueban, publican, editan y borran cualquier contenido de `Documentos` (DEC-B33). */
    public const REVISORES_CONTENIDO = [self::SUPERADMINISTRADOR, self::ADMINISTRADOR, self::DOCENTE_DIRECTOR];

    /** Roles que moderan las reseñas: los mismos revisores del contenido (DEC-B44). */
    public const MODERADORES_RESENAS = self::REVISORES_CONTENIDO;

    /** Roles que un administrador (no superadministrador) puede gestionar y asignar. */
    private const GESTIONABLES_POR_ADMINISTRADOR = [
        self::DOCENTE_DIRECTOR,
        self::ESTUDIANTE_LIDER,
        self::ESTUDIANTE,
    ];

    private function __construct()
    {
    }

    public static function esValido(string $rol): bool
    {
        return in_array($rol, self::TODOS, true);
    }

    public static function esAdministrativo(string $rol): bool
    {
        return in_array($rol, self::ADMINISTRATIVOS, true);
    }

    public static function esModeradorResenas(?string $rol): bool
    {
        return $rol !== null && in_array($rol, self::MODERADORES_RESENAS, true);
    }

    public static function esRevisorContenido(?string $rol): bool
    {
        return $rol !== null && in_array($rol, self::REVISORES_CONTENIDO, true);
    }

    /**
     * Roles que `$actor` puede asignar al crear o editar un usuario.
     *
     * @return list<string>
     */
    public static function asignablesPor(string $actor): array
    {
        return match ($actor) {
            self::SUPERADMINISTRADOR => self::TODOS,
            self::ADMINISTRADOR => self::GESTIONABLES_POR_ADMINISTRADOR,
            default => [],
        };
    }

    /**
     * Si `$actor` puede crear, editar, desactivar o reactivar a un usuario con rol `$objetivo`.
     * Las reglas sobre uno mismo y el último superadministrador las aplica el servicio de usuarios.
     */
    public static function puedeGestionar(string $actor, string $objetivo): bool
    {
        return in_array($objetivo, self::asignablesPor($actor), true);
    }
}
