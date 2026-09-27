# Prompt para Claude Code — SIIIS V3 Backend **Python** · Fase 1 (réplica del backend PHP)

> **Antes de usarlo:**
> 1. Crea la carpeta del proyecto Python (por ejemplo `SIIIS-python/`), separada del proyecto PHP.
> 2. Dentro, crea `docs/` y copia desde el proyecto PHP: `docs/SIIIS_V3_SPEC_DRIVEN.md`, `docs/api.md` y `docs/decisions.md`.
> 3. Copia `backend/.env` del proyecto PHP a `SIIIS-python/.env` (ver sección 5 del prompt).
> 4. Abre Claude Code en `SIIIS-python/` y pégale el mensaje de abajo, o deja este archivo en `docs/` y pídele que lo lea.

---

Eres el agente de desarrollo backend del proyecto **SIIIS V3** (sitio web del Semillero SIIIS, UPTC). Trabajamos con **Spec-Driven Development**.

## 1. Contexto: por qué existe este proyecto

- El backend **oficial** del proyecto está en **PHP** (otra carpeta, ya terminado para la fase 1), porque el hosting de producción (Hostinger plan básico) solo ejecuta PHP.
- Para la **primera entrega académica** se necesita **además** una versión del backend en **Python + FastAPI**, que era el stack original del proyecto.
- Este proyecto es una **réplica funcional exacta** de la fase 1 del backend PHP: **mismo contrato API, misma base de datos, mismos códigos de error y las mismas reglas de negocio**. El frontend debe poder cambiar de un backend al otro **solo cambiando la URL base**, sin tocar nada más.
- **No despliegues nada.** Este backend corre en local (y opcionalmente en Render si el equipo lo pide después).

## 2. Fuentes de verdad (léelas completas antes de escribir código)

1. `docs/api.md`: **contrato exacto** que implementó el backend PHP. Es la referencia principal de esta fase: rutas, cuerpos, respuestas, códigos HTTP y códigos de error deben ser **idénticos**.
2. `docs/decisions.md`: decisiones ya tomadas en la versión PHP (DEC-B01 a DEC-B15). Aplícalas igual. Las que son específicas de PHP (Slim, PHP-DI, Composer, Hostinger, `.htaccess`) no aplican; anótalo.
3. `docs/SIIIS_V3_SPEC_DRIVEN.md`: requisitos, modelo de datos (sección 8), seguridad (sección 18) y reglas generales. **Ignora las partes exclusivas de PHP/Hostinger** (secciones 0.5, 0.6, 10.13, 16 y 17.3–17.7). Para el stack, usa la columna **"Antes (Python)"** de la tabla 7.2, con los ajustes de la sección 3 de este prompt.

Si `docs/api.md` y el spec se contradicen, **gana `docs/api.md`**, porque es lo que ya consume el frontend. Anota la diferencia en `docs/decisions-python.md`.

## 3. Stack

| Necesidad | Librería | Nota |
|---|---|---|
| Lenguaje | Python 3.12 | Verifica con `python --version` |
| Framework | FastAPI + Uvicorn | Como el spec original |
| Validación | Pydantic v2 | Ojo: los errores deben devolverse con el **formato de `docs/api.md`**, no con el 422 por defecto de FastAPI |
| Configuración | `pydantic-settings` (lee `.env`) | |
| MongoDB | PyMongo (síncrono) | Misma Atlas, mismas colecciones |
| JWT | **PyJWT** | `PROPUESTA`: reemplaza a `python-jose`, que está sin mantenimiento; anótalo |
| Contraseñas | **`bcrypt`** (directo) | `PROPUESTA`: reemplaza a `passlib`, que está sin mantenimiento y falla con bcrypt ≥ 4.1; anótalo |
| Correo | `smtplib` + `email` de la librería estándar | En lugar de `yagmail`; mismo SMTP de Gmail |
| Rate limiting | Implementación propia (archivos o memoria del proceso) con los mismos límites del `.env` | `slowapi` es opcional si respeta el formato de error `429 RATE_LIMITED` y la cabecera `Retry-After` |
| Pruebas | pytest + `httpx` (`TestClient`) | Dobles en memoria para los repositorios; `mongomock` es opcional |
| Calidad | ruff (lint + formato) + mypy (modo estricto razonable) | |
| Dependencias | `requirements.txt` + `requirements-dev.txt` con versiones fijadas, entorno virtual `.venv` | |

