# SIIIS V3 — Backend (API PHP)

API REST del sitio web del Semillero SIIIS (UPTC), bajo `/api/v1`. Spec: `../docs/SIIIS_V3_SPEC_DRIVEN.md`.
Contrato para el frontend: `../docs/api.md`. Decisiones pendientes: `../docs/decisions.md`.

**Stack:** PHP 8.2+ · Slim 4 · PHP-DI · MongoDB Atlas (`ext-mongodb` + `mongodb/mongodb`) · `firebase/php-jwt` ·
PHPMailer · Cloudinary (`cloudinary/cloudinary_php`) · phpdotenv · PHPUnit 11 · PHPStan (nivel 6) · PHP-CS-Fixer (PSR-12).

**Endpoints:** fase 1 (salud, login, sesión, recuperación de contraseña, Home), fase 2 (registro administrativo,
integrantes, edición, desactivación/reactivación y foto de perfil) y fase 3 (proyectos en `/projects`, artículos y
noticias en `/articles`, con revisión, publicación y archivos adjuntos) y fase 4 (reseñas en `/reviews` con
moderación, inscripciones en `/inscriptions` con documentos privados y contacto en `/contact` con buzón para
administradores). El detalle está en `../docs/api.md`.

## Requisitos

- PHP ≥ 8.2 con las extensiones `mongodb` (≥ 1.18; ver DEC-B03), `fileinfo`, `curl`, `mbstring`, `openssl` y `json`.
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
| `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY`, `CLOUDINARY_API_SECRET` | Cuenta de Cloudinary para las fotos de perfil y los adjuntos de documentos. Para los PDF, la cuenta debe tener activado "Allow delivery of PDF and ZIP files" (DEC-B40) |
| `CLOUDINARY_PERFILES_FOLDER` | Carpeta de las fotos (`siiis/perfiles`). Las pruebas usan siempre `siiis_test/perfiles` |
| `CLOUDINARY_DOCUMENTOS_FOLDER` | Carpeta de los adjuntos (`siiis/documentos`). Las pruebas usan siempre `siiis_test/documentos` |

Límites provisionales y configurables (todos tienen valor por defecto): `PERFIL_*` (longitudes del perfil, semestre,
teléfono y `PERFIL_FOTO_MAX_BYTES`, 2 MB; DEC-B21), `USERS_PER_PAGE_*` (DEC-B26), `DOCUMENTOS_PER_PAGE_*` (DEC-B41) y
`DOCUMENTO_*` (longitudes de título, descripción y contenido, integrantes por documento; DEC-B37 y DEC-B38; y
adjuntos: `DOCUMENTO_ADJUNTO_MAX_BYTES`, imágenes de 5 MB, `DOCUMENTO_PDF_MAX_BYTES`, PDF de 3 MB, y
`DOCUMENTO_ADJUNTOS_MAX`, 10; DEC-B40 y DEC-B42). Optimización de Cloudinary al subir (DEC-B42): `PERFIL_FOTO_LADO_PX`
(400, recorte cuadrado), `DOCUMENTO_IMAGEN_MAX_ANCHO_PX` (1600, sin agrandar) y `CLOUDINARY_CALIDAD` (`auto`); al
responder, la API entrega las imágenes con `f_auto,q_auto`.

Si falta `MONGO_URI` o `JWT_SECRET`, o el secreto es corto, la API responde `500 CONFIG_ERROR`. El detalle aparece en la respuesta si `APP_DEBUG=true` y siempre en el log de errores de PHP. Sin `CLOUDINARY_*` la API arranca, pero subir una foto responde `502 CLOUDINARY_UNAVAILABLE`; sin `MAIL_*`, `forgot-password` responde igual que siempre y el fallo queda en `storage/logs`.

> **Credenciales de desarrollo:** los valores de `MAIL_*` y `CLOUDINARY_*` de los equipos de desarrollo son cuentas personales. En producción se usan las del semillero.

## Scripts CLI

Todos se niegan a ejecutarse desde la web. Con `--test` usan `MONGO_DB_NAME_TEST`.

```bash
php scripts/crear_indices.php         # índices oficiales + TTL de Sesiones (idempotente)
php scripts/crear_superadmin.php      # pide correo, nombre, apellido y contraseña (sin eco)
php scripts/seed_multimedia.php       # ítems de ejemplo del slider y los videos (no duplica)
php cron/limpiar_ratelimit.php        # borra contadores de rate limiting vencidos (cron diario)
```

`seed_multimedia.php` usa `scripts/data/multimedia_seed.json` si existe (no versionado, para tus URLs reales de Cloudinary). Si no, usa `scripts/data/multimedia_seed.example.json`, con imágenes del cloud público `demo`. También acepta `--file=ruta.json`.

### Datos de demostración (`seed_demo.php`)

```bash
php scripts/seed_demo.php --test --password=<contraseña>   # siembra; al final imprime las cuentas
php scripts/seed_demo.php --test --limpiar                 # borra todo lo sembrado (Atlas y Cloudinary)
```

- **Solo corre contra `siiis_test`**: con cualquier otra base termina con error sin escribir nada. Solo sube
  archivos a la carpeta `siiis_test/demo` de Cloudinary y solo borra allí.
- Siembra 8 integrantes (uno por rol, más 3 estudiantes y un egresado inactivo), 6 proyectos, 6 artículos y
  noticias en distintos estados y visibilidad, 10 reseñas (3 ocultas), 5 mensajes de contacto, 3 inscripciones
  pendientes con PDF privados, 3 imágenes en `slider_principal`, 3 en `galeria` y un video en `video_presentacion`.
