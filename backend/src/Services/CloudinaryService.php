<?php

declare(strict_types=1);

namespace Siiis\Services;

use Cloudinary\Cloudinary;
use Siiis\Config\Settings;
use Throwable;

/**
 * Subida y borrado de imágenes y PDF en Cloudinary (spec 13.2). MongoDB solo guarda `{url, public_id}`.
 * Todo se sube como recurso `image`, también los PDF (DEC-B40): así el borrado es igual para todos los
 * archivos y un PDF admite miniaturas de su primera página (`.jpg`). Para que un PDF se pueda descargar, la
 * cuenta debe tener activado "Allow delivery of PDF and ZIP files"; si no, Cloudinary responde
 * 401 "deny or ACL failure" (subirlo como `raw` no lo evita).
 * Para ahorrar espacio (DEC-B42), las imágenes se transforman al subir (Cloudinary guarda solo el
 * resultado) y la API las entrega con `f_auto,q_auto` (`Siiis\Support\CloudinaryUrl`).
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
        $opciones = [
            'folder' => $folder,
            'resource_type' => 'image',
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
        } catch (Throwable $e) {
            throw new CloudinaryException('Error al subir a Cloudinary: ' . $e->getMessage(), 0, $e);
        }

        if (!is_string($res['secure_url'] ?? null) || !is_string($res['public_id'] ?? null)) {
            throw new CloudinaryException('Respuesta de Cloudinary sin secure_url o public_id.');
        }

        return ['url' => $res['secure_url'], 'public_id' => $res['public_id']];
    }

    /**
     * Borra el archivo y lo invalida en la CDN. Borrar un public_id que ya no existe no es error.
     *
     * @throws CloudinaryException
     */
    public function delete(string $publicId): void
    {
        try {
            $res = $this->client()->uploadApi()->destroy($publicId, [
                'resource_type' => 'image',
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
