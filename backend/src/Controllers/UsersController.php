<?php

declare(strict_types=1);

namespace Siiis\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Siiis\Middleware\AuthMiddleware;
use Siiis\Security\Roles;
use Siiis\Services\UsersService;
use Siiis\Support\JsonResponse;
use Siiis\Validation\UsersQueryValidator;

/**
 * Integrantes (spec 10.8 y 11.3). Rutas públicas con sesión opcional (OptionalAuthMiddleware):
 * el rol, si lo hay, sale del JWT.
 */
final class UsersController
{
    /** Respuesta pública cacheable un minuto; con sesión no se guarda (DEC-B26). */
    private const CACHE_PUBLICA = 'public, max-age=60';

    public function __construct(
        private readonly UsersService $users,
        private readonly UsersQueryValidator $queryValidator,
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
