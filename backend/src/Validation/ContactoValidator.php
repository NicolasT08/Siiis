<?php

declare(strict_types=1);

namespace Siiis\Validation;

use Siiis\Config\Settings;
use Siiis\Repositories\MensajesContactoRepository;
use Siiis\Support\ApiException;

/**
 * Cuerpo de `POST /contact` y `PATCH /contact/messages/{id}` (DEC-B46). Solo acepta escalares
 * (antiinyección NoSQL). `nombre` y `asunto` quedan en una sola línea porque van a cabeceras del correo.
 */
final class ContactoValidator
{
    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * @return array{nombre: string, correo: string, asunto: string, mensaje: string}
     *
     * @throws ApiException 400 VALIDATION_ERROR
     */
    public function validate(mixed $body): array
    {
        $s = $this->settings;
        $errors = [];
        $datos = [
            'nombre' => InputReader::texto($body, 'nombre', $s->contactoNombreMaxLength, $errors, true),
            'correo' => InputReader::correo($body, $errors),
            'asunto' => InputReader::texto($body, 'asunto', $s->contactoAsuntoMaxLength, $errors, true),
            'mensaje' => InputReader::texto($body, 'mensaje', $s->contactoMensajeMaxLength, $errors),
        ];

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return $datos;
    }

    /**
     * `{"estado": "leido" | "no_leido"}`.
     *
     * @throws ApiException 400 VALIDATION_ERROR
     */
    public function estado(mixed $body): string
    {
        $errors = [];
        $estado = InputReader::opcion($body, 'estado', MensajesContactoRepository::ESTADOS, $errors);
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return $estado;
    }
}
