"""Respuestas JSON centralizadas, con el mismo formato que `JsonResponse` de PHP."""

from typing import Any

from fastapi.responses import JSONResponse, Response


class ApiJSONResponse(JSONResponse):
    # PHP responde `application/json; charset=utf-8`; Starlette omitiría el charset.
    media_type = "application/json; charset=utf-8"


def json_ok(data: Any, status: int = 200, headers: dict[str, str] | None = None) -> ApiJSONResponse:
    return ApiJSONResponse(data, status_code=status, headers=headers)


def json_error(
    code: str,
    message: str,
    status: int,
    fields: dict[str, str] | None = None,
    headers: dict[str, str] | None = None,
) -> ApiJSONResponse:
    error: dict[str, Any] = {"code": code, "message": message}
    if fields:
        error["fields"] = fields
    return ApiJSONResponse({"error": error}, status_code=status, headers=headers)


def no_content() -> Response:
    return Response(status_code=204)
