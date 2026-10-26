<?php

declare(strict_types=1);

namespace Siiis\Validation;

use Siiis\Config\Settings;

/**
 * Política de contraseña compartida por registro y restablecimiento: solo longitud mínima configurable
 * (TODO_SPEC, DEC-B11) y máximo 72 bytes (límite de bcrypt).
 */
final class PasswordRules
{
    public const MAX_BYTES = 72;

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

        return null;
    }
}
