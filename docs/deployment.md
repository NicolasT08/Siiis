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
- [ ] Límites de PHP: `upload_max_filesize` ___ · `post_max_size` ___ · `max_execution_time` ___ · `memory_limit` ___. Para los adjuntos de documentos (DEC-B40, DEC-B42), que son el archivo más grande: `upload_max_filesize` ≥ el mayor entre `DOCUMENTO_ADJUNTO_MAX_BYTES` (imágenes, 5 MB) y `DOCUMENTO_PDF_MAX_BYTES` (PDF, 3 MB) y `post_max_size` algo mayor (p. ej. 6 MB); con eso también caben las fotos de perfil (2 MB, DEC-B29). **Desde la fase 4**, la inscripción manda hasta 3 documentos en una sola petición (DEC-B49): `post_max_size` debe ser de unos 16 MB y `upload_max_filesize` de al menos 5 MB, y `max_execution_time` debe alcanzar para 3 subidas seguidas a Cloudinary (hasta 30 s cada una). Si el cuerpo supera `post_max_size`, PHP descarta los archivos y la API responde `400` en lugar de `413`
- [ ] Foto de perfil de punta a punta: `POST /api/v1/users/{id}/photo` con una imagen real → `200` y la URL de Cloudinary abre; la foto queda en `CLOUDINARY_PERFILES_FOLDER` (`siiis/perfiles`), recortada a 400 × 400 (en la consola de Cloudinary, el archivo ya mide 400 × 400; DEC-B42), y la URL que devuelve la API lleva `f_auto,q_auto`
- [ ] **Cloudinary del semillero: activar "Allow delivery of PDF and ZIP files"** (consola de Cloudinary → *Settings → Security*), en la misma cuenta que usan las `CLOUDINARY_*` de producción. Sin ese ajuste los PDF adjuntos se suben, pero al abrirlos Cloudinary responde `401` con `X-Cld-Error: deny or ACL failure` (DEC-B40). Subirlos como `raw` no lo evita: se probó el 2026-11-04 y la cuenta bloquea cualquier PDF, sin importar el tipo de recurso ni la extensión. En la cuenta de desarrollo ya está activado (2026-11-05)
- [ ] Adjunto de punta a punta: `POST /api/v1/projects/{id}/files` con un PDF real → `201`, y la URL de `archivos_adjuntos[].url` abre el PDF en el navegador (no `401`); el archivo queda en `CLOUDINARY_DOCUMENTOS_FOLDER` (`siiis/documentos`). Luego quitarlo con `DELETE /api/v1/projects/{id}/files/{archivo}`. Con una imagen de más de 1600 px de ancho, comprobar en la consola que quedó de 1600 px y que su URL en la API lleva `f_auto,q_auto` (DEC-B42)
- [ ] **Fase 4 — inscripción de punta a punta** (DEC-B49, DEC-B50): `POST /api/v1/inscriptions` (formulario con un PDF y una imagen) → `201`; en la consola de Cloudinary los archivos quedan en `CLOUDINARY_INSCRIPCIONES_FOLDER` (`siiis/inscripciones`) como **Authenticated** (no *Upload*); la `url` guardada en `Inscripciones` no abre (`401`) y los enlaces de `GET /api/v1/inscriptions/{id}` sí (`200`). Luego resolverla con `PATCH` (`aceptada`/`rechazada`) y comprobar el correo al aspirante
- [ ] **Fase 4 — contacto y reseñas**: `POST /api/v1/contact` → `201` y el correo llega a `CONTACTO_CORREO_DESTINO` con "Responder a" el remitente; `POST /api/v1/reviews` sin sesión → `oculta`; un moderador la aprueba con `PATCH /api/v1/reviews/{id}`. `PATCH /api/v1/inscriptions/{id}`, `PATCH /api/v1/contact/messages/{id}` y `DELETE /api/v1/reviews/{id}` sin token → `401` (no `405`)
- [ ] **IP real del visitante** para el rate limiting (DEC-B48): confirmar con `Sesiones.ip_acceso` (o en `storage/logs`) que llega la IP del visitante y no la de un proxy de Hostinger. Si todas las peticiones llegan con la misma IP, configurar `TRUSTED_PROXIES` (ver "IP del cliente detrás de un proxy", más abajo); si no, los límites (10 logins por 15 min, 20 inscripciones, 20 mensajes y 10 reseñas por hora) serían para todo el sitio
- [ ] Disponibilidad de SSH, Composer o despliegue con Git (DECISION-014). Sin SSH: `composer install --no-dev` en local o CI y subir `vendor/`
- [ ] Los métodos `PATCH` y `DELETE` y la cabecera `Authorization` llegan a PHP (`GET /api/v1/auth/me` con token → `200`). La fase 2 los usa: `PATCH /api/v1/users/{id}` con `{"biografia": "x"}` sobre el propio usuario → `200`, y `DELETE /api/v1/users/{id}` sin token → `401` (no `405`). La fase 3 también: `PATCH /api/v1/projects/{id}` y `DELETE /api/v1/projects/{id}` sin token → `401`, y `DELETE /api/v1/projects/{id}/files/{archivo}` sin token → `401`. Si alguno se filtra, usar `POST` con `X-HTTP-Method-Override` (nota Hostinger de la spec 11.3)
- [ ] Cron jobs disponibles y el de limpieza del rate limiting registrado (ver "Mantenimiento")

