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

    // ---------------------------------------------------------------- adjuntos (DEC-B40)

    /**
     * @return iterable<string, array{callable(): string, string, string}>
     */
    public static function adjuntosValidos(): iterable
    {
        yield 'pdf' => [TestImages::pdf(...), 'application/pdf', 'informe.pdf'];
        yield 'png' => [TestImages::png(...), 'image/png', 'informe.png'];
        yield 'jpeg' => [TestImages::jpeg(...), 'image/jpeg', 'informe.jpg'];
        yield 'webp' => [TestImages::webp(...), 'image/webp', 'informe.webp'];
    }

    /**
     * @param callable(): string $crear
     */
    #[DataProvider('adjuntosValidos')]
    public function testAdjuntoAceptaPdfEImagenes(callable $crear, string $mime, string $nombre): void
    {
        $path = $crear();
        $archivo = TestImages::subida($path, UPLOAD_ERR_OK, 'application/octet-stream', 'informe');

        $resultado = (new FileValidator())->adjunto(['archivo' => $archivo], 'archivo', self::MAX, self::MAX);

        self::assertSame($path, $resultado['path']);
        self::assertSame($mime, $resultado['mime']);
        self::assertSame($nombre, $resultado['nombre']);
    }

    /**
     * @return iterable<string, array{callable(): string}>
     */
    public static function adjuntosNoAdmitidos(): iterable
    {
        yield 'gif' => [TestImages::gif(...)];
        yield 'php con extensión pdf' => [static fn (): string => TestImages::disfrazado()];
        yield 'texto' => [static fn (): string => TestImages::disfrazado('solo texto, no un PDF')];
    }

    /**
     * @param callable(): string $crear
     */
    #[DataProvider('adjuntosNoAdmitidos')]
    public function testAdjuntoRechazaOtrosFormatos(callable $crear): void
    {
        $archivo = TestImages::subida($crear(), UPLOAD_ERR_OK, 'application/pdf', 'informe.pdf');

        $this->assertApiError(
            fn () => (new FileValidator())->adjunto(['archivo' => $archivo], 'archivo', self::MAX, self::MAX),
            415,
            'UNSUPPORTED_MEDIA_TYPE',
        );
    }

    public function testAdjuntoLimiteYCampo(): void
    {
        $validator = new FileValidator();

        try {
            $validator->adjunto(['archivo' => TestImages::subida(TestImages::pngDeTamano(2049))], 'archivo', 2048, 1024);
            self::fail('Se esperaba 413');
        } catch (ApiException $e) {
            self::assertSame(413, $e->status);
            self::assertSame('El archivo supera el tamaño máximo de 2 KB.', $e->getMessage());
        }

        try {
            $validator->adjunto(['foto' => TestImages::subida(TestImages::pdf())], 'archivo', self::MAX, self::MAX);
            self::fail('Se esperaba 400');
        } catch (ApiException $e) {
            self::assertSame(['archivo' => 'Adjunta un archivo en el campo "archivo".'], $e->fields);
        }
    }

    /**
     * DEC-B42: cada tipo con su límite, aplicado según el contenido real y no según lo que declara el cliente.
     */
    public function testAdjuntoLimitesDistintosParaImagenYPdf(): void
    {
        $validator = new FileValidator();

        // Imagen de 2 KB: entra con límite de imagen 2 KB aunque el de PDF sea 1 KB.
        $imagen = $validator->adjunto(['archivo' => TestImages::subida(TestImages::pngDeTamano(2048))], 'archivo', 2048, 1024);
        self::assertSame('image/png', $imagen['mime']);

        // PDF de 1 KB + 1 byte: supera su propio límite aunque el de imagen sea mayor.
        try {
            $validator->adjunto(
                ['archivo' => TestImages::subida(TestImages::pdfDeTamano(1025), UPLOAD_ERR_OK, 'image/png', 'x.png')],
                'archivo',
                2048,
                1024,
            );
            self::fail('Se esperaba 413');
        } catch (ApiException $e) {
            self::assertSame(413, $e->status);
            self::assertSame('FILE_TOO_LARGE', $e->errorCode);
            self::assertSame('El PDF supera el tamaño máximo de 1 KB.', $e->getMessage());
        }

        // Al revés: imagen por encima de su límite, por debajo del de PDF.
        try {
            $validator->adjunto(['archivo' => TestImages::subida(TestImages::pngDeTamano(1025))], 'archivo', 1024, 2048);
            self::fail('Se esperaba 413');
        } catch (ApiException $e) {
            self::assertSame('La imagen supera el tamaño máximo de 1 KB.', $e->getMessage());
        }

        $pdf = $validator->adjunto(['archivo' => TestImages::subida(TestImages::pdfDeTamano(2048))], 'archivo', 1024, 2048);
        self::assertSame('application/pdf', $pdf['mime']);
        self::assertSame(2048, $pdf['size']);
    }

    /**
     * @return iterable<string, array{string|null, string, string}>
     */
    public static function nombres(): iterable
    {
        yield 'normal' => ['Informe final.pdf', 'pdf', 'Informe final.pdf'];
        yield 'extensión en mayúsculas' => ['FOTO.JPEG', 'jpg', 'FOTO.jpg'];
        yield 'extensión engañosa' => ['virus.exe', 'pdf', 'virus.exe.pdf'];
        yield 'con ruta' => ['C:\Users\ana\..\informe.pdf', 'pdf', 'informe.pdf'];
        yield 'ruta unix' => ['../../etc/passwd', 'pdf', 'passwd.pdf'];
        yield 'caracteres de control' => ["in\x00for\nme.pdf", 'pdf', 'informe.pdf'];
        yield 'vacío' => ['', 'png', 'archivo.png'];
        yield 'null' => [null, 'pdf', 'archivo.pdf'];
        yield 'solo extensión' => ['.pdf', 'pdf', 'archivo.pdf'];
        yield 'utf-8 inválido' => ["\xFF\xFE.pdf", 'pdf', 'archivo.pdf'];
        yield 'tildes' => ['Reseña técnica.pdf', 'pdf', 'Reseña técnica.pdf'];
    }

    #[DataProvider('nombres')]
    public function testNombreSeguro(?string $original, string $extension, string $esperado): void
    {
        self::assertSame($esperado, FileValidator::nombre($original, $extension));
    }

    public function testNombreLargoSeRecorta(): void
    {
        $nombre = FileValidator::nombre(str_repeat('ñ', 400) . '.pdf', 'pdf');

        self::assertSame(FileValidator::NOMBRE_MAX_LENGTH, mb_strlen($nombre));
        self::assertStringEndsWith('ñ.pdf', $nombre);
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
