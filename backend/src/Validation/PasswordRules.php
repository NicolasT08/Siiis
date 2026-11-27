<?php

declare(strict_types=1);

namespace Siiis\Validation;

use Siiis\Config\Settings;

/**
 * Política de contraseña compartida por registro y restablecimiento (DEC-B11): longitud mínima configurable
 * (`PASSWORD_MIN_LENGTH`, 8 por defecto), al menos una letra y un número, y máximo 72 bytes (límite de bcrypt).
 */
final class PasswordRules
{
    public const MAX_BYTES = 72;

    public const COMPLEXITY_MESSAGE = 'La contraseña debe tener al menos una letra y un número.';

    private function __construct()
    {
    }

    /**
     * Devuelve el mensaje de error o null si la contraseña cumple la política.
     */
    public static function error(string $password, Settings $settings): ?string
    {
        if (mb_strlen($password) < $settings->passwordMinLength) {
            return sprintf('La contraseña debe tener al menos %d caracteres.', $settings->passwordMinLength);
        }
        if (strlen($password) > self::MAX_BYTES) {
            return 'La contraseña es demasiado larga (máximo 72 bytes).';
        }
        // \p{L}: cualquier letra Unicode (incluye tildes y ñ); número: dígito 0-9.
        if (preg_match('/\p{L}/u', $password) !== 1 || preg_match('/[0-9]/', $password) !== 1) {
            return self::COMPLEXITY_MESSAGE;
        }

        return null;
    }
}
