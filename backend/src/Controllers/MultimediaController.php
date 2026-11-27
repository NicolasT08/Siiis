<?php

declare(strict_types=1);

namespace Siiis\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Siiis\Config\Settings;
use Siiis\Security\RateLimiter;
use Siiis\Services\MultimediaService;
use Siiis\Support\ApiException;
use Siiis\Support\ClientIp;
use Siiis\Support\JsonResponse;
use Siiis\Validation\FileValidator;
use Siiis\Validation\MultimediaValidator;
use Siiis\Validation\QueryReader;

/**
 * Administración de fotos del carrusel y videos del inicio (DEC-B62): solo superadministrador y administrador.
 * Las respuestas son `no-store` para que el panel vea los cambios al instante (las públicas se cachean 5 min).
 */
final class MultimediaController
{
    public function __construct(
        private readonly MultimediaService $multimedia,
        private readonly MultimediaValidator $validator,
        private readonly FileValidator $files,
        private readonly RateLimiter $rateLimiter,
        private readonly Settings $settings,
    ) {
    }

    /** GET /multimedia?seccion=slider_principal|video_presentacion */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $errors = [];
        $seccion = QueryReader::opcion($request->getQueryParams(), 'seccion', array_keys(MultimediaValidator::SECCIONES), $errors);
        if ($seccion === null && $errors === []) {
            $errors['seccion'] = 'Indica la sección: slider_principal o video_presentacion.';
        }
        if ($errors !== [] || $seccion === null) {
            throw ApiException::validation($errors);
        }

        return JsonResponse::ok(['data' => $this->multimedia->listar($seccion)])->withHeader('Cache-Control', 'no-store');
    }

    /** POST /multimedia (multipart: archivo, seccion, descripcion, orden opcional) */
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $s = $this->settings;
        $this->rateLimiter->limitar('multimedia', ClientIp::from($request), $s->rateLimitMultimediaMax, $s->rateLimitMultimediaWindowSeconds);

        // Primero los datos (no se lee el archivo si la sección no es válida), después el archivo.
        $datos = $this->validator->crear($request->getParsedBody());
        $archivos = $request->getUploadedFiles();
        $archivo = MultimediaValidator::SECCIONES[$datos['seccion']] === 'video'
            ? $this->files->video($archivos, 'archivo', $s->multimediaVideoMaxBytes)
            : $this->files->imagen($archivos, 'archivo', $s->documentoAdjuntoMaxBytes);

        return JsonResponse::ok(['multimedia' => $this->multimedia->crear($datos, $archivo)], 201)
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * PATCH /multimedia/{id}: descripcion y/o orden.
     *
     * @param array{id: string} $args
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $cambios = $this->validator->actualizar($request->getParsedBody());

        return JsonResponse::ok(['multimedia' => $this->multimedia->actualizar($args['id'], $cambios)])
            ->withHeader('Cache-Control', 'no-store');
    }

    /** PUT /multimedia/orden: {"seccion", "ids": [...]} con todos los elementos de la sección en el orden deseado. */
    public function reorder(ServerRequestInterface $request): ResponseInterface
    {
        $datos = $this->validator->reordenar($request->getParsedBody());

        return JsonResponse::ok(['data' => $this->multimedia->reordenar($datos['seccion'], $datos['ids'])])
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * DELETE /multimedia/{id}: borra el registro y el archivo en Cloudinary.
     *
     * @param array{id: string} $args
     */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->multimedia->eliminar($args['id']);

        return JsonResponse::noContent();
    }
}
