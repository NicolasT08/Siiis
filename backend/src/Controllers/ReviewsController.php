<?php

declare(strict_types=1);

namespace Siiis\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Siiis\Config\Settings;
use Siiis\Middleware\AuthMiddleware;
use Siiis\Repositories\ResenasRepository;
use Siiis\Security\RateLimiter;
use Siiis\Security\Roles;
use Siiis\Services\ResenasService;
use Siiis\Support\ApiException;
use Siiis\Support\ClientIp;
use Siiis\Support\JsonResponse;
use Siiis\Validation\ListadoQueryValidator;
use Siiis\Validation\ResenaValidator;

/**
 * Reseñas sobre el semillero (spec 10.10; DEC-B43 a DEC-B45). `GET` y `POST` son públicos con sesión
 * opcional; `PATCH` y `DELETE` son para moderadores (la ruta lleva RoleMiddleware). El rol sale del JWT.
 */
final class ReviewsController
{
    /** Respuesta pública cacheable un minuto; con sesión no se guarda (igual que el contenido, DEC-B41). */
    private const CACHE_PUBLICA = 'public, max-age=60';

    public function __construct(
        private readonly ResenasService $resenas,
        private readonly ResenaValidator $validator,
        private readonly RateLimiter $rateLimiter,
        private readonly Settings $settings,
    ) {
    }

    /**
     * GET /api/v1/reviews
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $rol = $request->getAttribute(AuthMiddleware::ATTR_ROL);
        $rol = is_string($rol) ? $rol : null;
        $query = ListadoQueryValidator::validar(
            $request->getQueryParams(),
            $this->settings->resenasPerPageDefault,
            $this->settings->resenasPerPageMax,
            'estado',
            Roles::esModeradorResenas($rol) ? ResenasRepository::ESTADOS : null,
        );

        return JsonResponse::ok($this->resenas->listar($query, $rol))
            ->withHeader('Cache-Control', $rol !== null ? 'no-store' : self::CACHE_PUBLICA)
            ->withHeader('Vary', 'Authorization');
    }

    /**
     * POST /api/v1/reviews — público, con rate limiting por IP (DEC-B48). Si llega un token que no es válido,
     * `401`: así una sesión vencida no publica la reseña como anónima sin avisar (DEC-B43).
     */
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $s = $this->settings;
        $this->rateLimiter->limitar(
            'reviews',
            ClientIp::from($request),
            $s->rateLimitReviewsMax,
            $s->rateLimitReviewsWindowSeconds,
        );

        $actorId = $request->getAttribute(AuthMiddleware::ATTR_USUARIO_ID);
        $actorId = is_string($actorId) ? $actorId : null;
        if ($actorId === null && trim($request->getHeaderLine('Authorization')) !== '') {
            throw ApiException::unauthorized();
        }

        $input = $this->validator->crear($request->getParsedBody(), $actorId !== null);

        return JsonResponse::ok(['resena' => $this->resenas->crear($input, $actorId)], 201)
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * PATCH /api/v1/reviews/{id} — moderadores (DEC-B44).
     *
     * @param array{id: string} $args
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $estado = $this->validator->estado($request->getParsedBody());

        return JsonResponse::ok(['resena' => $this->resenas->cambiarEstado($args['id'], $estado)])
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * DELETE /api/v1/reviews/{id} — moderadores; borrado físico (DEC-B44).
     *
     * @param array{id: string} $args
     */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->resenas->eliminar($args['id']);

        return JsonResponse::noContent()->withHeader('Cache-Control', 'no-store');
    }
}
