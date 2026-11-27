<?php

declare(strict_types=1);

namespace Siiis\Security;

use Closure;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use RuntimeException;
use Siiis\Support\Clock;
use Siiis\Support\Logger;
use Throwable;

/**
 * Llaves públicas de Google (JWKS) para verificar los id_token de Google Identity Services (RF-17, DEC-B61).
 *
 * - Se guardan en un archivo de `storage/cache` el tiempo que indique `Cache-Control: max-age` de Google (entre 5
 *   minutos y 1 día; 1 hora si no lo dice). Así no se descargan en cada login.
 * - Si Google no responde y hay una copia vencida, se usa esa (las llaves rotan con días de solapamiento).
 * - `claves(true)` fuerza la descarga: se usa cuando un token trae un `kid` que la copia no tiene (rotación).
 */
class GoogleJwksProvider
{
    public const URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private const TTL_POR_DEFECTO = 3600;
    private const TTL_MIN = 300;
    private const TTL_MAX = 86400;
    private const TIMEOUT_SECONDS = 5;

    /** @var Closure(): array{0: string, 1: ?int} descarga: [cuerpo JSON, max-age o null] */
    private readonly Closure $descargar;

    /**
     * @param (Closure(): array{0: string, 1: ?int})|null $descargar para pruebas; por defecto, HTTPS con cURL
     */
    public function __construct(
        private readonly string $archivoCache,
        private readonly Clock $clock,
        private readonly Logger $logger,
        ?Closure $descargar = null,
    ) {
        $this->descargar = $descargar ?? static fn (): array => self::descargarDeGoogle();
    }

    /**
     * @return array<string, Key> llaves por `kid`
     *
     * @throws RuntimeException si no hay llaves (Google no responde y no hay copia)
     */
    public function claves(bool $refrescar = false): array
    {
        $cache = $this->leerCache();
        $ahora = $this->clock->now()->getTimestamp();
        if (!$refrescar && $cache !== null && $cache['expira'] > $ahora) {
            return JWK::parseKeySet($cache['jwks'], 'RS256');
        }

        try {
            [$cuerpo, $maxAge] = ($this->descargar)();
            $jwks = json_decode($cuerpo, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($jwks) || !is_array($jwks['keys'] ?? null) || $jwks['keys'] === []) {
                throw new RuntimeException('Respuesta de JWKS sin llaves.');
            }
            $claves = JWK::parseKeySet($jwks, 'RS256');
        } catch (Throwable $e) {
            if ($cache !== null) {
                $this->logger->warning('No se pudieron renovar las llaves de Google; se usa la copia guardada', ['error' => $e->getMessage()]);

                return JWK::parseKeySet($cache['jwks'], 'RS256');
            }

            throw new RuntimeException('No se pudieron obtener las llaves de Google: ' . $e->getMessage(), 0, $e);
        }

        $ttl = max(self::TTL_MIN, min(self::TTL_MAX, $maxAge ?? self::TTL_POR_DEFECTO));
        $this->guardarCache(['expira' => $ahora + $ttl, 'jwks' => $jwks]);

        return $claves;
    }

    /**
     * @return array{expira: int, jwks: array<string, mixed>}|null
     */
    private function leerCache(): ?array
    {
        if (!is_file($this->archivoCache)) {
            return null;
        }
        $datos = json_decode((string) @file_get_contents($this->archivoCache), true);
        if (!is_array($datos) || !is_int($datos['expira'] ?? null) || !is_array($datos['jwks'] ?? null)) {
            return null;
        }

        /** @var array{expira: int, jwks: array<string, mixed>} $datos */
        return $datos;
    }

    /**
     * @param array{expira: int, jwks: array<string, mixed>} $datos
     */
    private function guardarCache(array $datos): void
    {
        $dir = dirname($this->archivoCache);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        // Escritura atómica: un login simultáneo nunca lee un archivo a medias.
        $temporal = $this->archivoCache . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporal, json_encode($datos, JSON_UNESCAPED_SLASHES)) === false || !@rename($temporal, $this->archivoCache)) {
            @unlink($temporal);
            $this->logger->warning('No se pudo guardar la caché de llaves de Google', ['archivo' => $this->archivoCache]);
        }
    }

    /**
     * @return array{0: string, 1: ?int}
     */
    private static function descargarDeGoogle(): array
    {
        $curl = curl_init(self::URL);
        if ($curl === false) {
            throw new RuntimeException('cURL no disponible.');
        }
        $maxAge = null;
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $linea) use (&$maxAge): int {
                if (preg_match('/^cache-control:.*\bmax-age=(\d+)/i', $linea, $m) === 1) {
                    $maxAge = (int) $m[1];
                }

                return strlen($linea);
            },
        ]);
        $cuerpo = curl_exec($curl);
        $estado = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($cuerpo) || $estado !== 200) {
            throw new RuntimeException(sprintf('Google respondió %s %s', (string) $estado, $error));
        }

        return [$cuerpo, $maxAge];
    }
}
