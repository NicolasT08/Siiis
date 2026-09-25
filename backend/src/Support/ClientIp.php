<?php

declare(strict_types=1);

namespace Siiis\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * IP del cliente para rate limiting y auditoría. Usa REMOTE_ADDR; no confía en
 * X-Forwarded-For porque el cliente puede falsificarlo.
 */
final class ClientIp
{
    public static function from(ServerRequestInterface $request): string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : 'desconocida';
    }
}
