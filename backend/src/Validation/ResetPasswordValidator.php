<?php

declare(strict_types=1);

namespace Siiis\Validation;

use Siiis\Config\Settings;
use Siiis\Support\ApiException;

/**
 * POST /auth/reset-password — {"token", "password"} (RF-04).
 * Política de contraseña: solo longitud mínima configurable (TODO_SPEC, DEC-B11) y máximo 72 bytes
 * (límite de bcrypt).
 */
final class ResetPasswordValidator
{
    public const MAX_PASSWORD_BYTES = 72;

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * @return array{token: string, password: string}
     *
     * @throws ApiException 400 VALIDATION_ERROR
     */
    public function validate(mixed $body): array
    {
        $errors = [];
        $token = InputReader::string($body, 'token', $errors);
        $password = InputReader::string($body, 'password', $errors, false);

        if (!isset($errors['password'])) {
            if (mb_strlen($password) < $this->settings->passwordMinLength) {
                $errors['password'] = sprintf(
                    'La contraseña debe tener al menos %d caracteres.',
                    $this->settings->passwordMinLength,
                );
            } elseif (strlen($password) > self::MAX_PASSWORD_BYTES) {
                $errors['password'] = 'La contraseña es demasiado larga (máximo 72 bytes).';
            }
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return ['token' => $token, 'password' => $password];
    }
}
