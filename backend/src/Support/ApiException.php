<?php

declare(strict_types=1);

namespace Siiis\Support;

use RuntimeException;

/**
 * Error de negocio/HTTP con código estable para el frontend. El ErrorHandler lo convierte en
 * {"error": {"code", "message", "fields"?}}.
 */
final class ApiException extends RuntimeException
{
    /**
     * @param array<string, string> $fields errores por campo (solo VALIDATION_ERROR)
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = [],
    ) {
        parent::__construct($message, $status);
    }

    /**
     * @param array<string, string> $fields
     */
    public static function validation(array $fields, string $message = 'Los datos enviados no son válidos.'): self
    {
        return new self(400, 'VALIDATION_ERROR', $message, $fields);
    }

    public static function unauthorized(string $message = 'Autenticación requerida o sesión no válida.'): self
    {
        return new self(401, 'UNAUTHORIZED', $message);
    }
}