- `--password` es la misma para todas las cuentas y sigue la política de contraseñas (DEC-B11). Es idempotente: si
  se vuelve a correr no duplica nada y les pone esa contraseña a las cuentas que ya existían.
- Los correos son `demo.*@example.com` (dominio reservado): los correos que mande la API, por ejemplo al aceptar
  una inscripción, nunca llegan a una persona real. `--limpiar` reconoce los datos por ese prefijo.
- Las imágenes y los PDF los genera el script en PHP puro (`scripts/demo/DemoArchivos.php`, sin GD), así que no hay
  archivos de terceros ni licencias. El video se copia en Cloudinary desde el cloud público `demo` (`dog.mp4`).
  Los datos están en `scripts/demo/datos.php`.
- No toca lo de `seed_multimedia.php` ni otras cuentas de `siiis_test`.
- Para servir la API contra `siiis_test` sin editar el `.env`:
  `MONGO_DB_NAME=siiis_test php -d variables_order=EGPCS -S localhost:8000 -t public` (Git Bash). Sin
  `variables_order=EGPCS`, PHP no pasa las variables de entorno del proceso a `$_ENV` y se usa la del `.env`.

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

- **Unit** y **Api** no necesitan base de datos: la app Slim corre en memoria con repositorios, reloj, correo y Cloudinary sustituidos por dobles.
- **Integration** usa `MONGO_DB_NAME_TEST` en Atlas. Se salta automáticamente si no hay `MONGO_URI` o conexión, y nunca se ejecuta contra la base principal. Cada prueba borra los datos que crea. Cubre repositorios, login y recuperación, la transacción de registro (incluida su reversión), la paginación real de `GET /users` y el ciclo edición → desactivación → reactivación. En la fase 3
  (`DocumentosFlowIntegrationTest`), con la app real: el ciclo crear → publicar → editar → borrar en `/projects` y
  `/articles`, la paginación completa por actor, que el listado público nunca muestre borradores ni ocultos, y que
  autor e integrantes de una página salgan de una sola consulta a `Usuarios` (lo comprueba con el monitoreo de
  comandos del driver). En la fase 4 (`InteraccionFlowIntegrationTest`), con la app real: reseñas de visitante e
  integrante con moderación y el listado público recorrido entero sin ocultas; contacto guardado y buzón; e
  inscripción por formulario con documentos privados en Cloudinary real, enlaces firmados que abren, URL guardada
  que no abre, duplicado `409` y aceptación sin crear la cuenta.
- Las pruebas con **Cloudinary real** se saltan si falta alguna `CLOUDINARY_*`. Suben solo a `siiis_test/perfiles`, `siiis_test/documentos` y `siiis_test/inscripciones` (privados), y vacían esas carpetas al terminar. Comprueban también las transformaciones al subir (DEC-B42) y la descarga de los PDF (la cuenta debe tener activada la entrega de PDF, DEC-B40).

**Antivirus en Windows:** si `forgot-password` deja en el log `certificate verify failed`, un antivirus que inspecciona el correo (p. ej. Avast Web/Mail Shield) está interceptando el TLS del SMTP. Excluye el SMTP de Gmail del escaneo; no desactives la verificación en el código.

## Estructura

```text
backend/
├── bootstrap.php          # carga autoload + .env y construye la app
├── public/                # index.php (front controller) y .htaccess
├── src/
│   ├── Config/            # Settings, Container (PHP-DI), Routes, Application
│   ├── Controllers/       # Health, Auth, Home (+ contacto), Users, Documentos, Reviews, Inscriptions, MensajesContacto (delgados)
│   ├── Database/          # Mongo (cliente único), Collections (nombres oficiales)
│   ├── Middleware/        # Auth, OptionalAuth, Role, ErrorHandler, Cors, JsonBody, ClientIp
│   ├── Repositories/      # consultas a las 8 colecciones oficiales; Cuentas (transacción)
│   ├── Security/          # PasswordHasher, JwtService, RateLimiter, Roles (jerarquía), SessionAuthenticator
│   ├── Services/          # Auth, PasswordReset, Mail, UserAccount, Users, Documentos, Resenas, Contacto, Inscripciones, Cloudinary
│   ├── Support/           # JsonResponse, Serializer, presentadores, ModuloDocumentos, CloudinaryUrl, Logger, Clock…
│   └── Validation/        # validadores de entrada (antiinyección NoSQL) y FileValidator (MIME real)
├── scripts/               # CLI: índices, superadmin, seed
├── cron/                  # mantenimiento: limpiar_ratelimit.php (cron de hPanel)
├── storage/               # logs/, ratelimit/, cache/ (no versionado)
└── tests/                 # Unit/, Api/, Integration/, Support/ (dobles)
```

## Despliegue en Hostinger (resumen de la sección 17.7 del spec)

1. En hPanel activa PHP 8.2+ y la extensión `mongodb` (≥ 1.18, ver DEC-B03), y agrega la IP del servidor en Atlas.
2. Ejecuta `composer install --no-dev --optimize-autoloader` y sube el backend (sin `public/`) a `siiis-api/`, fuera de `public_html`.
3. Crea el `.env` de producción en `siiis-api/` con `APP_ENV=production` y `APP_DEBUG=false`, y da permisos de escritura a `storage/`.
4. Copia `public/index.php` y `public/.htaccess` a `public_html/api/` y ajusta `BOOTSTRAP_PATH` a `../../siiis-api/bootstrap.php`.
5. Ejecuta `crear_indices.php` y `crear_superadmin.php`, y verifica `GET /api/v1/health`.
6. Revisa que `upload_max_filesize` y `post_max_size` permitan fotos de 2 MB, y prueba `forgot-password` y una foto de perfil.

La checklist completa está en `../docs/deployment.md`.
