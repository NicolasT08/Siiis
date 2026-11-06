<?php

declare(strict_types=1);

namespace Siiis\Controllers;

use Psr\Http\Message\ResponseInterface;
use Siiis\Config\Settings;
use Siiis\Repositories\MultimediaRepository;
use Siiis\Support\CloudinaryUrl;
use Siiis\Support\JsonResponse;
use Siiis\Support\Serializer;

/**
 * Recursos públicos de la página de inicio (spec 10.12): slider (RF-07) y videos (RF-11).
 * Los bloques S-I-I-I-S, hero, CTA y footer no tienen backend en la fase 1 (DEC-B12).
 */
final class HomeController
{
    private const CACHE_CONTROL = 'public, max-age=300';

    public function __construct(
        private readonly MultimediaRepository $multimedia,
        private readonly Settings $settings,
    ) {
    }

    /**
     * GET /api/v1/home/slider
     */
    public function slider(): ResponseInterface
    {
        return $this->seccion(MultimediaRepository::SECCION_SLIDER);
    }

    /**
     * GET /api/v1/home/videos
     */
    public function videos(): ResponseInterface
    {
        return $this->seccion(MultimediaRepository::SECCION_VIDEOS);
    }

    private function seccion(string $seccion): ResponseInterface
    {
        $items = array_map(
            static fn (array $doc): mixed => Serializer::serialize([
                '_id' => $doc['_id'] ?? null,
                'tipo' => $doc['tipo'] ?? null,
                // Imágenes de Cloudinary con f_auto,q_auto (DEC-B42); los videos y otras URL, tal cual.
                'url' => is_string($doc['url'] ?? null) ? CloudinaryUrl::entrega($doc['url']) : null,
                'descripcion' => $doc['descripcion'] ?? null,
                'orden' => $doc['orden'] ?? null,
            ]),
            $this->multimedia->findBySeccion($seccion, $this->settings->homeMaxItems),
        );

        return JsonResponse::ok(['data' => $items])->withHeader('Cache-Control', self::CACHE_CONTROL);
    }
}
