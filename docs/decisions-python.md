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
- Decisión: librería `bcrypt` 5.x con **coste 10** (el de `password_hash()` en PHP 8.2; el valor por defecto de la librería Python es 12). Genera hashes `$2b$` y verifica los `$2y$` de PHP (el algoritmo es el mismo).
- Verificado con PHP 8.2 local: `password_verify()` acepta los hashes `$2b$` de Python, y Python acepta los `$2y$` de PHP (prueba unitaria con un hash real).
- `needs_rehash`: Python considera vigentes `$2y$` y `$2b$` de coste 10. PHP, en cambio, marca `$2b$` como algoritmo "desconocido" y lo rehashea a `$2y$` en su siguiente login. No pasa nada malo, pero si Python aplicara la misma regla volvería a hashear sus propias contraseñas en cada login.
- Requiere migración: no.

## DEC-P09 — Contraseñas de más de 72 bytes y con NUL (igual que PHP)

- Estado: APROBADA (backend, 2026-09-27)
- Contexto: `bcrypt` 5 lanza `ValueError` con más de 72 bytes. PHP 8.2 los recorta en silencio al verificar y al hashear, y además `password_verify()` corta la contraseña en el primer byte NUL.
- Decisión: `app/security.py` recorta a 72 bytes y corta en el primer NUL antes de llamar a bcrypt, así que `verify()` da el mismo resultado que PHP en todos los casos. Como en PHP, `hash()` lanza `ValueError` si la contraseña tiene un NUL.
- **Hallazgo en PHP (reportar):** en `reset-password`, una contraseña con `\u0000` pasa la validación y `password_hash()` lanza `ValueError`, por lo que la respuesta es `500 INTERNAL_ERROR`. Python replica esa respuesta. Lo lógico sería rechazarla en el validador con `400 VALIDATION_ERROR`, pero el cambio debe hacerse en los dos backends a la vez.
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

- Estado: APROBADA (gana `docs/api.md`; confirmada por backend el 2026-09-27)
- Contexto: el prompt de la fase no incluye `METHOD_NOT_ALLOWED` en su lista de códigos, pero `docs/api.md` sí (405).
- Decisión: se implementa como en PHP: mensaje `Método HTTP no permitido para esta ruta.` y cabecera `Allow`. Starlette agrega `HEAD` a las rutas GET; se quita de `Allow` para que coincida con Slim.
- Otros detalles de enrutamiento copiados de Slim: `/ruta/` (con barra final) responde 404 en lugar de redirigir, y `/docs`, `/redoc` y `/openapi.json` están desactivados (404), porque PHP no los tiene.

## DEC-P08 — Aplicabilidad de las decisiones del backend PHP

| Decisión PHP | ¿Aplica en Python? |
|---|---|
| DEC-B01 (PHP-DI) | No aplica. Equivalente: `app/container.py` (dataclass en `app.state`); las pruebas lo construyen con dobles |
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

## DEC-P10 — Textos de `message` y `fields` copiados del backend PHP

- Estado: APROBADA (backend, 2026-09-27). Resuelve el antiguo TODO-P01.
- Contexto: `docs/api.md` no fija el `message` de cada error ni los textos de `fields`.
- Decisión: se copiaron literalmente de `backend/src` del proyecto PHP (`ApiException`, `ErrorHandler`, `JsonBodyMiddleware`, `InputReader` y los validadores). Se incluye el código `HTTP_ERROR`, que PHP usa para cualquier otro error HTTP del framework aunque no esté en `api.md`.

## DEC-P11 — Validación manual en lugar de modelos Pydantic

- Estado: PROPUESTA
- Contexto: el prompt sugiere Pydantic para validar la entrada y una carpeta `schemas/`, pero las respuestas deben ser idénticas a las de PHP: los mismos textos por campo, el mismo orden de las comprobaciones y la misma conversión de números a texto (`12345678` → `"12345678"`, `1.0` → `"1"`).
- Decisión: `app/validation.py` replica `InputReader` y los tres validadores de PHP. Pydantic solo se usa para la configuración. El cuerpo se lee en `app/request_body.py`, que replica `JsonBodyMiddleware`:
  - como máximo 31 niveles de anidamiento;
  - se rechazan `NaN`, el BOM, el UTF-8 inválido y los surrogates sueltos;
  - `application/x-www-form-urlencoded` en POST se lee como el `$_POST` de PHP.
