# Prompt para Claude Code — SIIIS V3 Backend PHP · Fase 1 (bootstrap + login + página principal)

> Copia todo lo que está debajo de la línea en Claude Code, abierto en la raíz del repositorio `siiis-v3/`.
> Antes de ejecutarlo, deja el archivo `SIIIS_V3_SPEC_DRIVEN.md` en `docs/` (o en la raíz y ajusta la ruta).

---

Eres el agente de desarrollo backend del proyecto **SIIIS V3** (sitio web del Semillero SIIIS, UPTC). Trabajamos con **Spec-Driven Development**. La fuente de verdad es `docs/SIIIS_V3_SPEC_DRIVEN.md`. **Léelo completo antes de escribir código**, en especial las secciones 0, 7.2, 8, 9, 10 (incluida 10.13), 11, 12, 13, 15, 16, 17.4, 18 y 20.

## 1. Contexto fijo (no negociable)

- **Solo trabajas en `backend/`** (y en `docs/` para documentación). **No toques `frontend/`.**
- **Backend:** PHP 8.2+, Slim 4, Composer, PSR-4 (`Siiis\`), PSR-12, `declare(strict_types=1);`.
- **Base de datos:** MongoDB Atlas (remota) con la extensión `mongodb` + `mongodb/mongodb`. **No cambies el modelo de datos**: usa exactamente las colecciones y campos de la sección 8.2 (`Credenciales`, `Usuarios`, `Multimedia`, `Sesiones`, etc., con tildes y mayúsculas tal cual). Centraliza los nombres en una clase `Collections`.
- **Multimedia:** Cloudinary (en esta fase solo se leen URLs guardadas en `Multimedia`; no hay subidas).
- **Correo:** PHPMailer vía SMTP (Gmail).
- **Hosting:** Hostinger plan básico (hosting compartido): sin procesos persistentes, `.htaccess`, código fuera de `public_html` (sección 0.6 y 17.4).
- **Frontend:** React + TypeScript (otro integrante). Tú solo le entregas el contrato de la API.
- **Login con Google: FUERA de esta fase.** No lo implementes; deja la ruta sin registrar.

## 2. Reglas de trabajo

1. No inventes reglas de negocio. Lo que no esté en el spec márcalo `DECISION_REQUIRED` o `TODO_SPEC` en `docs/decisions.md` y sigue con lo que no dependa de ello.
2. Antes de programar, crea un `CLAUDE.md` en la raíz con: resumen del stack, comandos (instalar, servir, testear, lint), reglas de este prompt y la ruta del spec, para que futuras sesiones lo sigan.
3. Muéstrame un **plan corto** (archivos a crear, orden y dudas) y **espera mi aprobación** antes de implementar.
4. Trabaja por pasos y haz **un commit pequeño por paso** con mensajes descriptivos en español.
5. Ningún secreto en el repo. Solo `.env.example`.
6. Al terminar cada paso, corre pruebas + `php-cs-fixer` + `phpstan`. No avances con errores.

## 3. Verificación inicial del entorno (paso 0)

- Ejecuta `php -v`, `php -m` y `composer -V`. Confirma que existen las extensiones `mongodb`, `fileinfo`, `curl`, `mbstring` y `openssl`. Si falta algo, **detente y dime qué instalar**.
- Anota la versión de la extensión `mongodb` local. En `composer.json`, fija `mongodb/mongodb` a una versión compatible y configura `config.platform` (`php` y `ext-mongodb`) con la versión de **producción de Hostinger**. Como aún no la conozco, pon `TODO_SPEC: confirmar versión de ext-mongodb en Hostinger` en `docs/deployment.md` y usa provisionalmente la versión local.

## 4. Alcance de esta fase

### 4.1 Bootstrap (Fase técnica 0 del spec)

Crea la estructura de la sección 9 del spec, solo con lo necesario para esta fase:

```text
backend/
├── public/index.php, public/.htaccess
├── src/
│   ├── Config/Settings.php, Config/Routes.php, Config/Container.php (si usas PHP-DI; si no, fábrica simple)
│   ├── Database/Mongo.php, Database/Collections.php
│   ├── Security/PasswordHasher.php, JwtService.php, RateLimiter.php
│   ├── Middleware/AuthMiddleware.php, ErrorHandler.php, CorsMiddleware.php (solo desarrollo), JsonBodyMiddleware.php
│   ├── Services/MailService.php
│   ├── Support/JsonResponse.php, Serializer.php, TokenGenerator.php, ObjectIdValidator.php
│   ├── Validation/LoginValidator.php, ForgotPasswordValidator.php, ResetPasswordValidator.php
│   ├── Repositories/CredencialesRepository.php, UsuariosRepository.php, SesionesRepository.php, MultimediaRepository.php
│   └── Controllers/HealthController.php, AuthController.php, HomeController.php
├── scripts/crear_indices.php, crear_superadmin.php, seed_multimedia.php
├── storage/ (logs/, ratelimit/, cache/ — con .gitkeep, contenido ignorado)
├── tests/Unit/, tests/Integration/, tests/Api/
├── .env.example, composer.json, phpunit.xml, phpstan.neon, .php-cs-fixer.php, README.md
```

- Slim con `setBasePath('/api')` y todas las rutas bajo `/v1`.
- `ErrorHandler` global: JSON `{"error": {"code": "...", "message": "..."}}`, sin trazas si `APP_DEBUG=false`.
- `CorsMiddleware` activo solo si `APP_ENV=development`, con origen `FRONTEND_ORIGIN` y respuesta `204` al preflight `OPTIONS`.
- `backend/public/.htaccess` debe incluir la regla que pasa la cabecera `Authorization` a PHP (sección 17.4).

### 4.2 Módulo de login (sin Google)

Requisitos: RF-02, RF-04, RNF-05, secciones 12.1–12.3 y 10.13.6 del spec. El diseño de la pantalla tiene: correo, contraseña, “Remember me”, “Forgot Password?” y botón “Inicia Sesión”.

Endpoints:

| Método y ruta | Auth | Entrada | Respuesta OK | Errores |
|---|---|---|---|---|
| `GET /api/v1/health` | No | — | `200 {"status":"ok","db":"ok"}` | `503 DB_UNAVAILABLE` |
| `POST /api/v1/auth/login` | No | `{"correo","password"}` | `200 {"token","expira_en","usuario":{"id","nombre","apellido","rol","foto_perfil"}}` | `400 VALIDATION_ERROR`, `401 INVALID_CREDENTIALS` (mensaje genérico, sin revelar si el correo existe), `403 ACCOUNT_BLOCKED`, `403 ACCOUNT_INACTIVE`, `429 RATE_LIMITED` |
| `GET /api/v1/auth/me` | Sí | — | `200 {"usuario":{...}}` | `401 UNAUTHORIZED` |
| `POST /api/v1/auth/logout` | Sí | — | `204` | `401` |
| `POST /api/v1/auth/forgot-password` | No | `{"correo"}` | `200` con mensaje genérico **siempre** (exista o no el correo) | `400`, `429` |
| `POST /api/v1/auth/reset-password` | No | `{"token","password"}` | `200` | `400 INVALID_OR_EXPIRED_TOKEN`, `400 VALIDATION_ERROR` |

> `logout` no estaba en el contrato original (11.3). Impleméntalo y regístralo en `docs/decisions.md` como `PROPUESTA` pendiente de aprobación del equipo.

Reglas de implementación:

- **Contraseñas:** `password_hash(PASSWORD_BCRYPT)` / `password_verify()`, con rehash si hace falta.
- **Login:** buscar en `Credenciales` por `correo` (normalizado a minúsculas y sin espacios) → verificar `estado_cuenta` → verificar el hash → cargar `Usuarios` por `credencial_id` → verificar `estado == true` → emitir el JWT → crear la sesión → actualizar `ultimo_acceso` y reiniciar `intentos_fallidos`.
- **Intentos fallidos:** incrementar `intentos_fallidos`. Al llegar a `MAX_LOGIN_ATTEMPTS` (configurable), el comportamiento exacto (bloqueo temporal o `estado_cuenta = bloqueada`) es `DECISION_REQUIRED`. Implementa el contador y deja el umbral en config, sin bloquear permanentemente hasta que se decida.
- **JWT:** `firebase/php-jwt`, HS256, claims `sub` (id de `Usuarios`), `rol`, `jti` aleatorio, `iat`, `exp = iat + JWT_TTL_SECONDS` (máximo 28800). Si `JWT_TTL_SECONDS > 28800`, recórtalo a 28800.
- **Sesiones:** insertar en `Sesiones` los campos `usuario_id`, `token` = **hash SHA-256 del `jti`** (nunca el JWT completo), `fecha_inicio`, `fecha_expiracion` (UTCDateTime), `ip_acceso` y `activa: true`.
- **AuthMiddleware:** valida firma y `exp`, busca la sesión por hash del `jti` con `activa: true` y `fecha_expiracion > ahora`, y adjunta `usuario_id` y `rol` a la request. El rol **nunca** viene del cuerpo de la petición.
- **Remember me:** **no cambia la duración** (la sesión dura como máximo 8 h por RNF-05). El backend lo ignora; que el frontend decida dónde guardar el token. Anótalo en `docs/api.md`.
- **Recuperación de contraseña:** token de `bin2hex(random_bytes(32))`. En `Credenciales.token_recuperacion` guarda **solo su hash SHA-256**, con `token_expiracion` = ahora + `PASSWORD_RESET_TTL_MINUTES`. El correo lleva el enlace `PASSWORD_RESET_URL?token=...` (la ruta del frontend queda en config como `TODO_SPEC`). Al restablecer: comparar con `hash_equals`, guardar el nuevo hash, borrar el token y **desactivar todas las sesiones** del usuario.
- **Política de contraseña:** mínimo de longitud configurable (`PASSWORD_MIN_LENGTH`). Las reglas exactas son `TODO_SPEC`.
- **Rate limiting** (en archivos de `storage/ratelimit/`, por IP): `login` y `forgot-password`, con límites en `.env`.
- **Correo:** si el SMTP falla en `forgot-password`, registra el error en el log y responde igual el mensaje genérico.
- **Antiinyección NoSQL:** castea a `string` todos los valores de entrada antes de usarlos en filtros y rechaza arrays u objetos donde se espera texto.
- **Respuestas:** pasan por `Serializer` (ObjectId → string, UTCDateTime → ISO 8601) y nunca incluyen `password`, `token_recuperacion` ni `token`.

### 4.3 Módulo de página principal (Home)

Requisitos: RF-07 (slider) y RF-11 (videos), colección `Multimedia` (sección 8.2). El diseño tiene: hero con botón “Inscríbete”, un carrusel de imágenes, bloques alternados de video/imagen y texto (acróstico S-I-I-I-S), una sección de llamada a la acción y un footer.

| Método y ruta | Auth | Respuesta OK |
|---|---|---|
| `GET /api/v1/home/slider` | No | `200 {"data":[{"id","tipo","url","descripcion","orden"}]}` — `seccion = slider_principal`, ordenado por `orden` ascendente |
| `GET /api/v1/home/videos` | No | `200 {"data":[...]}` — `seccion = video_presentacion`, ordenado por `orden` |

- Usa proyección: devuelve solo los campos listados (no `public_id`).
- Aplica un tope de resultados configurable (`HOME_MAX_ITEMS`).
- Añade la cabecera `Cache-Control: public, max-age=300` (son datos públicos que cambian poco).
- **No** crees endpoints nuevos para los bloques S-I-I-I-S, el hero ni el footer. Si esos bloques necesitan datos del backend (textos, imágenes de `galeria`), regístralo como `DECISION_REQUIRED` en `docs/decisions.md`. El texto puede ser estático en el frontend.
- **No** implementes todavía las rutas de administración de `Multimedia` (subir/eliminar). Para poblar datos usa `scripts/seed_multimedia.php`, con URLs de ejemplo de Cloudinary configurables.
- El botón “Inscríbete” solo lleva a la página de inscripción (frontend). No requiere backend en esta fase.

### 4.4 Scripts CLI

- `scripts/crear_indices.php`: índices de la sección 8.4 y 10.13.5, idempotente, incluido el TTL de `Sesiones.fecha_expiracion`.
- `scripts/crear_superadmin.php`: crea el par `Credenciales` + `Usuarios` (rol `superadministrador`) **en una transacción**. Pide el correo y la contraseña por consola (sin eco para la contraseña) y falla si el correo ya existe.
- `scripts/seed_multimedia.php`: inserta ítems de ejemplo en `slider_principal` y `video_presentacion`. No duplica si ya existen.
- Todos los scripts deben negarse a ejecutarse desde la web (`PHP_SAPI !== 'cli'`).

## 5. Variables de entorno (`backend/.env.example`)

Incluye como mínimo:

```dotenv
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost:8000
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

`Settings.php` debe fallar al arrancar con un error claro si falta `MONGO_URI` o `JWT_SECRET`, o si `JWT_SECRET` mide menos de 32 caracteres.

## 6. Pruebas (PHPUnit)

- **Unitarias:** `PasswordHasher`; `JwtService` (emisión, expiración, firma inválida, recorte a 8 h); `Serializer`; validadores; `TokenGenerator`; `RateLimiter`.
- **API** (la app Slim en memoria, con repositorios y `MailService` sustituidos por dobles de prueba):
  - login OK / credenciales inválidas / cuenta bloqueada / usuario inactivo / payload inválido / payload con arrays (intento de inyección);
  - `/me` con y sin token, con sesión desactivada y con token expirado;
  - logout;
  - forgot-password responde igual exista o no el correo;
  - reset con token válido, inválido y expirado;
  - slider y videos ordenados, con la proyección correcta.
- **Integración** contra `MONGO_DB_NAME_TEST` en Atlas: se **saltan automáticamente** (`markTestSkipped`) si no hay `MONGO_URI` configurada. Nunca se ejecutan contra la base `siiis`.
- **Composer scripts:** `composer test`, `composer lint` (php-cs-fixer en modo `--dry-run`), `composer analyse` (phpstan nivel 6) y `composer serve` (`php -S localhost:8000 -t public`).

## 7. Documentación a entregar

- `backend/README.md`: requisitos, instalación, `.env`, cómo correr los scripts, servir, testear y cómo desplegar en Hostinger (resumen de la sección 17.7).
- `docs/api.md`: contrato exacto de los 8 endpoints de esta fase, con ejemplos de request/response y códigos de error, **para el integrante de frontend**.
- `docs/decisions.md`: todas las `DECISION_REQUIRED`, `TODO_SPEC` y `PROPUESTA` que surjan (como mínimo: logout, umbral de bloqueo, ruta de reset en el frontend, contenido de los bloques S-I-I-I-S y la versión de `ext-mongodb` en Hostinger).
- `docs/deployment.md`: la checklist de la sección 17.6 del spec, con casillas por marcar.

## 8. Criterios de aceptación de la fase

- [ ] `composer install` limpio; `composer test`, `composer lint` y `composer analyse` sin errores.
- [ ] `GET /api/v1/health` devuelve `ok` conectado a Atlas.
- [ ] Con un superadmin creado por script: login → `/me` → logout → `/me` da `401`.
- [ ] El flujo forgot/reset funciona de punta a punta (correo real recibido en desarrollo).
- [ ] `/home/slider` y `/home/videos` devuelven los datos sembrados, ordenados.
- [ ] Ninguna respuesta expone hashes, tokens ni `public_id`.
- [ ] No hay secretos en Git; `frontend/` quedó intacto.
- [ ] Documentación de la sección 7 creada.

## 9. Cuándo detenerte y preguntarme

- Falta una extensión de PHP o no conecta a Atlas.
- Algo del spec contradice este prompt.
- Una implementación requiere cambiar campos o colecciones de MongoDB.
- Una decisión bloquea el avance y no hay trabajo independiente que hacer.

Empieza por el paso 0 (verificación del entorno), luego lee el spec y preséntame el plan.