## Estructura en el servidor (PROPUESTA, spec 17.4)

```text
domains/siiis.com.co/
├── public_html/
│   ├── index.html, assets/...      # build de React
│   ├── .htaccess                   # /api → PHP; resto → SPA
│   └── api/
│       ├── index.php               # copia de backend/public/index.php con BOOTSTRAP_PATH ajustado
│       └── .htaccess               # copia de backend/public/.htaccess
└── siiis-api/                      # contenido de backend/ excepto public/: src/, vendor/, storage/, scripts/, cron/, bootstrap.php, .env
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

## IP del cliente detrás de un proxy (`TRUSTED_PROXIES`)

El rate limiting y `Sesiones.ip_acceso` usan la IP del cliente. Por defecto (`TRUSTED_PROXIES` vacío) es `REMOTE_ADDR`, y la cabecera `X-Forwarded-For` se ignora, porque cualquier cliente puede enviarla con una IP inventada y saltarse los límites.

Si el sitio queda detrás de un proxy (un CDN como Cloudflare, o un balanceador de Hostinger) **todas** las peticiones llegan con la IP del proxy. En ese caso:

1. Averigua las IP o rangos del proxy (los publica el proveedor; en Cloudflare, <https://www.cloudflare.com/ips/>).
2. Ponlos en el `.env`, separados por comas, como IP sueltas o rangos CIDR (IPv4 o IPv6):

   ```dotenv
   TRUSTED_PROXIES=173.245.48.0/20,103.21.244.0/22,2400:cb00::/32
   ```

3. Comprueba con un login que `Sesiones.ip_acceso` guarda tu IP y no la del proxy.

Cómo se usa: solo si `REMOTE_ADDR` pertenece a esa lista se lee `X-Forwarded-For`, de derecha a izquierda, saltando los proxies de la lista, y se toma la primera IP que no es de confianza (las que haya escrito el cliente a la izquierda se ignoran). Si la cabecera falta o está malformada, se usa `REMOTE_ADDR`. Un valor mal escrito en `TRUSTED_PROXIES` hace que la API no arranque (error de configuración), para no confiar por accidente en quien no es. No pongas rangos amplios como `0.0.0.0/0`: equivale a confiar en cualquiera.

## Pasos de despliegue (spec 17.7)

1. Activa PHP 8.2+ y la extensión `mongodb` en hPanel (*Avanzado → Configuración PHP → Extensiones*).
2. Agrega la IP del servidor en la lista de acceso de MongoDB Atlas.
3. `composer install --no-dev --optimize-autoloader` (por SSH o en local/CI).
4. Sube `siiis-api/` (código y `vendor/`) por Git, FTP o el Administrador de archivos.
5. Crea el `.env` de producción en `siiis-api/` (fuera de `public_html`) con `APP_ENV=production` y `APP_DEBUG=false`. Usa un `JWT_SECRET` nuevo de al menos 32 caracteres, y las credenciales `MAIL_*` y `CLOUDINARY_*` **del semillero** (las de desarrollo son cuentas personales). Deja `CLOUDINARY_PERFILES_FOLDER=siiis/perfiles`, `CLOUDINARY_DOCUMENTOS_FOLDER=siiis/documentos` y `CLOUDINARY_INSCRIPCIONES_FOLDER=siiis/inscripciones`. Configura `CONTACTO_CORREO_DESTINO` (buzón del semillero que recibe contacto y avisos de inscripción; opcional `INSCRIPCION_CORREO_AVISO`) y revisa `INSCRIPCION_DOMINIOS_CORREO` (por defecto `uptc.edu.co`). Activa en esa cuenta la entrega de PDF (ver la lista de verificación). Las variables de optimización (`PERFIL_FOTO_LADO_PX`, `DOCUMENTO_IMAGEN_MAX_ANCHO_PX`, `CLOUDINARY_CALIDAD` y `DOCUMENTO_PDF_MAX_BYTES`, DEC-B42) pueden omitirse: los valores por defecto son los de `.env.example`.
6. Da permisos de escritura a `siiis-api/storage/` (logs, ratelimit, cache).
7. Sube `public_html/api/index.php` y su `.htaccess`.
8. Ejecuta una vez, por SSH o en local contra Atlas: `php scripts/crear_indices.php` y `php scripts/crear_superadmin.php`. Registra el cron de `cron/limpiar_ratelimit.php` (ver "Mantenimiento").
9. Verifica `GET https://siiis.com.co/api/v1/health` y completa la checklist de arriba (correo, foto de perfil, adjuntos, `PATCH`/`DELETE`). Para la fase 3: crea un proyecto con una cuenta de estudiante, compruébalo oculto en `GET /api/v1/projects` sin sesión, publícalo con un revisor (`{"estado": "ejecucion", "visible": true}`) y confirma que ya aparece; luego bórralo. Para la fase 4: los dos puntos "Fase 4" de la lista de verificación (inscripción, contacto y reseñas).
10. Frontend: compila con la URL base `/api/v1` y súbelo a `public_html/` **sin borrar** `api/` ni el `.htaccess`.

