<?php

declare(strict_types=1);

namespace Siiis\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * IP del cliente para rate limiting y auditoría.
 *
 * Por defecto usa REMOTE_ADDR y no confía en X-Forwarded-For, porque el cliente puede falsificarlo. Solo si
 * REMOTE_ADDR es uno de los proxies de confianza (`TRUSTED_PROXIES`) se toma la IP de X-Forwarded-For:
 * se recorre de derecha a izquierda saltando los proxies de confianza y se usa la primera IP que no lo es.
 * `ClientIpMiddleware` la calcula una vez por petición y la deja en el atributo `ClientIp::ATTRIBUTE`.
 */
final class ClientIp
{
    public const ATTRIBUTE = 'client_ip';

    public const DESCONOCIDA = 'desconocida';

    public static function from(ServerRequestInterface $request): string
    {
        $ip = $request->getAttribute(self::ATTRIBUTE);

        return is_string($ip) ? $ip : self::resolve($request, []);
    }

    /**
     * @param list<string> $trustedProxies IP o rangos CIDR
     */
    public static function resolve(ServerRequestInterface $request, array $trustedProxies): string
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        if (!is_string($remote) || filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return self::DESCONOCIDA;
        }
        if ($trustedProxies === [] || !self::esDeConfianza($remote, $trustedProxies)) {
            return $remote;
        }

        $saltos = array_reverse(array_map('trim', explode(',', $request->getHeaderLine('X-Forwarded-For'))));
        foreach ($saltos as $salto) {
            if (filter_var($salto, FILTER_VALIDATE_IP) === false) {
                // Cabecera vacía o malformada: no se adivina, se usa la IP del proxy.
                return $remote;
            }
            if (!self::esDeConfianza($salto, $trustedProxies)) {
                return $salto;
            }
        }

        return $remote;
    }

    /**
     * IP (`203.0.113.7`, `2001:db8::1`) o rango CIDR (`10.0.0.0/8`, `2001:db8::/32`) bien formado.
     */
    public static function esRangoValido(string $rango): bool
    {
        [$ip, $bits] = array_pad(explode('/', $rango, 2), 2, null);
        $binaria = @inet_pton($ip);
        if ($binaria === false || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($bits === null) {
            return true;
        }

        return ctype_digit($bits) && (int) $bits <= strlen($binaria) * 8;
    }

    /**
     * @param list<string> $rangos
     */
    private static function esDeConfianza(string $ip, array $rangos): bool
    {
        $binaria = @inet_pton($ip);
        if ($binaria === false) {
            return false;
        }

        foreach ($rangos as $rango) {
            [$red, $bits] = array_pad(explode('/', $rango, 2), 2, null);
            $redBinaria = @inet_pton($red);
            if ($redBinaria === false || strlen($redBinaria) !== strlen($binaria)) {
                continue;
            }
            $bits = $bits === null ? strlen($binaria) * 8 : (int) $bits;
            $bytes = intdiv($bits, 8);
            if (substr($binaria, 0, $bytes) !== substr($redBinaria, 0, $bytes)) {
                continue;
            }
            $resto = $bits % 8;
            if ($resto === 0) {
                return true;
            }
            $mascara = (0xFF << (8 - $resto)) & 0xFF;
            if ((ord($binaria[$bytes]) & $mascara) === (ord($redBinaria[$bytes]) & $mascara)) {
                return true;
            }
        }

        return false;
    }
}
