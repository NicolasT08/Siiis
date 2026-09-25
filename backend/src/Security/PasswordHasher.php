<?php

declare(strict_types=1);

namespace Siiis\Security;

/**
 * Hash de contraseñas con bcrypt nativo (spec 12.2).
 */
class PasswordHasher
{
    /**
     * Hash bcrypt fijo para igualar tiempos cuando el correo no existe (DEC-B07).
     * Corresponde a una contraseña aleatoria descartada; nunca valida nada real.
     */
    private const DUMMY_HASH = '$2y$10$kOGVwDHrqBjdNATCSkOxa.YMP4kf/V63g.dL2MoZnSmdqAVqmsGUO';

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT);
    }

    /**
     * Ejecuta una verificación con coste equivalente y descarta el resultado.
     */
    public function dummyVerify(string $password): void
    {
        password_verify($password, self::DUMMY_HASH);
    }
}
