<?php

declare(strict_types=1);

namespace Siiis\Support;

/**
 * Tokens aleatorios criptográficamente seguros y su hash para persistir (spec 10.5 y 12.3).
 */
final class TokenGenerator
{
    /**
     * Token hexadecimal de $bytes bytes aleatorios (64 caracteres por defecto).
     */
    public static function generate(int $bytes = 32): string
    {
        return bin2hex(random_bytes(max(16, $bytes)));
    }

    /**
     * Hash SHA-256 (hex) que se guarda en MongoDB en lugar del token en claro.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
