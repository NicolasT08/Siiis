<?php

declare(strict_types=1);

namespace Siiis\Validation;

use Siiis\Support\ApiException;

/**
 * Cuerpo de POST /auth/google: {"id_token": "<JWT de Google Identity Services>"}.
 */
final class GoogleLoginValidator
{
    /** Un id_token de Google mide ~1–2 KB; el límite evita cuerpos abusivos. */
    private const MAX_LENGTH = 4096;

    /**
     * @throws ApiException 400 VALIDATION_ERROR
     */
    public function validate(mixed $body): string
    {
        $errors = [];
        $token = InputReader::string($body, 'id_token', $errors);
        if (!isset($errors['id_token']) && (strlen($token) > self::MAX_LENGTH || preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/', $token) !== 1)) {
            $errors['id_token'] = 'El token de Google no tiene un formato válido.';
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return $token;
    }
}
