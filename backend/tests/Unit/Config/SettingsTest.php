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
        self::assertSame(20, $settings->usersPerPageDefault);
        self::assertSame(50, $settings->usersPerPageMax);
        self::assertSame(2097152, $settings->perfilFotoMaxBytes);
        self::assertSame('siiis/perfiles', $settings->cloudinaryPerfilesFolder);
        self::assertSame('', $settings->cloudinaryCloudName);
        self::assertSame(12, $settings->documentosPerPageDefault);
        self::assertSame(50, $settings->documentosPerPageMax);
        self::assertSame(200, $settings->documentoTituloMaxLength);
        self::assertSame(1000, $settings->documentoDescripcionMaxLength);
        self::assertSame(50000, $settings->documentoContenidoMaxLength);
        self::assertSame(20, $settings->documentoIntegrantesMax);
    }

    public function testCarpetaDeCloudinarySinBarrasExtremas(): void
    {
        $settings = Settings::fromEnv([
            'MONGO_URI' => 'mongodb://x',
            'JWT_SECRET' => self::SECRET,
            'CLOUDINARY_PERFILES_FOLDER' => '/siiis_test/perfiles/',
        ]);

        self::assertSame('siiis_test/perfiles', $settings->cloudinaryPerfilesFolder);
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
        yield 'per_page por defecto mayor que el máximo' => [
            ['MONGO_URI' => 'mongodb://x', 'JWT_SECRET' => self::SECRET, 'USERS_PER_PAGE_DEFAULT' => '60'],
            'USERS_PER_PAGE_DEFAULT',
        ];
        yield 'per_page de documentos mayor que el máximo' => [
            ['MONGO_URI' => 'mongodb://x', 'JWT_SECRET' => self::SECRET, 'DOCUMENTOS_PER_PAGE_MAX' => '10'],
            'DOCUMENTOS_PER_PAGE_DEFAULT',
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
