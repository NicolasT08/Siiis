# Checklist del despliegue en Hostinger con el profesor

Pasos en orden. Cada uno dice **qué hacer**, **cómo verificarlo** y **qué hacer si falla**. Marca `[x]` al
terminar y anota los valores en los `____`; al final pásalos a la checklist de `docs/deployment.md`.

Convenciones: el dominio es `siiis.com.co` y la carpeta del sitio en el servidor es
`/home/<usuario>/domains/siiis.com.co/` (hPanel la muestra en *Archivos → Administrador de archivos*). Las
pruebas con `curl` se pueden hacer desde Git Bash en el portátil.

**Plan B:** si en el paso 3 no aparece la extensión `mongodb` (o es menor que 1.18), se pasa a "Plan B" al final
de este documento: el backend va a Render y el frontend sigue en Hostinger.

---

## A. Antes de salir (en casa, con el repositorio)

- [ ] **A1. El código está al día y verificado.** En `backend/`: `composer test`, `composer lint`,
  `composer analyse` sin errores. Haz el commit (y el push, si el plan B usa Render con GitHub).
  - Si falla: no despliegues una versión que no pasa; corrige primero.
- [ ] **A2. Frontend y paquete.** Primero el build del frontend, **en PowerShell** (no en Git Bash):
  `cd frontend; npm run build` (usa `/api/v1` por defecto). Luego, desde la raíz:
  `powershell -ExecutionPolicy Bypass -File backend\scripts\empaquetar.ps1 -Zip`.
  - Verifica: termina con `Listo:` y existen `deploy\siiis-api.zip` y `deploy\public_html.zip`; este último trae
    el frontend (`index.html`, `assets/`, `.htaccess`) **y** `api/`.
  - Si falla: el script dice el motivo: falta `php`/`composer`; `vendor` local desactualizado → `composer install`
    en `backend/` y repetir; `frontend\dist` no existe o es un build de desarrollo (apunta a `localhost:8000` o se
    compiló con `VITE_API_URL=/api/v1` desde Git Bash) → vuelve a compilar con `npm run build` en PowerShell.
- [ ] **A3. Secretos a mano** (en un gestor de contraseñas o una nota local, **nunca** en el repo ni en el chat):
  - `JWT_SECRET` nuevo: `php -r "echo bin2hex(random_bytes(32));"` (64 caracteres).
  - `MONGO_URI` de producción: en Atlas, *Database Access* → usuario de la aplicación con contraseña fuerte
    (24+ caracteres aleatorios) y rol `readWrite` **solo** sobre la base `siiis` (DEC-B54).
  - `MAIL_*` y `CLOUDINARY_*`: las de desarrollo, por ahora (DEC-B55). `CONTACTO_CORREO_DESTINO`: el buzón que
    recibirá contacto y avisos de inscripción.
  - Datos del superadministrador inicial (correo, nombre, contraseña que cumpla 8+ caracteres con letra y número).
- [ ] **A4. Atlas.** *Network Access* → `0.0.0.0/0` (DEC-B54). Verifica en el portátil, con `MONGO_URI` de
  producción en un `.env` local temporal, que `php scripts/crear_indices.php` conecta (es idempotente).
  - Si falla: revisa usuario/contraseña (caracteres especiales en la URI van codificados, p. ej. `@` → `%40`).
- [ ] **A5. Cloudinary.** En la cuenta de desarrollo ya está activado *Settings → Security → Allow delivery of PDF
  and ZIP files*. Compruébalo en 10 segundos.
