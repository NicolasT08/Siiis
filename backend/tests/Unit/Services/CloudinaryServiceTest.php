<?php

declare(strict_types=1);

namespace Siiis\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Siiis\Services\CloudinaryService;

final class CloudinaryServiceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function mensajes(): iterable
    {
        // Archivo del usuario dañado o con formato falso → 415.
        yield 'pdf inválido' => ['Invalid PDF file', true];
        yield 'imagen inválida' => ['Invalid image file', true];
        yield 'imagen o archivo' => ['Invalid Image or file', true];
        yield 'formato no soportado' => ['Unsupported file format', true];
        yield 'formato no permitido' => ['Image file format pdf not allowed', true];
        yield 'archivo dañado' => ['Corrupt file', true];
        // Fallas de configuración o del servicio → siguen siendo 502.
        yield 'transformación' => ['Invalid transformation component - foo', false];
        yield 'parámetro format' => ['Invalid value for parameter format', false];
        yield 'api key' => ['Invalid api_key abc', false];
        yield 'firma' => ['Invalid Signature 123. String to sign - ...', false];
        yield 'falta parámetro' => ['Missing required parameter - file', false];
        yield 'tamaño' => ['File size too large. Got 20000000. Maximum is 10485760.', false];
    }

    #[DataProvider('mensajes')]
    public function testDistingueArchivoInvalidoDeFallaDelServicio(string $mensaje, bool $esperado): void
    {
        self::assertSame($esperado, CloudinaryService::esArchivoInvalido($mensaje));
    }
}
