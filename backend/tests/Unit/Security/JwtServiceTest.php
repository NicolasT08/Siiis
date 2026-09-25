<?php

declare(strict_types=1);

namespace Siiis\Tests\Unit\Security;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Siiis\Security\InvalidTokenException;
use Siiis\Security\JwtService;
use Siiis\Tests\Support\FixedClock;
use Siiis\Tests\Support\TestSettings;

final class JwtServiceTest extends TestCase
{
    private const USER_ID = '650f1c2e8b3a4a0012345678';

    public function testEmiteYDecodificaConClaimsEsperados(): void
    {
        $clock = new FixedClock();
        $service = new JwtService(TestSettings::JWT_SECRET, 3600, $clock);

        $issued = $service->issue(self::USER_ID, 'superadministrador');
        $claims = $service->decode($issued->token);

        self::assertSame(self::USER_ID, $claims->sub);
        self::assertSame('superadministrador', $claims->rol);
        self::assertSame($issued->jti, $claims->jti);
        self::assertSame(32, strlen($claims->jti));
        self::assertSame($clock->now()->getTimestamp(), $claims->iat);
        self::assertSame($claims->iat + 3600, $claims->exp);
        self::assertSame(3600, $issued->expiresIn());
    }

    public function testCadaTokenTieneJtiDistinto(): void
    {
        $service = new JwtService(TestSettings::JWT_SECRET, 3600, new FixedClock());

        self::assertNotSame(
            $service->issue(self::USER_ID, 'estudiante')->jti,
            $service->issue(self::USER_ID, 'estudiante')->jti,
        );
    }

    public function testRecortaLaDuracionAOchoHoras(): void
    {
        $service = new JwtService(TestSettings::JWT_SECRET, 100000, new FixedClock());

        self::assertSame(28800, $service->ttlSeconds());
        self::assertSame(28800, $service->issue(self::USER_ID, 'estudiante')->expiresIn());
    }

    public function testRechazaTokenExpirado(): void
    {
        $clock = new FixedClock();
        $service = new JwtService(TestSettings::JWT_SECRET, 60, $clock);
        $token = $service->issue(self::USER_ID, 'estudiante')->token;

        $clock->advance(61);

        $this->expectException(InvalidTokenException::class);
        $service->decode($token);
    }

    public function testRechazaFirmaInvalida(): void
    {
        $clock = new FixedClock();
        $emisor = new JwtService(str_repeat('a', 40), 60, $clock);
        $verificador = new JwtService(TestSettings::JWT_SECRET, 60, $clock);

        $this->expectException(InvalidTokenException::class);
        $verificador->decode($emisor->issue(self::USER_ID, 'estudiante')->token);
    }

    public function testRechazaTokenManipulado(): void
    {
        $service = new JwtService(TestSettings::JWT_SECRET, 60, new FixedClock());
        [$header, , $firma] = explode('.', $service->issue(self::USER_ID, 'estudiante')->token);
        $payload = rtrim(strtr(base64_encode((string) json_encode([
            'sub' => self::USER_ID, 'rol' => 'superadministrador', 'jti' => 'x', 'iat' => 1, 'exp' => 9999999999,
        ])), '+/', '-_'), '=');

        $this->expectException(InvalidTokenException::class);
        $service->decode("$header.$payload.$firma");
    }

    public function testRechazaAlgoritmoNone(): void
    {
        $service = new JwtService(TestSettings::JWT_SECRET, 60, new FixedClock());
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $token = $b64('{"alg":"none","typ":"JWT"}') . '.'
            . $b64((string) json_encode(['sub' => self::USER_ID, 'rol' => 'x', 'jti' => 'x', 'iat' => 1, 'exp' => 9999999999])) . '.';

        $this->expectException(InvalidTokenException::class);
        $service->decode($token);
    }

    public function testRechazaClaimsIncompletos(): void
    {
        $clock = new FixedClock();
        $now = $clock->now()->getTimestamp();
        $token = JWT::encode(['sub' => 'no-es-objectid', 'rol' => 'x', 'jti' => 'j', 'iat' => $now, 'exp' => $now + 60], TestSettings::JWT_SECRET, 'HS256');

        $this->expectException(InvalidTokenException::class);
        (new JwtService(TestSettings::JWT_SECRET, 60, $clock))->decode($token);
    }

    public function testRechazaTokenFirmadoConDuracionMayorAOchoHoras(): void
    {
        $clock = new FixedClock();
        $now = $clock->now()->getTimestamp();
        $token = JWT::encode(
            ['sub' => self::USER_ID, 'rol' => 'x', 'jti' => 'j', 'iat' => $now, 'exp' => $now + 28801],
            TestSettings::JWT_SECRET,
            'HS256',
        );

        $this->expectException(InvalidTokenException::class);
        (new JwtService(TestSettings::JWT_SECRET, 60, $clock))->decode($token);
    }
}
