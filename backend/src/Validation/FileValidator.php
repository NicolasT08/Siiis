<?php

declare(strict_types=1);

namespace Siiis\Validation;

use finfo;
use Psr\Http\Message\UploadedFileInterface;
use Siiis\Support\ApiException;

/**
 * Archivos subidos (spec 10.13.8 y 13.1): MIME real con `finfo` (se ignoran la extensión y el
 * Content-Type que declara el cliente) y tamaño contra el límite configurado.
 */
final class FileValidator
{
    /** Imágenes admitidas para la foto de perfil (DEC-B21) → extensión. */
    public const IMAGENES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /** Adjuntos de documentos (DEC-B40) → extensión. */
    public const ADJUNTOS = self::IMAGENES + ['application/pdf' => 'pdf'];

    /** Largo máximo del nombre original que se guarda en `nombre_archivo`. */
    public const NOMBRE_MAX_LENGTH = 150;

    /**
     * @param array<string, mixed> $archivos `getUploadedFiles()` de la petición
     *
     * @return array{path: string, mime: string, size: int} ruta temporal de PHP, nunca bajo public_html
     *
     * @throws ApiException 400 VALIDATION_ERROR, 413 FILE_TOO_LARGE, 415 UNSUPPORTED_MEDIA_TYPE
     */
    public function imagen(array $archivos, string $campo, int $maxBytes): array
    {
        $archivo = self::archivo($archivos, $campo, sprintf('Adjunta una imagen en el campo "%s".', $campo));
        ['path' => $path, 'mime' => $mime, 'size' => $size] = self::validar(
            $archivo,
            $campo,
            $maxBytes,
            self::IMAGENES,
            'La imagen',
            'Formato no admitido. Usa una imagen JPEG, PNG o WebP.',
        );

        return ['path' => $path, 'mime' => $mime, 'size' => $size];
    }

    /**
     * Adjunto de un documento: PDF, JPEG, PNG o WebP. Devuelve además un `nombre` seguro para mostrar,
     * a partir del nombre original del cliente, con la extensión del tipo real.
     *
     * @param array<string, mixed> $archivos
     *
     * @return array{path: string, mime: string, size: int, nombre: string}
     *
     * @throws ApiException 400 VALIDATION_ERROR, 413 FILE_TOO_LARGE, 415 UNSUPPORTED_MEDIA_TYPE
     */
    public function adjunto(array $archivos, string $campo, int $maxBytes): array
    {
        $archivo = self::archivo($archivos, $campo, sprintf('Adjunta un archivo en el campo "%s".', $campo));
        $validado = self::validar(
            $archivo,
            $campo,
            $maxBytes,
            self::ADJUNTOS,
            'El archivo',
            'Formato no admitido. Usa un PDF o una imagen JPEG, PNG o WebP.',
        );

        return $validado + ['nombre' => self::nombre($archivo->getClientFilename(), self::ADJUNTOS[$validado['mime']])];
    }

    /**
     * Nombre para mostrar: sin rutas ni caracteres de control, recortado y con la extensión del tipo real
     * (un "informe.exe" que en realidad es PDF se guarda como "informe.exe.pdf").
     */
    public static function nombre(?string $original, string $extension): string
    {
        $nombre = basename(str_replace('\\', '/', (string) $original));
        $nombre = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $nombre));
        if (preg_match('//u', $nombre) !== 1) {
            $nombre = '';
        }

        $base = (string) preg_replace('/\.' . preg_quote($extension, '/') . '$/i', '', $nombre);
        if ($extension === 'jpg') {
            $base = (string) preg_replace('/\.jpeg$/i', '', $base);
        }
        $base = trim($base, ' .');
        if ($base === '') {
            $base = 'archivo';
        }

        return mb_substr($base, 0, self::NOMBRE_MAX_LENGTH - strlen($extension) - 1) . '.' . $extension;
    }

    /**
     * @param array<string, mixed> $archivos
     *
     * @throws ApiException 400 VALIDATION_ERROR
     */
    private static function archivo(array $archivos, string $campo, string $mensajeFalta): UploadedFileInterface
    {
        $archivo = $archivos[$campo] ?? null;
        // Un arreglo (campo[]) o un campo de texto no es un archivo.
        if (!$archivo instanceof UploadedFileInterface || $archivo->getError() === UPLOAD_ERR_NO_FILE) {
            throw ApiException::validation([$campo => $mensajeFalta]);
        }

        return $archivo;
    }

    /**
     * @param array<string, string> $tipos MIME admitido → extensión
     *
     * @return array{path: string, mime: string, size: int}
     *
     * @throws ApiException 400 VALIDATION_ERROR, 413 FILE_TOO_LARGE, 415 UNSUPPORTED_MEDIA_TYPE
     */
    private static function validar(
        UploadedFileInterface $archivo,
        string $campo,
        int $maxBytes,
        array $tipos,
        string $sujeto,
        string $mensajeFormato,
    ): array {
        $error = $archivo->getError();
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw self::demasiadoGrande($sujeto, $maxBytes);
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw ApiException::validation([$campo => 'No se pudo recibir el archivo. Intenta de nuevo.']);
        }

        try {
            $path = $archivo->getStream()->getMetadata('uri');
        } catch (\RuntimeException) {
            // El temporal ya no existe o no se puede leer.
            $path = null;
        }
        $size = is_string($path) && is_file($path) ? filesize($path) : false;
        if (!is_string($path) || $size === false) {
            throw ApiException::validation([$campo => 'No se pudo recibir el archivo. Intenta de nuevo.']);
        }
        if ($size === 0) {
            throw ApiException::validation([$campo => 'El archivo está vacío.']);
        }
        if ($size > $maxBytes) {
            throw self::demasiadoGrande($sujeto, $maxBytes);
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!is_string($mime) || !array_key_exists($mime, $tipos)) {
            throw new ApiException(415, 'UNSUPPORTED_MEDIA_TYPE', $mensajeFormato);
        }

        return ['path' => $path, 'mime' => $mime, 'size' => $size];
    }

    private static function demasiadoGrande(string $sujeto, int $maxBytes): ApiException
    {
        return new ApiException(
            413,
            'FILE_TOO_LARGE',
            sprintf('%s supera el tamaño máximo de %s.', $sujeto, self::legible($maxBytes)),
        );
    }

    private static function legible(int $bytes): string
    {
        return $bytes >= 1048576 && $bytes % 1048576 === 0
            ? sprintf('%d MB', intdiv($bytes, 1048576))
            : sprintf('%d KB', (int) ceil($bytes / 1024));
    }
}
