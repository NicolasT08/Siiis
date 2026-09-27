# Registro de decisiones — SIIIS V3 (backend Python, fase 1)

Complementa `docs/decisions.md` (backend PHP). Formato según la sección 29 del spec. Mismas etiquetas:
`DECISION_REQUIRED`, `TODO_SPEC`, `PROPUESTA`, `APROBADA`.

---

## DEC-P01 — Python 3.14

- Estado: APROBADA (backend, 2026-09-27)
- Contexto: el prompt de la fase indica Python 3.12 como mínimo; en el equipo de desarrollo está instalado 3.14.7.
- Decisión: `.venv` creado con **Python 3.14.7**. `pyproject.toml` declara `requires-python = ">=3.12"` y ruff/mypy apuntan a 3.12, para que el código siga siendo compatible con 3.12.
- Verificación: todas las dependencias se instalaron desde wheels para 3.14 (`--only-binary=:all:`), sin compilar nada.
- Requiere migración: no.

## DEC-P02 — PyJWT en lugar de `python-jose`

- Estado: PROPUESTA
- Contexto: el stack Python original (spec 7.2) usaba `python-jose`, que está sin mantenimiento.
- Decisión: `PyJWT` 2.x con HS256. Es compatible con `firebase/php-jwt` (DEC-B02): los dos usan el mismo `JWT_SECRET` y los mismos claims.
- Requiere migración: no.

## DEC-P03 — `bcrypt` directo en lugar de `passlib`

- Estado: PROPUESTA
- Contexto: `passlib` está sin mantenimiento y falla con `bcrypt` ≥ 4.1.
- Decisión: librería `bcrypt` 5.x. Genera hashes `$2b$` y verifica los `$2y$` de PHP (el algoritmo es el mismo).
- Nota: `bcrypt` 5 lanza `ValueError` con contraseñas de más de 72 bytes; PHP las trunca en silencio. Se manejará en `app/security.py` para igualar el comportamiento de PHP.
- Requiere migración: no.

## DEC-P04 — `smtplib` + `email` en lugar de `yagmail`/PHPMailer

- Estado: PROPUESTA
- Decisión: librería estándar, mismo servidor SMTP de Gmail y las mismas variables `MAIL_*` (DEC-B04).
- Requiere migración: no.

## DEC-P05 — Rate limiting en memoria del proceso

- Estado: PROPUESTA
- Contexto: PHP guarda los contadores en archivos (`storage/ratelimit/`) porque no mantiene estado entre peticiones. Uvicorn sí mantiene el proceso vivo.
- Decisión: ventana fija por IP en memoria, con los mismos límites del `.env` y la misma respuesta (`429 RATE_LIMITED` + `Retry-After`).
- Limitación: con varios *workers* cada uno tiene su propio contador, y los contadores se reinician al reiniciar el servidor. En desarrollo local se usa un solo proceso.
- Requiere migración: no.

## DEC-P06 — Ubicación del `.env` y puerto

- Estado: APROBADA (prompt de la fase)
- Decisión: el `.env` va en la raíz de este proyecto (lo crea el responsable de backend) y la app corre en el puerto **8001** (PHP usa 8000). `docs/api.md` indica `http://localhost:8000/api/v1` como URL de desarrollo, que corresponde al backend PHP; el frontend solo cambia la URL base.
- Requiere migración: no.

## DEC-P07 — `METHOD_NOT_ALLOWED` (405)

- Estado: APROBADA (gana `docs/api.md`)
- Contexto: el prompt de la fase no incluye `METHOD_NOT_ALLOWED` en su lista de códigos, pero `docs/api.md` sí (405).
- Decisión: se implementa como en `docs/api.md`.

## DEC-P08 — Aplicabilidad de las decisiones del backend PHP

| Decisión PHP | ¿Aplica en Python? |
|---|---|
| DEC-B01 (PHP-DI) | No aplica. Equivalente: dependencias de FastAPI (`Depends` + `dependency_overrides` en pruebas) |
| DEC-B02 (`firebase/php-jwt` ^7) | No aplica la librería; sí la regla `JWT_SECRET` ≥ 32 caracteres (ver DEC-P02) |
| DEC-B03 / DEC-B13 (`ext-mongodb` en Hostinger) | No aplica (PyMongo) |
| DEC-B04 (variables `MAIL_*`) | Aplica |
| DEC-B05 (`POST /auth/logout`) | Aplica |
| DEC-B06 (intentos fallidos sin bloqueo) | Aplica |
| DEC-B07 (orden de verificación del login) | Aplica |
| DEC-B08 (`pendiente_verificacion` → `ACCOUNT_PENDING`) | Aplica |
| DEC-B09 (recuperación solo para cuentas activas) | Aplica |
| DEC-B10 (`PASSWORD_RESET_URL`) | Aplica |
| DEC-B11 (política de contraseñas) | Aplica |
| DEC-B12 (contenido estático de la home) | Aplica (sin endpoints nuevos) |
| DEC-B14 (sin índice en `token_recuperacion`) | Aplica: no se crean índices |
| DEC-B15 (diferencia de tiempo en `forgot-password`) | Aplica. Python podría enviar el correo después de responder (`BackgroundTasks`), pero eso cambiaría el comportamiento respecto de PHP; se mantiene el envío síncrono |

## TODO-P01 — Textos exactos de los mensajes de error

- Estado: TODO_SPEC
- Contexto: `docs/api.md` fija `code`, status y algunos mensajes (`VALIDATION_ERROR`, respuestas de `forgot-password`/`reset-password`), pero no el `message` de cada error ni los textos de `fields`.
- Provisional: textos en español definidos en `app/errors.py`.
- Falta: copiar los textos del backend PHP para que los cuerpos sean idénticos.

## Nota — nombre del spec

El spec está en `docs/SIIIS_V3_SPEC_DRIVEN_1.md`, mientras que `docs/api.md` y `docs/decisions.md` lo citan como `docs/SIIIS_V3_SPEC_DRIVEN.md`.
