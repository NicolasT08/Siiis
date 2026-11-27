<?php

declare(strict_types=1);

namespace Siiis\Support;

/**
 * Forma JSON de una reseña (DEC-B45). Lista blanca de campos: nunca copia el documento entero.
 */
final class ResenaPresenter
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $resena
     *
     * @return array<string, mixed>
     */
    public static function presentar(array $resena): array
    {
        $data = Serializer::serialize([
            '_id' => $resena['_id'] ?? null,
            'usuario_id' => $resena['usuario_id'] ?? null,
            'nombre_visible' => $resena['nombre_visible'] ?? null,
            'comentario' => $resena['comentario'] ?? null,
            'calificacion' => $resena['calificacion'] ?? null,
            'estado' => $resena['estado'] ?? null,
            'fecha' => $resena['fecha'] ?? null,
        ]);

        return is_array($data) ? $data : [];
    }
}
