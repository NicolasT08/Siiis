<?php

declare(strict_types=1);

namespace Siiis\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Siiis\Support\Clock;

/**
 * Reloj controlable para pruebas.
 */
final class FixedClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $now = '2026-09-25T12:00:00Z')
    {
        $this->now = new DateTimeImmutable($now, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('%+d seconds', $seconds));
    }
}
