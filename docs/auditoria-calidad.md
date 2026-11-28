# Auditoría de atributos de calidad — SIIIS V3 (antes del despliegue)

- Fecha: 2026-11-28 · Rama: `integracion-backend-php` · Alcance: `backend/`, `frontend/` (solo lectura),
  `deploy/` (paquete armado el 2026-11-27).
- Método: lectura de código, del build y de los `.htaccess`; `npm audit` y `composer audit` (solo para reportar).
  No se corrió la suite de pruebas, no se leyó el `.env` y no se tocaron la base `siiis` ni Cloudinary.
- Severidad: **Crítico** (impide desplegar o expone datos ya), **Alto** (arreglar antes de desplegar o el mismo
  día), **Medio** (próximas semanas), **Bajo** (mejora).

## Estado de las correcciones (2026-11-28)

Corregidos según el plan aprobado (`docs/PLAN.md`): **A-01** (`scripts/respaldar_base.php`), **A-02**
(`components/ErrorBoundary.tsx`, `lib/recargaPorChunk.ts`), **A-03** (`frontend/public/.htaccess` y
`frontend/public/assets/.htaccess`; falta comprobar en Hostinger), **A-04** (`lib/sesion.ts`, `AuthProvider.tsx`),
**M-01** (`MONGO_*_TIMEOUT_MS` en `Database/Mongo.php`) y **M-03** (`RATE_LIMIT_RESET_*`). El resto sigue abierto.

## Resumen

| Severidad | Hallazgos |
|---|---|
| Crítico | Ninguno |
| Alto | A-01 sin respaldos de la BD · A-02 frontend sin error boundary (pantalla en blanco, también tras un redespliegue) · A-03 `.htaccess` del sitio sin cabeceras de seguridad ni política de caché · A-04 sin cierre automático de sesión a las 8 h en el frontend (RNF-05) |
| Medio | M-01 timeouts de MongoDB incompletos · M-02 logs sin rotación ni purga · M-03 `reset-password` sin rate limit · M-04 consumo de Cloudinary gratuito por el video · M-05 `fetch` sin timeout en el frontend |
| Bajo | B-01 a B-12 |

Lo que ya está bien (no requiere acción): manejador global de errores en JSON sin trazas con `APP_DEBUG=false`
(también si falla el arranque, `public/index.php`); `503 DB_UNAVAILABLE` cuando Atlas no responde; `502`/`415`
limpios cuando falla Cloudinary; el correo que falla no tumba la operación (DEC-B47); cabeceras de seguridad en
todas las respuestas de la API (`SecurityHeadersMiddleware`); casteo y rechazo de arreglos en todas las entradas
(`InputReader`, `QueryReader`, `ObjectIdValidator`); MIME real con `finfo`, tamaño máximo y nombre saneado en las
subidas (`FileValidator`); rol tomado del JWT y sesión validada contra `Sesiones` en cada petición; jerarquía de
roles y verificación de propietario en usuarios y documentos (sin IDOR encontrados); `siiis-api/` fuera de
`public_html` y con `Require all denied`; `.env` fuera del paquete y de Git; Markdown sin HTML crudo
(`skipHtml`) y con el filtro de URL de react-markdown; caché pública corta con `Vary: Authorization` en las rutas
que dependen del token; paginación con máximo en todos los listados; sin N+1; el sitio público se degrada con
dignidad si la API no responde (inicio sin videos ni noticias, carrusel con estado vacío, "Acerca de" con datos
fijos del director, `ErrorCarga` con "Reintentar" en las páginas de datos) y tiene página 404.

---

## 1. Disponibilidad y robustez

### A-02 · Alto · Sin error boundary: un error de render o de carga de un chunk deja la página en blanco

- Archivos: `frontend/src/main.tsx`, `frontend/src/app/routes/AppRoutes.tsx`.
- Riesgo: no hay ningún `ErrorBoundary` (no existe `componentDidCatch` ni `errorElement` en el proyecto). Una
  excepción en cualquier componente desmonta toda la aplicación y el visitante ve una pantalla en blanco.
  Caso concreto del día del despliegue: quien tenga el sitio abierto con el build anterior y navegue a una
  página diferida (`lazy`) pedirá un chunk con un hash que ya no existe en `public_html/assets/`; `import()`
  falla y, sin boundary, la aplicación desaparece.
