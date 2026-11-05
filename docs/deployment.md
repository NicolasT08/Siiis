# Despliegue — Backend SIIIS V3 en Hostinger

Resumen de las secciones 17.4, 17.6 y 17.7 del spec. Registra aquí los resultados de cada verificación.

## Versiones fijadas

| Componente | Local (2026-09-25) | Producción (Hostinger) |
|---|---|---|
| PHP | 8.2.34 | `TODO_SPEC` |
| Extensión `mongodb` | 1.21.9 | Se asume **1.18.1** (reporte de marzo de 2025). `TODO_SPEC`: confirmar la versión real con `phpinfo()` |
| `mongodb/mongodb` | 1.19.1 (`~1.19.1`, desde 2026-11-04) | Requiere la extensión **≥ 1.18** (DEC-B03) |
| `cloudinary/cloudinary_php` | 3.3.0 (`^3.1`, fase 2) | Usa Guzzle sobre `ext-curl`; necesita salida HTTPS a `api.cloudinary.com` |

`composer.json → config.platform` fija `php 8.2.34` y `ext-mongodb 1.18.1`, la versión que se asume en Hostinger
(DEC-B03). Así Composer solo instala dependencias compatibles con esa extensión.

**Riesgo aceptado (DEC-B03).** `mongodb/mongodb` 1.19.1 tiene dos avisos de seguridad que Composer permite
mediante `config.audit.ignore`:

- PKSA-61k5-cqr9-b8b4 (nombres de base de datos o colección con `.` o NUL). Los nombres salen de
  `Collections` y `.env`, nunca de la entrada del usuario.
- PKSA-y93m-jgy6-r9ns (inyección en GridFS). El backend no usa GridFS.

Mientras siga la 1.19, no se pueden construir nombres de colección desde la entrada ni usar GridFS.
`composer audit` los muestra como *ignored*; eso es lo esperado.

Cuando se confirme la versión real con `phpinfo()`:

1. Si es **1.18.x, 1.19.x o 1.20.x**: deja `config.platform.ext-mongodb` con la versión exacta y corre
   `composer update --lock`. No cambia nada más.
2. Si es **≥ 1.21**: vuelve a `mongodb/mongodb` `^1.21.5` y a `ext-mongodb` `^1.21`, pon la versión real en
   `config.platform`, quita los dos avisos de `audit.ignore` y corre `composer update mongodb/mongodb`.
3. Si es **< 1.18**: el backend no arranca. Hay que activar una versión más nueva en hPanel o pedirla a
   soporte.

## Checklist de verificación en Hostinger (spec 17.6)

- [ ] Versión de PHP seleccionada (≥ 8.2): ______
- [ ] Extensión `mongodb` activada en hPanel y su versión exacta (`phpinfo()`): ______ (se asume 1.18.1; si difiere, seguir los pasos de "Versiones fijadas")
- [ ] Extensiones `fileinfo`, `curl`, `openssl` y `mbstring` activas
- [ ] Conexión saliente a MongoDB Atlas (puerto 27017, URI `mongodb+srv://`): `GET /api/v1/health` responde `{"status":"ok","db":"ok"}`
- [ ] Lista de acceso IP de Atlas: IP saliente del servidor ______ agregada en *Atlas → Network Access*. Si la IP no es estable, decidir sobre `0.0.0.0/0` (DECISION-015)
- [ ] Conexión saliente a `smtp.gmail.com:587/465` (probar `forgot-password` y revisar `storage/logs`), a `api.cloudinary.com` y a `www.googleapis.com`. En desarrollo ya se verificaron el SMTP de Gmail y Cloudinary (2026-10-27); falta verificarlos desde Hostinger
- [ ] Límites de PHP: `upload_max_filesize` ___ · `post_max_size` ___ · `max_execution_time` ___ · `memory_limit` ___. Para las fotos de perfil (DEC-B29): `upload_max_filesize` ≥ `PERFIL_FOTO_MAX_BYTES` (2 MB) y `post_max_size` algo mayor (p. ej. 3 MB). Si el cuerpo supera `post_max_size`, PHP descarta el archivo y la API responde `400` en lugar de `413`
- [ ] Foto de perfil de punta a punta: `POST /api/v1/users/{id}/photo` con una imagen real → `200` y la URL de Cloudinary abre; la foto queda en `CLOUDINARY_PERFILES_FOLDER` (`siiis/perfiles`)
- [ ] Disponibilidad de SSH, Composer o despliegue con Git (DECISION-014). Sin SSH: `composer install --no-dev` en local o CI y subir `vendor/`
- [ ] Los métodos `PATCH` y `DELETE` y la cabecera `Authorization` llegan a PHP (`GET /api/v1/auth/me` con token → `200`). La fase 2 los usa: `PATCH /api/v1/users/{id}` con `{"biografia": "x"}` sobre el propio usuario → `200`, y `DELETE /api/v1/users/{id}` sin token → `401` (no `405`). Si alguno se filtra, usar `POST` con `X-HTTP-Method-Override` (nota Hostinger de la spec 11.3)
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
5. Crea el `.env` de producción en `siiis-api/` (fuera de `public_html`) con `APP_ENV=production` y `APP_DEBUG=false`. Usa un `JWT_SECRET` nuevo de al menos 32 caracteres, y las credenciales `MAIL_*` y `CLOUDINARY_*` **del semillero** (las de desarrollo son cuentas personales). Deja `CLOUDINARY_PERFILES_FOLDER=siiis/perfiles`.
6. Da permisos de escritura a `siiis-api/storage/` (logs, ratelimit, cache).
7. Sube `public_html/api/index.php` y su `.htaccess`.
8. Ejecuta una vez, por SSH o en local contra Atlas: `php scripts/crear_indices.php` y `php scripts/crear_superadmin.php`.
9. Verifica `GET https://siiis.com.co/api/v1/health` y completa la checklist de arriba (correo, foto de perfil, `PATCH`/`DELETE`).
10. Frontend: compila con la URL base `/api/v1` y súbelo a `public_html/` **sin borrar** `api/` ni el `.htaccess`.

## Mantenimiento

- Las sesiones vencidas las borra Atlas con el índice TTL de `Sesiones.fecha_expiracion` (no hace falta cron).
- Los contadores de rate limiting se sobrescriben por IP. `TODO_SPEC`: si crecen, crear `cron/limpiar_ratelimit.php` (spec 9) que llame a `RateLimiter::purge()` desde un cron de hPanel.
- Logs en `storage/logs/app-YYYY-MM-DD.log`. No registran contraseñas ni tokens.
- Fotos huérfanas en Cloudinary: si falla el borrado de una foto reemplazada, la API responde igual `200` y deja en el log `No se pudo borrar una foto de Cloudinary` con su `public_id`. Revisa el log de vez en cuando y bórralas desde la consola de Cloudinary.
- Los usuarios desactivados no se borran (borrado lógico, DEC-B22): conservan sus datos y su foto.
