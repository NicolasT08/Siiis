<?php

declare(strict_types=1);

namespace Siiis\Security;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Siiis\Config\Settings;
use Siiis\Support\Clock;
use Siiis\Support\ObjectIdValidator;
use Throwable;

/**
 * Emisión y validación de JWT HS256 (spec 10.13.6, 12.1). Expiración máxima de 8 horas (RNF-05).
 */
class JwtService
{
    private const ALGORITHM = 'HS256';

    private readonly int $ttlSeconds;

    public function __construct(
        private readonly string $secret,
        int $ttlSeconds,
        private readonly Clock $clock,
    ) {
        $this->ttlSeconds = max(1, min($ttlSeconds, Settings::MAX_JWT_TTL_SECONDS));
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    public function issue(string $userId, string $rol): IssuedToken
    {
        $issuedAt = $this->clock->now();
        $expiresAt = $issuedAt->modify(sprintf('+%d seconds', $this->ttlSeconds));
        $jti = bin2hex(random_bytes(16));

        $token = JWT::encode([
            'sub' => $userId,
            'rol' => $rol,
            'jti' => $jti,
            'iat' => $issuedAt->getTimestamp(),
            'exp' => $expiresAt->getTimestamp(),
        ], $this->secret, self::ALGORITHM);

        return new IssuedToken($token, $jti, $issuedAt, $expiresAt);
    }

    /**
     * Valida firma, algoritmo y `exp`, y devuelve los claims.
     *
     * @throws InvalidTokenException
     */
    public function decode(string $jwt): TokenClaims
    {
        $previousTimestamp = JWT::$timestamp;
        JWT::$timestamp = $this->clock->now()->getTimestamp();
        try {
            $payload = (array) JWT::decode($jwt, new Key($this->secret, self::ALGORITHM));
        } catch (Throwable $e) {
            throw new InvalidTokenException('Token inválido o expirado.', 0, $e);
        } finally {
            JWT::$timestamp = $previousTimestamp;
        }

        $sub = $payload['sub'] ?? null;
        $rol = $payload['rol'] ?? null;
        $jti = $payload['jti'] ?? null;
        $iat = $payload['iat'] ?? null;
        $exp = $payload['exp'] ?? null;

        if (
            !ObjectIdValidator::isValid($sub) || !is_string($rol) || $rol === ''
            || !is_string($jti) || $jti === '' || !is_int($iat) || !is_int($exp)
        ) {
            throw new InvalidTokenException('Token con claims incompletos.');
        }
        if ($exp - $iat > Settings::MAX_JWT_TTL_SECONDS) {
            throw new InvalidTokenException('Token con duración superior a la permitida.');
        }

        return new TokenClaims((string) $sub, $rol, $jti, $iat, $exp);
    }
}
