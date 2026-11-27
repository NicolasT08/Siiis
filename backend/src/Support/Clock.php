<?php

declare(strict_types=1);

namespace Siiis\Support;

use DateTimeImmutable;

/**
 * Reloj inyectable para poder probar expiraciones sin esperar.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
