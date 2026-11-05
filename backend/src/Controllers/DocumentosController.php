<?php

declare(strict_types=1);

namespace Siiis\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Siiis\Config\Settings;
use Siiis\Middleware\AuthMiddleware;
use Siiis\Security\Roles;
use Siiis\Services\DocumentosService;
use Siiis\Support\JsonResponse;
use Siiis\Support\ModuloDocumentos;
use Siiis\Validation\DocumentosQueryValidator;
use Siiis\Validation\DocumentoValidator;
use Siiis\Validation\FileValidator;

/**
 * Contenido de la colección unificada `Documentos` (spec 9 y 10.9): un solo controlador para `/projects` y
 * `/articles`. Cada ruta fija su módulo con el argumento `modulo` (ver Routes). Las consultas son públicas
 * con sesión opcional; crear, editar, borrar y manejar adjuntos exigen sesión. El rol sale del JWT.
 */
final class DocumentosController
{
    /** Respuesta pública cacheable un minuto; con sesión no se guarda (igual que GET /users). */
    private const CACHE_PUBLICA = 'public, max-age=60';

    public function __construct(
        private readonly DocumentosService $documentos,
        private readonly DocumentosQueryValidator $queryValidator,
        private readonly DocumentoValidator $validator,
        private readonly FileValidator $fileValidator,
        private readonly Settings $settings,
    ) {
    }

    /**
     * GET /api/v1/{projects|articles}
     *
     * @param array{modulo: string} $args
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $modulo = ModuloDocumentos::from($args['modulo']);
        $actorRol = self::actorRol($request);
        $query = $this->queryValidator->validate(
            $request->getQueryParams(),
            $modulo,
            Roles::esRevisorContenido($actorRol),
        );

        return self::conCache(
            JsonResponse::ok($this->documentos->listar($modulo, $query, self::actorId($request), $actorRol)),
            $actorRol !== null,
        );
    }

    /**
     * GET /api/v1/{projects|articles}/{id}
     *
     * @param array{modulo: string, id: string} $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $actorRol = self::actorRol($request);
        $documento = $this->documentos->obtener(
            ModuloDocumentos::from($args['modulo']),
            $args['id'],
            self::actorId($request),
            $actorRol,
        );

        return self::conCache(JsonResponse::ok(['documento' => $documento]), $actorRol !== null);
    }

    /**
     * POST /api/v1/{projects|articles} — requiere sesión (AuthMiddleware).
     *
     * @param array{modulo: string} $args
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $modulo = ModuloDocumentos::from($args['modulo']);
        $input = $this->validator->crear($request->getParsedBody(), $modulo);
        $documento = $this->documentos->crear(
            $modulo,
            $input,
            (string) $request->getAttribute(AuthMiddleware::ATTR_USUARIO_ID),
            (string) $request->getAttribute(AuthMiddleware::ATTR_ROL),
        );

        return JsonResponse::ok(['documento' => $documento], 201)->withHeader('Cache-Control', 'no-store');
    }

    /**
     * PATCH /api/v1/{projects|articles}/{id} — requiere sesión.
     *
     * @param array{modulo: string, id: string} $args
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $modulo = ModuloDocumentos::from($args['modulo']);
        $input = $this->validator->actualizar($request->getParsedBody(), $modulo);
        $documento = $this->documentos->actualizar(
            $modulo,
            $args['id'],
            $input,
            (string) $request->getAttribute(AuthMiddleware::ATTR_USUARIO_ID),
            (string) $request->getAttribute(AuthMiddleware::ATTR_ROL),
        );

        return JsonResponse::ok(['documento' => $documento])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * DELETE /api/v1/{projects|articles}/{id} — requiere sesión; borrado físico (DEC-B35).
     *
     * @param array{modulo: string, id: string} $args
     */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->documentos->eliminar(
            ModuloDocumentos::from($args['modulo']),
            $args['id'],
            (string) $request->getAttribute(AuthMiddleware::ATTR_USUARIO_ID),
            (string) $request->getAttribute(AuthMiddleware::ATTR_ROL),
        );

        return JsonResponse::noContent()->withHeader('Cache-Control', 'no-store');
    }

    /**
     * POST /api/v1/{projects|articles}/{id}/files — multipart/form-data con el archivo en el campo `archivo`
     * (DEC-B39, DEC-B40). Un archivo por petición.
     *
     * @param array{modulo: string, id: string} $args
     */
    public function storeFile(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $archivo = $this->fileValidator->adjunto(
            $request->getUploadedFiles(),
            'archivo',
            $this->settings->documentoAdjuntoMaxBytes,
        );
        $documento = $this->documentos->agregarAdjunto(
            ModuloDocumentos::from($args['modulo']),
            $args['id'],
            $archivo,
            (string) $request->getAttribute(AuthMiddleware::ATTR_USUARIO_ID),
            (string) $request->getAttribute(AuthMiddleware::ATTR_ROL),
        );

        return JsonResponse::ok(['documento' => $documento], 201)->withHeader('Cache-Control', 'no-store');
    }

    /**
     * DELETE /api/v1/{projects|articles}/{id}/files/{archivo} (DEC-B39).
     *
     * @param array{modulo: string, id: string, archivo: string} $args
     */
    public function destroyFile(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $documento = $this->documentos->quitarAdjunto(
            ModuloDocumentos::from($args['modulo']),
            $args['id'],
            $args['archivo'],
            (string) $request->getAttribute(AuthMiddleware::ATTR_USUARIO_ID),
            (string) $request->getAttribute(AuthMiddleware::ATTR_ROL),
        );

        return JsonResponse::ok(['documento' => $documento])->withHeader('Cache-Control', 'no-store');
    }

    private static function actorId(ServerRequestInterface $request): ?string
    {
        $id = $request->getAttribute(AuthMiddleware::ATTR_USUARIO_ID);

        return is_string($id) ? $id : null;
    }

    private static function actorRol(ServerRequestInterface $request): ?string
    {
        $rol = $request->getAttribute(AuthMiddleware::ATTR_ROL);

        return is_string($rol) ? $rol : null;
    }

    /**
     * La respuesta depende del token: `Vary` evita que una caché sirva la vista equivocada.
     */
    private static function conCache(ResponseInterface $response, bool $autenticado): ResponseInterface
    {
        return $response
            ->withHeader('Cache-Control', $autenticado ? 'no-store' : self::CACHE_PUBLICA)
            ->withHeader('Vary', 'Authorization');
    }
}
