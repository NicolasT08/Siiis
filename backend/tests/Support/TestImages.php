<?php

declare(strict_types=1);

namespace Siiis\Tests\Support;

use Slim\Psr7\UploadedFile;

/**
 * Archivos de prueba mínimos (sin GD): solo los bytes de cabecera que reconoce finfo. El PNG es una
 * imagen 1x1 válida que Cloudinary acepta.
 */
final class TestImages
{
    public const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private static array $archivos = [];

    public static function png(): string
    {
        return self::escribir((string) base64_decode(self::PNG_1X1, true), 'png');
    }

    public static function jpeg(): string
    {
        return self::escribir("\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00" . str_repeat("\x00", 32) . "\xFF\xD9", 'jpg');
    }

    public static function webp(): string
    {
        $vp8l = "\x2F\x00\x00\x00\x00\x07\x10\x11\x11\x88\x88\x08";

        return self::escribir('RIFF' . pack('V', 4 + 8 + strlen($vp8l)) . 'WEBPVP8L' . pack('V', strlen($vp8l)) . $vp8l, 'webp');
    }

    /**
     * PDF 1.4 mínimo y válido (una página en blanco), con la tabla xref calculada: Cloudinary lo acepta.
     */
    public static function pdf(): string
    {
        $objetos = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << >> >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objetos as $i => $objeto) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $objeto . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objetos) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer << /Size ' . (count($objetos) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";

        return self::escribir($pdf, 'pdf');
    }

    public static function gif(): string
    {
        return self::escribir("GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF!\xF9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;", 'gif');
    }

    /**
     * Texto o script con extensión de imagen: finfo lo detecta por el contenido.
     */
    public static function disfrazado(string $contenido = "<?php echo 'hola';"): string
    {
        return self::escribir($contenido, 'png');
    }

    /**
     * PNG válido relleno hasta `$bytes` bytes.
     */
    public static function pngDeTamano(int $bytes): string
    {
        $png = (string) base64_decode(self::PNG_1X1, true);

        return self::escribir($png . str_repeat("\x00", max(0, $bytes - strlen($png))), 'png');
    }

    /**
     * PNG válido de `$ancho` x `$alto` (escala de grises, todo negro), armado a mano con zlib: sirve para
     * comprobar en Cloudinary que las transformaciones al subir cambian las dimensiones.
     */
    public static function pngDeDimensiones(int $ancho, int $alto): string
    {
        $chunk = static function (string $tipo, string $datos): string {
            return pack('N', strlen($datos)) . $tipo . $datos . pack('N', crc32($tipo . $datos));
        };
        // Cada fila: byte de filtro 0 + un byte por píxel (8 bits, color tipo 0).
        $filas = str_repeat("\x00" . str_repeat("\x00", $ancho), $alto);
        $png = "\x89PNG\r\n\x1A\n"
            . $chunk('IHDR', pack('NNCCCCC', $ancho, $alto, 8, 0, 0, 0, 0))
            . $chunk('IDAT', (string) gzcompress($filas, 9))
            . $chunk('IEND', '');

        return self::escribir($png, 'png');
    }

    /**
     * PDF válido relleno con un comentario hasta `$bytes` bytes.
     */
    public static function pdfDeTamano(int $bytes): string
    {
        $pdf = (string) file_get_contents(self::pdf());

        return self::escribir($pdf . '%' . str_repeat('x', max(0, $bytes - strlen($pdf) - 2)) . "\n", 'pdf');
    }

    public static function vacio(): string
    {
        return self::escribir('', 'png');
    }

    public static function subida(
        string $path,
        int $error = UPLOAD_ERR_OK,
        string $tipoDeclarado = 'image/png',
        ?string $nombre = null,
    ): UploadedFile {
        return new UploadedFile($path, $nombre ?? basename($path), $tipoDeclarado, (int) filesize($path), $error);
    }

    public static function limpiar(): void
    {
        foreach (self::$archivos as $archivo) {
            @unlink($archivo);
        }
        self::$archivos = [];
    }

    private static function escribir(string $bytes, string $extension): string
    {
        $path = sys_get_temp_dir() . '/siiis-img-' . bin2hex(random_bytes(6)) . '.' . $extension;
        file_put_contents($path, $bytes);
        self::$archivos[] = $path;

        return $path;
    }
}
