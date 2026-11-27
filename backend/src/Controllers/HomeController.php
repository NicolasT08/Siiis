<?php

declare(strict_types=1);

namespace Siiis\Controllers;

use MongoDB\BSON\ObjectId;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Siiis\Config\Settings;
use Siiis\Repositories\CredencialesRepository;
use Siiis\Repositories\MultimediaRepository;
use Siiis\Repositories\UsuariosRepository;
use Siiis\Security\RateLimiter;
use Siiis\Services\ContactoService;
use Siiis\Support\ApiException;
use Siiis\Support\ClientIp;
use Siiis\Support\CloudinaryUrl;
use Siiis\Support\JsonResponse;
use Siiis\Support\Serializer;
use Siiis\Support\UsuarioPresenter;
use Siiis\Validation\ContactoValidator;

/**
 * Recursos públicos de la página de inicio (spec 10.12): slider (RF-07), videos (RF-11), director (DEC-B67) y
 * contacto (RF-03).
 * Los bloques S-I-I-I-S, hero, CTA y footer no tienen backend en la fase 1 (DEC-B12).
 */
final class HomeController
{
    private const CACHE_CONTROL = 'public, max-age=300';

    public function __construct(
        private readonly MultimediaRepository $multimedia,
        private readonly Settings $settings,
        private readonly ContactoService $contacto,
        private readonly ContactoValidator $contactoValidator,
        private readonly RateLimiter $rateLimiter,
        private readonly CredencialesRepository $credenciales,
        private readonly UsuariosRepository $usuarios,
    ) {
    }

    /**
     * POST /api/v1/contact — público, con rate limiting por IP (DEC-B46 a DEC-B48).
     */
    public function contact(ServerRequestInterface $request): ResponseInterface
    {
        $s = $this->settings;
        $this->rateLimiter->limitar(
            'contact',
            ClientIp::from($request),
            $s->rateLimitContactMax,
            $s->rateLimitContactWindowSeconds,
        );

        $this->contacto->enviar($this->contactoValidator->validate($request->getParsedBody()));

        return JsonResponse::ok([
            'mensaje' => 'Recibimos tu mensaje. El equipo del semillero te responderá al correo que indicaste.',
        ], 201)->withHeader('Cache-Control', 'no-store');
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

    /**
     * GET /api/v1/home/director — público (DEC-B67). Busca la cuenta de DIRECTOR_CORREO y devuelve solo
     * {id, nombre, apellido, foto_perfil}; nunca el correo. 404 si la variable está vacía o la cuenta no está activa.
     */
    public function director(): ResponseInterface
    {
        $correo = $this->settings->directorCorreo;
        $credencial = $correo === '' ? null : $this->credenciales->findByCorreo($correo);
        $usuarioId = $credencial['usuario_id'] ?? null;
        $usuario = $usuarioId instanceof ObjectId && ($credencial['estado_cuenta'] ?? null) === 'activa'
            ? $this->usuarios->findById($usuarioId)
            : null;
        if ($usuario === null || ($usuario['estado'] ?? null) !== true) {
            throw new ApiException(404, 'NOT_FOUND', 'No hay un director configurado.');
        }

        $publico = UsuarioPresenter::publico($usuario);
        $director = array_intersect_key($publico, array_flip(['id', 'nombre', 'apellido', 'foto_perfil']));

        return JsonResponse::ok(['director' => $director])->withHeader('Cache-Control', self::CACHE_CONTROL);
    }

    private function seccion(string $seccion): ResponseInterface
    {
        $items = array_map(
            static fn (array $doc): mixed => Serializer::serialize([
                '_id' => $doc['_id'] ?? null,
                'tipo' => $doc['tipo'] ?? null,
                // Imágenes de Cloudinary con f_auto,q_auto (DEC-B42), videos con q_auto; otras URL, tal cual.
                'url' => is_string($doc['url'] ?? null) ? CloudinaryUrl::entrega($doc['url']) : null,
                'descripcion' => $doc['descripcion'] ?? null,
                'orden' => $doc['orden'] ?? null,
            ]),
            $this->multimedia->findBySeccion($seccion, $this->settings->homeMaxItems),
        );

        return JsonResponse::ok(['data' => $items])->withHeader('Cache-Control', self::CACHE_CONTROL);
    }
}