- Corrección: un `ErrorBoundary` de clase alrededor de `<AppRoutes />` (en `main.tsx`) con un mensaje en español
  y botones "Recargar" / "Ir al inicio". Si el error es de carga de un módulo (`TypeError: Failed to fetch
  dynamically imported module` o `ChunkLoadError`), recargar la página una sola vez (con una marca en
  `sessionStorage` para no entrar en bucle). Complementa A-03 (`index.html` sin caché).

### M-01 · Medio · MongoDB: solo hay timeout de selección de servidor

- Archivo: `backend/src/Database/Mongo.php:17-37`.
- Riesgo: se configura `serverSelectionTimeoutMS = 5000`, pero no `connectTimeoutMS` (10 s por defecto) ni
  `socketTimeoutMS` (5 min por defecto en el driver). Si Atlas acepta la conexión pero responde lento (cluster
  M0 compartido con throttling), una petición puede quedar esperando minutos. En Linux, `max_execution_time` no
  cuenta el tiempo de espera de red, así que tampoco la corta. Con pocos procesos PHP en el plan compartido,
  unas cuantas peticiones colgadas bastan para que el sitio deje de responder.
- Corrección: agregar `connectTimeoutMS => 5000` y `socketTimeoutMS => 15000` en las opciones del `Client`.
  `ConnectionTimeoutException` hereda de `ConnectionException`, así que el `ErrorHandler` ya la convierte en
  `503 DB_UNAVAILABLE`.

### M-05 · Medio · El frontend no limita el tiempo de espera de las peticiones

- Archivo: `frontend/src/lib/api.ts:290-310` (`request`).
- Riesgo: `fetch` sin timeout; si la API tarda (ver M-01), las secciones quedan en "Cargando…" indefinidamente
  en vez de mostrar `ErrorCarga` con "Reintentar".
- Corrección: combinar la señal del llamador con `AbortSignal.timeout(20000)` (30–60 s para subidas
  multipart) y traducir el `TimeoutError` a `ApiError('NETWORK_ERROR', …)`.

### B-01 · Bajo · `/api/v1/health` solo revisa MongoDB

- Archivo: `backend/src/Controllers/HealthController.php`.
- Riesgo: responde `ok` aunque `storage/` no se pueda escribir (sin logs ni rate limiting, que además es
  *fail-open*) o falte la configuración de Cloudinary o del SMTP.
- Corrección: agregar `storage: ok|error` (escritura de prueba en `storage/cache`) y `cloudinary`/`mail`:
  `configurado|sin_configurar` (sin llamar a servicios externos, para que el health siga siendo rápido). No
  exponer detalles.

### B-02 · Bajo · Errores fatales de PHP responden 500 sin cuerpo JSON

- Archivo: `backend/public/index.php`.
- Riesgo: falta de memoria o `max_execution_time` no son excepciones; con `display_errors=0` la respuesta es un
  500 vacío. No se filtran datos (el frontend lo muestra como error genérico), pero se rompe el formato
  `{"error":{…}}`.
- Corrección: `register_shutdown_function` que, si `error_get_last()` es fatal y no se enviaron cabeceras,
  responda `500 INTERNAL_ERROR` en JSON.

### B-03 · Bajo · La animación de carga depende de Cloudinary y retrasa cada navegación

- Archivo: `frontend/src/components/PageLoader.tsx`, `app/layout/AppLayout.tsx:7`.
- Riesgo: el logo de la moneda viene de `res.cloudinary.com` sin `f_auto,q_auto`; si Cloudinary falla se ve una
  moneda gris. Además, el overlay cubre la página 700 ms en cada cambio de ruta aunque ya esté lista.
- Corrección: servir el logo desde `public/` (como los íconos `favicon.ico` y `apple-touch-icon.png`) y bajar la duración o atarla a la carga real.

---

## 2. Seguridad

### A-03 · Alto · El `.htaccess` del sitio no envía cabeceras de seguridad ni controla la caché

