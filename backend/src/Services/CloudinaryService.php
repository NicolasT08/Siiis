<?php

declare(strict_types=1);

namespace Siiis\Services;

use Cloudinary\Api\Exception\BadRequest;
use Cloudinary\Cloudinary;
use Siiis\Config\Settings;
use Siiis\Support\CloudinaryUrl;
use Throwable;

/**
 * Subida y borrado de imágenes y PDF en Cloudinary (spec 13.2). MongoDB solo guarda `{url, public_id}`.
 * Todo se sube como recurso `image`, también los PDF (DEC-B40): así el borrado es igual para todos los
 * archivos y un PDF admite miniaturas de su primera página (`.jpg`). Para que un PDF se pueda descargar, la
 * cuenta debe tener activado "Allow delivery of PDF and ZIP files"; si no, Cloudinary responde
 * 401 "deny or ACL failure" (subirlo como `raw` no lo evita).
 * Para ahorrar espacio (DEC-B42), las imágenes se transforman al subir (Cloudinary guarda solo el
 * resultado) y la API las entrega con `f_auto,q_auto` (`Siiis\Support\CloudinaryUrl`).
 * Los documentos de inscripción se suben privados (`authenticated`) y se ven con enlaces firmados que vencen
 * (DEC-B50).
 * El cliente del SDK se crea al primer uso: la app arranca aunque falten las credenciales.
 */
class CloudinaryService
{
    /** Segundos; el plan compartido de Hostinger no admite peticiones muy largas. */
    private const TIMEOUT_SECONDS = 30;

    /** Formatos de la foto de perfil (DEC-B29). */
    public const FORMATOS_IMAGEN = ['jpg', 'png', 'webp'];

    /** Formatos de los adjuntos de documentos (DEC-B40). */
    public const FORMATOS_ADJUNTO = ['jpg', 'png', 'webp', 'pdf'];

    /** Tipo de entrega público (cualquiera con la URL lo abre). */
    private const TIPO_PUBLICO = 'upload';

    /** Tipo de entrega privado: solo con firma (documentos de inscripción, DEC-B50). */
    private const TIPO_PRIVADO = 'authenticated';

    private ?Cloudinary $cloudinary = null;

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * Transformación al subir la foto de perfil (DEC-B42): recorte cuadrado de `PERFIL_FOTO_LADO_PX`
     * centrado en lo importante de la imagen (`g_auto`) y calidad `CLOUDINARY_CALIDAD`. Cloudinary guarda
     * solo el resultado, no el original.
     *
     * @return array<string, int|string>
     */
    public static function transformacionFotoPerfil(Settings $settings): array
    {
        return [
            'width' => $settings->perfilFotoLadoPx,
            'height' => $settings->perfilFotoLadoPx,
            'crop' => 'fill',
            'gravity' => 'auto',
            'quality' => $settings->cloudinaryCalidad,
        ];
    }

    /**
     * Transformación al subir una imagen adjunta (DEC-B42): ancho máximo `DOCUMENTO_IMAGEN_MAX_ANCHO_PX` sin
     * agrandar las más pequeñas (`c_limit`, conserva la proporción) y calidad `CLOUDINARY_CALIDAD`. Los PDF se
     * suben sin transformación.
     *
     * @return array<string, int|string>
     */
    public static function transformacionImagenAdjunta(Settings $settings): array
    {
        return [
            'width' => $settings->documentoImagenMaxAnchoPx,
            'crop' => 'limit',
            'quality' => $settings->cloudinaryCalidad,
        ];
    }

    /**
     * Sube un archivo ya validado desde el temporal de PHP. Cloudinary genera un public_id único
     * dentro de `$folder`.
     *
     * @param list<string>              $formatos       segunda barrera tras finfo: Cloudinary rechaza los demás
     * @param array<string, int|string> $transformacion transformación al subir (incoming); vacía, el archivo
     *                                                  se guarda tal cual
     *
     * @return array{url: string, public_id: string}
     *
     * @throws CloudinaryException
     */
    public function upload(string $path, string $folder, array $formatos = self::FORMATOS_IMAGEN, array $transformacion = []): array
    {
        return $this->subir($path, $folder, $formatos, $transformacion, self::TIPO_PUBLICO);
    }

    /**
     * Como `upload`, pero como recurso privado (`authenticated`, DEC-B50). La URL devuelta es la `secure_url`
     * **sin** su segmento de firma: por sí sola no abre el archivo (401). Para verlo hay que pedir un enlace
     * con `enlacePrivado`.
     *
     * @param list<string>              $formatos
     * @param array<string, int|string> $transformacion
     *
     * @return array{url: string, public_id: string}
     *
     * @throws CloudinaryException
     */
    public function uploadPrivado(string $path, string $folder, array $formatos, array $transformacion = []): array
    {
        $subida = $this->subir($path, $folder, $formatos, $transformacion, self::TIPO_PRIVADO);

        return ['url' => CloudinaryUrl::sinFirma($subida['url']), 'public_id' => $subida['public_id']];
    }

