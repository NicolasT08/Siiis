<?php

declare(strict_types=1);

namespace Siiis\Services;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Siiis\Config\Settings;
use Siiis\Repositories\MultimediaRepository;
use Siiis\Support\ApiException;
use Siiis\Support\Clock;
use Siiis\Support\CloudinaryUrl;
use Siiis\Support\Logger;
use Siiis\Support\ObjectIdValidator;
use Siiis\Support\Serializer;
use Siiis\Validation\MultimediaValidator;
use Throwable;

/**
 * Administración de las fotos del carrusel y los videos de presentación del inicio (RF-07, RF-11, DEC-B62).
 * Solo superadministrador y administrador (lo exige la ruta). Las rutas públicas GET /home/* no cambian.
 */
class MultimediaService
{
    /** Subcarpeta de Cloudinary por sección, dentro de CLOUDINARY_HOME_FOLDER (como cargar_multimedia.php). */
    private const SUBCARPETA = [
        MultimediaRepository::SECCION_SLIDER => 'slider',
        MultimediaRepository::SECCION_VIDEOS => 'videos',
    ];

    public function __construct(
        private readonly MultimediaRepository $multimedia,
        private readonly CloudinaryService $cloudinary,
        private readonly Settings $settings,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listar(string $seccion): array
    {
        return array_map(self::presentar(...), $this->multimedia->listar($seccion));
    }

    /**
     * Sube el archivo a Cloudinary y lo registra. Si MongoDB falla, se borra lo subido (compensación).
     *
     * Con `reemplaza` (DEC-B69), el elemento anterior no cuenta para el límite y se borra solo si la subida y el
     * registro del nuevo salieron bien.
     *
     * @param array{seccion: string, descripcion: string, orden: int|null, reemplaza?: ObjectId|null} $datos
     * @param array{path: string, mime: string, size: int}                                              $archivo validado por FileValidator
     *
     * @return array<string, mixed>
     *
     * @throws ApiException 404 NOT_FOUND, 409 MULTIMEDIA_LIMIT o VIDEO_LIMIT, 415 UNSUPPORTED_MEDIA_TYPE,
     *                      502 CLOUDINARY_UNAVAILABLE
     */
    public function crear(array $datos, array $archivo): array
    {
        $seccion = $datos['seccion'];
        $tipo = MultimediaValidator::SECCIONES[$seccion];
        $anterior = null;
        if (($datos['reemplaza'] ?? null) !== null) {
            $anterior = $this->multimedia->findById($datos['reemplaza']);
            if ($anterior === null || ($anterior['seccion'] ?? null) !== $seccion) {
                throw self::noEncontrado();
            }
        }
        $this->exigirEspacio($seccion, $anterior === null ? 0 : 1);

        $folder = $this->settings->cloudinaryHomeFolder . '/' . self::SUBCARPETA[$seccion];
        try {
            $subido = $tipo === 'video'
                ? $this->cloudinary->uploadVideo($archivo['path'], $folder)
                : $this->cloudinary->upload(
                    $archivo['path'],
                    $folder,
                    CloudinaryService::FORMATOS_IMAGEN,
                    // Mismas reglas que las imágenes adjuntas (DEC-B42): 1600 px de ancho máximo y calidad automática.
                    CloudinaryService::transformacionImagenAdjunta($this->settings),
                );
        } catch (ArchivoRechazadoException $e) {
            $this->logger->warning('Cloudinary rechazó un archivo del inicio', ['seccion' => $seccion, 'error' => $e->getMessage()]);

            throw new ApiException(
                415,
                'UNSUPPORTED_MEDIA_TYPE',
                $tipo === 'video'
                    ? 'El video está dañado o no es un MP4, WebM o MOV válido. Comprueba que se reproduzca en tu equipo.'
                    : 'La imagen está dañada o no es una imagen válida. Prueba con otra foto en JPG, PNG o WebP.',
            );
        } catch (CloudinaryException $e) {
            $this->logger->error('No se pudo subir un archivo del inicio', ['seccion' => $seccion, 'error' => $e->getMessage()]);

            throw new ApiException(502, 'CLOUDINARY_UNAVAILABLE', 'No se pudo guardar el archivo. Intenta de nuevo más tarde.');
        }

        $item = [
            'tipo' => $tipo,
            'url' => $subido['url'],
            'public_id' => $subido['public_id'],
            'seccion' => $seccion,
            'descripcion' => $datos['descripcion'],
            // Al reemplazar, el nuevo toma el lugar del anterior.
            'orden' => $datos['orden'] ?? (is_int($anterior['orden'] ?? null) ? $anterior['orden'] : $this->multimedia->maxOrden($seccion) + 1),
            'fecha_subida' => new UTCDateTime($this->clock->now()),
        ];
        try {
            $id = $this->multimedia->insertar($item);
        } catch (Throwable $e) {
            $this->borrarArchivo($subido['public_id'], $tipo, 'compensación tras fallo en MongoDB');

            throw $e;
        }

        // El nuevo ya quedó guardado: ahora sí se borra el anterior (registro y archivo).
        if ($anterior !== null && $anterior['_id'] instanceof ObjectId) {
            $borrado = $this->multimedia->eliminar($anterior['_id']);
            $publicId = $borrado['public_id'] ?? null;
            if (is_string($publicId) && $publicId !== '') {
                $this->borrarArchivo($publicId, (string) ($borrado['tipo'] ?? $tipo), 'reemplazado desde la administración');
            }
        }

        return self::presentar(['_id' => $id] + $item);
    }

    /**
     * Antes de subir: no se gasta una subida si la sección ya está llena. Los videos tienen su propio máximo
     * (HOME_MAX_VIDEOS, DEC-B69); las fotos, HOME_MAX_ITEMS (lo que muestra GET /home/*).
     *
     * @throws ApiException 409 VIDEO_LIMIT o MULTIMEDIA_LIMIT
     */
    private function exigirEspacio(string $seccion, int $liberados): void
    {
        $ocupados = $this->multimedia->contar($seccion) - $liberados;
        if ($seccion === MultimediaRepository::SECCION_VIDEOS) {
            $max = $this->settings->homeMaxVideos;
            if ($ocupados >= $max) {
                throw new ApiException(409, 'VIDEO_LIMIT', $max === 1
                    ? 'Ya hay un video de presentación. Usa "Reemplazar video" para cambiarlo.'
                    : sprintf('Ya hay %d videos de presentación, el máximo. Reemplaza o borra uno antes de subir otro.', $max));
            }

            return;
        }
        if ($ocupados >= $this->settings->homeMaxItems) {
            throw new ApiException(
                409,
                'MULTIMEDIA_LIMIT',
                sprintf('La sección ya tiene el máximo de %d elementos. Borra uno antes de subir otro.', $this->settings->homeMaxItems),
            );
        }
    }

    /**
     * @param array{descripcion?: string, orden?: int} $cambios
     *
     * @return array<string, mixed>
     *
     * @throws ApiException 404 NOT_FOUND
     */
    public function actualizar(string $id, array $cambios): array
    {
        $objectId = ObjectIdValidator::toObjectId($id);
        $doc = $objectId === null ? null : $this->multimedia->actualizar($objectId, $cambios);
        if ($doc === null) {
            throw self::noEncontrado();
        }

        return self::presentar($doc);
    }

    /**
     * Reordena toda la sección: `ids` debe traer exactamente sus elementos, en el orden deseado.
     *
     * @param list<\MongoDB\BSON\ObjectId> $ids
     *
     * @return list<array<string, mixed>>
     *
     * @throws ApiException 400 VALIDATION_ERROR
     */
    public function reordenar(string $seccion, array $ids): array
    {
        $actuales = array_map(static fn (array $d): string => (string) $d['_id'], $this->multimedia->listar($seccion));
        $pedidos = array_map(static fn ($id): string => (string) $id, $ids);
        $ordenadosA = $actuales;
        $ordenadosB = $pedidos;
        sort($ordenadosA);
        sort($ordenadosB);
        if ($ordenadosA !== $ordenadosB) {
            throw ApiException::validation(['ids' => 'La lista debe tener exactamente los elementos de la sección (recarga la página e intenta de nuevo).']);
        }
        $this->multimedia->reordenar($seccion, $ids);

        return $this->listar($seccion);
    }

    /**
     * Borra el registro y después su archivo de Cloudinary. Si Cloudinary falla, el registro ya no existe y el archivo
     * queda en el log para borrarlo a mano (como los adjuntos de documentos).
     *
     * @throws ApiException 404 NOT_FOUND
     */
    public function eliminar(string $id): void
    {
        $objectId = ObjectIdValidator::toObjectId($id);
        $doc = $objectId === null ? null : $this->multimedia->eliminar($objectId);
        if ($doc === null) {
            throw self::noEncontrado();
        }
        $publicId = $doc['public_id'] ?? null;
        if (is_string($publicId) && $publicId !== '') {
            $this->borrarArchivo($publicId, (string) ($doc['tipo'] ?? 'imagen'), 'borrado desde la administración');
        }
    }

    private function borrarArchivo(string $publicId, string $tipo, string $motivo): void
    {
        try {
            $this->cloudinary->delete(
                $publicId,
                false,
                $tipo === 'video' ? CloudinaryService::RECURSO_VIDEO : CloudinaryService::RECURSO_IMAGEN,
            );
        } catch (CloudinaryException $e) {
            $this->logger->error('No se pudo borrar un archivo del inicio de Cloudinary', [
                'public_id' => $publicId,
                'motivo' => $motivo,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Vista de administración: como la pública más `seccion` y `fecha_subida`. Nunca el `public_id`.
     *
     * @param array<string, mixed> $doc
     *
     * @return array<string, mixed>
     */
    private static function presentar(array $doc): array
    {
        /** @var array<string, mixed> */
        return Serializer::serialize([
            '_id' => $doc['_id'] ?? null,
            'tipo' => $doc['tipo'] ?? null,
            'url' => is_string($doc['url'] ?? null) ? CloudinaryUrl::entrega($doc['url']) : null,
            'seccion' => $doc['seccion'] ?? null,
            'descripcion' => $doc['descripcion'] ?? null,
            'orden' => $doc['orden'] ?? null,
            'fecha_subida' => $doc['fecha_subida'] ?? null,
        ]);
    }

    private static function noEncontrado(): ApiException
    {
        return new ApiException(404, 'NOT_FOUND', 'El elemento no existe.');
    }
}
