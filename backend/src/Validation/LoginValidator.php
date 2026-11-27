<?php

declare(strict_types=1);

namespace Siiis\Validation;

use Siiis\Support\ApiException;

/**
 * POST /auth/login — {"correo", "password"} (RF-02). Campos extra (p. ej. "recordarme") se ignoran.
 */
final class LoginValidator
{
    private const MAX_PASSWORD_LENGTH = 256;

    /**
     * @return array{correo: string, password: string}
     *
     * @throws ApiException 400 VALIDATION_ERROR
     */
    public function validate(mixed $body): array
    {
        $errors = [];
        $correo = InputReader::correo($body, $errors);
        $password = InputReader::string($body, 'password', $errors, false);

        if (!isset($errors['password']) && mb_strlen($password) > self::MAX_PASSWORD_LENGTH) {
            $errors['password'] = 'La contraseña es demasiado larga.';
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return ['correo' => $correo, 'password' => $password];
    }
}
