<?php

declare(strict_types=1);

namespace Siiis\Database;

/**
 * Nombres oficiales de las 8 colecciones (spec 8.2, SIIIS-BD-001). Usar siempre estas constantes
 * para evitar errores de tildes, eñes y mayúsculas.
 */
final class Collections
{
    public const CREDENCIALES = 'Credenciales';
    public const USUARIOS = 'Usuarios';
    public const DOCUMENTOS = 'Documentos';
    public const MULTIMEDIA = 'Multimedia';
    public const RESENAS = 'Reseñas';
    public const INSCRIPCIONES = 'Inscripciones';
    public const SESIONES = 'Sesiones';
    public const MENSAJES_CONTACTO = 'Mensajes_Contacto';

    private function __construct()
    {
    }
}
