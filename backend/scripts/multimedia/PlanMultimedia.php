<?php

declare(strict_types=1);

namespace Siiis\Scripts\Multimedia;

use finfo;
use Siiis\Repositories\MultimediaRepository;

/**
 * Arma el plan de carga de `cargar_multimedia.php` a partir de una carpeta local (sin red ni base de datos, para
 * poder probarlo). Estructura esperada:
 *
 *   <carpeta>/slider/   imágenes JPG, PNG o WebP  → Multimedia.seccion = slider_principal, tipo = imagen
 *   <carpeta>/videos/   videos MP4, WebM o MOV    → Multimedia.seccion = video_presentacion, tipo = video
 *   <carpeta>/descripciones.json (opcional)       → {"01-laboratorio.jpg": "Texto alternativo", ...}
 *
 * - Orden: el de los nombres de archivo (orden natural: 2 antes que 10). Se recomienda un prefijo numérico
 *   (`01-`, `02_`...).
 * - Descripción (texto alternativo): la de `descripciones.json` o, si no hay, el nombre del archivo sin el prefijo
 *   numérico ni la extensión, con guiones convertidos en espacios.
 * - public_id en Cloudinary: `<folder>/slider/<nombre sin prefijo ni extensión, en minúsculas y sin tildes>`. Es
 *   estable: renombrar `01-lab.jpg` a `03-lab.jpg` cambia el orden pero no vuelve a subir el archivo.
 */
final class PlanMultimedia
{
    /** Límites del plan gratuito de Cloudinary (imagen 10 MB, video 100 MB). */
    public const MAX_IMAGEN = 10 * 1024 * 1024;
    public const MAX_VIDEO = 100 * 1024 * 1024;