- Archivos: `frontend/dist/.htaccess` = `deploy/public_html/.htaccess` (la API sí las envía desde
  `SecurityHeadersMiddleware`).
- Riesgo:
  - Sin `X-Frame-Options` / `frame-ancestors`, el panel de administración se puede incrustar en un iframe de otro
    sitio (*clickjacking*).
  - Sin `X-Content-Type-Options`, `Referrer-Policy` ni HSTS en las páginas del sitio.
  - El JWT se guarda en `localStorage`/`sessionStorage` (`lib/api.ts:228-251`), así que cualquier XSS futuro
    roba la sesión; hoy no se encontró ninguno (Markdown seguro, sin `dangerouslySetInnerHTML`), pero no hay
    CSP como segunda barrera.
  - `index.html` sin `Cache-Control`: el navegador puede reutilizar un `index.html` viejo que apunta a chunks
    borrados (agrava A-02). Los `/assets/*` con hash no tienen caché larga (RNF-06).
- Corrección propuesta (agregar al inicio del `.htaccess` raíz; **probar en Hostinger** con
  `curl -I https://…/` y `curl -I https://…/assets/<archivo>.js`, porque LiteSpeed no soporta todas las
  directivas de Apache):

  ```apache
  <IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Content-Security-Policy "frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
    <FilesMatch "^index\.html$">
      Header set Cache-Control "no-cache"
    </FilesMatch>
  </IfModule>
  ```

  más un `assets/.htaccess` con `Header set Cache-Control "public, max-age=31536000, immutable"` (evita `<If>`,
  que LiteSpeed puede no interpretar). Para HSTS: activar "Forzar HTTPS" en hPanel y luego agregar
  `Header always set Strict-Transport-Security "max-age=15552000"` solo cuando todo el sitio responda por HTTPS.
  Una CSP completa (`script-src`, `img-src` y `object-src` con `https://res.cloudinary.com` por el visor de PDF,
  `connect-src`, Google Identity si se usa RF-17) conviene probarla primero en modo `Report-Only`, después del
  despliegue.

### A-04 · Alto · Sin cierre automático de la sesión a las 8 h en el frontend (RNF-05)

- Archivos: `frontend/src/components/AuthProvider.tsx`, `frontend/src/lib/api.ts:28` y `:228-251`.
- Riesgo: el backend vence el token a las 8 h, pero el frontend no usa `expira_en`. Una pantalla ya cargada (por
  ejemplo el panel en un equipo compartido de la universidad) sigue mostrando datos y el menú del usuario
  después de vencida la sesión, hasta la siguiente petición. El ERS pide que la sesión "se cierre
  automáticamente".
- Corrección: guardar la fecha de vencimiento junto al token (`Date.now() + expira_en * 1000`, en el mismo
  almacenamiento), descartarla al leer si ya pasó y, en `AuthProvider`, programar un `setTimeout` que llame a
  la limpieza de sesión (la misma del manejador de `401`, con `sesionExpirada = true`) al vencer. Volver a
  comprobar en `visibilitychange` (los temporizadores se congelan en pestañas suspendidas).

### M-03 · Medio · `POST /auth/reset-password` es una ruta pública de escritura sin rate limit

- Archivo: `backend/src/Controllers/AuthController.php:136-145`.
- Riesgo: el token tiene 256 bits (fuerza bruta inviable), pero cada petición con un token bien formado hace
  una búsqueda en `Credenciales` por `token_recuperacion`, que no tiene índice (DEC-B14), y con token válido
  calcula un hash de contraseña. Es la única ruta pública de escritura sin límite (login, forgot, contacto,
  reseñas, inscripciones, Google y cambio de contraseña sí lo tienen).
- Corrección: `rateLimiter->limitar('reset', $ip, $s->rateLimitForgotMax, $s->rateLimitForgotWindowSeconds)`
  antes de validar, reutilizando los límites de `forgot` (sin variables nuevas).

### B-04 · Bajo · Imágenes externas en el Markdown

- Archivo: `frontend/src/components/Markdown.tsx:27`.
- Riesgo: el contenido puede insertar `![](http://otro-sitio/pixel.png)`: contenido mixto o rastreo de
  visitantes. Lo mitiga que solo se publica lo que aprueba un revisor (DEC-B33).