## Mantenimiento

- Las sesiones vencidas las borra Atlas con el índice TTL de `Sesiones.fecha_expiracion` (no hace falta cron).
- **Contadores de rate limiting** (spec 9 y 17.7): cada IP deja un archivo pequeño en `storage/ratelimit/` por acción, que nunca se borra solo. `cron/limpiar_ratelimit.php` borra los que no cambian desde hace más que la ventana más larga del `.env` (hoy 1 h), así que no afecta a ningún límite vigente; `--segundos=N` alarga ese plazo (no se admite uno menor). No usa MongoDB. En hPanel (*Avanzado → Cron Jobs*), una vez al día, por ejemplo a las 3:00:

  ```text
  0 3 * * * /usr/bin/php /home/USUARIO/domains/siiis.com.co/siiis-api/cron/limpiar_ratelimit.php
  ```

  La ruta de `php` y la de la carpeta las muestra hPanel en la misma pantalla. La salida es una línea con la cantidad de contadores borrados.
- Logs en `storage/logs/app-YYYY-MM-DD.log`. No registran contraseñas ni tokens.
- Fotos huérfanas en Cloudinary: si falla el borrado de una foto reemplazada, la API responde igual `200` y deja en el log `No se pudo borrar una foto de Cloudinary` con su `public_id`. Revisa el log de vez en cuando y bórralas desde la consola de Cloudinary.
- Adjuntos huérfanos: lo mismo al quitar un adjunto o borrar un documento. El mensaje del log es `No se pudo borrar un adjunto de Cloudinary`, con el `public_id` y el motivo.
- Espacio en Cloudinary (DEC-B42): las fotos de perfil se guardan en 400 × 400 y las imágenes adjuntas con 1600 px de ancho como máximo, ambas con calidad automática; los PDF, tal cual y de hasta 3 MB. Lo subido antes del 2026-11-05 conserva su tamaño original. Revisa el uso en la consola (*Dashboard → Usage*); si se acerca al cupo del plan, baja `CLOUDINARY_CALIDAD` (p. ej. `auto:eco`) o los límites en el `.env`.
- Los usuarios desactivados no se borran (borrado lógico, DEC-B22): conservan sus datos y su foto.
- Documentos de inscripción huérfanos (fase 4): si falla una subida o el guardado en MongoDB, la API borra de Cloudinary lo ya subido; si ese borrado también falla, queda en el log `Documento de inscripción huérfano en Cloudinary` con el `public_id`. Se borran desde la consola (filtrando por tipo **Authenticated** en `siiis/inscripciones`).
- Correos de contacto e inscripción que fallan: la operación responde `201` igual (DEC-B47) y el log registra `No se pudo reenviar el mensaje de contacto` o `No se pudo enviar un correo de inscripción`, con el id del registro. El mensaje sigue en `Mensajes_Contacto` y la solicitud en `Inscripciones`.
- Las solicitudes de inscripción y los mensajes de contacto no se borran (no hay ruta, DEC-B52). Cuánto tiempo conservar las rechazadas y sus documentos (datos personales) lo debe decidir el equipo.
