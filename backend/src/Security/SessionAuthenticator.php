<?php

declare(strict_types=1);

namespace Siiis\Security;

use Psr\Http\Message\ServerRequestInterface;
use Siiis\Repositories\SesionesRepository;
use Siiis\Support\ApiException;
use Siiis\Support\Clock;
use Siiis\Support\TokenGenerator;

/**
 * Valida JWT + sesión activa en `Sesiones` (spec 10.13.6). Lo usan AuthMiddleware y OptionalAuthMiddleware.
 */
final class SessionAuthenticator
{
    public const ATTR_USUARIO_ID = 'usuario_id';
    public const ATTR_ROL = 'rol';
    public const ATTR_SESSION_HASH = 'session_hash';

    public function __construct(
        private readonly JwtService $jwt,
        private readonly SesionesRepository $sesiones,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Devuelve la request con usuario_id, rol (del token firmado, nunca del cuerpo) y hash de la sesión,
     * o null si no trae cabecera `Authorization: Bearer`.
     *
     * @throws ApiException 401 si el token es inválido o vencido, o la sesión no está activa
     */
    public function authenticate(ServerRequestInterface $request): ?ServerRequestInterface
    {
        $token = $this->bearerToken($request);
        if ($token === null) {
            return null;
        }

        try {
            $claims = $this->jwt->decode($token);
        } catch (InvalidTokenException) {
            throw ApiException::unauthorized();
        }

        $sessionHash = TokenGenerator::hash($claims->jti);
        $sesion = $this->sesiones->findActiva($sessionHash, $this->clock->now());
        if ($sesion === null || (string) ($sesion['usuario_id'] ?? '') !== $claims->sub) {
            throw ApiException::unauthorized();
        }

        return $request
            ->withAttribute(self::ATTR_USUARIO_ID, $claims->sub)
            ->withAttribute(self::ATTR_ROL, $claims->rol)
            ->withAttribute(self::ATTR_SESSION_HASH, $sessionHash);
    }

    private function bearerToken(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if ($header === '') {
            // LiteSpeed/Apache pueden entregar la cabecera reenviada por .htaccess con otro nombre.
            $server = $request->getServerParams();
            $header = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