- Corrección: mostrar solo imágenes `https:` (y, si se quiere, solo de `res.cloudinary.com`).

### B-05 · Bajo · Dependencias con avisos (solo desarrollo)

- `npm audit`: **0 vulnerabilidades en producción** (`--omit=dev`); 13 en desarrollo (4 altas: `vite`,
  `source-map-js`, `brace-expansion`, `zod`; 9 moderadas en `eslint`/`typescript-eslint`). No llegan al build
  servido, pero `vite` afecta al servidor de desarrollo.
- `composer audit`: 1 aviso en `symfony/filesystem` (dependencia de desarrollo de php-cs-fixer, no está en
  `deploy/siiis-api/vendor`). En producción, 2 avisos de `mongodb/mongodb` **ignorados con justificación** en
  `composer.json` (DEC-B03: GridFS no se usa; los nombres de BD y colecciones son constantes).
- Corrección: `npm audit fix` y `composer update symfony/filesystem` en una rama aparte, después del
  despliegue. Mantener la regla de DEC-B03 (no usar GridFS).

### Revisado sin hallazgos

- **Inyección NoSQL**: toda entrada pasa por `InputReader`/`QueryReader`, que rechazan arreglos y objetos donde se
  espera texto; los ids pasan por `ObjectIdValidator` (también rechaza `\n` final); los filtros de listados usan
  listas blancas.
- **Autorización / IDOR**: todas las rutas de escritura llevan `AuthMiddleware`, y las administrativas
  `RoleMiddleware` (`Config/Routes.php`). Usuarios: propio o gestionable según DEC-B17; no se puede cambiar el
  propio rol ni estado; se protege al último superadministrador. Documentos: autor o revisor, y el autor solo
  mientras está `en_revision` (con escritura condicionada para evitar carreras). Inscripciones y buzón: solo
  administrativos. Los documentos de inscripción se entregan con URL firmada (DEC-B50).
- **Subidas**: MIME por contenido (`finfo`), extensión derivada del MIME, tamaño máximo por tipo, nombre saneado
  y truncado; los temporales de PHP nunca quedan bajo `public_html`.
- **Exposición de archivos**: `siiis-api/.htaccess` con `Require all denied`; `index.php` de la API apunta a
  `../../siiis-api/bootstrap.php`; `storage/` y `.env` fuera de `public_html`; el paquete `siiis-api.zip` no trae
  `.env` y lleva `storage/` vacío.
- **XSS**: sin `dangerouslySetInnerHTML`; Markdown con `skipHtml`; enlaces externos con `noopener noreferrer`.
- **Cabeceras de la API**: `nosniff`, `X-Frame-Options: DENY`, CSP `default-src 'none'; frame-ancestors
  'none'`, `Referrer-Policy: no-referrer`, `Cache-Control: no-store` por defecto y HSTS solo en producción.

---

## 3. Rendimiento y escalabilidad

### M-04 · Medio · El video de presentación puede agotar el plan gratuito de Cloudinary

- Archivos: `backend/src/Services/CloudinaryService.php` (video hasta `MULTIMEDIA_VIDEO_MAX_BYTES`, 50 MB),
  `frontend/src/features/home/HomePage.tsx:32`.
- Riesgo: el plan gratuito da 25 créditos al mes (1 crédito ≈ 1 GB de ancho de banda, 1 GB de almacenamiento o
  1 000 transformaciones). Un video de 50 MB reproducido completo 500 veces al mes consume unos 25 GB, todo el
  cupo. Si se agota, Cloudinary deja de entregar **todas** las imágenes (slider, fotos, adjuntos). `preload=
  "metadata"` ayuda (no descarga el video hasta que se reproduce).
- Corrección: subir el video ya comprimido (720p, unos 10–15 MB) o bajar `MULTIMEDIA_VIDEO_MAX_BYTES`; revisar el
  consumo en el panel de Cloudinary la primera semana. Alternativa: video en YouTube (requeriría una decisión,
  porque la spec 8.2 fija Cloudinary).

