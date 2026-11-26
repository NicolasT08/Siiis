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
- [ ] Lista de acceso de Atlas (*Atlas → Network Access*): `0.0.0.0/0` con contraseña fuerte del usuario de la aplicación (DEC-B54, DECISION-015 decidida). Si Hostinger confirma una IP saliente estable (______), se puede cambiar a esa IP
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

### Paquete listo para subir (`backend/scripts/empaquetar.ps1`)

En Windows, desde la raíz del repositorio:

```powershell
powershell -ExecutionPolicy Bypass -File backend\scripts\empaquetar.ps1
# otro dominio: ... -Dominio midominio.com
```

Genera `deploy/` (ignorada por Git) con esta misma estructura:

- `deploy/siiis-api/`: `bootstrap.php`, `composer.json/.lock`, `src/`, `cron/`, los scripts de producción
  (`cli.php`, `crear_indices.php`, `crear_superadmin.php`, `seed_multimedia.php`, `cargar_multimedia.php` con `multimedia/`), `vendor/` **sin** paquetes de
  desarrollo (`composer install --no-dev --optimize-autoloader`), `storage/{logs,ratelimit,cache}` vacías, un
  `.htaccess` que lo deniega todo (por si la carpeta quedara por error dentro de `public_html`) y
  `.env.produccion.ejemplo` (generado desde `.env.example`, con `APP_ENV=production`, `APP_DEBUG=false`, las URL
  de `https://<dominio>` y todos los secretos vacíos). **No** incluye `.env`, `tests/`, `seed_demo.php`,
  `scripts/demo/` ni las herramientas de calidad.
- `deploy/public_html/`: el **contenido de `frontend/dist`** (`index.html`, `assets/`, el `.htaccess` de la raíz, el
  de abajo, que viene de `frontend/public/.htaccess`) y `api/` con `index.php` (ya con el `BOOTSTRAP_PATH` de
  Hostinger, en UTF-8 sin BOM) y su `.htaccess`. El frontend nunca pisa `api/`: si `frontend/dist` trajera una
  carpeta `api/`, el script se detiene. Antes de correrlo: `cd frontend; npm run build` **en PowerShell** (en Git
  Bash, `VITE_API_URL=/api/v1` se convierte en `D:/Program Files/Git/api/v1`; el script detecta ese build y el de
  desarrollo, que apunta a `localhost:8000`, y se detiene). Con `-SinFrontend` arma solo el backend.

El script parte del `backend/vendor` local (Composer solo quita los paquetes de desarrollo, sin descargar nada; así
no hace falta la extensión `zip` ni `unzip`) y al final verifica el paquete: sin `.env`, sin PHPUnit/PHPStan,
secretos vacíos, `index.php` sin BOM y sin errores de sintaxis, y que el autoload carga la aplicación. Si algo
falla, se detiene con el motivo.

