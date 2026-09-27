# SIIIS V3 — Backend Python (fase 1)

Réplica en **Python + FastAPI** de la fase 1 del backend PHP oficial del Semillero SIIIS (UPTC).
Tiene el mismo contrato (`docs/api.md`), usa la misma base de datos en MongoDB Atlas y aplica los
mismos códigos de error y las mismas reglas de negocio. El frontend puede usar cualquiera de los
dos backends cambiando solo la URL base:

| Backend | URL base en desarrollo |
|---|---|
| PHP (oficial) | `http://localhost:8000/api/v1` |
| Python (este) | `http://localhost:8001/api/v1` |

Endpoints: `GET /health`, `POST /auth/login`, `GET /auth/me`, `POST /auth/logout`,
`POST /auth/forgot-password`, `POST /auth/reset-password`, `GET /home/slider`, `GET /home/videos`.

## Requisitos

- Python 3.12 o superior (probado con 3.14.7).
- Acceso a MongoDB Atlas (la misma URI que usa el backend PHP).

## Instalación

```powershell
py -3.14 -m venv .venv
.venv\Scripts\python -m pip install -r requirements-dev.txt   # o requirements.txt en producción
```

## Configuración (`.env`)

Copia `.env.example` a `.env` en la raíz y completa los valores. Las variables tienen **los mismos
nombres que en el backend PHP**, así que puedes usar el mismo archivo para los dos.

| Variable | Uso |
|---|---|
| `MONGO_URI` | Obligatoria. URI `mongodb+srv://` de Atlas |
| `MONGO_DB_NAME` / `MONGO_DB_NAME_TEST` | Base principal (`siiis`) y base para las pruebas de integración (`siiis_test`, siempre distinta) |
| `JWT_SECRET` | Obligatoria, ≥ 32 bytes. **La misma que en PHP**, para que los tokens sirvan en los dos backends |
| `JWT_TTL_SECONDS` | Duración del token; se recorta a 28800 (8 h) |
| `APP_ENV` | `development` activa CORS para `FRONTEND_ORIGIN`; por defecto `production` |
| `MAIL_*` | SMTP de Gmail con contraseña de aplicación (recuperación de contraseña) |
| `RATE_LIMIT_*`, `PASSWORD_*`, `HOME_MAX_ITEMS` | Mismos significados y valores por defecto que en PHP |

Si falta `MONGO_URI` o `JWT_SECRET`, o el secreto es corto, la aplicación **no arranca** y
muestra qué variable falla, sin mostrar su valor.

## Ejecutar en local

```powershell
.venv\Scripts\uvicorn app.main:app --reload --port 8001 --no-proxy-headers
curl http://localhost:8001/api/v1/health
```

`--no-proxy-headers` hace que la IP del cliente (para el rate limit y `Sesiones.ip_acceso`) sea la
de la conexión, como `REMOTE_ADDR` en PHP (DEC-P13).

## Pruebas y calidad

```powershell
.venv\Scripts\python -m pytest                       # unitarias + API + integración
.venv\Scripts\python -m pytest -m "not integration"  # sin Atlas
.venv\Scripts\ruff check .
.venv\Scripts\ruff format --check .                  # ruff format . para corregir
.venv\Scripts\mypy
```

- **Unitarias** (`tests/unit`): bcrypt (incluida la compatibilidad con hashes `$2y$` de PHP),
  JWT (incluido un token emitido por PHP), tokens, serializador, validación, cuerpo JSON y
  configuración.
- **API** (`tests/api`): la app completa con repositorios, reloj y correo en memoria. Replican las
  pruebas de API del backend PHP.
- **Integración** (`tests/integration`): contra `MONGO_DB_NAME_TEST` en Atlas. Se saltan si no hay
  `MONGO_URI` o conexión y nunca se ejecutan contra la base `siiis`. Cada prueba borra los datos
  que crea.

## Scripts

```powershell
.venv\Scripts\python -m scripts.crear_superadmin          # base principal
.venv\Scripts\python -m scripts.crear_superadmin --test   # MONGO_DB_NAME_TEST
```

Hace lo mismo que `crear_superadmin.php`: pide correo, nombre, apellido y contraseña (sin eco) y
crea `Credenciales` + `Usuarios` en una transacción. **Este proyecto no crea índices**: los índices
oficiales los crea el backend PHP con `php scripts/crear_indices.php` (DEC-P15).

## Estructura

```text
app/
├── main.py             # entrada de uvicorn (falla al arrancar si el .env no es válido)
├── application.py      # crea FastAPI: rutas /api/v1, errores, CORS
├── config.py           # Settings (mismas reglas que Settings.php)
├── container.py        # dependencias (sustituibles en pruebas)
├── errors.py           # formato {"error": {...}} y manejadores
├── request_body.py     # lectura del JSON (como JsonBodyMiddleware)
├── validation.py       # InputReader + validadores con los textos de PHP
├── security.py         # bcrypt, JWT, tokens
├── auth.py             # JWT + sesión activa (como AuthMiddleware)
├── rate_limit.py, mail.py, serializers.py, database.py, cors.py, logger.py, ...
├── repositories/       # Credenciales, Usuarios, Sesiones, Multimedia
├── services/           # auth_service, password_reset_service
└── routers/            # health, auth, home
scripts/crear_superadmin.py
tests/{unit,api,integration}/
docs/                   # api.md, decisions.md (PHP), decisions-python.md, spec
```

## Diferencias con el backend PHP

Ninguna es visible en el contrato. Están documentadas en `docs/decisions-python.md`: PyJWT,
`bcrypt` directo, `smtplib`, el rate limit en memoria y la validación manual, entre otras.

## Prueba cruzada PHP ↔ Python (manual)

Requiere las dos apps corriendo con el mismo `MONGO_URI`, `MONGO_DB_NAME` y `JWT_SECRET`.

```powershell
# Terminal 1 — backend PHP (puerto 8000)
cd D:\NickG\Documentos\Programacion\Uni\PHP\SIIIS\backend
php scripts/crear_indices.php        # solo si la base aún no tiene los índices
php scripts/crear_superadmin.php     # crea el usuario con hash $2y$
composer serve

# Terminal 2 — backend Python (puerto 8001)
cd D:\NickG\Documentos\Programacion\Uni\Python\Siis
.venv\Scripts\uvicorn app.main:app --port 8001 --no-proxy-headers

# Terminal 3 — pruebas (PowerShell)
$cuerpo = @{ correo = "<correo>"; password = "<contraseña>" } | ConvertTo-Json
# 1) Usuario creado por PHP → login en Python
$py = Invoke-RestMethod -Method Post -Uri http://localhost:8001/api/v1/auth/login -ContentType "application/json" -Body $cuerpo
# 2) Token emitido por Python → /auth/me en PHP
Invoke-RestMethod -Uri http://localhost:8000/api/v1/auth/me -Headers @{ Authorization = "Bearer $($py.token)" }
# 3) Al revés: token de PHP → /auth/me en Python
$php = Invoke-RestMethod -Method Post -Uri http://localhost:8000/api/v1/auth/login -ContentType "application/json" -Body $cuerpo
Invoke-RestMethod -Uri http://localhost:8001/api/v1/auth/me -Headers @{ Authorization = "Bearer $($php.token)" }
# 4) Logout en un backend invalida la sesión en el otro (debe dar 401)
Invoke-RestMethod -Method Post -Uri http://localhost:8001/api/v1/auth/logout -Headers @{ Authorization = "Bearer $($php.token)" }
Invoke-RestMethod -Uri http://localhost:8000/api/v1/auth/me -Headers @{ Authorization = "Bearer $($php.token)" }
```
