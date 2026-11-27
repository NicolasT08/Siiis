<?php

declare(strict_types=1);

namespace Siiis\Tests\Support;

use Closure;
use Firebase\JWT\JWT;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * id_token "de Google" firmados con una llave RSA de prueba y el JWKS con su llave pública. Sirve para probar
 * POST /auth/google sin red: el verificador usa este JWKS en lugar del de Google.
 */
final class GoogleTokens
{
    public const CLIENT_ID = '1234567890-prueba.apps.googleusercontent.com';
    public const KID = 'llave-prueba-1';

    private static ?OpenSSLAsymmetricKey $llave = null;
    private static ?OpenSSLAsymmetricKey $otraLlave = null;

    /**
     * Llaves RSA fijas de `fixtures/` (solo para pruebas; no firman nada real). No se generan al vuelo porque
     * PHP en Windows no puede crear llaves sin un openssl.cnf configurado.
     */
    private static function llave(bool $otra = false): OpenSSLAsymmetricKey
    {
        $actual = $otra ? self::$otraLlave : self::$llave;
        if ($actual === null) {
            $archivo = __DIR__ . '/fixtures/' . ($otra ? 'google_prueba_otra.pem' : 'google_prueba.pem');
            $nueva = openssl_pkey_get_private((string) file_get_contents($archivo));
            if ($nueva === false) {
                throw new RuntimeException("No se pudo leer la llave RSA de prueba $archivo.");
            }
            $actual = $nueva;
            if ($otra) {
                self::$otraLlave = $nueva;
            } else {
                self::$llave = $nueva;
            }
        }

        return $actual;
    }

    /**
     * JWKS con la llave pública de prueba (formato de https://www.googleapis.com/oauth2/v3/certs).
     *
     * @return array{keys: list<array<string, string>>}
     */
    public static function jwks(string $kid = self::KID): array
    {
        $detalles = openssl_pkey_get_details(self::llave());
        if ($detalles === false) {
            throw new RuntimeException('No se pudo leer la llave de prueba.');
        }
        $b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return ['keys' => [[
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => $kid,
            'n' => $b64($detalles['rsa']['n']),
            'e' => $b64($detalles['rsa']['e']),
        ]]];
    }

    /** Descargador falso para GoogleJwksProvider; cuenta las descargas en `$descargas`. */
    public static function descargador(int &$descargas, string $kid = self::KID): Closure
    {
        return static function () use (&$descargas, $kid): array {
            $descargas++;

            return [json_encode(self::jwks($kid), JSON_THROW_ON_ERROR), 3600];
        };
    }

    /**
     * Token válido para `$correo` en `$ahora`, con los claims de Google. `$cambios` reemplaza claims; un valor
     * `null` lo quita. `$otraLlave` firma con una llave que no está en el JWKS.
     *
     * @param array<string, mixed> $cambios
     */
    public static function token(string $correo, int $ahora, array $cambios = [], bool $otraLlave = false, string $kid = self::KID): string
    {
        $claims = $cambios + [
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'azp' => self::CLIENT_ID,
            'sub' => '10769150350006150715113082367',
            'email' => $correo,
            'email_verified' => true,
            'hd' => 'uptc.edu.co',
            'name' => 'Ana Pérez',
            'iat' => $ahora,
            'exp' => $ahora + 3600,
        ];
        $claims = array_filter($claims, static fn (mixed $v): bool => $v !== null);

        return JWT::encode($claims, self::llave($otraLlave), 'RS256', $kid);
    }
}