- [ ] **A6. Fotos y videos del inicio.** Arma una carpeta local, p. ej. `C:\siiis-inicio\`, con:
  - `slider\`: fotos JPG, PNG o WebP (máx. 10 MB c/u), con prefijo de orden: `01-Inauguracion.jpg`, `02-Laboratorio.jpg`…
  - `videos\`: videos MP4, WebM o MOV (máx. 100 MB c/u): `01-Presentacion.mp4`…
  - `descripciones.json` (opcional, es el texto alternativo): `{"01-Inauguracion.jpg": "Inauguración del semillero 2024"}`.
    Sin él, la descripción sale del nombre del archivo.

  Compruébala sin escribir nada: en `backend/`, `php scripts/cargar_multimedia.php --carpeta=C:\siiis-inicio --simular`
  (con el `.env` local de pruebas basta). Debe listar cada archivo con `SUBIR` y ningún `!`.
  - Si sale `!`: el mensaje dice qué archivo y por qué (formato, tamaño o nombre repetido). Corrígelo y repite.

## B. hPanel: PHP

- [ ] **B1. Versión de PHP.** *Sitios web → siiis.com.co → Avanzado → Configuración de PHP → Versión de PHP* →
  **8.2** (o 8.3 si no hay 8.2). Anota: `____`.
  - Verifica: en B3 (`phpinfo`).
  - Si falla (solo hay < 8.2): el backend no corre → Plan B.
- [ ] **B2. Extensiones.** Misma pantalla, pestaña *Extensiones PHP*: activa **`mongodb`**, `fileinfo`, `curl`,
  `openssl`, `mbstring` y guarda.
  - Si `mongodb` no está en la lista: pregunta por chat a soporte de Hostinger si la pueden activar; si no → Plan B.
- [ ] **B3. Comprobar con `phpinfo`.** En el Administrador de archivos, crea `public_html/info-temp.php` con
  `<?php phpinfo();` y abre `https://siiis.com.co/info-temp.php`.
  - Verifica y anota: versión de PHP `____`; sección **mongodb** presente, versión `____`; `fileinfo`, `curl`,
    `openssl` y `mbstring` presentes.
  - mongodb **≥ 1.18**: seguimos (1.21 o mayor también sirve sin cambios hoy; ver "Versiones fijadas" en
    `docs/deployment.md` para después). **< 1.18** o ausente: Plan B.
  - **Borra `info-temp.php` en cuanto termines** (expone la configuración del servidor).
- [ ] **B4. Límites de PHP** (*Configuración de PHP → Opciones de PHP*): `upload_max_filesize` ≥ **6M**,
  `post_max_size` **16M**, `max_execution_time` **120**, `memory_limit` **256M**. Anota los finales: `____`.
  - Si no deja subirlos tanto: con menos de 16M la inscripción con 3 archivos grandes responde `400`; se puede
    seguir y anotarlo como limitación.
- [ ] **B5. SSL.** *Seguridad → SSL*: certificado activo para `siiis.com.co` y "Forzar HTTPS" encendido.
  - Verifica: `https://siiis.com.co` abre con candado.
  - Si falla: instala el SSL gratuito y espera unos minutos; sin HTTPS no sigas (el token viajaría en claro).

## C. Subir el backend

- [ ] **C1. `siiis-api/` fuera de `public_html`.** Administrador de archivos → entra a `domains/siiis.com.co/`
  (la carpeta que **contiene** a `public_html`) → sube `siiis-api.zip` → clic derecho → *Extraer* → borra el zip.
  - Verifica: existen `domains/siiis.com.co/siiis-api/bootstrap.php`, `.../vendor/autoload.php` y
    `.../storage/logs`, `ratelimit` y `cache`.
  - Si no se puede escribir junto a `public_html`: súbela a la carpeta de inicio (`/home/<usuario>/siiis-api`) y en
    C4 ajusta `BOOTSTRAP_PATH` a esa ruta absoluta. Si faltan las carpetas de `storage`, créalas a mano.
- [ ] **C2. `.env`.** En `siiis-api/`, copia `.env.produccion.ejemplo` como **`.env`** y edítalo: `MONGO_URI`,
  `JWT_SECRET`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `CLOUDINARY_CLOUD_NAME/API_KEY/API_SECRET`,
  `CONTACTO_CORREO_DESTINO` y, para el login con Google, `GOOGLE_CLIENT_ID` (el mismo `VITE_GOOGLE_CLIENT_ID` con el
  que se compiló el frontend en A2) con `GOOGLE_DOMINIO_PERMITIDO=uptc.edu.co`. Deja `APP_ENV=production`,
  `APP_DEBUG=false` y `CORS_ORIGIN` vacío.
  - Verifica: `APP_DEBUG=false` (con `true` la API no arranca a propósito, DEC-B56). Permisos del `.env`: `600` o
    `640` (clic derecho → *Permisos*).
- [ ] **C3. Permisos de `storage/`.** `siiis-api/storage` y sus tres carpetas con `755` (Hostinger ejecuta PHP con
  tu usuario, así que basta).
  - Verifica: después de E1 aparece `storage/logs/` con contenido solo si hubo errores; en E3 debe aparecer algo en
    `storage/ratelimit/`.
  - Si no se escribe: prueba `775`.