En `public_html/api/index.php` (el script ya lo hace), cambia solo esta línea:

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
2. Lista de acceso de MongoDB Atlas: `0.0.0.0/0` con contraseña fuerte del usuario de la aplicación (DEC-B54, DECISION-015). El usuario de la base de producción debe tener solo `readWrite` sobre `siiis`.
3. `composer install --no-dev --optimize-autoloader` (por SSH o en local/CI). En Windows, `backend/scripts/empaquetar.ps1` lo hace y arma `deploy/` (ver arriba).
4. Sube `siiis-api/` (código y `vendor/`) por Git, FTP o el Administrador de archivos.
5. Crea el `.env` de producción en `siiis-api/` (fuera de `public_html`) a partir de `.env.produccion.ejemplo`, con `APP_ENV=production` y `APP_DEBUG=false` (con `APP_DEBUG=true` en producción la API no arranca, DEC-B56). Usa un `JWT_SECRET` nuevo de al menos 32 caracteres. Para las credenciales `MAIL_*` y `CLOUDINARY_*` se usan **por ahora las de desarrollo**, hasta que el semillero tenga sus cuentas (DEC-B55); cuando las tenga, solo hay que cambiarlas en el `.env`. Deja `CORS_ORIGIN` vacío (mismo dominio). Deja `CLOUDINARY_PERFILES_FOLDER=siiis/perfiles`, `CLOUDINARY_DOCUMENTOS_FOLDER=siiis/documentos` y `CLOUDINARY_INSCRIPCIONES_FOLDER=siiis/inscripciones`. Configura `CONTACTO_CORREO_DESTINO` (buzón del semillero que recibe contacto y avisos de inscripción; opcional `INSCRIPCION_CORREO_AVISO`) y revisa `INSCRIPCION_DOMINIOS_CORREO` (por defecto `uptc.edu.co`). Activa en esa cuenta la entrega de PDF (ver la lista de verificación). Las variables de optimización (`PERFIL_FOTO_LADO_PX`, `DOCUMENTO_IMAGEN_MAX_ANCHO_PX`, `CLOUDINARY_CALIDAD` y `DOCUMENTO_PDF_MAX_BYTES`, DEC-B42) pueden omitirse: los valores por defecto son los de `.env.example`.
6. Da permisos de escritura a `siiis-api/storage/` (logs, ratelimit, cache).
7. Sube `public_html/api/index.php` y su `.htaccess`.
8. Ejecuta una vez, por SSH o en local contra Atlas: `php scripts/crear_indices.php` y `php scripts/crear_superadmin.php`. Registra el cron de `cron/limpiar_ratelimit.php` (ver "Mantenimiento").
9. Verifica `GET https://siiis.com.co/api/v1/health` y completa la checklist de arriba (correo, foto de perfil, adjuntos, `PATCH`/`DELETE`). Para la fase 3: crea un proyecto con una cuenta de estudiante, compruébalo oculto en `GET /api/v1/projects` sin sesión, publícalo con un revisor (`{"estado": "ejecucion", "visible": true}`) y confirma que ya aparece; luego bórralo. Para la fase 4: los dos puntos "Fase 4" de la lista de verificación (inscripción, contacto y reseñas).
10. Frontend: ya va en `public_html/` del paquete (ver arriba). Si se sube aparte, el contenido de `frontend/dist` va a `public_html/` **sin borrar** `api/` ni el `.htaccess`.
11. Fotos y videos de la página de inicio: `php scripts/cargar_multimedia.php --carpeta=RUTA` contra la base real (ver "Contenido inicial de la página de inicio").

## Contenido inicial de la página de inicio (`scripts/cargar_multimedia.php`)

Sube a Cloudinary (carpeta `siiis/home`) las fotos del slider y los videos de una carpeta local y los registra en
`Multimedia` con su sección, orden y descripción (DEC-B60). La estructura de la carpeta y las reglas están en el
encabezado de `scripts/multimedia/PlanMultimedia.php`; el paso a paso, en `docs/checklist-manana.md` (A6 y D4).

```bash
php scripts/cargar_multimedia.php --carpeta=RUTA --simular   # solo muestra el plan
php scripts/cargar_multimedia.php --carpeta=RUTA             # base real (MONGO_DB_NAME), pide escribir "si"
php scripts/cargar_multimedia.php --carpeta=RUTA --test      # base de pruebas y carpeta siiis_test/home
```

- `RUTA/slider/`: JPG, PNG o WebP hasta 10 MB (se reducen a 1920 px de ancho); `RUTA/videos/`: MP4, WebM o MOV
  hasta 100 MB (tal cual). El orden sale del nombre (`01-`, `02-`…); la descripción, de `descripciones.json` o del
  nombre.
- No duplica: el `public_id` sale del nombre sin el prefijo de orden; lo que ya está en `Multimedia` no se vuelve a
  subir (solo se actualizan `orden` y `descripcion`). Si una corrida subió un archivo pero no alcanzó a guardarlo,
  la siguiente lo registra sin subirlo otra vez.
- No borra: para quitar una foto, bórrala en Atlas (`Multimedia`) y en Cloudinary (`siiis/home`).
- Usa un cliente de Cloudinary con 10 minutos de espera (un video puede tardar); por eso conviene correrlo desde el
  portátil o por SSH, no desde la web.

## Login con Google (RF-17, DEC-B61)

Opcional: sin `GOOGLE_CLIENT_ID`, `POST /api/v1/auth/google` responde `404` y el frontend no muestra el botón. El login
con correo y contraseña funciona igual en los dos casos.

### 1. Crear el ID de cliente en Google Cloud

1. Entra a <https://console.cloud.google.com/> con una cuenta del semillero (idealmente `@uptc.edu.co`) y crea un
   proyecto, p. ej. `SIIIS Web` (*Selector de proyectos → Proyecto nuevo*).
