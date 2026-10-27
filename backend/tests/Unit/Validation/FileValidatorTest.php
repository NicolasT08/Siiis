<?php

declare(strict_types=1);

namespace Siiis\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Siiis\Support\ApiException;
use Siiis\Tests\Support\TestImages;
use Siiis\Validation\FileValidator;

final class FileValidatorTest extends TestCase
{
    private const MAX = 2097152;

    protected function tearDown(): void
    {
        TestImages::limpiar();
    }

    /**
     * @return iterable<string, array{callable(): string, string}>
     */
    public static function imagenesValidas(): iterable
    {
        yield 'png' => [TestImages::png(...), 'image/png'];
        yield 'jpeg' => [TestImages::jpeg(...), 'image/jpeg'];
        yield 'webp' => [TestImages::webp(...), 'image/webp'];
    }

    /**
     * @param callable(): string $crear
     */
    #[DataProvider('imagenesValidas')]
    public function testAceptaJpegPngYWebpPorSuContenido(callable $crear, string $mime): void
    {
        $path = $crear();
        // El tipo declarado por el cliente se ignora.
        $archivo = TestImages::subida($path, UPLOAD_ERR_OK, 'application/octet-stream');

        $imagen = (new FileValidator())->imagen(['foto' => $archivo], 'foto', self::MAX);

        self::assertSame($mime, $imagen['mime']);
        self::assertSame($path, $imagen['path']);
        self::assertSame(filesize($path), $imagen['size']);
    }

    /**
     * @return iterable<string, array{callable(): string}>
     */
    public static function formatosNoAdmitidos(): iterable
    {
        yield 'gif' => [TestImages::gif(...)];
        yield 'script PHP con extensión .png' => [static fn (): string => TestImages::disfrazado()];
        yield 'SVG con extensión .png' => [static fn (): string => TestImages::disfrazado('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')];
        yield 'HTML con extensión .png' => [static fn (): string => TestImages::disfrazado('<!DOCTYPE html><html><body>x</body></html>')];
    }

    /**
     * @param callable(): string $crear
     */
    #[DataProvider('formatosNoAdmitidos')]
    public function testRechazaOtrosFormatosAunqueDigaSerImagen(callable $crear): void
    {
        $this->assertApiError(
            fn () => (new FileValidator())->imagen(['foto' => TestImages::subida($crear(), UPLOAD_ERR_OK, 'image/png')], 'foto', self::MAX),
            415,
            'UNSUPPORTED_MEDIA_TYPE',
        );
    }

    public function testRespetaElLimiteDeTamano(): void
    {
        $validator = new FileValidator();

        $justo = $validator->imagen(['foto' => TestImages::subida(TestImages::pngDeTamano(1024))], 'foto', 1024);
        self::assertSame(1024, $justo['size']);

        $this->assertApiError(
            fn () => $validator->imagen(['foto' => TestImages::subida(TestImages::pngDeTamano(1025))], 'foto', 1024),
            413,
            'FILE_TOO_LARGE',
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function erroresDeTamanoDePhp(): iterable
    {
        yield 'upload_max_filesize' => [UPLOAD_ERR_INI_SIZE];
        yield 'MAX_FILE_SIZE del formulario' => [UPLOAD_ERR_FORM_SIZE];
    }

    #[DataProvider('erroresDeTamanoDePhp')]
    public function testErroresDeTamanoDePhpSon413(int $error): void
    {
        $this->assertApiError(
            fn () => (new FileValidator())->imagen(['foto' => TestImages::subida(TestImages::png(), $error)], 'foto', self::MAX),
            413,
            'FILE_TOO_LARGE',
        );
    }

    /**
     * Los archivos se crean dentro de la prueba: los data providers corren antes que cualquier tearDown.
     *
     * @return iterable<string, array{callable(): array<string, mixed>}>
     */
    public static function sinArchivoUtil(): iterable
    {
        yield 'sin archivos' => [static fn (): array => []];
        yield 'otro campo' => [static fn (): array => ['imagen' => 'x']];
        yield 'arreglo foto[]' => [static fn (): array => ['foto' => [TestImages::subida(TestImages::png())]]];
        yield 'sin archivo seleccionado' => [static fn (): array => ['foto' => TestImages::subida(TestImages::png(), UPLOAD_ERR_NO_FILE)]];
        yield 'subida parcial' => [static fn (): array => ['foto' => TestImages::subida(TestImages::png(), UPLOAD_ERR_PARTIAL)]];
        yield 'vacío' => [static fn (): array => ['foto' => TestImages::subida(TestImages::vacio())]];
        yield 'temporal borrado' => [static function (): array {
            $path = TestImages::png();
            $archivo = TestImages::subida($path);
            unlink($path);

            return ['foto' => $archivo];
        }];
    }

    /**
     * @param callable(): array<string, mixed> $archivos
     */
    #[DataProvider('sinArchivoUtil')]
    public function testSinArchivoUtilEsErrorDeValidacion(callable $archivos): void
    {
        try {
            (new FileValidator())->imagen($archivos(), 'foto', self::MAX);
            self::fail('Se esperaba ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status);
            self::assertSame('VALIDATION_ERROR', $e->errorCode);
            self::assertSame(['foto'], array_keys($e->fields));
        }
    }

    private function assertApiError(callable $fn, int $status, string $code): void
    {
        try {
            $fn();
            self::fail('Se esperaba ApiException');
        } catch (ApiException $e) {
            self::assertSame($status, $e->status, $e->getMessage());
            self::assertSame($code, $e->errorCode);
        }
    }
}
