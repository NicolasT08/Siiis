# SIIIS V3 — Backend (API PHP)

API REST del sitio web del Semillero SIIIS (UPTC), bajo `/api/v1`. Spec: `../docs/SIIIS_V3_SPEC_DRIVEN.md`.
Contrato para el frontend: `../docs/api.md`. Decisiones pendientes: `../docs/decisions.md`.

**Stack:** PHP 8.2+ · Slim 4 · PHP-DI · MongoDB Atlas (`ext-mongodb` + `mongodb/mongodb`) · `firebase/php-jwt` ·
PHPMailer · phpdotenv · PHPUnit 11 · PHPStan (nivel 6) · PHP-CS-Fixer (PSR-12).

## Requisitos

- PHP ≥ 8.2 con las extensiones `mongodb` (≥ 1.21), `fileinfo`, `curl`, `mbstring`, `openssl` y `json`.
- Composer 2.
- Acceso a un clúster de MongoDB Atlas (con tu IP en *Network Access*).

Compruébalo con `php -v`, `php -m` y `php --ri mongodb`.

## Instalación

```bash
cd backend
composer install
cp .env.example .env      # y completa los valores
```

Variables mínimas en `.env`:

| Variable | Descripción |
|---|---|
| `MONGO_URI` | URI `mongodb+srv://` de Atlas (obligatoria) |
| `MONGO_DB_NAME` / `MONGO_DB_NAME_TEST` | Base principal (`siiis`) y base de pruebas de integración (`siiis_test`, siempre distinta) |
| `JWT_SECRET` | Obligatoria, ≥ 32 caracteres. Genera una con `php -r "echo bin2hex(random_bytes(32));"` |
| `JWT_TTL_SECONDS` | Duración del token; se recorta a 28800 (8 h) |
| `MAIL_*` | SMTP de Gmail con contraseña de aplicación (solo para recuperación de contraseña) |
| `PASSWORD_RESET_URL` | Ruta del frontend para restablecer la contraseña |

Si falta `MONGO_URI` o `JWT_SECRET`, o el secreto es corto, la API responde `500 CONFIG_ERROR`. El detalle aparece en la respuesta si `APP_DEBUG=true` y siempre en el log de errores de PHP.

## Scripts CLI

Todos se niegan a ejecutarse desde la web. Con `--test` usan `MONGO_DB_NAME_TEST`.

```bash
php scripts/crear_indices.php         # índices oficiales + TTL de Sesiones (idempotente)
php scripts/crear_superadmin.php      # pide correo, nombre, apellido y contraseña (sin eco)
php scripts/seed_multimedia.php       # ítems de ejemplo del slider y los videos (no duplica)
```

`seed_multimedia.php` usa `scripts/data/multimedia_seed.json` si existe (no versionado, para tus URLs reales de Cloudinary). Si no, usa `scripts/data/multimedia_seed.example.json`, con imágenes del cloud público `demo`. También acepta `--file=ruta.json`.

## Servir en local

```bash
composer serve     # php -S localhost:8000 -t public public/index.php
curl http://localhost:8000/api/v1/health
```

Con `APP_ENV=development` se activa CORS para `FRONTEND_ORIGIN` (por defecto `http://localhost:5173`).

## Pruebas y calidad

```bash
composer test      # PHPUnit: Unit + Api (dobles en memoria) + Integration (Atlas)
composer lint      # php-cs-fixer --dry-run (usa `composer lint:fix` para corregir)
composer analyse   # phpstan nivel 6
```

- **Unit** y **Api** no necesitan base de datos: la app Slim corre en memoria con repositorios, reloj y correo sustituidos por dobles.
- **Integration** usa `MONGO_DB_NAME_TEST` en Atlas. Se salta automáticamente si no hay `MONGO_URI` o conexión, y nunca se ejecuta contra la base principal. Cada prueba borra los datos que crea.

## Estructura

```text
backend/
├── bootstrap.php          # carga autoload + .env y construye la app
├── public/                # index.php (front controller) y .htaccess
├── src/
│   ├── Config/            # Settings, Container (PHP-DI), Routes, Application
│   ├── Controllers/       # Health, Auth, Home (delgados)
│   ├── Database/          # Mongo (cliente único), Collections (nombres oficiales)
│   ├── Middleware/        # AuthMiddleware, ErrorHandler, CorsMiddleware, JsonBodyMiddleware
│   ├── Repositories/      # consultas a Credenciales, Usuarios, Sesiones y Multimedia
│   ├── Security/          # PasswordHasher, JwtService, RateLimiter
│   ├── Services/          # AuthService, PasswordResetService, MailService
│   ├── Support/           # JsonResponse, Serializer, TokenGenerator, ObjectIdValidator, Logger, Clock
│   └── Validation/        # validadores de entrada (antiinyección NoSQL)
├── scripts/               # CLI: índices, superadmin, seed
├── storage/               # logs/, ratelimit/, cache/ (no versionado)
└── tests/                 # Unit/, Api/, Integration/, Support/ (dobles)
```

## Despliegue en Hostinger (resumen de la sección 17.7 del spec)

1. En hPanel activa PHP 8.2+ y la extensión `mongodb` (≥ 1.21, ver DEC-B03), y agrega la IP del servidor en Atlas.
2. Ejecuta `composer install --no-dev --optimize-autoloader` y sube el backend (sin `public/`) a `siiis-api/`, fuera de `public_html`.
3. Crea el `.env` de producción en `siiis-api/` con `APP_ENV=production` y `APP_DEBUG=false`, y da permisos de escritura a `storage/`.
4. Copia `public/index.php` y `public/.htaccess` a `public_html/api/` y ajusta `BOOTSTRAP_PATH` a `../../siiis-api/bootstrap.php`.
5. Ejecuta `crear_indices.php` y `crear_superadmin.php`, y verifica `GET /api/v1/health`.

La checklist completa está en `../docs/deployment.md`.
