<?php

declare(strict_types=1);

namespace Siiis\Services;

use RuntimeException;
use Siiis\Repositories\RespaldoRepository;
use Siiis\Support\Clock;
use Throwable;

/**
 * Respaldo de la base en archivos locales (auditoría A-01; Atlas M0 no tiene respaldos automáticos).
 *
 * - Una carpeta por respaldo, `AAAAMMDD-HHMMSS` en UTC, dentro de `RESPALDO_DIR`, con un archivo
 *   `<coleccion>.json` por colección y un `manifest.json`.
 * - Formato: Extended JSON canónico v2 de MongoDB, un documento por línea (el formato que lee `mongoimport`),
 *   así se restauran `ObjectId`, fechas y tipos numéricos exactos.
 * - Se escribe primero en `.parcial-<nombre>` y se renombra al terminar: un respaldo a medias nunca cuenta como
 *   válido ni desplaza a uno bueno al aplicar la retención.
 * - Conserva los `RESPALDO_MAX` más recientes y borra los demás (solo carpetas con el formato de nombre propio).
 * - No envía nada a servicios externos. Carpetas 0700 y archivos 0600 (en Linux), y un `.htaccess` que niega el
 *   acceso web por si `RESPALDO_DIR` quedara por error bajo un directorio público.
 */
final class RespaldoService
{
    private const PATRON_CARPETA = '/^\d{8}-\d{6}(-\d+)?$/';
    private const PREFIJO_PARCIAL = '.parcial-';

    public function __construct(
        private readonly RespaldoRepository $repositorio,
        private readonly Clock $clock,
        private readonly string $directorio,
        private readonly int $maximo,
    ) {
        if ($maximo < 1) {
            throw new \InvalidArgumentException('RESPALDO_MAX debe ser al menos 1.');
        }
    }

    /**
     * @return array{carpeta: string, colecciones: array<string, int>, borrados: list<string>}
     *
     * @throws RuntimeException si no se puede escribir el respaldo
     */
    public function crear(): array
    {
        $this->asegurarDirectorio($this->directorio);

        $nombre = $this->nombreLibre();
        $parcial = $this->directorio . '/' . self::PREFIJO_PARCIAL . $nombre;
        $final = $this->directorio . '/' . $nombre;
        $this->crearCarpeta($parcial);

        try {
            $conteos = [];
            foreach ($this->repositorio->colecciones() as $coleccion) {
                $conteos[$coleccion] = $this->exportar($coleccion, $parcial);
            }
            $this->escribir($parcial . '/manifest.json', (string) json_encode([
                'base' => $this->repositorio->databaseName(),
                'fecha' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
                'formato' => 'MongoDB Extended JSON canónico v2, un documento por línea (mongoimport)',
                'colecciones' => $conteos,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

            if (!@rename($parcial, $final)) {
                throw new RuntimeException('No se pudo cerrar el respaldo: ' . $final);
            }
        } catch (Throwable $e) {
            self::borrarCarpeta($parcial);

            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }

        return ['carpeta' => $final, 'colecciones' => $conteos, 'borrados' => $this->aplicarRetencion()];
    }

    /**
     * Respaldos completos, del más reciente al más antiguo (rutas absolutas).
     *
     * @return list<string>
     */
    public function respaldos(): array
    {
        $carpetas = [];
        foreach (scandir($this->directorio) ?: [] as $entrada) {
            if (preg_match(self::PATRON_CARPETA, $entrada) === 1 && is_dir($this->directorio . '/' . $entrada)) {
                $carpetas[] = $entrada;
            }
        }
        // AAAAMMDD-HHMMSS se ordena como texto; el sufijo -N (mismo segundo) va después del nombre base.
        usort($carpetas, static fn (string $a, string $b): int => strnatcmp($b, $a));

        return array_map(fn (string $c): string => $this->directorio . '/' . $c, $carpetas);
    }

    private function exportar(string $coleccion, string $carpeta): int
    {
        if ($coleccion === '' || preg_match('#[\\\\/\x00]#', $coleccion) === 1 || str_starts_with($coleccion, '.')) {
            throw new RuntimeException('Nombre de colección no válido para un archivo: ' . $coleccion);
        }
        $archivo = $carpeta . '/' . $coleccion . '.json';
        $handle = @fopen($archivo, 'xb');
        if ($handle === false) {
            throw new RuntimeException('No se pudo crear ' . $archivo);
        }

        $total = 0;
        try {
            foreach ($this->repositorio->documentos($coleccion) as $documento) {
                if (fwrite($handle, $documento->toCanonicalExtendedJSON() . "\n") === false) {
                    throw new RuntimeException('No se pudo escribir ' . $archivo);
                }
                $total++;
            }
        } finally {
            fclose($handle);
        }
        @chmod($archivo, 0600);

        return $total;
    }

    /**
     * @return list<string> carpetas borradas
     */
    private function aplicarRetencion(): array
    {
        $borrados = [];
        foreach (array_slice($this->respaldos(), $this->maximo) as $carpeta) {
            self::borrarCarpeta($carpeta);
            $borrados[] = $carpeta;
        }

        return $borrados;
    }

    private function nombreLibre(): string
    {
        $base = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd-His');
        $nombre = $base;
        for ($i = 2; file_exists($this->directorio . '/' . $nombre) || file_exists($this->directorio . '/' . self::PREFIJO_PARCIAL . $nombre); $i++) {
            $nombre = $base . '-' . $i;
        }

        return $nombre;
    }

    private function asegurarDirectorio(string $directorio): void
    {
        if (!is_dir($directorio)) {
            $this->crearCarpeta($directorio);
        }
        if (!is_writable($directorio)) {
            throw new RuntimeException('No se puede escribir en ' . $directorio);
        }
        $htaccess = $directorio . '/.htaccess';
        if (!is_file($htaccess)) {
            $this->escribir($htaccess, "# Respaldos de la base: nunca se sirven por la web.\nRequire all denied\n");
        }
    }

    private function crearCarpeta(string $ruta): void
    {
        if (!@mkdir($ruta, 0700, true) && !is_dir($ruta)) {
            throw new RuntimeException('No se pudo crear la carpeta ' . $ruta);
        }
        @chmod($ruta, 0700);
    }

    private function escribir(string $archivo, string $contenido): void
    {
        if (@file_put_contents($archivo, $contenido, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo escribir ' . $archivo);
        }
        @chmod($archivo, 0600);
    }

    private static function borrarCarpeta(string $carpeta): void
    {
        if (!is_dir($carpeta)) {
            return;
        }
        foreach (scandir($carpeta) ?: [] as $entrada) {
            if ($entrada === '.' || $entrada === '..') {
                continue;
            }
            $ruta = $carpeta . '/' . $entrada;
            is_dir($ruta) && !is_link($ruta) ? self::borrarCarpeta($ruta) : @unlink($ruta);
        }
        @rmdir($carpeta);
    }
}