### B-06 · Bajo · Índices para consultas frecuentes fuera de la lista oficial

- Archivos: `backend/scripts/crear_indices.php` (crea los 6 índices de la spec 8.4) y los repositorios.
- Riesgo: sin índice hoy: `Sesiones.usuario_id` (cierre de sesiones al desactivar o cambiar contraseña),
  `Documentos.autor_id` / `integrantes` (perfil y "mis contenidos"), orden por `fecha_creacion`,
  `Reseñas.estado` + fecha, `Multimedia.seccion` + `orden`, `Inscripciones.correo` + `estado_solicitud`.
  Con el volumen esperado (cientos de documentos) el impacto es despreciable; se nota a partir de miles.
- Corrección: registrarlos como PROPUESTA en `docs/decisions.md` para el equipo de BD (la spec 8.4 es la lista
  oficial). **Verificar en Atlas (solo lectura, pestaña *Indexes*) que los 6 oficiales existen en `siiis`**;
  esta auditoría no se conectó a esa base.

### B-07 · Bajo · Contadores de rate limiting: dependen de un cron

- Archivos: `backend/src/Security/RateLimiter.php`, `backend/cron/limpiar_ratelimit.php`.
- Riesgo: un archivo por (acción, IP) que solo borra el cron. Sin el cron, crecen sin límite y consumen inodos
  del plan compartido (Hostinger limita la cantidad de archivos). El cron está documentado
  (`docs/deployment.md:241-244`) pero falta registrarlo en hPanel (checklist).
- Corrección: registrar el cron diario el día del despliegue. Opcional: purga oportunista (1 de cada 1 000
  peticiones llama a `purge`).

### B-08 · Bajo · Carrusel sin `loading="lazy"` en las fotos que no son la primera

- Archivo: `frontend/src/components/ImageCarousel.tsx:189`.
- Corrección: `loading="lazy"` y `decoding="async"` para `i > 0`; `fetchpriority="high"` en la primera.

### Revisado sin hallazgos

- **Paginación**: `page` de 1 a 10 000 y `per_page` con máximo configurable en usuarios, documentos, reseñas,
  inscripciones y mensajes; multimedia limitado por `HOME_MAX_ITEMS`/`HOME_MAX_VIDEOS`.
- **N+1**: autores e integrantes se resuelven con una sola consulta `$in` (`UsuariosRepository:123`). El perfil
  del integrante hace 4 peticiones en paralelo (proyectos y artículos × autor e integrante), con `per_page=50`.
- **Caché HTTP**: `/home/*` `public, max-age=300`; listados y detalles de documentos y reseñas `public,
  max-age=60` sin token y `no-store` con token, siempre con `Vary: Authorization`; usuarios `public, no-cache`.
- **Build**: JS inicial 103 KB gzip, CSS 17 KB gzip, Markdown (49 KB gzip) y panel en chunks diferidos.
- **Atlas M0**: 512 MB y hasta 500 conexiones. Los archivos están en Cloudinary, así que en MongoDB solo hay
  texto (estimado: muy por debajo de 50 MB en el primer año). `ext-mongodb` reutiliza el cliente dentro de cada
  proceso PHP; el plan compartido tiene pocos procesos, lejos del límite. Las sesiones vencidas las borra el
  índice TTL.

---

## 4. Mantenibilidad y recuperabilidad

### A-01 · Alto · No existe ningún respaldo de la base de datos

- Contexto: Atlas M0 no tiene respaldos automáticos ni restauración a un punto en el tiempo. En el repositorio no
  hay ningún script de exportación (`backend/scripts/` no tiene uno; `docs/deployment.md` no lo menciona).
- Riesgo: un borrado accidental (por ejemplo un mal uso de `scripts/eliminar_cuentas.php`), un error de
  migración o la pérdida de la cuenta de Atlas no tienen vuelta atrás: cuentas, documentos, reseñas e
  inscripciones.
