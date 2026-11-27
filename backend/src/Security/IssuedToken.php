<?php

declare(strict_types=1);

namespace Siiis\Security;

use DateTimeImmutable;

/**
 * Resultado de emitir un JWT: el token para el cliente y los datos para registrar la sesión.
 */
final readonly class IssuedToken
{
    public function __construct(
        public string $token,
        public string $jti,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
    ) {
    }

    public function expiresIn(): int
    {
        return $this->expiresAt->getTimestamp() - $this->issuedAt->getTimestamp();
    }
}