    /**
     * Enlace de descarga firmado de un recurso privado que vence en `$expira` (timestamp Unix). Se genera sin
     * llamar a Cloudinary: solo firma la petición con el API secret.
     *
     * @throws CloudinaryException si falta configuración
     */
    public function enlacePrivado(string $publicId, string $formato, int $expira): string
    {
        try {
            return $this->client()->uploadApi()->privateDownloadUrl($publicId, $formato, [
                'type' => self::TIPO_PRIVADO,
                'resource_type' => 'image',
                'expires_at' => $expira,
            ]);
        } catch (CloudinaryException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new CloudinaryException('No se pudo firmar el enlace de Cloudinary: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param list<string>              $formatos
     * @param array<string, int|string> $transformacion
     *
     * @return array{url: string, public_id: string}
     *
     * @throws CloudinaryException
     */
    private function subir(string $path, string $folder, array $formatos, array $transformacion, string $tipo): array
    {
        $opciones = [
            'folder' => $folder,
            'resource_type' => 'image',
            'type' => $tipo,
            'allowed_formats' => $formatos,
            'unique_filename' => true,
            'use_filename' => false,
            'overwrite' => false,
        ];
        if ($transformacion !== []) {
            $opciones['transformation'] = $transformacion;
        }

        try {
            $res = $this->client()->uploadApi()->upload($path, $opciones);
        } catch (CloudinaryException $e) {
            throw $e;
        } catch (BadRequest $e) {
            // 400 de Cloudinary: si es por el archivo (dañado, formato falso) es culpa del archivo, no del servicio.
            if (self::esArchivoInvalido($e->getMessage())) {
                throw new ArchivoRechazadoException('Cloudinary rechazó el archivo: ' . $e->getMessage(), 0, $e);
            }

            throw new CloudinaryException('Error al subir a Cloudinary: ' . $e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new CloudinaryException('Error al subir a Cloudinary: ' . $e->getMessage(), 0, $e);
        }

        if (!is_string($res['secure_url'] ?? null) || !is_string($res['public_id'] ?? null)) {
            throw new CloudinaryException('Respuesta de Cloudinary sin secure_url o public_id.');
        }

        return ['url' => $res['secure_url'], 'public_id' => $res['public_id']];
    }

    /**
     * ¿El mensaje de un 400 de Cloudinary dice que el archivo es inválido o está dañado? Ejemplos reales:
     * "Invalid PDF file", "Invalid image file", "Invalid Image or file", "Unsupported file format",
     * "Image file format pdf not allowed". Los 400 por parámetros mal configurados ("Invalid transformation…",
     * "Invalid value for parameter format", credenciales) siguen siendo fallas del servicio (502).
     */
    public static function esArchivoInvalido(string $mensaje): bool
    {
        return preg_match('/\b(invalid|corrupt(ed)?|malformed|damaged|unsupported)\s+(image|pdf|video|file)\b/i', $mensaje) === 1
            || preg_match('/\bfile\s+format\b.{0,30}\bnot\s+allowed\b/i', $mensaje) === 1;
    }

    /**
     * Borra el archivo y lo invalida en la CDN. Borrar un public_id que ya no existe no es error. Un recurso
     * privado se borra con `$privado = true` (Cloudinary distingue el tipo de entrega).
     *
     * @throws CloudinaryException
     */
    public function delete(string $publicId, bool $privado = false): void
    {
        try {
            $res = $this->client()->uploadApi()->destroy($publicId, [
                'resource_type' => 'image',
                'type' => $privado ? self::TIPO_PRIVADO : self::TIPO_PUBLICO,
                'invalidate' => true,
            ]);
        } catch (CloudinaryException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new CloudinaryException('Error al borrar en Cloudinary: ' . $e->getMessage(), 0, $e);
        }

        $resultado = $res['result'] ?? null;
        if ($resultado !== 'ok' && $resultado !== 'not found') {
            throw new CloudinaryException(sprintf('Cloudinary no borró %s (result=%s).', $publicId, var_export($resultado, true)));
        }
    }

    private function client(): Cloudinary
    {
        if ($this->cloudinary !== null) {
            return $this->cloudinary;
        }

        $s = $this->settings;
        if ($s->cloudinaryCloudName === '' || $s->cloudinaryApiKey === '' || $s->cloudinaryApiSecret === '') {
            throw new CloudinaryException('Cloudinary no configurado (CLOUDINARY_CLOUD_NAME / _API_KEY / _API_SECRET).');
        }

        return $this->cloudinary = new Cloudinary([
            'cloud' => [
                'cloud_name' => $s->cloudinaryCloudName,
                'api_key' => $s->cloudinaryApiKey,
                'api_secret' => $s->cloudinaryApiSecret,
            ],
            'url' => ['secure' => true],
            'api' => ['timeout' => self::TIMEOUT_SECONDS, 'upload_timeout' => self::TIMEOUT_SECONDS],
        ]);
    }
}
