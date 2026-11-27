<?php

declare(strict_types=1);

namespace Siiis\Validation;

use Siiis\Support\ApiException;

/**
 * POST /auth/forgot-password — {"correo"} (RF-04).
 */
final class ForgotPasswordValidator
{
    /**
     * @return array{correo: string}
     *
     * @throws ApiException 400 VALIDATION_ERROR
     */
    public function validate(mixed $body): array
    {
        $errors = [];
        $correo = InputReader::correo($body, $errors);

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return ['correo' => $correo];
    }
}
