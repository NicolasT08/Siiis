<?php

declare(strict_types=1);

namespace Siiis\Validation;

use Siiis\Config\Settings;
use Siiis\Support\ApiException;

/**
 * POST /auth/reset-password — {"token", "password"} (RF-04). Política de contraseña en PasswordRules.
 */
final class ResetPasswordValidator
{
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
            $passwordError = PasswordRules::error($password, $this->settings);
            if ($passwordError !== null) {
                $errors['password'] = $passwordError;
            }
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return ['token' => $token, 'password' => $password];
    }
}
