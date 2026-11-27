<?php

declare(strict_types=1);

namespace Siiis\Validation;

use Siiis\Config\Settings;
use Siiis\Repositories\ResenasRepository;
use Siiis\Support\ApiException;

/**
 * Cuerpo de `POST /reviews` y `PATCH /reviews/{id}` (DEC-B43, DEC-B44). Solo acepta escalares
 * (antiinyección NoSQL). `usuario_id`, `estado` y `fecha` nunca salen del cuerpo al crear.
 */
final class ResenaValidator
{
    public const CALIFICACION_MIN = 1;
    public const CALIFICACION_MAX = 5;

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * Con sesión, `nombre_visible` sale del perfil y se ignora el del cuerpo.
     *
     * @return array{nombre_visible: string|null, comentario: string, calificacion: int|null}
     *
     * @throws ApiException 400 VALIDATION_ERROR
     */
    public function crear(mixed $body, bool $conSesion): array
    {
        $errors = [];
        $nombre = $conSesion
            ? null
            : InputReader::texto($body, 'nombre_visible', $this->settings->resenaNombreMaxLength, $errors, true);
        $comentario = InputReader::texto($body, 'comentario', $this->settings->resenaComentarioMaxLength, $errors);
        $calificacion = self::calificacion(is_array($body) ? ($body['calificacion'] ?? null) : null, $errors);

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return ['nombre_visible' => $nombre, 'comentario' => $comentario, 'calificacion' => $calificacion];
    }

    /**
     * `{"estado": "visible" | "oculta"}`.
     *
     * @throws ApiException 400 VALIDATION_ERROR
     */
    public function estado(mixed $body): string
    {
        $errors = [];
        $estado = InputReader::opcion($body, 'estado', ResenasRepository::ESTADOS, $errors);
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return $estado;
    }

    /**
     * Opcional: entero JSON de 1 a 5 (`"5"` como texto se rechaza, igual que `semestre`).
     *
     * @param array<string, string> $errors
     */
    private static function calificacion(mixed $valor, array &$errors): ?int
    {
        if ($valor === null) {
            return null;
        }
        if (!is_int($valor) || $valor < self::CALIFICACION_MIN || $valor > self::CALIFICACION_MAX) {
            $errors['calificacion'] = sprintf(
                'La calificación debe ser un número entero entre %d y %d.',
                self::CALIFICACION_MIN,
                self::CALIFICACION_MAX,
            );

            return null;
        }

        return $valor;
    }
}
