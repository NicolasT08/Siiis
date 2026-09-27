# CLAUDE.md — SIIIS V3 · Backend Python (fase 1)

Réplica funcional **exacta** de la fase 1 del backend PHP oficial (otro proyecto), en Python + FastAPI.
El frontend debe poder cambiar de backend **solo cambiando la URL base**.

## Fuentes de verdad (en este orden)

1. `docs/api.md`: contrato exacto implementado en PHP. **No modificarlo.** Si contradice al spec, gana `api.md`.
2. `docs/decisions.md`: decisiones DEC-B01 a DEC-B15 del backend PHP.
3. `docs/SIIIS_V3_SPEC_DRIVEN.md`: requisitos, modelo de datos (sección 8) y seguridad (sección 18). Ignorar las partes exclusivas de PHP/Hostinger (0.5, 0.6, 10.13, 16, 17.3–17.7).
4. `docs/PROMPT_CLAUDE_CODE_BACKEND_PYTHON_FASE1.md`: alcance e instrucciones de esta fase.
5. `docs/decisions-python.md`: diferencias con PHP y decisiones nuevas (`DECISION_REQUIRED` / `TODO_SPEC`).
6. Código PHP de referencia: `D:\NickG\Documentos\Programacion\Uni\PHP\SIIIS\backend` (textos exactos
   de `message`/`fields` y comportamiento). **Solo lectura**: no modificar nada, no leer su
   `backend/.env` ni ejecutar git en ese proyecto.

## Módulos (`app/`)

`application.py` (crea la app) · `main.py` (entrada de uvicorn) · `config.py` · `container.py`
(dependencias, sustituibles en pruebas) · `errors.py` · `responses.py` · `request_body.py`
(JsonBodyMiddleware) · `cors.py` · `validation.py` (InputReader + validadores) · `security.py` ·
`serializers.py` · `database.py` · `logger.py` · `clock.py` · `client_ip.py` · `auth.py`
(AuthMiddleware) · `rate_limit.py` · `mail.py` · `repositories/` · `services/` · `routers/`.
Dobles de prueba en `tests/fakes.py`; entorno de pruebas de auth en `tests/api/auth_env.py`.

## Stack

Python 3.14 (mínimo 3.12) · FastAPI + Uvicorn · Pydantic v2 + pydantic-settings · PyMongo (síncrono) ·
PyJWT (HS256) · bcrypt · smtplib · pytest + httpx · ruff · mypy (strict).

## Comandos (PowerShell, desde la raíz)

```powershell
py -3.14 -m venv .venv                                  # crear entorno
.venv\Scripts\python -m pip install -r requirements-dev.txt
.venv\Scripts\uvicorn app.main:app --reload --port 8001 --no-proxy-headers  # PHP usa 8000
.venv\Scripts\python -m pytest                          # pruebas (-m "not integration": sin Atlas)
.venv\Scripts\python -m scripts.crear_superadmin --test # superadmin en MONGO_DB_NAME_TEST
.venv\Scripts\ruff check .                              # lint
.venv\Scripts\ruff format --check .                     # formato
.venv\Scripts\mypy                                      # tipos
```

Al terminar cada paso, los cuatro chequeos deben pasar sin errores.

## Compatibilidad con el backend PHP (obligatoria)

- Misma base de datos Atlas: colecciones y campos de la sección 8.2 del spec, nombres en `app/database.py`.
- Mismas rutas, cuerpos, status y códigos de error que `docs/api.md`; errores con formato `{"error": {"code", "message", "fields"?}}`.
- Hashes bcrypt `$2y$` (PHP) y `$2b$` (Python) intercambiables.
- JWT HS256 con claims `sub`, `rol`, `jti`, `iat`, `exp` (máx. 28800 s) y el mismo `JWT_SECRET`.
- `Sesiones.token` = SHA-256 hex del `jti`; token de recuperación = 32 bytes hex, guardado como SHA-256.
- **No crear ni modificar índices** en Atlas.
- Mismos nombres de variables en `.env` (un solo `.env` sirve a los dos backends).

## Reglas

- No inventar reglas: lo que falte va a `docs/decisions-python.md` como `DECISION_REQUIRED` o `TODO_SPEC`.
- **Nunca leer ni mostrar valores del `.env`**; solo comprobar si una variable tiene valor.
- Commits pequeños, uno por paso, en español. Sin secretos en Git.
- Pruebas de integración solo contra `MONGO_DB_NAME_TEST`; se niegan a correr si el nombre es `siiis`.
- No desplegar nada.
