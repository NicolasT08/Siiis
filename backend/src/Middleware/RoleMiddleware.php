<?php

declare(strict_types=1);

namespace Siiis\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Siiis\Security\Roles;
use Siiis\Support\ApiException;

/**
 * Exige uno de los roles permitidos. Va después de AuthMiddleware (en Slim se agrega antes):
 * `->add(RoleMiddleware::administrativos())->add(AuthMiddleware::class)`.
 * El rol sale del JWT firmado; cambiar el rol de un usuario cierra sus sesiones (DEC-B17).
 */
final class RoleMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $permitidos
     */
    public function __construct(private readonly array $permitidos)
    {
    }

    public static function administrativos(): self
    {
        return new self(Roles::ADMINISTRATIVOS);
    }

    public static function moderadoresResenas(): self
    {
        return new self(Roles::MODERADORES_RESENAS);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $rol = $request->getAttribute(AuthMiddleware::ATTR_ROL);
        if (!is_string($rol)) {
            // Ruta sin AuthMiddleware delante: se trata como no autenticada.
            throw ApiException::unauthorized();
        }
        if (!in_array($rol, $this->permitidos, true)) {
            throw ApiException::forbidden();
        }

        return $handler->handle($request);
    }
}