- Corrección propuesta (**no implementada**): `backend/scripts/exportar_colecciones.php`.
  - Solo CLI (mismo guard que `cron/limpiar_ratelimit.php`), lee el `.env` con `Settings::fromEnv`.
  - Recorre las colecciones de `Siiis\Database\Collections` (las 8 oficiales; `Sesiones` opcional con
    `--con-sesiones`, porque es efímera) y escribe un archivo por colección en
    `storage/backups/AAAA-MM-DD_HHMM/<coleccion>.json.gz`, en Extended JSON canónico
    (`MongoDB\BSON\Document::fromPHP($doc)->toCanonicalExtendedJSON()`, una línea por documento), para que
    `mongoimport` o un script de restauración recuperen `ObjectId` y fechas exactas.
  - Escribe `manifest.json` con la cantidad de documentos por colección y el nombre de la base.
  - Permisos `0600` en los archivos y `0700` en la carpeta; `storage/` ya está fuera de `public_html`.
  - `--conservar=N` borra los respaldos con más de N días (por defecto 30).
  - Cron semanal en hPanel (por ejemplo, domingo 4:00) y, una vez al mes, descargar la última carpeta a un
    almacenamiento externo del semillero (Drive institucional). Los respaldos contienen hashes de contraseñas
    y datos personales: no se suben a Git ni a carpetas compartidas abiertas.
  - Script hermano `restaurar_colecciones.php --desde=CARPETA --base=siiis_test` que, por seguridad, por
    defecto solo restaure en una base distinta de `siiis`.
- Mientras tanto, **antes de desplegar hoy**: hacer una exportación manual desde Atlas (Compass → *Export
  Collection* en JSON, o `mongodump` si está disponible).

### M-02 · Medio · Los logs no rotan ni se purgan

- Archivo: `backend/src/Support/Logger.php` (`storage/logs/app-AAAA-MM-DD.log`, fuera de `public_html`).
- Riesgo: un archivo por día que nunca se borra; un error repetido (por ejemplo Atlas caído con mucho tráfico)
  escribe una línea con traza por petición y puede llenar la cuota de disco. Contenido: no se registran
  contraseñas ni tokens, y `zend.exception_ignore_args=1` quita los argumentos de las trazas. Pero el
  `ErrorHandler` registra `$exception->getMessage()` de errores no controlados, que en errores del driver puede
  incluir valores de la consulta (por ejemplo un correo).
- Corrección: agregar la purga de logs con más de 30 días al cron de mantenimiento (o un
  `cron/limpiar_logs.php`), y un tope por archivo (si `app-hoy.log` supera, por ejemplo, 5 MB, dejar de escribir
  trazas y solo contar). Opcional: no incluir `message` del driver en el log, solo la clase de la excepción.

### B-09 · Bajo · `docs/PLAN.md` no existe

- `CLAUDE.md` exige leer y actualizar `docs/PLAN.md`, pero el archivo no está en el repositorio (nunca se
  versionó). El estado real está repartido entre `README.md`, `docs/checklist-manana.md` y `docs/decisions.md`.
- Corrección: crear `docs/PLAN.md` con el estado actual y lo que queda (puede partir del plan de corrección de
  esta auditoría), o ajustar `CLAUDE.md`.

### B-10 · Bajo · Decisiones abiertas que afectan la entrega

- DEC-B63 sigue en **PROPUESTA** para `codigo_estudiantil` y `areas_interes` (cambio del modelo de
  `Inscripciones`, regla 3 de `CLAUDE.md`): ya están en producción en el código.
- DEC-B65 quitó la obligación de `acepta_tratamiento_datos`. No es un requisito del ERS, pero el formulario
  recoge datos personales; conviene que el equipo confirme que es aceptable frente a la política de
  `/privacidad`.
- Corrección: cerrar ambas con el equipo y dejar constancia en `docs/decisions.md`.

---

## 5. Usabilidad y accesibilidad

Bien resuelto: `lang="es"`; formularios con `<label>` que envuelven o apuntan a cada campo; `aria-invalid` y
mensajes por campo en español (`lib/errors.ts`, `fields` del backend); `role="alert"` en errores y
`role="status"` en cargas; menú principal y menú de usuario con `aria-expanded`/`aria-controls` y cierre con
Escape; foco visible (`:focus-visible` dorado) en el menú, el selector de archivos y los controles del carrusel;
`prefers-reduced-motion`; contraste de la paleta correcto para texto (azul `#0b2d4f` y gris `#5b6878` sobre
blanco, dorado `#fac542` solo sobre azul; el bronce `#b07d2e`, de bajo contraste, solo se usa en íconos y
bordes); textos alternativos en el carrusel, los desarrolladores y el logo (decorativo con `alt=""` donde hay
texto al lado).