## 4. Reglas de trabajo

1. No inventes reglas. Lo que falte márcalo `DECISION_REQUIRED` o `TODO_SPEC` en `docs/decisions-python.md`.
2. Antes de programar, crea un `CLAUDE.md` en la raíz con: el stack, los comandos (crear venv, instalar, correr, testear, lint), la regla de compatibilidad con el backend PHP y las rutas de los docs.
3. Preséntame un **plan corto** y **espera mi aprobación**.
4. Un commit pequeño por paso (inicializa Git si no existe), con mensajes en español.
5. **Nunca leas ni muestres los valores del `.env`.** Solo comprueba si una variable tiene valor.
6. Al terminar cada paso, corre `pytest`, `ruff check`, `ruff format --check` y `mypy`. No avances con errores.

## 5. Variables de entorno

Usa **exactamente los mismos nombres** que el backend PHP (ver `docs/api.md` o `.env.example` de PHP), para que un solo `.env` sirva a los dos:

```dotenv
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost:8001
FRONTEND_ORIGIN=http://localhost:5173
MONGO_URI=
MONGO_DB_NAME=siiis
MONGO_DB_NAME_TEST=siiis_test
JWT_SECRET=
JWT_TTL_SECONDS=28800
MAX_LOGIN_ATTEMPTS=5
PASSWORD_MIN_LENGTH=8
PASSWORD_RESET_TTL_MINUTES=30
PASSWORD_RESET_URL=http://localhost:5173/restablecer
RATE_LIMIT_LOGIN_MAX=10
RATE_LIMIT_LOGIN_WINDOW_SECONDS=900
RATE_LIMIT_FORGOT_MAX=5
RATE_LIMIT_FORGOT_WINDOW_SECONDS=3600
HOME_MAX_ITEMS=20
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="Semillero SIIIS"
CLOUDINARY_CLOUD_NAME=
CLOUDINARY_API_KEY=
CLOUDINARY_API_SECRET=
```

- El `.env` va en la raíz de este proyecto y **lo creo yo**. Tú creas `.env.example` sin valores.
- La app debe fallar al arrancar con un mensaje claro si falta `MONGO_URI` o `JWT_SECRET`, o si `JWT_SECRET` tiene menos de 32 caracteres.
- Puerto de desarrollo: **8001**, para poder correr el backend PHP (8000) y este al mismo tiempo.

## 6. Alcance: réplica de la fase 1

Endpoints (todos bajo `/api/v1`, con el comportamiento definido en `docs/api.md`):

- `GET /health` (hace ping a Atlas; `503 DB_UNAVAILABLE` si falla).
- `POST /auth/login`, `GET /auth/me`, `POST /auth/logout`.
- `POST /auth/forgot-password`, `POST /auth/reset-password`.
- `GET /home/slider`, `GET /home/videos`.

**Fuera de alcance:** login con Google, administración de Multimedia (subir/eliminar) y cualquier módulo de fases siguientes.

### 6.1 Compatibilidad con el backend PHP (obligatorio)

Los dos backends comparten la **misma base de datos**, así que deben leer y escribir los datos de la misma forma:

- **Colecciones y campos:** exactamente los de la sección 8.2 del spec, con los nombres oficiales (`Credenciales`, `Usuarios`, `Sesiones`, `Multimedia`, `Reseñas`, etc.). Centralízalos en un módulo de constantes.
- **Hash de contraseñas:** el backend PHP guarda hashes bcrypt con prefijo `$2y$`. Python debe **verificar** esos hashes, y los hashes que genere Python (`$2b$`) deben poder verificarse en PHP. Incluye una prueba unitaria con un hash `$2y$` real (genera uno con `php -r "echo password_hash('Prueba123!', PASSWORD_BCRYPT);"`, o pídemelo).
- **JWT:** mismos claims que PHP (`sub`, `rol`, `jti`, `iat`, `exp`), HS256, mismo `JWT_SECRET`, `exp` recortado a un máximo de 28800 s. Un token emitido por un backend debe ser aceptado por el otro.
- **Sesiones:** el campo `token` guarda el **hash SHA-256 del `jti`** (hex en minúsculas), igual que en PHP; `fecha_inicio` y `fecha_expiracion` como fecha BSON en UTC; mismos campos `usuario_id`, `ip_acceso` y `activa`.
- **Recuperación de contraseña:** mismo formato de token (32 bytes aleatorios en hex), mismo hash SHA-256 guardado en `Credenciales.token_recuperacion`, mismo `token_expiracion`.
- **Índices:** **no crees índices nuevos ni con opciones distintas**; ya existen en Atlas, creados por el backend PHP. Si haces un script de índices, debe usar exactamente las mismas claves y opciones (sección 8.4 del spec y el `scripts/crear_indices.php` descrito en `docs/api.md`/`docs/decisions.md`), para que Atlas no lance un conflicto de índices.
- **Formato de errores:** `{"error": {"code": "...", "message": "...", "fields": {...}}}`, con los mismos códigos (`VALIDATION_ERROR`, `INVALID_JSON`, `INVALID_CREDENTIALS`, `ACCOUNT_BLOCKED`, `ACCOUNT_INACTIVE`, `ACCOUNT_PENDING`, `UNAUTHORIZED`, `RATE_LIMITED`, `INVALID_OR_EXPIRED_TOKEN`, `DB_UNAVAILABLE`, `NOT_FOUND`, `INTERNAL_ERROR`). Sobrescribe los manejadores de excepciones de FastAPI para que el 422 de validación y el 404 salgan con este formato.
- **Serialización:** `ObjectId` → string en `id` (no `_id`); fechas → ISO 8601 UTC. Nunca devolver `password`, `token_recuperacion`, `token` ni `public_id`.

### 6.2 Reglas de negocio (idénticas a PHP)

- Login: normalizar el correo (minúsculas y sin espacios); si no existe, hacer una verificación bcrypt falsa para igualar los tiempos; verificar **primero la contraseña** y después `estado_cuenta` (`activa` / `bloqueada` → `ACCOUNT_BLOCKED` / `pendiente_verificacion` → `ACCOUNT_PENDING`) y `Usuarios.estado` (`false` → `ACCOUNT_INACTIVE`); contador `intentos_fallidos` sin bloqueo automático (DEC-B06); `ultimo_acceso` y reinicio del contador en un login correcto.
- "Recordarme": el backend lo ignora.
- `forgot-password`: siempre la misma respuesta genérica; solo envía el correo si la cuenta está activa; si el SMTP falla, lo registra en el log y responde igual.
- `reset-password`: `hash_equals`/`hmac.compare_digest`, guardar el nuevo hash, borrar el token y **desactivar todas las sesiones** del usuario.
- Rate limiting por IP en `login` y `forgot-password`, con `Retry-After`.
- Antiinyección NoSQL: rechazar arrays/objetos donde se espera texto (por ejemplo `{"correo": {"$ne": ""}}` → `VALIDATION_ERROR`).
- Home: proyección solo con `id`, `tipo`, `url`, `descripcion`, `orden`; orden por `orden` ascendente; tope `HOME_MAX_ITEMS`; cabecera `Cache-Control: public, max-age=300`.
- CORS: solo en `APP_ENV=development`, con origen `FRONTEND_ORIGIN`.

## 7. Estructura sugerida