- [ ] **C4. Frontend, `public_html/api` y `.htaccess`.** En `public_html/` sube `public_html.zip` y extráelo **ahí**
  (crea `index.html`, `assets/`, `.htaccess` y `api/` con `index.php` y su `.htaccess`). Borra el zip. Si había un
  `default.php` o `index.php` de bienvenida de Hostinger, bórralo.
  - Verifica: `public_html/api/index.php` tiene la línea
    `const BOOTSTRAP_PATH = __DIR__ . '/../../siiis-api/bootstrap.php';`.
  - Si ya existía un `public_html/.htaccess` con algo de Hostinger (p. ej. forzar HTTPS), junta las reglas en vez
    de reemplazarlo.

## D. Base de datos y superadministrador

- [ ] **D1. ¿Hay SSH?** *Avanzado → Acceso SSH*. Anota: `sí / no`.
- [ ] **D2. Índices.** Con SSH: `cd ~/domains/siiis.com.co/siiis-api && php scripts/crear_indices.php`. Sin SSH:
  desde el portátil, en `backend/` con el `.env` local apuntando a la `MONGO_URI` de producción y
  `MONGO_DB_NAME=siiis`.
  - Verifica: el script lista los índices creados o ya existentes; en Atlas aparecen en las colecciones.
  - Si falla con `php` no encontrado por SSH: usa la ruta que muestra hPanel (p. ej. `/usr/bin/php82`).
- [ ] **D3. Superadministrador.** Igual que D2: `php scripts/crear_superadmin.php` (pide los datos por consola; la
  contraseña no se ve al escribirla).
  - Verifica: termina sin error y en Atlas hay un documento en `Usuarios` con rol `superadministrador`.
  - Si dice que el correo ya existe: ya estaba creado; usa ese.
  - Al terminar, **vuelve a dejar el `.env` local** como estaba (base de pruebas), si lo cambiaste (después de D4).
- [ ] **D4. Fotos y videos del inicio (para que la página no arranque vacía).** Después de D2/D3 y antes de E, con
  el mismo `.env` de producción (local o por SSH; en el servidor, sube antes la carpeta de A6):
  `php scripts/cargar_multimedia.php --carpeta=C:\siiis-inicio` (sin `--test`: base real `siiis`, carpeta
  `siiis/home` de Cloudinary). Muestra el plan y pide escribir `si` antes de subir o escribir.
  - Verifica: termina con `Listo: N subidos, 0 actualizados, 0 con error.` y en Atlas, colección `Multimedia`,
    hay un documento por archivo (`slider_principal` / `video_presentacion`). En E1 se puede comprobar también
    `curl https://siiis.com.co/api/v1/home/slider`.
  - Correrlo dos veces **no duplica**: lo ya cargado sale como `sin cambios`. Para cambiar el orden, renombra el
    prefijo (`02-` → `05-`) y vuelve a correrlo: solo actualiza `orden` y `descripcion`, sin volver a subir.
  - Si un archivo falla (`x …`): los demás quedan cargados; corrige ese archivo y repite (no duplica).
  - Si no hay tiempo: el sitio funciona igual; el carrusel muestra las fotos de respaldo del frontend y la sección de
    videos no aparece hasta que haya alguno.

## E. Verificar la API en producción

- [ ] **E1. Health.** `curl -i https://siiis.com.co/api/v1/health` → `200 {"status":"ok","db":"ok"}`.

  | Si responde… | Causa probable | Qué hacer |
  |---|---|---|
  | `500` `CONFIG_ERROR` | Falta o está mal una variable del `.env` (`MONGO_URI`, `JWT_SECRET` < 32, `APP_DEBUG=true`, `CORS_ORIGIN` mal escrito) | Revisa el `.env`; el detalle está en el `error_log` de `public_html/api/` o en los logs de hPanel |
  | `503` `DB_UNAVAILABLE` | Atlas no deja entrar | Revisa `0.0.0.0/0`, usuario y contraseña de la URI |
  | `500` en blanco / página de error de LiteSpeed | Falta `vendor/`, ruta de `BOOTSTRAP_PATH` mala o PHP < 8.2 | Revisa C1 y C4; mira `error_log` |
  | Error con `MongoDB\Driver` o "Class not found" | Extensión `mongodb` no activa | B2/B3 |
  | `404` en HTML (no JSON) | No se subió `api/.htaccess` o no se aplica la reescritura | Revisa C4 |