    /** @var array<string, array{seccion: string, tipo: string, mimes: list<string>}> */
    public const SUBCARPETAS = [
        'slider' => [
            'seccion' => MultimediaRepository::SECCION_SLIDER,
            'tipo' => 'imagen',
            'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        ],
        'videos' => [
            'seccion' => MultimediaRepository::SECCION_VIDEOS,
            'tipo' => 'video',
            'mimes' => ['video/mp4', 'video/webm', 'video/quicktime'],
        ],
    ];

    private function __construct()
    {
    }

    /**
     * @return array{
     *     items: list<array{seccion: string, tipo: string, ruta: string, archivo: string, public_id: string, orden: int, descripcion: string, bytes: int}>,
     *     errores: list<string>
     * }
     */
    public static function desdeCarpeta(string $carpeta, string $folderCloudinary): array
    {
        $items = [];
        $errores = [];
        $carpeta = rtrim($carpeta, '/\\');
        if (!is_dir($carpeta)) {
            return ['items' => [], 'errores' => ["No existe la carpeta $carpeta."]];
        }

        $descripciones = [];
        $json = $carpeta . '/descripciones.json';
        if (is_file($json)) {
            $leido = json_decode((string) file_get_contents($json), true);
            if (!is_array($leido)) {
                $errores[] = 'descripciones.json no es un objeto JSON válido {"archivo": "descripción"}.';
            } else {
                foreach ($leido as $archivo => $texto) {
                    if (is_string($archivo) && is_string($texto)) {
                        $descripciones[$archivo] = trim($texto);
                    }
                }
            }
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $hayAlguna = false;
        foreach (self::SUBCARPETAS as $sub => $regla) {
            $dir = $carpeta . '/' . $sub;
            if (!is_dir($dir)) {
                continue;
            }
            $hayAlguna = true;
            $nombres = array_values(array_filter(
                scandir($dir) ?: [],
                static fn (string $n): bool => $n[0] !== '.' && is_file($dir . '/' . $n),
            ));
            natcasesort($nombres);

            $orden = 0;
            $ids = [];
            foreach ($nombres as $nombre) {
                $ruta = $dir . '/' . $nombre;
                $mime = (string) $finfo->file($ruta);
                $bytes = (int) filesize($ruta);
                if (!in_array($mime, $regla['mimes'], true)) {
                    $errores[] = sprintf('%s/%s: formato %s no admitido en %s/ (usa %s).', $sub, $nombre, $mime, $sub, $regla['tipo'] === 'imagen' ? 'JPG, PNG o WebP' : 'MP4, WebM o MOV');
                    continue;
                }
                $max = $regla['tipo'] === 'imagen' ? self::MAX_IMAGEN : self::MAX_VIDEO;
                if ($bytes === 0 || $bytes > $max) {
                    $errores[] = sprintf('%s/%s: pesa %.1f MB; el máximo es %d MB.', $sub, $nombre, $bytes / 1048576, $max / 1048576);
                    continue;
                }
                $slug = self::slug($nombre);
                if ($slug === '' || isset($ids[$slug])) {
                    $errores[] = sprintf('%s/%s: su nombre sin prefijo ni extensión se repite o queda vacío; cámbialo.', $sub, $nombre);
                    continue;
                }
                $ids[$slug] = true;
                $items[] = [
                    'seccion' => $regla['seccion'],
                    'tipo' => $regla['tipo'],
                    'ruta' => $ruta,
                    'archivo' => $sub . '/' . $nombre,
                    'public_id' => trim($folderCloudinary, '/') . '/' . $sub . '/' . $slug,
                    'orden' => ++$orden,
                    'descripcion' => $descripciones[$nombre] ?? self::descripcion($nombre),
                    'bytes' => $bytes,
                ];
            }
        }
        if (!$hayAlguna) {
            $errores[] = "La carpeta $carpeta no tiene las subcarpetas slider/ ni videos/.";
        }

        return ['items' => $items, 'errores' => $errores];
    }

    /**
     * Recursos que ya están en Cloudinary (`--existentes=RUTA`). JSON:
     *
     *   {"slider": [{"public_id": "siiis/Foto_1", "descripcion": "…", "orden": 1}, …],
     *    "videos": [{"public_id": "siiis/presentacion", "descripcion": "…"}]}
     *
     * `orden` es opcional: sin él, la posición en la lista (1, 2, 3…). Nada se sube ni se toca en Cloudinary.
     *
     * @return array{
     *     items: list<array{seccion: string, tipo: string, public_id: string, orden: int, descripcion: string}>,
     *     errores: list<string>
     * }
     */
    public static function desdeJson(string $ruta): array
    {
        $datos = is_file($ruta) ? json_decode((string) file_get_contents($ruta), true) : null;
        if (!is_array($datos)) {
            return ['items' => [], 'errores' => ["$ruta no existe o no es un JSON válido con las listas \"slider\" y \"videos\"."]];
        }

        $items = [];
        $errores = [];
        foreach (array_diff(array_keys($datos), array_keys(self::SUBCARPETAS)) as $clave) {
            $errores[] = sprintf('Clave desconocida "%s": solo se admiten "slider" y "videos".', (string) $clave);
        }
        foreach (self::SUBCARPETAS as $clave => $regla) {
            $lista = $datos[$clave] ?? [];
            if (!is_array($lista) || !array_is_list($lista)) {
                $errores[] = "\"$clave\" debe ser una lista.";
                continue;
            }
            $vistos = [];
            foreach ($lista as $i => $elemento) {
                $donde = sprintf('%s[%d]', $clave, $i);
                $publicId = is_array($elemento) ? ($elemento['public_id'] ?? null) : null;
                $descripcion = is_array($elemento) ? ($elemento['descripcion'] ?? null) : null;
                $orden = is_array($elemento) ? ($elemento['orden'] ?? $i + 1) : null;
                if (!is_string($publicId) || preg_match('#^[A-Za-z0-9_][A-Za-z0-9_./ -]{0,254}\z#', $publicId) !== 1 || str_contains($publicId, '..')) {
                    $errores[] = "$donde: public_id falta o no es válido.";
                    continue;
                }
                if (!is_string($descripcion) || trim($descripcion) === '' || mb_strlen($descripcion) > 200) {
                    $errores[] = "$donde: la descripción (texto alternativo) es obligatoria, de hasta 200 caracteres.";
                    continue;
                }
                if (!is_int($orden) || $orden < 1 || $orden > 1000) {
                    $errores[] = "$donde: orden debe ser un entero de 1 a 1000.";
                    continue;
                }
                if (isset($vistos[$publicId])) {
                    $errores[] = "$donde: public_id repetido ($publicId).";
                    continue;
                }
                $vistos[$publicId] = true;
                $items[] = [
                    'seccion' => $regla['seccion'],
                    'tipo' => $regla['tipo'],
                    'public_id' => $publicId,
                    'orden' => $orden,
                    'descripcion' => trim((string) preg_replace('/\s+/u', ' ', $descripcion)),
                ];
            }
        }
        if ($items === [] && $errores === []) {
            $errores[] = 'El JSON no trae elementos en "slider" ni en "videos".';
        }

        return ['items' => $items, 'errores' => $errores];
    }

    /** Nombre sin prefijo numérico ni extensión: "01-Laboratorio de Redes.jpg" → "Laboratorio de Redes". */
    public static function base(string $nombre): string
    {
        $sinExtension = (string) preg_replace('/\.[^.]+$/', '', $nombre);

        return trim((string) preg_replace('/^\d+[\s._-]*/', '', $sinExtension));
    }

    /** Para el public_id: "Laboratorio de Redes" → "laboratorio-de-redes". */
    public static function slug(string $nombre): string
    {
        $base = self::base($nombre);
        $ascii = strtr($base, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n']);

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
    }

    /** Texto alternativo por defecto: "01-laboratorio_de-redes.jpg" → "Laboratorio de redes". */
    public static function descripcion(string $nombre): string
    {
        $texto = trim((string) preg_replace('/[\s_-]+/u', ' ', self::base($nombre)));

        return $texto === '' ? '' : mb_strtoupper(mb_substr($texto, 0, 1)) . mb_substr($texto, 1);
    }
}
