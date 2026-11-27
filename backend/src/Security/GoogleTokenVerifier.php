<?php

declare(strict_types=1);

namespace Siiis\Security;

use Firebase\JWT\JWT;
use RuntimeException;
use Siiis\Config\Settings;
use Siiis\Support\ApiException;
use Siiis\Support\Clock;
use Throwable;
use UnexpectedValueException;

/**
 * Verifica el id_token de Google Identity Services (RF-17, DEC-B61) y devuelve el correo normalizado.
 *
 * Comprueba: firma RS256 con las llaves de Google (JWKS), `iss` de Google, `aud` = GOOGLE_CLIENT_ID, `exp`
 * vigente, `email_verified = true` y `hd` = GOOGLE_DOMINIO_PERMITIDO (exacto). No crea ni busca cuentas.
 */
class GoogleTokenVerifier
{
    private const EMISORES = ['accounts.google.com', 'https://accounts.google.com'];

    /** Margen para relojes un poco desfasados (segundos). */
    private const MARGEN_RELOJ = 60;

    public function __construct(
        private readonly GoogleJwksProvider $jwks,
        private readonly Settings $settings,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @throws ApiException 401 INVALID_GOOGLE_TOKEN, 403 GOOGLE_DOMAIN_NOT_ALLOWED, 503 GOOGLE_UNAVAILABLE
     */
    public function verificar(string $idToken): string
    {
        $claims = $this->decodificar($idToken);

        $iss = $claims['iss'] ?? null;
        $aud = $claims['aud'] ?? null;
        if (!in_array($iss, self::EMISORES, true) || $aud !== $this->settings->googleClientId || !is_int($claims['exp'] ?? null)) {
            throw self::tokenInvalido();
        }

        $correo = is_string($claims['email'] ?? null) ? mb_strtolower(trim($claims['email'])) : null;
        $verificado = $claims['email_verified'] ?? null;
        $hd = $claims['hd'] ?? null;
        $dominio = $this->settings->googleDominioPermitido;
        if (
            !is_string($correo)
            || ($verificado !== true && $verificado !== 'true')
            || !is_string($hd)
            || strtolower($hd) !== $dominio
            || !str_ends_with($correo, '@' . $dominio)
        ) {
            throw new ApiException(
                403,
                'GOOGLE_DOMAIN_NOT_ALLOWED',
                sprintf('Solo se puede entrar con Google usando una cuenta institucional @%s verificada.', $dominio),
            );
        }

        return $correo;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodificar(string $idToken): array
    {
        $anteriorTimestamp = JWT::$timestamp;
        $anteriorLeeway = JWT::$leeway;
        JWT::$timestamp = $this->clock->now()->getTimestamp();
        JWT::$leeway = self::MARGEN_RELOJ;
        try {
            try {
                return (array) JWT::decode($idToken, $this->claves(false));
            } catch (UnexpectedValueException $e) {
                // Un `kid` desconocido puede ser una llave nueva de Google (rotación): se renuevan y se reintenta.
                if (!str_contains($e->getMessage(), 'kid')) {
                    throw $e;
                }

                return (array) JWT::decode($idToken, $this->claves(true));
            }
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable) {
            throw self::tokenInvalido();
        } finally {
            JWT::$timestamp = $anteriorTimestamp;
            JWT::$leeway = $anteriorLeeway;
        }
    }

    /**
     * @return array<string, \Firebase\JWT\Key>
     */
    private function claves(bool $refrescar): array
    {
        try {
            return $this->jwks->claves($refrescar);
        } catch (RuntimeException) {
            throw new ApiException(503, 'GOOGLE_UNAVAILABLE', 'No se pudo comprobar el inicio de sesión con Google. Intenta de nuevo en unos minutos.');
        }
    }

    private static function tokenInvalido(): ApiException
    {
        return new ApiException(401, 'INVALID_GOOGLE_TOKEN', 'El inicio de sesión con Google no es válido o venció. Intenta de nuevo.');
    }
}