- [ ] **E2. Cabeceras y errores sin trazas.** `curl -i https://siiis.com.co/api/v1/no-existe` → `404` en JSON
  `{"error":{"code":"NOT_FOUND",...}}`, con `X-Content-Type-Options: nosniff`, `Strict-Transport-Security` y sin
  `X-Powered-By`.
- [ ] **E3. Login y token.** `curl -i -X POST https://siiis.com.co/api/v1/auth/login -H "Content-Type: application/json" -d '{"correo":"<correo>","password":"<clave>"}'`
  → `200` con `token`. Luego `curl -i https://siiis.com.co/api/v1/auth/me -H "Authorization: Bearer <token>"` → `200`.
  - Si `/auth/me` da `401` con un token recién emitido: el servidor no está pasando la cabecera `Authorization`;
    confirma que `public_html/api/.htaccess` es el del paquete (tiene la regla `HTTP_AUTHORIZATION`).
- [ ] **E4. PATCH y DELETE llegan a PHP.** Sin token: `curl -i -X DELETE https://siiis.com.co/api/v1/users/650f1c2e8b3a4a0012345678`
  y `curl -i -X PATCH https://siiis.com.co/api/v1/projects/650f1c2e8b3a4a0012345678` → **`401`** (no `405` ni
  `403` de LiteSpeed).
  - Si da `405`/`403` del servidor: anotarlo; hay que usar `X-HTTP-Method-Override` (spec 11.3) y avisar a frontend.
- [ ] **E5. Correo.** `POST /auth/forgot-password` con el correo del superadmin → llega el correo, con enlace a
  `https://siiis.com.co/restablecer?token=...` (la pantalla todavía no existe en el frontend; el enlace solo se
  revisa).
  - Si no llega (revisa spam): mira `siiis-api/storage/logs/app-<fecha>.log` (`No se pudo enviar el correo...`).
    Si Hostinger bloquea `smtp.gmail.com`, prueba `MAIL_PORT=465`; si tampoco, usar el SMTP del correo del dominio
    (DECISION-013).
- [ ] **E6. Cloudinary.** Con el token del superadmin, sube su foto:
  `curl -X POST https://siiis.com.co/api/v1/users/<id>/photo -H "Authorization: Bearer <token>" -F "foto=@foto.jpg"`
  → `200` y la `url` abre una imagen de 400 × 400.
  - Si `502 CLOUDINARY_UNAVAILABLE`: credenciales `CLOUDINARY_*` o salida HTTPS bloqueada; mira el log.
  - Si `400` en `fields.foto` con un archivo pequeño: revisa `upload_max_filesize`/`post_max_size` (B4).
- [ ] **E7. Contenido de punta a punta.** Crea un proyecto con el superadmin (`POST /api/v1/projects`), súbele un PDF
  (`POST /api/v1/projects/<id>/files` con `-F "archivo=@prueba.pdf"`) → `201` y el PDF abre; publícalo
  (`PATCH` con `{"estado":"ejecucion","visible":true}`) y comprueba que sale en `GET /api/v1/projects` sin token;
  luego bórralo (`DELETE`).
  - Si el PDF da `401` al abrirlo: falta "Allow delivery of PDF" en Cloudinary (A5).
- [ ] **E8. Inscripción, contacto y reseñas.** Inscripción con un correo `@uptc.edu.co` real del equipo, una carta en
  PDF y la casilla (`acepta_tratamiento_datos=true`) → `201` y llegan los dos correos. Con el superadmin:
  `GET /api/v1/inscriptions/<id>` → enlaces que descargan; `PATCH` `{"estado_solicitud":"rechazada"}` → `200`.
  `POST /api/v1/contact` → `201` y llega al buzón. `POST /api/v1/reviews` sin sesión → queda `oculta`.
  - La solicitud de prueba queda guardada (no hay `DELETE`): entra en el borrado manual de rechazadas a los 6 meses
    (`docs/deployment.md`, "Mantenimiento"), o bórrala a mano hoy en Atlas y Cloudinary.
- [ ] **E9. IP real.** En Atlas, `Sesiones` → el último documento: `ip_acceso` debe ser la IP pública del portátil
  (búscala en cualquier "cuál es mi IP"). Anota: `____`.
  - Si aparece siempre la misma IP de Hostinger: configurar `TRUSTED_PROXIES` (`docs/deployment.md`); mientras
    tanto el rate limiting cuenta para todo el sitio.
