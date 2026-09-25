<?php

declare(strict_types=1);

namespace Siiis\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Siiis\Config\ConfigException;
use Siiis\Config\Settings;

final class SettingsTest extends TestCase
{
    private const SECRET = 'un-secreto-suficientemente-largo-de-32+';

    public function testCargaValoresYDefaults(): void
    {
        $settings = Settings::fromEnv(['MONGO_URI' => 'mongodb://x', 'JWT_SECRET' => self::SECRET]);

        self::assertSame('production', $settings->appEnv);
        self::assertFalse($settings->appDebug);
        self::assertSame('siiis', $settings->mongoDbName);
        self::assertSame(28800, $settings->jwtTtlSeconds);
        self::assertSame(20, $settings->homeMaxItems);
    }

    public function testRecortaJwtTtlAOchoHoras(): void
    {
        $settings = Settings::fromEnv([
            'MONGO_URI' => 'mongodb://x',
            'JWT_SECRET' => self::SECRET,
            'JWT_TTL_SECONDS' => '999999',
        ]);

        self::assertSame(Settings::MAX_JWT_TTL_SECONDS, $settings->jwtTtlSeconds);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function configuracionesInvalidas(): iterable
    {
        yield 'sin MONGO_URI' => [['JWT_SECRET' => self::SECRET], 'MONGO_URI'];
        yield 'sin JWT_SECRET' => [['MONGO_URI' => 'mongodb://x'], 'JWT_SECRET'];
        yield 'JWT_SECRET corto' => [['MONGO_URI' => 'mongodb://x', 'JWT_SECRET' => 'corto'], '32 caracteres'];
        yield 'entero inválido' => [
            ['MONGO_URI' => 'mongodb://x', 'JWT_SECRET' => self::SECRET, 'HOME_MAX_ITEMS' => 'abc'],
            'HOME_MAX_ITEMS',
        ];
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('configuracionesInvalidas')]
    public function testFallaConErrorClaro(array $env, string $mensaje): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($mensaje);

        Settings::fromEnv($env);
    }
}
