<?php

declare(strict_types=1);

namespace Siiis\Security;

/**
 * Claims validados de un JWT propio.
 */
final readonly class TokenClaims
{
    public function __construct(
        public string $sub,
        public string $rol,
        public string $jti,
        public int $iat,
        public int $exp,
    ) {
    }
}
