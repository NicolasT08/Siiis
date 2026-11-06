<?php

declare(strict_types=1);

namespace Siiis\Support;

/**
 * Forma JSON de un mensaje de `Mensajes_Contacto` para el buzón de administradores (DEC-B46).
 */
final class MensajeContactoPresenter
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $mensaje
     *
     * @return array<string, mixed>
     */
    public static function presentar(array $mensaje): array
    {
        $data = Serializer::serialize([
            '_id' => $mensaje['_id'] ?? null,
            'nombre' => $mensaje['nombre'] ?? null,
            'correo' => $mensaje['correo'] ?? null,
            'asunto' => $mensaje['asunto'] ?? null,
            'mensaje' => $mensaje['mensaje'] ?? null,
            'estado' => $mensaje['estado'] ?? null,
            'fecha' => $mensaje['fecha'] ?? null,
        ]);

        return is_array($data) ? $data : [];
    }
}
