<?php

declare(strict_types=1);

namespace Siiis\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Siiis\Config\Settings;
use Siiis\Repositories\MensajesContactoRepository;
use Siiis\Services\ContactoService;
use Siiis\Support\JsonResponse;
use Siiis\Validation\ContactoValidator;
use Siiis\Validation\ListadoQueryValidator;

/**
 * Buzón de `Mensajes_Contacto` para `superadministrador` y `administrador` (DEC-B46). Las rutas llevan
 * `RoleMiddleware::administrativos()`. El envío público (`POST /contact`) está en HomeController (spec 10.12).
 */
final class MensajesContactoController
{
    public function __construct(
        private readonly ContactoService $contacto,
        private readonly ContactoValidator $validator,
        private readonly Settings $settings,
    ) {
    }

    /**
     * GET /api/v1/contact/messages
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = ListadoQueryValidator::validar(
            $request->getQueryParams(),
            $this->settings->mensajesPerPageDefault,
            $this->settings->mensajesPerPageMax,
            'estado',
            MensajesContactoRepository::ESTADOS,
        );

        return JsonResponse::ok($this->contacto->listar($query))->withHeader('Cache-Control', 'no-store');
    }

    /**
     * PATCH /api/v1/contact/messages/{id} — `{"estado": "leido" | "no_leido"}`.
     *
     * @param array{id: string} $args
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $estado = $this->validator->estado($request->getParsedBody());

        return JsonResponse::ok(['mensaje_contacto' => $this->contacto->cambiarEstado($args['id'], $estado)])
            ->withHeader('Cache-Control', 'no-store');
    }
}