2. **Pantalla de consentimiento** (*APIs y servicios → Pantalla de consentimiento de OAuth*; en la consola nueva,
   *Google Auth Platform → Desarrollo de la marca / Público*):
   - Tipo de usuario: **Interno** si el proyecto pertenece a la organización de Google Workspace de la UPTC (solo
     entran cuentas `@uptc.edu.co`); si no, **Externo** (el backend igual rechaza cualquier dominio que no sea
     `GOOGLE_DOMINIO_PERMITIDO`).
   - Nombre de la app: `SIIIS – Semillero de Investigación`, correo de asistencia y, si quieres, el logo.
   - Dominios autorizados: `siiis.com.co`. Página principal: `https://siiis.com.co`.
   - Permisos (scopes): solo `openid`, `email` y `profile`. No son sensibles, así que no hace falta la verificación
     de Google.
3. **Publicar la app** (solo si es *Externo*): en *Público*, el estado empieza en **Prueba** y solo entran los
   "usuarios de prueba" de la lista. Pulsa **Publicar app → En producción**. Con los tres permisos básicos no hay
   revisión de Google y la publicación es inmediata. Con tipo *Interno* no hace falta publicar.
4. **Credenciales** (*APIs y servicios → Credenciales → Crear credenciales → ID de cliente de OAuth*):
   - Tipo de aplicación: **Aplicación web**. Nombre: `SIIIS frontend`.
   - **Orígenes de JavaScript autorizados** (sin barra final ni rutas):
     - `https://siiis.com.co` (y `https://www.siiis.com.co` si el sitio también responde con `www`).
     - `http://localhost:5173` y `http://localhost` (desarrollo con `npm run dev`; Google Identity Services pide los dos).
   - **URI de redireccionamiento autorizados**: ninguno (el botón usa una ventana emergente y le devuelve el token a la
     página).
5. Copia el **ID de cliente** (`…apps.googleusercontent.com`). No es un secreto (va en el JavaScript del sitio). El
   "secreto del cliente" que muestra Google **no se usa**: no lo copies a ningún archivo.

Los cambios de orígenes pueden tardar desde unos minutos hasta una hora en aplicarse.

### 2. Configurar backend y frontend

- Backend (`siiis-api/.env`): `GOOGLE_CLIENT_ID=<id de cliente>` y `GOOGLE_DOMINIO_PERMITIDO=uptc.edu.co`.
- Frontend: compilar con `VITE_GOOGLE_CLIENT_ID=<el mismo id>`. Lo más simple es ponerlo en
  `frontend/.env.production.local` o en `frontend/.env.local` (no se versionan) y correr `npm run build`. El build lo
  deja escrito en el JavaScript; si se compila sin él, el botón de Google no aparece.
- El servidor necesita salida HTTPS a `www.googleapis.com`: descarga las llaves públicas de Google y las guarda en
  `storage/cache/google_jwks.json` el tiempo que Google indica (unas horas). Sin esa salida, el login con Google
  responde `503 GOOGLE_UNAVAILABLE`.

### 3. Verificar

1. `https://siiis.com.co` → *Iniciar sesión* → aparece "Iniciar sesión con Google".
2. Con una cuenta `@uptc.edu.co` que **tenga** usuario en el semillero → entra con el mismo menú que con contraseña.
3. Con una `@uptc.edu.co` **sin** usuario → "no está registrada en el semillero…", con enlace a `/inscripcion`.
4. Con una `@gmail.com` → "Solo se puede entrar con Google usando una cuenta institucional…".
5. Si el botón no aparece o Google dice `origin_mismatch` / "The given origin is not allowed": revisa los orígenes del
   paso 1.4 (exactos, con `https://`).

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
- Los mensajes de contacto no se borran (no hay ruta, DEC-B52).
- **Solicitudes de inscripción rechazadas: se conservan 6 meses y luego se borran a mano** (DEC-B52, decisión del equipo del 2026-11-26). No hay ruta ni cron. Una vez al mes, un administrador con acceso a Atlas y a Cloudinary:
  1. En Atlas (*Data Explorer*, base `siiis`, colección `Inscripciones`) filtra las rechazadas con más de 6 meses, contados desde `fecha_solicitud` (el modelo no guarda la fecha de resolución). Ejemplo, cambiando la fecha por la de hoy menos 6 meses:

     ```json
     { "estado_solicitud": "rechazada", "fecha_solicitud": { "$lt": { "$date": "2026-05-26T00:00:00Z" } } }
     ```

  2. Anota el `public_id` de cada elemento de `documentos_adjuntos` y bórralos en la consola de Cloudinary (*Media Library*, carpeta `siiis/inscripciones`, tipo **Authenticated**). Primero los archivos: si se borra antes el documento, se pierden los `public_id`.
  3. Borra esos documentos en Atlas.
  Las solicitudes `pendiente` y `aceptada` no se borran con este procedimiento.

## Seguridad en producción (revisión del 2026-11-26)

