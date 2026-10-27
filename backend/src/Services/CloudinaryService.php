<?php

declare(strict_types=1);

namespace Siiis\Services;

use Cloudinary\Cloudinary;
use Siiis\Config\Settings;
use Throwable;

/**
 * Subida y borrado de imágenes en Cloudinary (spec 13.2). MongoDB solo guarda `{url, public_id}`.
 * El cliente del SDK se crea al primer uso: la app arranca aunque falten las credenciales.
 */
class CloudinaryService
{
    /** Segundos; el plan compartido de Hostinger no admite peticiones muy largas. */
    private const TIMEOUT_SECONDS = 30;

    private ?Cloudinary $cloudinary = null;

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * Sube una imagen ya validada desde el temporal de PHP. Cloudinary genera un public_id único
     * dentro de `$folder`.
     *
     * @return array{url: string, public_id: string}
     *
     * @throws CloudinaryException
     */
    public function upload(string $path, string $folder): array
    {
        try {
            $res = $this->client()->uploadApi()->upload($path, [
                'folder' => $folder,
                'resource_type' => 'image',
                // Segunda barrera tras finfo: Cloudinary rechaza otros formatos.
                'allowed_formats' => ['jpg', 'png', 'webp'],
                'unique_filename' => true,
                'use_filename' => false,
                'overwrite' => false,
            ]);
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
     * Borra la imagen y la invalida en la CDN. Borrar un public_id que ya no existe no es error.
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
