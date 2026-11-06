<?php

declare(strict_types=1);

namespace Siiis\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Siiis\Config\Settings;
use Siiis\Repositories\InscripcionesRepository;
use Siiis\Security\RateLimiter;
use Siiis\Services\InscripcionesService;
use Siiis\Support\ClientIp;
use Siiis\Support\JsonResponse;
use Siiis\Validation\FileValidator;
use Siiis\Validation\InscripcionValidator;
use Siiis\Validation\ListadoQueryValidator;

/**
 * Inscripción al semillero (spec 10.11; DEC-B49 a DEC-B52). `POST` es público, con rate limiting por IP; las
 * consultas y la resolución son para `superadministrador` y `administrador` (la ruta lleva RoleMiddleware).
 */
final class InscriptionsController
{
    public function __construct(
        private readonly InscripcionesService $inscripciones,
        private readonly InscripcionValidator $validator,
        private readonly FileValidator $fileValidator,
        private readonly RateLimiter $rateLimiter,
        private readonly Settings $settings,
    ) {
    }

    /**
     * POST /api/v1/inscriptions — `multipart/form-data` con los datos y los archivos en `documentos[]`.
     * Primero se validan los datos y después los archivos (no se analiza un archivo si el formulario no sirve).
     */
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $s = $this->settings;
        $this->rateLimiter->limitar(
            'inscriptions',
            ClientIp::from($request),
            $s->rateLimitInscriptionsMax,
            $s->rateLimitInscriptionsWindowSeconds,
        );

        $input = $this->validator->crear($request->getParsedBody());
        $archivos = $this->fileValidator->adjuntos(
            $request->getUploadedFiles(),
            'documentos',
            $s->inscripcionDocumentosMax,
            $s->documentoAdjuntoMaxBytes,
            $s->documentoPdfMaxBytes,
        );

        return JsonResponse::ok([
            'inscripcion' => $this->inscripciones->crear($input, $archivos),
            'mensaje' => 'Recibimos tu solicitud. Te escribiremos al correo que indicaste con la respuesta.',
        ], 201)->withHeader('Cache-Control', 'no-store');
    }

    /**
     * GET /api/v1/inscriptions
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = ListadoQueryValidator::validar(
            $request->getQueryParams(),
            $this->settings->inscripcionesPerPageDefault,
            $this->settings->inscripcionesPerPageMax,
            'estado_solicitud',
            InscripcionesRepository::ESTADOS,
        );

        return JsonResponse::ok($this->inscripciones->listar($query))->withHeader('Cache-Control', 'no-store');
    }

    /**
     * GET /api/v1/inscriptions/{id} — con enlaces firmados de los documentos (DEC-B50).
     *
     * @param array{id: string} $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return JsonResponse::ok(['inscripcion' => $this->inscripciones->obtener($args['id'])])
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * PATCH /api/v1/inscriptions/{id} — `{"estado_solicitud": "aceptada" | "rechazada"}` (DEC-B52).
     *
     * @param array{id: string} $args
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $estado = $this->validator->resolver($request->getParsedBody());

        return JsonResponse::ok(['inscripcion' => $this->inscripciones->resolver($args['id'], $estado)])
            ->withHeader('Cache-Control', 'no-store');
    }
}
