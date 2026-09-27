"""IP del cliente para rate limiting y auditoría (réplica de `Support/ClientIp`).

Usa la dirección de la conexión (REMOTE_ADDR en PHP); no confía en X-Forwarded-For.
"""

import ipaddress

from fastapi import Request

DESCONOCIDA = "desconocida"


def client_ip(request: Request) -> str:
    host = request.client.host if request.client else None
    if not host:
        return DESCONOCIDA
    try:
        ipaddress.ip_address(host)
    except ValueError:
        return DESCONOCIDA
    return host
