<?php

declare(strict_types=1);

namespace Siiis\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Siiis\Security\RateLimiter;
use Siiis\Support\ApiException;
use Siiis\Tests\Support\FixedClock;

final class RateLimiterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/siiis-rl-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testPermiteHastaElMaximoYLuegoBloquea(): void
    {
        $limiter = new RateLimiter($this->dir, new FixedClock());

        for ($i = 0; $i < 3; $i++) {
            self::assertSame(0, $limiter->hit('login', '1.2.3.4', 3, 60));
        }
        self::assertSame(60, $limiter->hit('login', '1.2.3.4', 3, 60));
    }

    public function testLimitarLanzaRateLimitedConRetryAfter(): void
    {
        $limiter = new RateLimiter($this->dir, new FixedClock());
        $limiter->limitar('reviews', '1.2.3.4', 1, 3600);

        try {
            $limiter->limitar('reviews', '1.2.3.4', 1, 3600);
            self::fail('Debía lanzar 429');
        } catch (ApiException $e) {
            self::assertSame(429, $e->status);
            self::assertSame('RATE_LIMITED', $e->errorCode);
            self::assertSame(['Retry-After' => '3600'], $e->headers);
        }
    }

    public function testSeparaPorAccionYPorClave(): void
    {
        $limiter = new RateLimiter($this->dir, new FixedClock());
        $limiter->hit('login', '1.2.3.4', 1, 60);

        self::assertGreaterThan(0, $limiter->hit('login', '1.2.3.4', 1, 60));
        self::assertSame(0, $limiter->hit('login', '5.6.7.8', 1, 60));
        self::assertSame(0, $limiter->hit('forgot', '1.2.3.4', 1, 60));
    }

    public function testReiniciaAlVencerLaVentana(): void
    {
        $clock = new FixedClock();
        $limiter = new RateLimiter($this->dir, $clock);
        $limiter->hit('login', 'ip', 1, 60);

        $clock->advance(30);
        self::assertSame(30, $limiter->hit('login', 'ip', 1, 60));

        $clock->advance(30);
        self::assertSame(0, $limiter->hit('login', 'ip', 1, 60));
    }

    public function testNoGuardaLaIpEnClaro(): void
    {
        $limiter = new RateLimiter($this->dir, new FixedClock());
        $limiter->hit('login', '198.51.100.7', 5, 60);

        $files = glob($this->dir . '/*') ?: [];
        self::assertCount(1, $files);
        self::assertStringNotContainsString('198.51.100.7', $files[0] . file_get_contents($files[0]));
    }
}
