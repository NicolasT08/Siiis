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
        self::assertSame(10, $settings->rateLimitChangePasswordMax);
        self::assertSame(900, $settings->rateLimitChangePasswordWindowSeconds);
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
        // Adjuntos y optimización de Cloudinary (DEC-B40, DEC-B42).
        self::assertSame(5242880, $settings->documentoAdjuntoMaxBytes);
        self::assertSame(3145728, $settings->documentoPdfMaxBytes);
        self::assertSame(400, $settings->perfilFotoLadoPx);
        self::assertSame(1600, $settings->documentoImagenMaxAnchoPx);
        self::assertSame('auto', $settings->cloudinaryCalidad);
        // Fase 4: reseñas y contacto (DEC-B43 a DEC-B48).
        self::assertSame(10, $settings->rateLimitReviewsMax);
        self::assertSame(3600, $settings->rateLimitReviewsWindowSeconds);
        self::assertSame(20, $settings->rateLimitContactMax);
        self::assertSame(3600, $settings->rateLimitContactWindowSeconds);
        self::assertSame(10, $settings->resenasPerPageDefault);
        self::assertSame(50, $settings->resenasPerPageMax);
        self::assertSame(80, $settings->resenaNombreMaxLength);
        self::assertSame(1000, $settings->resenaComentarioMaxLength);
        self::assertSame('', $settings->contactoCorreoDestino);
        self::assertSame(80, $settings->contactoNombreMaxLength);
        self::assertSame(150, $settings->contactoAsuntoMaxLength);
        self::assertSame(3000, $settings->contactoMensajeMaxLength);
        self::assertSame(20, $settings->mensajesPerPageDefault);
        self::assertSame(50, $settings->mensajesPerPageMax);
        // Inscripciones (DEC-B48 a DEC-B52).
        self::assertSame(20, $settings->rateLimitInscriptionsMax);
        self::assertSame(3600, $settings->rateLimitInscriptionsWindowSeconds);
        self::assertSame(20, $settings->inscripcionesPerPageDefault);
        self::assertSame(50, $settings->inscripcionesPerPageMax);
        self::assertSame(3, $settings->inscripcionDocumentosMax);
        self::assertSame('siiis/inscripciones', $settings->cloudinaryInscripcionesFolder);
        self::assertSame(3600, $settings->inscripcionEnlaceTtlSeconds);
        self::assertSame(['uptc.edu.co'], $settings->inscripcionDominiosCorreo);
        self::assertSame(120, $settings->inscripcionNombreMaxLength);
        self::assertSame(100, $settings->inscripcionCartaMinLength);
        self::assertSame(3000, $settings->inscripcionCartaMaxLength);
        self::assertSame('', $settings->inscripcionCorreoAviso);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function calidadesValidas(): iterable
    {
        foreach (['auto', 'auto:best', 'auto:good', 'auto:eco', 'auto:low', '1', '70', '100'] as $calidad) {
            yield $calidad => [$calidad];
        }
    }

    #[DataProvider('calidadesValidas')]
    public function testCalidadDeCloudinary(string $calidad): void
    {
        $settings = Settings::fromEnv(['MONGO_URI' => 'mongodb://x', 'JWT_SECRET' => self::SECRET, 'CLOUDINARY_CALIDAD' => $calidad]);

        self::assertSame($calidad, $settings->cloudinaryCalidad);
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

    /**
     * @return iterable<string, array{array<string, string>, list<string>}>
     */
    public static function dominiosDeCorreo(): iterable
    {
        yield 'sin variable' => [[], ['uptc.edu.co']];
        yield 'vacía = cualquiera' => [['INSCRIPCION_DOMINIOS_CORREO' => ''], []];
        yield 'solo espacios' => [['INSCRIPCION_DOMINIOS_CORREO' => '  '], []];
        yield 'varios, normalizados' => [['INSCRIPCION_DOMINIOS_CORREO' => ' @UPTC.edu.co, gmail.com,,uptc.edu.co '], ['uptc.edu.co', 'gmail.com']];
    }

    /**
     * @param array<string, string> $env
     * @param list<string>          $esperados
     */
    #[DataProvider('dominiosDeCorreo')]
    public function testDominiosDeCorreoDeInscripcion(array $env, array $esperados): void
    {
        $settings = Settings::fromEnv($env + ['MONGO_URI' => 'mongodb://x', 'JWT_SECRET' => self::SECRET]);

        self::assertSame($esperados, $settings->inscripcionDominiosCorreo);
    }

    public function testProxiesDeConfianza(): void
    {
        $base = ['MONGO_URI' => 'mongodb://x', 'JWT_SECRET' => self::SECRET];

        self::assertSame([], Settings::fromEnv($base)->trustedProxies);
        self::assertSame([], Settings::fromEnv($base + ['TRUSTED_PROXIES' => ' '])->trustedProxies);
        self::assertSame(
            ['10.0.0.0/8', '2001:db8::/32', '203.0.113.7'],
            Settings::fromEnv($base + ['TRUSTED_PROXIES' => ' 10.0.0.0/8 ,2001:db8::/32,,203.0.113.7,10.0.0.0/8'])
                ->trustedProxies,
        );
    }

    public function testRechazaProxiesNoValidos(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('TRUSTED_PROXIES');

        Settings::fromEnv(['MONGO_URI' => 'mongodb://x', 'JWT_SECRET' => self::SECRET, 'TRUSTED_PROXIES' => '10.0.0.0/40']);
    }

    public function testVentanaDeRateLimitMaxima(): void
    {
        $base = ['MONGO_URI' => 'mongodb://x', 'JWT_SECRET' => self::SECRET];

        self::assertSame(3600, Settings::fromEnv($base)->ventanaRateLimitMaxima());
        self::assertSame(
            7200,
            Settings::fromEnv($base + ['RATE_LIMIT_LOGIN_WINDOW_SECONDS' => '7200'])->ventanaRateLimitMaxima(),
        );
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
        yield 'per_page de reseñas mayor que el máximo' => [
            ['MONGO_URI' => 'mongodb://x', 'JWT_SECRET' => self::SECRET, 'RESENAS_PER_PAGE_DEFAULT' => '51'],
            'RESENAS_PER_PAGE_DEFAULT no puede ser mayor que RESENAS_PER_PAGE_MAX',
        ];
        yield 'per_page de mensajes mayor que el máximo' => [
            ['MONGO_URI' => 'mongodb://x', 'JWT_SECRET' => self::SECRET, 'MENSAJES_PER_PAGE_MAX' => '5'],
            'MENSAJES_PER_PAGE_DEFAULT no puede ser mayor que MENSAJES_PER_PAGE_MAX',
        ];
        yield 'rate limit de reseñas no numérico' => [
            ['MONGO_URI' => 'mongodb://x', 'JWT_SECRET' => self::SECRET, 'RATE_LIMIT_REVIEWS_MAX' => 'diez'],
            'RATE_LIMIT_REVIEWS_MAX',
        ];
        yield 'carta mínima mayor que la máxima' => [
            ['MONGO_URI' => 'mongodb://x', 'JWT_SECRET' => self::SECRET, 'INSCRIPCION_CARTA_MIN_LENGTH' => '4000'],
            'INSCRIPCION_CARTA_MIN_LENGTH',
        ];
        yield 'dominio de correo inválido' => [
            ['MONGO_URI' => 'mongodb://x', 'JWT_SECRET' => self::SECRET, 'INSCRIPCION_DOMINIOS_CORREO' => 'uptc.edu.co, no valido'],
            'INSCRIPCION_DOMINIOS_CORREO',
        ];
        yield 'dominio sin punto' => [
            ['MONGO_URI' => 'mongodb://x', 'JWT_SECRET' => self::SECRET, 'INSCRIPCION_DOMINIOS_CORREO' => 'localhost'],
            'INSCRIPCION_DOMINIOS_CORREO',
        ];
        foreach (['0', '101', 'auto:fast', 'best', '50,w_9000', ' f_auto'] as $calidad) {
            yield 'calidad ' . $calidad => [
                ['MONGO_URI' => 'mongodb://x', 'JWT_SECRET' => self::SECRET, 'CLOUDINARY_CALIDAD' => $calidad],
                'CLOUDINARY_CALIDAD',
            ];
        }
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