- Formato de correo: `FILTER_VALIDATE_EMAIL` de PHP portado como expresión regular. Se comparó con PHP 8.2 en ~23 000 casos generados, sin diferencias.
- Diferencia conocida: PHP también lee `multipart/form-data` como `$_POST`; en Python ese cuerpo llega vacío (los campos salen como obligatorios). El frontend envía JSON, así que no le afecta.

## DEC-P12 — Configuración con las reglas de `Settings.php`

- Estado: APROBADA (backend)
- Decisión: se aplica `trim` a cada valor y un valor vacío toma el valor por defecto. `APP_ENV` vale por defecto `production`, como en PHP. `APP_DEBUG` solo es verdadero con `1/true/yes/on`. Los enteros deben ser positivos (solo dígitos). `JWT_SECRET` se mide en bytes (≥ 32) y `JWT_TTL_SECONDS` se recorta a 28800.
- Diferencia: PHP valida la configuración en cada petición y responde `500 CONFIG_ERROR`; Python no arranca (exigido por el prompt de la fase).

## DEC-P13 — IP del cliente y cabeceras de proxy

- Estado: PROPUESTA
- Contexto: PHP usa `REMOTE_ADDR` y no confía en `X-Forwarded-For`. Uvicorn, por defecto, sí acepta `X-Forwarded-For` cuando la petición viene de `127.0.0.1`.
- Decisión: `app/client_ip.py` usa la dirección de la conexión. Para igualar a PHP también en local, se recomienda arrancar con `--no-proxy-headers` (documentado en el README).

## DEC-P14 — `httpx` para `TestClient`

- Estado: PROPUESTA
- Contexto: Starlette 1.x muestra un aviso de obsolescencia al usar `httpx` en `TestClient` y sugiere `httpx2`. El prompt fija `httpx`.
- Decisión: se mantiene `httpx==0.28.1` (funciona) y el aviso se silencia en `pyproject.toml`. Cambiar a `httpx2` es una decisión del equipo.

## DEC-P15 — Sin script de índices en Python

- Estado: APROBADA (prompt de la fase: "no crees índices nuevos ni con opciones distintas")
- Decisión: este proyecto no crea ni modifica índices. Los índices oficiales los crea `php scripts/crear_indices.php` (`correo_unico`, `credencial_id_unico`, `categoria_estado`, `estado_solicitud`, `token` y `fecha_expiracion_ttl` con `expireAfterSeconds: 0`). Una prueba de integración comprueba que el índice único `correo_unico` existe en `siiis_test`.
- **Hallazgo (2026-09-27, solo lectura):** en `siiis_test` están los 6 índices oficiales. En la base principal `siiis` no hay ninguno (las colecciones todavía no existen), así que `crear_indices.php` solo se ha ejecutado con `--test`. Antes de crear usuarios reales hay que ejecutarlo contra `siiis` desde el proyecto PHP.

## DEC-P16 — `crear_superadmin` en Python

- Estado: PROPUESTA
- Decisión: `python -m scripts.crear_superadmin [--test]` escribe los mismos campos y aplica las mismas validaciones y la misma transacción que `crear_superadmin.php`. El hash sale con prefijo `$2b$`: PHP lo verifica (comprobado) y lo reescribe como `$2y$` en su primer login (DEC-P03).
- No se replica `seed_multimedia.php`, que no es obligatorio en la fase 1.

## DEC-P17 — Detalles del correo

- Estado: APROBADA (backend)
- Decisión: mismo asunto, HTML y texto que PHP (`htmlspecialchars` con `&apos;`). El texto alternativo sin HTML se genera igual que `strip_tags()` de PHP, que no decodifica las entidades HTML (`&amp;` se queda así). En recuperación de contraseña siempre se envía el texto explícito, así que esto solo importa si otro módulo usa el servicio de correo.
