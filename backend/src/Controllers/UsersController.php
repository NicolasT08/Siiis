<?php

declare(strict_types=1);

namespace Siiis\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Siiis\Config\Settings;
use Siiis\Middleware\AuthMiddleware;
use Siiis\Security\Roles;
use Siiis\Services\UsersService;
use Siiis\Support\JsonResponse;
use Siiis\Validation\FileValidator;
use Siiis\Validation\UpdateUserValidator;
use Siiis\Validation\UsersQueryValidator;

/**
 * Integrantes (spec 10.8 y 11.3). Las consultas son públicas con sesión opcional
 * (OptionalAuthMiddleware); la edición exige sesión. El rol, si lo hay, sale del JWT.
 */
final class UsersController
{
    /**
     * Respuesta pública: se puede guardar, pero hay que revalidarla siempre (DEC-B72, antes `max-age=60` por
     * DEC-B26). Así, desactivar a alguien se ve al instante en "Integrantes". Con sesión no se guarda.
     */
    private const CACHE_PUBLICA = 'public, no-cache';

    public function __construct(
        private readonly UsersService $users,
        private readonly UsersQueryValidator $queryValidator,
        private readonly UpdateUserValidator $updateValidator,
        private readonly FileValidator $fileValidator,
        private readonly Settings $settings,
    ) {
    }

    /**
     * GET /api/v1/users
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $actorRol = self::actorRol($request);
        $query = $this->queryValidator->validate(
            $request->getQueryParams(),
            $actorRol !== null && Roles::esAdministrativo($actorRol),
        );

        return self::conCache(JsonResponse::ok($this->users->listar($query, $actorRol)), $actorRol !== null);
    }

    /**
     * GET /api/v1/users/{id}
     *
     * @param array{id: string} $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $actorId = $request->getAttribute(AuthMiddleware::ATTR_USUARIO_ID);
        $actorRol = self::actorRol($request);
        $usuario = $this->users->obtener($args['id'], is_string($actorId) ? $actorId : null, $actorRol);

        return self::conCache(JsonResponse::ok(['usuario' => $usuario]), $actorRol !== null);
    }

    /**
     * PATCH /api/v1/users/{id} — requiere sesión (AuthMiddleware); permisos en UsersService (DEC-B17).
     *
     * @param array{id: string} $args
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = $this->updateValidator->validate($request->getParsedBody());
        $usuario = $this->users->actualizar(
            $args['id'],
            (string) $request->getAttribute(AuthMiddleware::ATTR_USUARIO_ID),
            (string) $request->getAttribute(AuthMiddleware::ATTR_ROL),
            $input,
        );

        return JsonResponse::ok(['usuario' => $usuario])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * DELETE /api/v1/users/{id} — desactivación lógica; solo roles administrativos (RoleMiddleware).
     *
     * @param array{id: string} $args
     */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $usuario = $this->users->desactivar(
            $args['id'],
            (string) $request->getAttribute(AuthMiddleware::ATTR_USUARIO_ID),
            (string) $request->getAttribute(AuthMiddleware::ATTR_ROL),
        );

        return JsonResponse::ok(['usuario' => $usuario])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * POST /api/v1/users/{id}/photo — multipart/form-data con el archivo en el campo `foto` (RF-06).
     *
     * @param array{id: string} $args
     */
    public function photo(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $imagen = $this->fileValidator->imagen(
            $request->getUploadedFiles(),
            'foto',
            $this->settings->perfilFotoMaxBytes,
        );
        $usuario = $this->users->cambiarFoto(
            $args['id'],
            (string) $request->getAttribute(AuthMiddleware::ATTR_USUARIO_ID),
            (string) $request->getAttribute(AuthMiddleware::ATTR_ROL),
            $imagen['path'],
        );

        return JsonResponse::ok(['usuario' => $usuario])->withHeader('Cache-Control', 'no-store');
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
