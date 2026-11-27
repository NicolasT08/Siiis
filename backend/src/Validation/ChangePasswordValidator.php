<?php

declare(strict_types=1);

namespace Siiis\Validation;

use Siiis\Config\Settings;
use Siiis\Support\ApiException;

/**
 * POST /auth/change-password — {"password_actual", "password_nueva"} (DEC-B23). Las contraseñas no se recortan.
 * La nueva sigue la política de PasswordRules (DEC-B11) y debe ser distinta de la actual. Que la actual sea la
 * correcta lo comprueba AuthService.
 */
final class ChangePasswordValidator
{
    public const MISMA_PASSWORD = 'La nueva contraseña debe ser distinta de la actual.';

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * @return array{password_actual: string, password_nueva: string}
     *
     * @throws ApiException 400 VALIDATION_ERROR
     */
    public function validate(mixed $body): array
    {
        $errors = [];
        $actual = InputReader::string($body, 'password_actual', $errors, false);
        $nueva = InputReader::string($body, 'password_nueva', $errors, false);

        if (!isset($errors['password_nueva'])) {
            $error = PasswordRules::error($nueva, $this->settings);
            if ($error === null && !isset($errors['password_actual']) && hash_equals($actual, $nueva)) {
                $error = self::MISMA_PASSWORD;
            }
            if ($error !== null) {
                $errors['password_nueva'] = $error;
            }
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return ['password_actual' => $actual, 'password_nueva' => $nueva];
    }
}
