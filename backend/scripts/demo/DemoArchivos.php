<?php

declare(strict_types=1);

namespace Siiis\Scripts\Demo;

use RuntimeException;

/**
 * Archivos de demostración generados en PHP puro (sin GD ni descargas): imágenes PNG abstractas para
 * `Multimedia` y PDF mínimos de una página para las inscripciones. Al ser generados por el script no tienen
 * autor ni licencia de terceros.
 */
final class DemoArchivos
{
    private function __construct()
    {
    }

    /**
     * PNG RGB de `$ancho` × `$alto` con un degradado diagonal entre dos colores y círculos más claros. El mismo
     * `$semilla` produce siempre la misma imagen.
     *
     * @param array{int, int, int} $desde color RGB de la esquina superior izquierda
     * @param array{int, int, int} $hasta color RGB de la esquina inferior derecha
     */
    public static function png(int $ancho, int $alto, array $desde, array $hasta, int $semilla): string
    {
        if ($ancho < 1 || $alto < 1) {
            throw new RuntimeException('Dimensiones de imagen no válidas.');
        }

        mt_srand($semilla);
        $circulos = [];
        for ($i = 0; $i < 5; $i++) {
            $radio = mt_rand((int) ($alto / 10), (int) ($alto / 3));
            $circulos[] = [mt_rand(0, $ancho), mt_rand(0, $alto), $radio * $radio];
        }
        mt_srand();

        $diagonal = max(1, $ancho + $alto - 2);
        $crudo = '';
        for ($y = 0; $y < $alto; $y++) {
            $fila = "\x00"; // filtro "None"
            for ($x = 0; $x < $ancho; $x++) {
                $t = ($x + $y) / $diagonal;
                $claro = 0;
                foreach ($circulos as [$cx, $cy, $r2]) {
                    if (($x - $cx) ** 2 + ($y - $cy) ** 2 <= $r2) {
                        $claro += 28;
                    }
                }
                $fila .= chr(min(255, (int) ($desde[0] + ($hasta[0] - $desde[0]) * $t) + $claro))
                    . chr(min(255, (int) ($desde[1] + ($hasta[1] - $desde[1]) * $t) + $claro))
                    . chr(min(255, (int) ($desde[2] + ($hasta[2] - $desde[2]) * $t) + $claro));
            }
            $crudo .= $fila;
        }

        $comprimido = gzcompress($crudo, 9);
        if ($comprimido === false) {
            throw new RuntimeException('No se pudo comprimir la imagen.');
        }

        return "\x89PNG\r\n\x1a\n"
            . self::chunk('IHDR', pack('NNCCCCC', $ancho, $alto, 8, 2, 0, 0, 0))
            . self::chunk('IDAT', $comprimido)
            . self::chunk('IEND', '');
    }

    /**
     * PDF de una página A4 con un título y líneas de texto (Helvetica). Los textos se convierten a
     * Windows-1252, la codificación de las fuentes estándar del PDF, para que se vean las tildes.
     *
     * @param list<string> $lineas
     */
    public static function pdf(string $titulo, array $lineas): string
    {
        $flujo = "BT\n/F1 18 Tf\n72 770 Td\n(" . self::textoPdf($titulo) . ") Tj\n/F1 11 Tf\n0 -32 Td\n";
        foreach ($lineas as $linea) {
            $flujo .= '(' . self::textoPdf($linea) . ") Tj\n0 -16 Td\n";
        }
        $flujo .= "ET\n";

        $objetos = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R'
                . ' /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length ' . strlen($flujo) . " >>\nstream\n" . $flujo . 'endstream',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
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

        return $pdf . 'trailer << /Size ' . (count($objetos) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    /**
     * Escribe el contenido en un archivo temporal y devuelve su ruta (el llamador lo borra).
     */
    public static function temporal(string $contenido, string $extension): string
    {
        $base = tempnam(sys_get_temp_dir(), 'siiis-demo-');
        if ($base === false) {
            throw new RuntimeException('No se pudo crear un archivo temporal.');
        }
        $ruta = $base . '.' . $extension;
        if (!rename($base, $ruta) || file_put_contents($ruta, $contenido) === false) {
            throw new RuntimeException('No se pudo escribir el archivo temporal ' . $ruta);
        }

        return $ruta;
    }

    private static function chunk(string $tipo, string $datos): string
    {
        return pack('N', strlen($datos)) . $tipo . $datos . pack('N', crc32($tipo . $datos));
    }

    private static function textoPdf(string $texto): string
    {
        $convertido = mb_convert_encoding($texto, 'Windows-1252', 'UTF-8');

        return strtr($convertido, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }
}