### B-11 · Bajo · La animación de carga se anuncia dos veces y bloquea el contenido

- Archivo: `frontend/src/components/PageLoader.tsx`.
- Riesgo: las dos caras de la moneda tienen `alt="Logo del semillero"` dentro de un `role="status"` con
  `aria-label`; un lector de pantalla puede leer "Cargando contenido, Logo del semillero, Logo del semillero" en
  cada navegación.
- Corrección: `alt=""` en ambas imágenes (el `aria-label` del contenedor basta).

### B-12 · Bajo · Mensajes genéricos ante errores sin cuerpo

- Archivo: `frontend/src/lib/api.ts:316-321`.
- Riesgo: si la respuesta no es JSON (500 vacío de B-02, página de error del hosting), el `message` queda vacío y
  depende de `mensajeError` para mostrar algo; verificar que siempre haya un texto en español
  ("El servidor no respondió correctamente. Intenta de nuevo en unos minutos.").
- Corrección: valor por defecto por código de estado en `mensajeError` (503: "El servicio no está disponible…",
  429: "Demasiados intentos…" con `Retry-After`).

---

## Plan de corrección

### Antes de desplegar hoy

| # | Acción | Hallazgo | Esfuerzo |
|---|---|---|---|
| 1 | Exportación manual de la base `siiis` desde Atlas (Compass o `mongodump`) y guardarla fuera del repo | A-01 | 15 min |
| 2 | Cabeceras de seguridad y caché (`index.html` no-cache, `/assets` immutable) en `.htaccess` raíz; "Forzar HTTPS" en hPanel | A-03 | 30 min |
| 3 | `ErrorBoundary` global con recarga única ante fallo de chunk | A-02 | 1 h |
| 4 | Cierre automático de sesión con `expira_en` (+ `visibilitychange`) | A-04 / RNF-05 | 1 h |
| 5 | `connectTimeoutMS` y `socketTimeoutMS` en `Mongo.php` | M-01 | 10 min |
| 6 | Rate limit en `reset-password` | M-03 | 10 min |
| 7 | Checklist de hosting: cron de `limpiar_ratelimit.php`, índices oficiales presentes en `siiis` (pestaña *Indexes*), límites de PHP y SMTP desde Hostinger | B-06, B-07 | 30 min |
| 8 | Decidir con el equipo/cliente RF-11 (documentos de inscripción) y RF-05 (panel lateral y previsualización en el perfil): implementarlo o dejar constancia de que se entrega así | Parte A | reunión |

Los puntos 3 a 6 tocan `frontend/` (lo desarrolla otro integrante) y `backend/`; requieren tu aprobación y la
del responsable del frontend. Después de 5 y 6: `composer test`, `composer lint` y `composer analyse`.

### Puede esperar (próximas semanas)

1. Script de respaldo y restauración automatizado con cron (A-01, diseño arriba).
2. Purga y tope de logs (M-02).
3. Revisar el consumo de Cloudinary la primera semana y comprimir el video (M-04).
4. Timeout en `fetch` del frontend (M-05).
5. Si se decide implementar: selector de documentos en la inscripción (RF-11) y panel de "artículos de interés"
   en el perfil (RF-05).
6. Health con `storage` y configuración de servicios (B-01); shutdown handler JSON (B-02).
7. CSP completa en modo `Report-Only` y luego obligatoria (A-03, segunda fase).
8. Índices adicionales como PROPUESTA al equipo de BD (B-06).
9. Mejoras menores: logo local y duración del loader (B-03, B-11), lazy en el carrusel (B-08), imágenes
   `https` en Markdown (B-04), mensajes por código (B-12), `npm audit fix`/`composer update` de dev (B-05),
   `docs/PLAN.md` (B-09) y cierre de DEC-B63/DEC-B65 (B-10).
