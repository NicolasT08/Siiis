# Despliegue — Backend SIIIS V3 en Hostinger

Resumen de las secciones 17.4, 17.6 y 17.7 del spec. Registra aquí los resultados de cada verificación.

## Versiones fijadas

| Componente | Local (2026-09-25) | Producción (Hostinger) |
|---|---|---|
| PHP | 8.2.34 | `TODO_SPEC` |
| Extensión `mongodb` | 1.21.9 | `TODO_SPEC: confirmar versión de ext-mongodb en Hostinger` (spec: 1.18.x reportada en 2025) |
| `mongodb/mongodb` | 1.21.5 (`^1.21.4`) | Requiere la extensión **≥ 1.21** (DEC-B03) |

`composer.json → config.platform` usa provisionalmente `php 8.2.34` y `ext-mongodb 1.21.9`. Cuando el profesor confirme la versión real con `phpinfo()`:

1. Actualiza `config.platform` con la versión de producción y corre `composer update --lock`.
2. Si la extensión de Hostinger es menor que 1.21, el backend no arranca con `mongodb/mongodb` 1.21. Hay que decidir entre pedir o activar una versión más nueva en hPanel, o aceptar la 1.18 ignorando el aviso de seguridad PKSA-61k5-cqr9-b8b4 (ver DEC-B03).

## Checklist de verificación en Hostinger (spec 17.6)

- [ ] Versión de PHP seleccionada (≥ 8.2): ______
- [ ] Extensión `mongodb` activada en hPanel y su versión exacta (`phpinfo()`): ______ ; `mongodb/mongodb` compatible fijada
- [ ] Extensiones `fileinfo`, `curl`, `openssl` y `mbstring` activas
- [ ] Conexión saliente a MongoDB Atlas (puerto 27017, URI `mongodb+srv://`): `GET /api/v1/health` responde `{"status":"ok","db":"ok"}`
- [ ] Lista de acceso IP de Atlas: IP saliente del servidor ______ agregada en *Atlas → Network Access*. Si la IP no es estable, decidir sobre `0.0.0.0/0` (DECISION-015)
- [ ] Conexión saliente a `smtp.gmail.com:587/465` (probar `forgot-password` y revisar `storage/logs`), a `api.cloudinary.com` y a `www.googleapis.com`
- [ ] Límites de PHP: `upload_max_filesize` ___ · `post_max_size` ___ · `max_execution_time` ___ · `memory_limit` ___
- [ ] Disponibilidad de SSH, Composer o despliegue con Git (DECISION-014). Sin SSH: `composer install --no-dev` en local o CI y subir `vendor/`
- [ ] Los métodos `PATCH` y `DELETE` y la cabecera `Authorization` llegan a PHP (`GET /api/v1/auth/me` con token → `200`)
- [ ] Cron jobs disponibles

## Estructura en el servidor (PROPUESTA, spec 17.4)

```text
domains/siiis.com.co/
├── public_html/
│   ├── index.html, assets/...      # build de React
│   ├── .htaccess                   # /api → PHP; resto → SPA
│   └── api/
│       ├── index.php               # copia de backend/public/index.php con BOOTSTRAP_PATH ajustado
│       └── .htaccess               # copia de backend/public/.htaccess
└── siiis-api/                      # contenido de backend/ excepto public/: src/, vendor/, storage/, scripts/, bootstrap.php, .env
```

En `public_html/api/index.php`, cambia solo esta línea:

```php
const BOOTSTRAP_PATH = __DIR__ . '/../../siiis-api/bootstrap.php';
```

`public_html/.htaccess` (referencia):

```apacheconf
RewriteEngine On
RewriteRule ^api/ - [L]
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^ index.html [L]
```

## Pasos de despliegue (spec 17.7)

1. Activa PHP 8.2+ y la extensión `mongodb` en hPanel (*Avanzado → Configuración PHP → Extensiones*).
2. Agrega la IP del servidor en la lista de acceso de MongoDB Atlas.
3. `composer install --no-dev --optimize-autoloader` (por SSH o en local/CI).
4. Sube `siiis-api/` (código y `vendor/`) por Git, FTP o el Administrador de archivos.
5. Crea el `.env` de producción en `siiis-api/` (fuera de `public_html`) con `APP_ENV=production` y `APP_DEBUG=false`. Usa un `JWT_SECRET` nuevo de al menos 32 caracteres.
6. Da permisos de escritura a `siiis-api/storage/` (logs, ratelimit, cache).
7. Sube `public_html/api/index.php` y su `.htaccess`.
8. Ejecuta una vez, por SSH o en local contra Atlas: `php scripts/crear_indices.php` y `php scripts/crear_superadmin.php`.
9. Verifica `GET https://siiis.com.co/api/v1/health`.
10. Frontend: compila con la URL base `/api/v1` y súbelo a `public_html/` **sin borrar** `api/` ni el `.htaccess`.

## Mantenimiento

- Las sesiones vencidas las borra Atlas con el índice TTL de `Sesiones.fecha_expiracion` (no hace falta cron).
- Los contadores de rate limiting se sobrescriben por IP. `TODO_SPEC`: si crecen, crear `cron/limpiar_ratelimit.php` (spec 9) que llame a `RateLimiter::purge()` desde un cron de hPanel.
- Logs en `storage/logs/app-YYYY-MM-DD.log`. No registran contraseñas ni tokens.
