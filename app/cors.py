"""CORS solo para desarrollo, réplica de `CorsMiddleware` de PHP.

Acepta únicamente FRONTEND_ORIGIN y responde cualquier OPTIONS (preflight) con 204.
"""

from starlette.datastructures import Headers, MutableHeaders
from starlette.responses import Response
from starlette.types import ASGIApp, Message, Receive, Scope, Send

ALLOW_METHODS = "GET, POST, PUT, PATCH, DELETE, OPTIONS"
ALLOW_HEADERS = "Content-Type, Authorization"
MAX_AGE = "600"


class CorsMiddleware:
    def __init__(self, app: ASGIApp, allowed_origin: str) -> None:
        self.app = app
        self.allowed_origin = allowed_origin

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return

        origin = Headers(scope=scope).get("origin", "")
        allowed = origin != "" and origin == self.allowed_origin

        if scope["method"] == "OPTIONS":
            response = Response(status_code=204)
            if allowed:
                response.headers["Access-Control-Allow-Origin"] = origin
                response.headers["Vary"] = "Origin"
                response.headers["Access-Control-Allow-Methods"] = ALLOW_METHODS
                response.headers["Access-Control-Allow-Headers"] = ALLOW_HEADERS
                response.headers["Access-Control-Max-Age"] = MAX_AGE
            else:
                response.headers["Vary"] = "Origin"
            await response(scope, receive, send)
            return

        async def send_wrapper(message: Message) -> None:
            if message["type"] == "http.response.start":
                headers = MutableHeaders(scope=message)
                if allowed:
                    headers["Access-Control-Allow-Origin"] = origin
                headers.append("Vary", "Origin")
            await send(message)

        await self.app(scope, receive, send_wrapper)