- [ ] **E10. Login con Google.** Antes de mañana, en Google Cloud, `https://siiis.com.co` debe estar en los
  **orígenes de JavaScript autorizados** del ID de cliente y la pantalla de consentimiento, publicada (o de tipo
  *Interno*); ver `docs/deployment.md`, "Login con Google". En el sitio: *Iniciar sesión* → "Iniciar sesión con
  Google" con una cuenta `@uptc.edu.co` que tenga usuario → entra. Con una `@gmail.com` → mensaje de cuenta
  institucional; con una `@uptc.edu.co` sin usuario → mensaje con enlace a la inscripción.
  - Si el botón no aparece: el build no tenía `VITE_GOOGLE_CLIENT_ID` (repite A2) o el origen no está autorizado
    (`origin_mismatch` en la ventana de Google).
  - Si responde `404`: falta `GOOGLE_CLIENT_ID` en el `.env` (C2). `503 GOOGLE_UNAVAILABLE`: el servidor no llega a
    `www.googleapis.com`. Si no se resuelve, se puede seguir: el login con contraseña funciona igual.

## F. Cron y frontend

- [ ] **F1. Cron.** *Avanzado → Cron Jobs* → diario 03:00:
  `/usr/bin/php /home/<usuario>/domains/siiis.com.co/siiis-api/cron/limpiar_ratelimit.php` (la ruta de PHP la
  muestra hPanel).
  - Verifica: en el historial del cron, la salida dice cuántos contadores borró.
- [ ] **F2. Frontend.** Ya quedó subido en C4 (viene en `public_html.zip`). Si hay que actualizarlo, repite A2 y
  sube de nuevo el zip: trae `api/` igual, así que no se pierde.
  - Verifica: `https://siiis.com.co` carga, el carrusel muestra las fotos de D4, aparece la sección de videos, inicias
    sesión con el superadmin y ves "Administración". Una ruta interna recargada
    (`https://siiis.com.co/proyectos`) no da `404` (la sirve `index.html`). `https://siiis.com.co/api/v1/health`
    sigue respondiendo.
  - Si al recargar una ruta interna da `404`: falta el `public_html/.htaccess` del paquete.

## G. Cierre

- [ ] **G1.** `info-temp.php` borrado (B3). `curl -i https://siiis.com.co/info-temp.php` → `404`.
- [ ] **G2.** `https://siiis.com.co/siiis-api/.env` → `404` (la carpeta está fuera de `public_html`).
- [ ] **G3.** Borra el `.env` local temporal con la `MONGO_URI` de producción si lo creaste (A4, D2).
- [ ] **G4.** Pasa los valores anotados (PHP, `mongodb`, límites, SSH, IP) a la checklist de `docs/deployment.md`
  y a DEC-B13 en `docs/decisions.md`.

---

## Plan B (si no hay `ext-mongodb` en Hostinger)

Pasos completos en `docs/deployment.md`, "Plan B: backend en Render (Docker)". Resumen para hacerlo en la misma
sesión (unos 20–30 minutos):

1. Render → *New → Web Service* → repositorio de GitHub → runtime **Docker**, *Root Directory* `backend`.
2. Variables: las del `.env` de C2, con `APP_URL=https://<servicio>.onrender.com`,
   `CORS_ORIGIN=https://siiis.com.co` y `PASSWORD_RESET_URL=https://siiis.com.co/restablecer`.
   *Health check path*: `/api/v1/health`.
3. Espera el build (5–10 min la primera vez). Verifica E1–E4 contra `https://<servicio>.onrender.com/api/v1/...`.
   - Si el build falla en `pecl install mongodb-1.21.9`: en *Settings → Build*, agrega el argumento
     `MONGODB_EXT_VERSION` con otra versión 1.21.x.
4. D2, D3 y D4 desde el portátil (Render no tiene consola en el plan Free).
5. Frontend: en PowerShell, `cd frontend; $env:VITE_API_URL='https://<servicio>.onrender.com/api/v1'; npm run build`
   y sube el **contenido** de `frontend\dist\` a `public_html/` (sin `api/`; el `.htaccess` sí).
6. Avisar al profesor de las limitaciones: el plan Free se duerme sin uso (la primera petición tarda ~1 min) y puede
   no dejar salir correo por SMTP (en ese caso, plan Starter).