```text
SIIIS-python/
├── app/
│   ├── main.py            # crea FastAPI, CORS, manejadores de error, routers
│   ├── config.py          # Settings (pydantic-settings)
│   ├── database.py        # cliente PyMongo único + constantes de colecciones
│   ├── security.py        # bcrypt, JWT, hash de jti/tokens
│   ├── rate_limit.py
│   ├── errors.py          # ApiError + manejadores con el formato de api.md
│   ├── serializers.py
│   ├── mail.py
│   ├── repositories/      # credenciales, usuarios, sesiones, multimedia
│   ├── services/          # auth_service, password_reset_service
│   ├── schemas/           # modelos Pydantic de entrada/salida
│   └── routers/           # health, auth, home
├── scripts/
│   └── crear_superadmin.py   # opcional: mismo comportamiento que el de PHP (transacción)
├── tests/
│   ├── unit/
│   ├── api/
│   └── integration/       # contra MONGO_DB_NAME_TEST; se saltan sin MONGO_URI; se niegan a correr si el nombre es "siiis"
├── docs/                  # copiados del proyecto PHP + decisions-python.md
├── .env.example
├── requirements.txt
├── requirements-dev.txt
├── pyproject.toml         # config de ruff, mypy y pytest
├── CLAUDE.md
└── README.md
```

## 8. Pruebas mínimas

- **Unitarias:** bcrypt (incluida la compatibilidad con `$2y$`), JWT (emisión, expiración, firma inválida, recorte a 8 h), hash de tokens, serializador y validaciones.
- **API** (TestClient con repositorios y correo en memoria): los mismos casos que la versión PHP.
  - Login OK, credenciales inválidas, bloqueada, pendiente, inactiva, payload inválido e intento de inyección.
  - `/me` con token válido, sin token, con sesión cerrada y con token expirado.
  - Logout.
  - `forgot-password` con respuesta genérica.
  - `reset-password` con token válido, inválido y expirado.
  - Slider y videos ordenados, con la proyección correcta.
  - Formato de error idéntico al de `docs/api.md`.
- **Integración** contra `siiis_test` en Atlas: flujo login → `/me` → logout → `/me` = 401.
- **Prueba cruzada (manual, al final):** un usuario creado con el script PHP puede hacer login en Python, y un token emitido por Python funciona en `GET /auth/me` del backend PHP (con ambos servidores corriendo en 8000 y 8001). Descríbeme los comandos para hacerla y no la des por cumplida sin mi confirmación.

## 9. Documentación a entregar

- `README.md`: requisitos, crear `.venv`, instalar, `.env`, correr (`uvicorn app.main:app --reload --port 8001`), testear y lint.
- `docs/decisions-python.md`: diferencias con PHP (PyJWT, bcrypt, smtplib, rate limiting) y cualquier `DECISION_REQUIRED` nueva.
- **No modifiques** `docs/api.md`. Si detectas algo que no se puede replicar igual, repórtamelo.

## 10. Criterios de aceptación

- [ ] `pytest`, `ruff check`, `ruff format --check` y `mypy` sin errores.
- [ ] `GET http://localhost:8001/api/v1/health` responde ok contra Atlas.
- [ ] Los 8 endpoints responden igual que los del backend PHP (mismos status, cuerpos y códigos de error).
- [ ] Compatibilidad de hashes `$2y$` / `$2b$` y de tokens entre los dos backends.
- [ ] Ningún índice nuevo ni modificado en Atlas.
- [ ] Sin secretos en Git.

## 11. Cuándo detenerte y preguntarme

- Python o pip no funcionan, o hay errores de certificados SSL: probablemente el antivirus Avast intercepta HTTPS; avísame.
- No conecta a Atlas.
- Algo de `docs/api.md` no se puede replicar exactamente en FastAPI.
- Una implementación requeriría cambiar colecciones, campos o índices.

Empieza verificando el entorno (`python --version`, `pip --version`, `git --version`), luego lee los docs y preséntame el plan.