- Cabeceras en todas las respuestas (DEC-B56): `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'`, `Referrer-Policy: no-referrer`, `Cache-Control: no-store` si la ruta no fija su caché y `Strict-Transport-Security` con `APP_ENV=production`. Sin `X-Powered-By`.
- `APP_DEBUG=true` con `APP_ENV=production` impide arrancar (los `500` mostrarían trazas).
- Sin CORS en Hostinger (mismo dominio). Solo se activa con `CORS_ORIGIN` (plan B, DEC-B57).
- Los logs no guardan contraseñas, tokens ni argumentos de funciones en las trazas.
- El `.env`, `vendor/` y `storage/` quedan fuera de `public_html`; `siiis-api/.htaccess` deniega todo por si acaso.

## Plan B: backend en Render (Docker)

Solo si Hostinger **no** permite activar la extensión `mongodb` (DEC-B58). El frontend sigue en Hostinger; solo el
backend va a Render. No está desplegado: estos son los pasos para hacerlo si hace falta.

Archivos: `backend/Dockerfile` (PHP 8.2 + Apache + `ext-mongodb` 1.21.9, la versión con la que pasan las pruebas
en local) y `backend/.dockerignore` (deja fuera `.env`, `vendor/`, `tests/` y los scripts de desarrollo).

1. **Prueba local (opcional, requiere Docker):**

   ```bash
   cd backend
   docker build -t siiis-api .
   # Contra la base de pruebas. --env-file no quita las comillas: MAIL_FROM_NAME llega con ellas (solo en esta prueba).
   docker run --rm -p 10000:10000 --env-file .env -e APP_ENV=production -e APP_DEBUG=false -e MONGO_DB_NAME=siiis_test siiis-api
   curl http://localhost:10000/api/v1/health
   ```

   Si `pecl` no encuentra la versión 1.21.9, construye con otra de la rama 1.x: `--build-arg MONGODB_EXT_VERSION=1.21.x`.
2. **Render:** *New → Web Service*, conecta el repositorio de GitHub, *Language/Runtime* **Docker**, *Root Directory* `backend`, *Dockerfile Path* `./Dockerfile`, región cercana (p. ej. Virginia/Ohio) y plan Free o Starter.
3. **Variables de entorno** (*Environment*): las mismas del `.env.produccion.ejemplo`, con estos cambios:
   - `APP_ENV=production`, `APP_DEBUG=false`, `MONGO_URI`, `JWT_SECRET`, `MAIL_*`, `CLOUDINARY_*`, `CONTACTO_CORREO_DESTINO`.
   - `APP_URL=https://<servicio>.onrender.com`.
   - `CORS_ORIGIN=https://siiis.com.co` (el dominio **del frontend**, exacto, con https y sin barra final).
   - `PASSWORD_RESET_URL=https://siiis.com.co/restablecer` (la pantalla está en el frontend).
   - No hace falta `PORT`: Render lo define y Apache escucha en él.
4. **Health check path:** `/api/v1/health`. Tras el despliegue, `https://<servicio>.onrender.com/api/v1/health` → `{"status":"ok","db":"ok"}`. Si responde `CONFIG_ERROR`, falta una variable (p. ej. `MONGO_URI`) o `CORS_ORIGIN` está mal escrito.
5. **Atlas:** la lista de acceso `0.0.0.0/0` (DEC-B54) ya cubre las IP de Render.
6. **IP real del visitante:** Render pone un proxy delante. Haz un login y mira `Sesiones.ip_acceso`; si aparece una IP interna (`10.x.x.x`) en lugar de la tuya, configura `TRUSTED_PROXIES` con ese rango interno (p. ej. `10.0.0.0/8`, que solo alcanza la red interna de Render) y vuelve a comprobar.
7. **Frontend:** compílalo con `VITE_API_URL=https://<servicio>.onrender.com/api/v1` y súbelo a `public_html/` como siempre (en ese caso no se sube `public_html/api/`).
8. **Cron:** sin `cron/limpiar_ratelimit.php` no pasa nada grave: el disco del contenedor es efímero y los contadores se borran en cada reinicio.

Limitaciones: logs y contadores de rate limiting se pierden al reiniciar o redeplegar (revisa los errores en *Logs* de Render, donde también llega `error_log`); el plan Free se duerme tras unos 15 minutos sin uso y la primera petición tarda cerca de un minuto; Render restringe el SMTP saliente (puertos 25, 465 y 587) en las instancias gratuitas, así que prueba `forgot-password` y el contacto desde Render y, si los correos no salen (log `No se pudo…`), pasa el servicio al plan Starter.
