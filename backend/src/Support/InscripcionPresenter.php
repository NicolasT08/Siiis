<?php

declare(strict_types=1);

namespace Siiis\Support;

/**
 * Formas JSON de una solicitud de inscripción (DEC-B50 a DEC-B52). Lista blanca de campos: nunca copia el
 * documento entero, nunca saca el `public_id` ni la URL guardada de los documentos.
 */
final class InscripcionPresenter
{
    private function __construct()
    {
    }

    /**
     * Listado del panel: sin la carta ni los documentos.
     *
     * @param array<string, mixed> $inscripcion
     *
     * @return array<string, mixed>
     */
    public static function resumen(array $inscripcion): array
    {
        $data = Serializer::serialize([
            '_id' => $inscripcion['_id'] ?? null,
            'nombre_completo' => $inscripcion['nombre_completo'] ?? null,
            'correo' => $inscripcion['correo'] ?? null,
            'telefono' => $inscripcion['telefono'] ?? null,
            'programa_academico' => $inscripcion['programa_academico'] ?? null,
            'semestre' => $inscripcion['semestre'] ?? null,
            'estado_solicitud' => $inscripcion['estado_solicitud'] ?? null,
            'fecha_solicitud' => $inscripcion['fecha_solicitud'] ?? null,
        ]);

        return is_array($data) ? $data : [];
    }

    /**
     * Detalle para administradores, con los enlaces firmados ya generados por el servicio.
     *
     * @param array<string, mixed>                                                               $inscripcion
     * @param list<array{nombre_archivo: string, formato: string, url: string, expira: string}> $documentos
     *
     * @return array<string, mixed>
     */
    public static function detalle(array $inscripcion, array $documentos): array
    {
        return self::resumen($inscripcion) + [
            'carta_motivacion' => is_string($inscripcion['carta_motivacion'] ?? null)
                ? $inscripcion['carta_motivacion']
                : null,
            'documentos_adjuntos' => $documentos,
        ];
    }

    /**
     * Respuesta al aspirante tras enviar la solicitud: solo lo que él mismo envió y el estado, sin enlaces.
     *
     * @param array<string, mixed> $inscripcion
     *
     * @return array<string, mixed>
     */
    public static function recibida(array $inscripcion): array
    {
        $documentos = is_array($inscripcion['documentos_adjuntos'] ?? null) ? $inscripcion['documentos_adjuntos'] : [];

        return self::resumen($inscripcion) + [
            'documentos_adjuntos' => array_values(array_map(
                static fn (mixed $d): array => [
                    'nombre_archivo' => is_array($d) && is_string($d['nombre_archivo'] ?? null) ? $d['nombre_archivo'] : '',
                ],
                $documentos,
            )),
        ];
    }
}
