# Contrato API — SIIIS V3 · Fases 1 a 5

Documento para Front-End (React + TypeScript). Fuente de verdad: `docs/SIIIS_V3_SPEC_DRIVEN.md`, sección 11.

## Convenciones generales

| Tema | Regla |
|---|---|
| URL base | Producción: `https://siiis.com.co/api/v1` (o relativa `/api/v1`). Desarrollo: `http://localhost:8000/api/v1` |
| Formato | JSON UTF-8. Las peticiones con cuerpo envían `Content-Type: application/json`, salvo las subidas de archivos (`multipart/form-data`, también en UTF-8) |
| Autenticación | `Authorization: Bearer <token>` en rutas protegidas |
| IDs | Strings hexadecimales de 24 caracteres, en el campo `id` (nunca `_id`) |
| Fechas | ISO 8601 UTC, p. ej. `2026-09-23T15:00:00Z` |
| CORS | Solo en desarrollo, para `FRONTEND_ORIGIN` (por defecto `http://localhost:5173`). En producción no hace falta (mismo dominio) |
| Imágenes | Las URL de imágenes de Cloudinary llegan con `f_auto,q_auto` (p. ej. `https://res.cloudinary.com/<cloud>/image/upload/f_auto,q_auto/v1/siiis/perfiles/abc.png`): Cloudinary elige el formato y la calidad para cada navegador. Úsalas tal cual, sin quitar ni agregar transformaciones. Los PDF y los videos llegan sin cambios (DEC-B42) |

### Formato de error (todas las rutas)

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Los datos enviados no son válidos.",
    "fields": { "correo": "El correo no tiene un formato válido." }
  }
}
```

- `code` es estable: úsalo para decidir la lógica en el frontend. `message` es texto en español que puede mostrarse.
- `fields` solo aparece en `VALIDATION_ERROR`, con un mensaje por campo.

| Código | HTTP | Cuándo |
|---|---|---|
| `VALIDATION_ERROR` | 400 | Campos faltantes, con tipo incorrecto (p. ej. un objeto donde se espera texto) o con formato inválido |
| `INVALID_JSON` | 400 | El cuerpo no es JSON válido o no es un objeto `{...}` |
| `INVALID_CREDENTIALS` | 401 | Correo o contraseña incorrectos (mensaje genérico) |
| `UNAUTHORIZED` | 401 | Falta el token, es inválido o vencido, o la sesión fue cerrada |
| `FORBIDDEN` | 403 | El usuario está autenticado, pero su rol no permite la acción (ver "Roles y permisos") |
| `ACCOUNT_BLOCKED` | 403 | La cuenta está bloqueada |
| `ACCOUNT_PENDING` | 403 | La cuenta está pendiente de verificación |
| `ACCOUNT_INACTIVE` | 403 | El usuario está inactivo (p. ej. por deserción) |
| `INVALID_OR_EXPIRED_TOKEN` | 400 | El enlace de recuperación es inválido, está vencido o ya se usó |
| `EMAIL_TAKEN` | 409 | Ya existe una cuenta con ese correo (registro) |
| `FILE_TOO_LARGE` | 413 | El archivo supera el tamaño máximo |
| `UNSUPPORTED_MEDIA_TYPE` | 415 | El contenido del archivo no es de un formato admitido |
| `CLOUDINARY_UNAVAILABLE` | 502 | No se pudo guardar el archivo en Cloudinary |
| `LAST_SUPERADMIN` | 409 | Se intentó quitar el rol o desactivar al último superadministrador activo |
| `ATTACHMENT_LIMIT` | 409 | El documento ya tiene el máximo de archivos adjuntos (`DOCUMENTO_ADJUNTOS_MAX`, 10) |
| `INSCRIPTION_PENDING` | 409 | Ya hay una solicitud de inscripción pendiente con ese correo (13a) |
| `INSCRIPTION_CLOSED` | 409 | La solicitud de inscripción ya fue aceptada o rechazada (13d) |
| `NOT_FOUND` | 404 | La ruta o el recurso no existe (p. ej. un usuario en `GET /users/{id}`) |
| `METHOD_NOT_ALLOWED` | 405 | Método HTTP no soportado en la ruta |
| `RATE_LIMITED` | 429 | Demasiados intentos; la cabecera `Retry-After` trae los segundos de espera |
| `DB_UNAVAILABLE` | 503 | La base de datos no responde |
| `INTERNAL_ERROR` | 500 | Error inesperado del servidor |

### Roles y permisos

`PROPUESTA` (DEC-B17), pendiente de aprobación del equipo. El rol sale del token; el frontend puede leerlo de `usuario.rol` (login o `/auth/me`) para mostrar u ocultar opciones, pero el backend siempre vuelve a validar.

| Rol | Puede gestionar usuarios con rol |
|---|---|
| `superadministrador` | todos |
| `administrador` | `docente_director`, `estudiante_lider`, `estudiante` |
| `docente_director`, `estudiante_lider`, `estudiante` | ninguno (solo editan su propio perfil) |

- Nadie cambia su propio `rol` ni su propio `estado`.
- No se puede desactivar ni bajar de rol al último superadministrador activo.
- Si a un usuario le cambian el rol o lo desactivan, todas sus sesiones se cierran: su siguiente petición protegida responde `401 UNAUTHORIZED` y debe volver a iniciar sesión.

**Rutas públicas con sesión opcional** (p. ej. `GET /users` y `GET /projects`): sin token, o con un token vencido o inválido, responden la vista pública (nunca `401`). Con un token válido incluyen datos adicionales (vista de administrador en `/users`; borradores propios o de revisión en `/projects` y `/articles`, ver secciones 9 y 10; reseñas ocultas para los moderadores en `GET /reviews`, sección 12). **Excepción:** `POST /reviews` con un token vencido o inválido responde `401`, para no publicar como anónima la reseña de alguien que creía tener sesión (12b).

---

## 1. `GET /health`

Estado de la API y de la conexión a MongoDB Atlas. Público.

**200**
```json
{ "status": "ok", "db": "ok" }
```

**503** `DB_UNAVAILABLE`

---

## 2. `POST /auth/login`

Público. Rate limit por IP: `RATE_LIMIT_LOGIN_MAX` intentos cada `RATE_LIMIT_LOGIN_WINDOW_SECONDS` (por defecto 10 cada 15 min).

**Request**
```json
{ "correo": "ana@uptc.edu.co", "password": "Clave-Segura-2026" }
```

- `correo` se normaliza (sin espacios, minúsculas).
- Los campos extra se ignoran.

**200**
```json
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "expira_en": 28800,
  "usuario": {
    "id": "650f1c2e8b3a4a0012345678",
    "nombre": "Ana",
    "apellido": "Pérez",
    "rol": "superadministrador",
    "foto_perfil": { "url": "https://res.cloudinary.com/.../ana.jpg" }
  }
}
```

- `expira_en`: segundos de validez del token (máximo 28800 = 8 horas, RNF-05).
- `foto_perfil`: `null` si el usuario no tiene foto.
- `rol` puede ser: `superadministrador`, `administrador`, `docente_director`, `estudiante_lider` o `estudiante`.

**Errores:** `400 VALIDATION_ERROR`, `400 INVALID_JSON`, `401 INVALID_CREDENTIALS`, `403 ACCOUNT_BLOCKED`, `403 ACCOUNT_PENDING`, `403 ACCOUNT_INACTIVE`, `429 RATE_LIMITED`.

> **"Remember me":** el backend lo ignora; la sesión dura como máximo 8 h en todos los casos (RNF-05). El frontend decide dónde guardar el token: por ejemplo, `localStorage` con "Remember me" marcado y `sessionStorage` sin marcar. Con cualquiera de las dos opciones, el token vence a las 8 h y hay que volver a iniciar sesión.

---

## 3. `GET /auth/me`

Protegido. Devuelve el perfil del usuario autenticado, útil para restaurar la sesión al recargar la página.

**200**
```json
{
  "usuario": {
    "id": "650f1c2e8b3a4a0012345678",
    "nombre": "Ana",
    "apellido": "Pérez",
    "rol": "superadministrador",
    "foto_perfil": null
  }
}
```

**401** `UNAUTHORIZED`: sin token, token inválido o vencido, sesión cerrada, o usuario desactivado. El frontend debe borrar el token guardado y enviar al usuario al login.

---

## 4. `POST /auth/logout`

Protegido. Cierra **solo la sesión del token enviado**; otras sesiones del mismo usuario siguen activas. No lleva cuerpo.

**204** sin contenido. **401** `UNAUTHORIZED`.

> `PROPUESTA` (DEC-B05): esta ruta no estaba en el contrato original 11.3.

---

## 5. `POST /auth/forgot-password`

Público. Rate limit por IP: `RATE_LIMIT_FORGOT_MAX` cada `RATE_LIMIT_FORGOT_WINDOW_SECONDS` (por defecto 5 por hora).

**Request**
```json
{ "correo": "ana@uptc.edu.co" }
```

**200**: siempre la misma respuesta, exista o no el correo:
```json
{ "mensaje": "Si el correo está registrado, recibirás un enlace para restablecer tu contraseña." }
```

- Si la cuenta existe y está activa, llega un correo con el enlace `PASSWORD_RESET_URL?token=<64 caracteres hex>`. Por defecto: `http://localhost:5173/restablecer?token=...`. La ruta propuesta es `/restablecer` (DEC-B10); la vista está pendiente de crear en el frontend.
- El token vence en `PASSWORD_RESET_TTL_MINUTES` (por defecto 30) y sirve una sola vez.

**Errores:** `400 VALIDATION_ERROR`, `429 RATE_LIMITED`.

---

## 6. `POST /auth/reset-password`

Público. La pantalla de restablecimiento lee `token` de la query string y lo envía junto con la nueva contraseña.

**Request**
```json
{ "token": "3f9a...(64 hex)", "password": "Nueva-Clave-2026" }
```

- `password`: misma política que en el registro (DEC-B11): mínimo `PASSWORD_MIN_LENGTH` caracteres (por defecto 8), al menos una letra y un número, y máximo 72 bytes. Si no la cumple, responde `400 VALIDATION_ERROR` con `fields.password` = `"La contraseña debe tener al menos una letra y un número."` (o el mensaje de longitud). Una contraseña rechazada no consume el token.

**200**
```json
{ "mensaje": "Tu contraseña fue actualizada. Inicia sesión con la nueva contraseña." }
```

Al restablecer, **se cierran todas las sesiones** del usuario.

**Errores:** `400 VALIDATION_ERROR` (con `fields.token` y/o `fields.password`) y `400 INVALID_OR_EXPIRED_TOKEN`.

---

## 6a. `POST /auth/change-password`

Protegido (cualquier rol). Cambia la contraseña del usuario autenticado. Rate limit por IP: `RATE_LIMIT_CHANGE_PASSWORD_MAX` cada `RATE_LIMIT_CHANGE_PASSWORD_WINDOW_SECONDS` (por defecto 10 cada 15 min); los intentos inválidos también cuentan, las peticiones sin sesión (`401`) no.

> `PROPUESTA` (DEC-B23): esta ruta no está en el contrato original 11.3.

**Request**
```json
{ "password_actual": "Clave-Segura-2026", "password_nueva": "Otra-Clave-2027" }
```

- Las contraseñas no se recortan: los espacios cuentan.
- `password_nueva`: misma política que en el registro (DEC-B11) y distinta de `password_actual`.

**200**
```json
{ "mensaje": "Tu contraseña fue actualizada. Se cerraron tus sesiones en otros dispositivos." }
```

- **La sesión actual sigue activa** (el token enviado sigue sirviendo); las demás sesiones del usuario se cierran y su siguiente petición protegida responde `401`.
- Si había un enlace de recuperación pendiente (`forgot-password`), deja de servir.

**Errores**

| Caso | Respuesta |
|---|---|
| Falta un campo, no es texto o la nueva no cumple la política | `400 VALIDATION_ERROR` con `fields.password_actual` y/o `fields.password_nueva` |
| Nueva igual a la actual | `400 VALIDATION_ERROR`, `fields.password_nueva` = `"La nueva contraseña debe ser distinta de la actual."` |
| Contraseña actual incorrecta | `400 VALIDATION_ERROR`, `fields.password_actual` = `"La contraseña actual no es correcta."` (no suma intentos fallidos de login ni cierra la sesión) |
| Sin token, sesión cerrada o vencida, usuario desactivado | `401 UNAUTHORIZED` |
| Cuenta bloqueada o pendiente con una sesión aún abierta | `403 ACCOUNT_BLOCKED` / `403 ACCOUNT_PENDING` |
| Demasiados intentos | `429 RATE_LIMITED` con `Retry-After` |

---

## 6b. `POST /auth/register`

Protegido: solo `superadministrador` y `administrador` (DEC-B16, `PROPUESTA`). No hay registro público; los aspirantes usan el módulo de inscripción. El rol que se puede asignar depende del rol de quien crea la cuenta (ver "Roles y permisos"). La cuenta se crea activa y ya puede iniciar sesión.

**Request**
```json
{
  "correo": "luis@uptc.edu.co",
  "password": "Clave-Segura-2026",
  "rol": "estudiante",
  "nombre": "Luis",
  "apellido": "Gómez",
  "programa_academico": "Ingeniería de Sistemas y Computación",
  "semestre": 6,
  "biografia": "Me interesa la IA.",
  "telefono": "+57 300 123 4567"
}
```

| Campo | Obligatorio | Regla (límites provisionales, DEC-B21) |
|---|---|---|
| `correo` | sí | formato de correo; se guarda sin espacios y en minúsculas |
| `password` | sí | mínimo `PASSWORD_MIN_LENGTH` (8) caracteres, al menos una letra y un número, y máximo 72 bytes (DEC-B11) |
| `rol` | sí | `superadministrador`, `administrador`, `docente_director`, `estudiante_lider` o `estudiante` (exacto, en minúsculas) |
| `nombre`, `apellido` | sí | texto de 1 a 80 caracteres |
| `programa_academico` | no | texto de hasta 120 caracteres |
| `semestre` | no | **número** entero de 1 a 10 (no texto: `6`, no `"6"`) |
| `biografia` | no | texto de hasta 1000 caracteres |
| `telefono` | no | **texto** con 7 a 20 dígitos y `+` inicial opcional; se aceptan espacios, guiones, puntos y paréntesis, que se eliminan al guardar |

- Los opcionales pueden omitirse o enviarse como `null`; un texto vacío se guarda como `null`.
- Cualquier otro campo (`estado`, `foto_perfil`, etc.) se ignora. La foto se sube después con `POST /users/{id}/photo`.
- Política de contraseñas (DEC-B11, la misma de `reset-password`). Los mensajes de `fields.password` se revisan en este orden:
  1. `"La contraseña debe tener al menos 8 caracteres."` (el número sale de `PASSWORD_MIN_LENGTH`).
  2. `"La contraseña es demasiado larga (máximo 72 bytes)."`
  3. `"La contraseña debe tener al menos una letra y un número."` Cuentan como letra las letras Unicode (incluidas las tildes y la `ñ`) y como número los dígitos `0`–`9`; los símbolos son opcionales.

**201**
```json
{
  "usuario": {
    "id": "6adcffcf84e888428a0cbc83",
    "correo": "luis@uptc.edu.co",
    "nombre": "Luis",
    "apellido": "Gómez",
    "rol": "estudiante",
    "programa_academico": "Ingeniería de Sistemas y Computación",
    "semestre": 6,
    "biografia": "Me interesa la IA.",
    "telefono": "+573001234567",
    "foto_perfil": null,
    "estado": true,
    "estado_cuenta": "activa",
    "fecha_ingreso": "2026-10-24T15:00:00Z",
    "fecha_salida": null,
    "ultimo_acceso": null
  }
}
```

Esta es la **vista de administrador** de un usuario; la usan también `GET /users` y `GET /users/{id}` con token de administrador.

**Errores:** `400 VALIDATION_ERROR` (con `fields`), `400 INVALID_JSON`, `401 UNAUTHORIZED`, `403 FORBIDDEN` (el rol del actor no es administrativo o no puede asignar el `rol` pedido), `409 EMAIL_TAKEN`.

---

## 6c. `GET /users`

Público con sesión opcional (RF-08). Listado paginado de integrantes, ordenado por `apellido` y `nombre` (orden alfabético en español: ignora tildes y mayúsculas). Contrato provisional: `PROPUESTA` DEC-B26.

| Quién consulta | Vista | Ve inactivos |
|---|---|---|
| Sin token (o token vencido/inválido/cerrado) | pública | no |
| `docente_director`, `estudiante_lider`, `estudiante` | pública | no |
| `superadministrador`, `administrador` | administrador (la de `POST /auth/register`) | sí |

**Query string** (todos opcionales)

| Parámetro | Regla |
|---|---|
| `page` | entero de 1 a 10000; por defecto 1 |
| `per_page` | entero de 1 a `USERS_PER_PAGE_MAX` (50); por defecto `USERS_PER_PAGE_DEFAULT` (20) |
| `rol` | uno de los 5 roles, exacto y en minúsculas |
| `estado` | `true` o `false`. **Solo administradores**; sin él ven activos e inactivos. Para los demás se ignora: siempre ven solo activos (DEC-B20) |

Ejemplo: `GET /api/v1/users?rol=estudiante&page=2&per_page=12`

**200** (vista pública)
```json
{
  "data": [
    {
      "id": "6adcffcf84e888428a0cbc83",
      "nombre": "Luis",
      "apellido": "Gómez",
      "rol": "estudiante",
      "programa_academico": "Ingeniería de Sistemas y Computación",
      "semestre": 6,
      "foto_perfil": { "url": "https://res.cloudinary.com/.../luis.jpg" },
      "biografia": "Me interesa la IA."
    }
  ],
  "meta": { "page": 2, "per_page": 12, "total": 30, "total_pages": 3 }
}
```

- La vista pública (DEC-B19) nunca incluye correo, teléfono, estado ni fechas. Los opcionales sin valor llegan como `null`.
- Con token de administrador, cada elemento de `data` tiene la forma de la vista de administrador (ver 6b).
- Una página fuera de rango responde `200` con `data: []` y el `meta` real.
- Cabeceras: sin sesión, `Cache-Control: public, max-age=60`; con sesión, `no-store`. Siempre `Vary: Authorization`.

**Errores:** `400 VALIDATION_ERROR`, con `fields` por parámetro (p. ej. `page=0`, `per_page=100`, `rol=egresado`, `estado=si` o un parámetro enviado como arreglo, `rol[]=x`). Nunca responde `401`.

---

## 6d. `GET /users/{id}`

Público con sesión opcional. Perfil de un integrante.

**200**
```json
{ "usuario": { "id": "6adcffcf84e888428a0cbc83", "nombre": "Luis", "...": "..." } }
```

| Quién consulta | Vista |
|---|---|
| Sin token, o un integrante que consulta a **otro** | pública (como en `GET /users`) |
| El propio usuario (su `id` es el de `/auth/me`) | administrador: incluye su correo, teléfono y estado de cuenta; sirve para precargar el formulario de edición |
| `superadministrador`, `administrador` | administrador, también para usuarios inactivos |

**404** `NOT_FOUND`: el `id` no tiene formato válido, no existe o, en la vista pública, el usuario está inactivo (DEC-B20). Mismas cabeceras de caché que `GET /users`.

---

## 6e. `PATCH /users/{id}`

Requiere token. Edición parcial de un perfil (RF-15): solo cambian los campos enviados. Contrato provisional: `PROPUESTA` DEC-B27 y DEC-B28.

| Quién | Puede editar | Puede cambiar `rol` y `estado` |
|---|---|---|
| Cualquier usuario | su propio perfil | no (los suyos nunca) |
| `administrador` | a `docente_director`, `estudiante_lider` y `estudiante` | sí; `rol` solo a esos tres roles |
| `superadministrador` | a cualquier usuario | sí, a cualquier rol |

**Request** (todos opcionales, al menos uno)
```json
{ "nombre": "Luis Alberto", "telefono": "+57 300 123 4567", "biografia": null, "rol": "estudiante_lider" }
```

- Campos: `nombre`, `apellido`, `programa_academico`, `semestre`, `biografia` y `telefono`, con las mismas reglas que en `POST /auth/register` (6b); además `rol` y `estado`.
- `nombre` y `apellido`, si se envían, no pueden quedar vacíos. Un opcional enviado como `null` o `""` se borra.
- Cualquier otro campo (`correo`, `foto_perfil`, `password`, …) se ignora.
- `estado` debe ser booleano JSON (`true`/`false`, no `"true"` ni `1`). `{"estado": true}` **reactiva** a un usuario inactivo: `fecha_salida` vuelve a `null`, `fecha_ingreso` se conserva y el usuario debe iniciar sesión de nuevo. `{"estado": false}` equivale a `DELETE /users/{id}` (6f).
- Enviar el `rol` o el `estado` actuales no cuenta como cambio: el formulario puede reenviar el objeto completo.
- Cambiar el `rol` o desactivar a un usuario **cierra todas sus sesiones**: tendrá que volver a iniciar sesión.

**200**: `{"usuario": {...}}` con la vista de administrador (6b). Cabecera `Cache-Control: no-store`.

**Errores:**
- `400 VALIDATION_ERROR`: con `fields` por campo inválido; sin `fields` si no llegó ningún campo editable.
- `400 INVALID_JSON`, `401 UNAUTHORIZED`.
- `403 FORBIDDEN`: un integrante intenta editar a otro (también si ese id no existe), alguien intenta cambiar su propio rol o estado, o un administrador intenta editar a un usuario o asignar un rol fuera de su jerarquía.
- `404 NOT_FOUND`: (administradores) el usuario no existe o el `id` no es válido.
- `409 LAST_SUPERADMIN`: se intentó quitar el rol o desactivar al último superadministrador activo.

---

## 6f. `DELETE /users/{id}`

Solo `superadministrador` y `administrador`, dentro de su jerarquía (ver "Roles y permisos"). Desactivación **lógica** por deserción (RF-09): el usuario no se borra. Contrato provisional: `PROPUESTA` DEC-B28.

- Deja `estado = false` y `fecha_salida` con la fecha actual, y cierra todas las sesiones del usuario.
- Desde ese momento no puede iniciar sesión (`403 ACCOUNT_INACTIVE`) y deja de aparecer en la vista pública de `GET /users` y `GET /users/{id}`.
- Repetir la petición sobre un usuario ya inactivo responde `200` sin cambios (conserva la `fecha_salida` original).
- Para reactivarlo: `PATCH /users/{id}` con `{"estado": true}` (6e).

**200**: `{"usuario": {...}}` con la vista de administrador (6b), ya con `"estado": false`. Cabecera `Cache-Control: no-store`.

**Errores:**
- `401 UNAUTHORIZED`.
- `403 FORBIDDEN`: el rol del actor no es administrativo, intenta desactivarse a sí mismo o el usuario está fuera de su jerarquía.
- `404 NOT_FOUND`: el usuario no existe o el `id` no es válido.
- `409 LAST_SUPERADMIN`: es el último superadministrador activo.

---

## 6g. `POST /users/{id}/photo`

Requiere token. Sube o cambia la foto de perfil (RF-06). Mismos permisos que editar el perfil (6e): el propio usuario, o un administrador sobre los roles que gestiona. Contrato provisional: `PROPUESTA` DEC-B29.

**Request**: `multipart/form-data` con el archivo en el campo `foto`. No fijes la cabecera `Content-Type` a mano: el navegador la arma con el `boundary`.

```ts
const form = new FormData();
form.append('foto', archivo); // File de un <input type="file" accept="image/jpeg,image/png,image/webp">
await fetch(`/api/v1/users/${id}/photo`, { method: 'POST', headers: { Authorization: `Bearer ${token}` }, body: form });
```

- Formatos: JPEG, PNG o WebP, hasta `PERFIL_FOTO_MAX_BYTES` (2 MB). El backend revisa el **contenido** del archivo; cambiar la extensión no sirve.
- Al subirla, Cloudinary la **recorta a un cuadrado de 400 × 400** (`PERFIL_FOTO_LADO_PX`), centrado en lo importante de la imagen, con calidad automática; el original no se guarda (DEC-B42). Si quieres que el usuario elija el encuadre, recórtala en el navegador antes de enviarla.
- La foto anterior se borra de Cloudinary después de guardar la nueva.

**200**: `{"usuario": {...}}` con la vista de administrador (6b); `foto_perfil` es `{"url": "https://res.cloudinary.com/<cloud>/image/upload/f_auto,q_auto/..."}`. Cabecera `Cache-Control: no-store`.

**Errores:**
- `400 VALIDATION_ERROR` (`fields.foto`): no llegó el archivo, está vacío o la subida quedó incompleta.
- `401 UNAUTHORIZED`, `403 FORBIDDEN`, `404 NOT_FOUND`: como en 6e.
- `413 FILE_TOO_LARGE`, `415 UNSUPPORTED_MEDIA_TYPE`.
- `502 CLOUDINARY_UNAVAILABLE`: Cloudinary no respondió; la foto anterior se conserva.

---

## 7. `GET /home/slider`

Público. Imágenes del carrusel (RF-07), de la colección `Multimedia` con `seccion = slider_principal`, ordenadas por `orden` ascendente. Máximo `HOME_MAX_ITEMS` ítems (por defecto 20).

Cabecera: `Cache-Control: public, max-age=300`.

**200**
```json
{
  "data": [
    {
      "id": "6ab693b1ccc363bbc95e1342",
      "tipo": "imagen",
      "url": "https://res.cloudinary.com/demo/image/upload/f_auto,q_auto/sample.jpg",
      "descripcion": "Imagen de ejemplo 1 del slider principal",
      "orden": 1
    }
  ]
}
```

`descripcion` es el texto alternativo: úsalo como `alt` de la imagen.

---

## 8. `GET /home/videos`

Público. Videos de presentación (RF-11), de `Multimedia` con `seccion = video_presentacion`. Mismo formato, orden, tope y caché que el slider.

**200**
```json
{
  "data": [
    {
      "id": "6ab693b1ccc363bbc95e1345",
      "tipo": "video",
      "url": "https://res.cloudinary.com/demo/video/upload/dog.mp4",
      "descripcion": "Video de ejemplo de presentación del semillero",
      "orden": 1
    }
  ]
}
```

---

## 9. Contenido: proyectos (`/projects`)

Fase 3. Proyectos de la colección `Documentos` (`categoria = proyecto`). Artículos y noticias usan el mismo contrato en `/articles` (sección 10). Contrato provisional: `PROPUESTA` DEC-B30 a DEC-B41, pendiente de aprobación del equipo.

### Reglas

| Tema | Regla |
|---|---|
| Estados de un proyecto | `en_revision`, `ejecucion`, `finalizado` |
| Qué es público | `visible = true` y `estado` distinto de `en_revision` |
| Quién crea | Cualquier usuario con sesión. Queda como autor; lo nuevo queda en `en_revision` y con `visible = false` |
| Revisores | `superadministrador`, `administrador` y `docente_director`: aprueban, publican (cambian `estado` y `visible`), editan y borran cualquier documento |
| Autor | Edita y borra su documento solo mientras está `en_revision` |
| Integrantes | Aparecen en el documento y ven sus borradores, pero no editan |
| Qué ve cada quien | Sin sesión: lo público. Con sesión: lo público más lo suyo (como autor o integrante). Revisores: todo |
| Lo que no puedes ver | Responde `404 NOT_FOUND`, también en `PATCH` y `DELETE` |
| `contenido` | **Markdown**, hasta 50 000 caracteres. Muéstralo con un renderizador de Markdown que **no interprete HTML** (el backend lo guarda tal cual). Pendiente de confirmar con frontend (DEC-B37) |

### Objeto `documento`

Los listados traen el **resumen**; el detalle (`GET /projects/{id}`, `POST`, `PATCH`) agrega `contenido`, `archivos_adjuntos` y `permisos`.

```json
{
  "id": "6aebe4272aead60a1d0a0177",
  "categoria": "proyecto",
  "titulo": "Visión por computador para cultivos",
  "descripcion": "Resumen corto que aparece en las tarjetas del listado.",
  "estado": "ejecucion",
  "visible": true,
  "autor": {
    "id": "6adcffcf84e888428a0cbc83",
    "nombre": "Luis",
    "apellido": "Gómez",
    "rol": "estudiante",
    "foto_perfil": { "url": "https://res.cloudinary.com/..." }
  },
  "integrantes": [
    { "id": "6adcffcf84e888428a0cbc84", "nombre": "Ana", "apellido": "Pérez", "rol": "estudiante_lider", "foto_perfil": null }
  ],
  "fecha_creacion": "2026-11-04T15:00:00Z",
  "fecha_actualizacion": "2026-11-04T16:30:00Z",

  "contenido": "## Objetivo\n\nTexto en **Markdown**.",
  "archivos_adjuntos": [
    { "archivo": "abc123.pdf", "url": "https://res.cloudinary.com/.../abc123.pdf", "nombre_archivo": "informe.pdf" }
  ],
  "permisos": { "editar": false, "borrar": false, "publicar": false }
}
```

- `autor` es `null` si el usuario ya no existe. Los integrantes que se desactivaron (egresados) **siguen apareciendo** (DEC-B38).
- `permisos` dice qué puede hacer quien consulta. Úsalo para mostrar u ocultar los botones de editar, borrar y publicar; el backend vuelve a validar de todas formas.
- `archivos_adjuntos[].archivo` es el identificador para quitar el adjunto (sección 11).

### 9a. `GET /projects`

Público, con sesión opcional. Paginado y ordenado del más reciente al más antiguo.

| Parámetro | Regla |
|---|---|
| `page` | 1 a 10000; por defecto 1 |
| `per_page` | 1 a `DOCUMENTOS_PER_PAGE_MAX` (50); por defecto `DOCUMENTOS_PER_PAGE_DEFAULT` (12) |
| `estado` | `en_revision`, `ejecucion` o `finalizado` (sin sesión, `en_revision` siempre devuelve una lista vacía) |
| `autor` | id de usuario: proyectos de esa persona como autora |
| `integrante` | id de usuario: proyectos donde participa (para la página de perfil) |
| `visible` | `true` o `false`; **solo revisores** (para los demás se ignora) |

**200**: `{"data": [resumen, ...], "meta": {"page", "per_page", "total", "total_pages"}}`, igual que `GET /users`. Una página fuera de rango devuelve `data: []`.

Caché: sin sesión, `Cache-Control: public, max-age=60`; con sesión, `no-store`. Siempre `Vary: Authorization`.

**Errores:** `400 VALIDATION_ERROR` con `fields` si un parámetro no es válido (estado fuera de la lista, id mal formado, parámetro repetido como arreglo).

### 9b. `GET /projects/{id}`

Público, con sesión opcional. **200** `{"documento": {...}}` con el detalle. Misma caché que el listado.

**Errores:** `404 NOT_FOUND` (`"El proyecto no existe."`) si el id no es válido, no existe, es un artículo o noticia, o quien consulta no puede verlo.

### 9c. `POST /projects`

Requiere token.

**Request**
```json
{
  "titulo": "Visión por computador para cultivos",
  "descripcion": "Resumen corto para el listado.",
  "contenido": "## Objetivo\n\nTexto en **Markdown**.",
  "integrantes": ["6adcffcf84e888428a0cbc84"],
  "estado": "ejecucion",
  "visible": true
}
```

| Campo | Obligatorio | Regla (límites provisionales en `.env`) |
|---|---|---|
| `titulo` | sí | texto de 1 a 200 caracteres |
| `descripcion` | sí | texto de 1 a 1000 caracteres |
| `contenido` | no | Markdown de hasta 50 000 caracteres; vacío o `null` → `null` |
| `integrantes` | no | lista de ids de usuarios **activos**, sin repetidos, hasta 20 |
| `estado` | no | solo revisores; por defecto `en_revision` |
| `visible` | no | booleano JSON (`true`/`false`); solo revisores; por defecto `false` |

- `categoria`, `autor_id`, `archivos_adjuntos` y las fechas se ignoran: la categoría es siempre `proyecto` y el autor sale del token.
- Si alguien que no es revisor envía `estado` distinto de `en_revision` o `visible: true`, recibe `403 FORBIDDEN`. Reenviar los valores por defecto está permitido.

**201** `{"documento": {...}}` con el detalle. Cabecera `Cache-Control: no-store`.

**Errores:**
- `400 VALIDATION_ERROR` (`fields`): campos faltantes o largos, tipos incorrectos, `estado` que no es de proyecto (p. ej. `publicado`), o `integrantes` que no existen o están inactivos (el mensaje lista los ids).
- `401 UNAUTHORIZED`, `403 FORBIDDEN`.

### 9d. `PATCH /projects/{id}`

Requiere token. Edición parcial: solo cambian los campos enviados. Los campos y reglas son los de `POST`; `integrantes` reemplaza la lista completa (`[]` la vacía). Un integrante que ya estaba y se desactivó puede quedarse, pero no se puede agregar uno inactivo.

- El autor edita solo mientras el proyecto está `en_revision`. Reenviar `estado` o `visible` con su valor actual no es un cambio.
- Los revisores editan cualquier proyecto y cambian `estado` y `visible`. Para publicar: `{"estado": "ejecucion", "visible": true}`.

**200** `{"documento": {...}}` con el detalle. Cabecera `Cache-Control: no-store`.

**Errores:**
- `400 VALIDATION_ERROR`: como en `POST`; sin ningún campo editable, sin `fields`.
- `401 UNAUTHORIZED`.
- `403 FORBIDDEN`: no eres el autor ni revisor; el proyecto ya no está en revisión; o intentas cambiar `estado` o `visible` sin ser revisor.
- `404 NOT_FOUND`: como en 9b.

### 9e. `DELETE /projects/{id}`

Requiere token. Borrado **físico**: el proyecto desaparece. Puede hacerlo el autor mientras está `en_revision`, o un revisor en cualquier momento.

**204** sin cuerpo.

**Errores:** `401 UNAUTHORIZED`, `403 FORBIDDEN` (como en 9d), `404 NOT_FOUND`.

---

## 10. Contenido: artículos y noticias (`/articles`)

Fase 3. Artículos y noticias de la colección `Documentos` (`categoria = articulo` o `noticia`). Usa **el mismo contrato que `/projects`** (sección 9): mismas reglas de quién crea, edita, publica, borra y ve; mismo objeto `documento`, paginación, caché y errores. Contrato provisional: `PROPUESTA` DEC-B30 a DEC-B41.

Lo que cambia respecto a `/projects`:

| Tema | `/articles` |
|---|---|
| Categorías | `articulo` y `noticia` (DEC-B30). No hay una ruta aparte para noticias |
| Estados | `en_revision` y `publicado` (DEC-B31). `ejecucion` y `finalizado` son solo de proyectos → `400 VALIDATION_ERROR` |
| Qué es público | Igual: `visible = true` y `estado` distinto de `en_revision`; en la práctica, `publicado` y visible |
| Para publicar | `PATCH` de un revisor con `{"estado": "publicado", "visible": true}` |
| Mensaje del 404 | `"El artículo o noticia no existe."` |

### 10a. `GET /articles`

Los parámetros de 9a, más `categoria`:

| Parámetro | Regla |
|---|---|
| `categoria` | `articulo` o `noticia`; sin el parámetro (o vacío) trae las dos |
| `estado` | `en_revision` o `publicado` |
| `page`, `per_page`, `autor`, `integrante`, `visible` | como en 9a |

Ejemplos: `GET /articles?categoria=noticia&per_page=3` (últimas noticias para la página de inicio); `GET /articles?integrante={id}` (publicaciones de un integrante en su perfil, RF-05).

**Errores:** `400 VALIDATION_ERROR` con `fields` (p. ej. `categoria=proyecto` o `estado=ejecucion`).

### 10b. `GET /articles/{id}`

Como 9b. Un proyecto pedido por esta ruta responde `404`, y un artículo pedido por `/projects/{id}` también.

### 10c. `POST /articles`

Como 9c, con **`categoria` obligatoria**:

```json
{
  "categoria": "noticia",
  "titulo": "El semillero gana el premio regional",
  "descripcion": "Resumen corto para el listado.",
  "contenido": "## Detalles\n\nTexto en **Markdown**."
}
```

| Campo | Obligatorio | Regla |
|---|---|---|
| `categoria` | sí | `articulo` o `noticia` (exacto, en minúsculas) |
| `estado` | no | `en_revision` o `publicado`; solo revisores |
| demás campos | | como en 9c |

### 10d. `PATCH /articles/{id}`

Como 9d. Además se puede cambiar `categoria` entre `articulo` y `noticia`, con los mismos permisos que el resto de campos (el autor, mientras está en revisión; los revisores, siempre). Un `PATCH` sin campos editables lista también `categoria` en el mensaje.

### 10e. `DELETE /articles/{id}`

Como 9e: borrado físico, `204`.

---

## 11. Archivos adjuntos (`/projects/{id}/files` y `/articles/{id}/files`)

Fase 3. Archivos de un proyecto, artículo o noticia, guardados en Cloudinary. Un archivo por petición. Contrato provisional: `PROPUESTA` DEC-B39 y DEC-B40.

**Permisos:** los mismos que editar el documento (9d): el autor mientras está `en_revision`, y los revisores siempre. Quien no puede ver el documento recibe `404`.

### 11a. `POST /{projects|articles}/{id}/files`

Requiere token. `multipart/form-data` con el archivo en el campo `archivo`. No fijes la cabecera `Content-Type` a mano: el navegador la arma con el `boundary`.

```ts
const form = new FormData();
form.append('archivo', archivo); // <input type="file" accept="application/pdf,image/jpeg,image/png,image/webp">
await fetch(`/api/v1/projects/${id}/files`, { method: 'POST', headers: { Authorization: `Bearer ${token}` }, body: form });
```

- Formatos y tamaño (DEC-B40 y DEC-B42), según el **contenido** real del archivo (cambiar la extensión no sirve):
  - **PDF**: hasta `DOCUMENTO_PDF_MAX_BYTES` (3 MB). Se guarda tal cual.
  - **JPEG, PNG o WebP**: hasta `DOCUMENTO_ADJUNTO_MAX_BYTES` (5 MB). Al subirla, Cloudinary la reduce a un ancho máximo de 1600 px (`DOCUMENTO_IMAGEN_MAX_ANCHO_PX`, conserva la proporción; las más pequeñas no se agrandan) con calidad automática.
  - Máximo `DOCUMENTO_ADJUNTOS_MAX` (10) adjuntos por documento.
  - El mensaje del `413` dice cuál límite se superó ("El PDF supera el tamaño máximo de 3 MB.").
- `nombre_archivo` guarda el nombre original, limpio y con la extensión del tipo real (p. ej. `Informe final.pdf`). Úsalo como texto del enlace.
- La `url` es pública (Cloudinary). Las imágenes llegan con `f_auto,q_auto`. Un PDF se abre en el navegador; para una miniatura de su primera página, cambia la extensión `.pdf` de la URL por `.jpg`.

**201** `{"documento": {...}}`: el detalle (sección 9) con el adjunto nuevo al final de `archivos_adjuntos`. Cabecera `Cache-Control: no-store`.

**Errores:**
- `400 VALIDATION_ERROR` (`fields.archivo`): no llegó el archivo, está vacío o la subida quedó incompleta.
- `401 UNAUTHORIZED`; `403 FORBIDDEN` y `404 NOT_FOUND`, como en 9d.
- `409 ATTACHMENT_LIMIT`: el documento ya tiene el máximo de adjuntos.
- `413 FILE_TOO_LARGE`, `415 UNSUPPORTED_MEDIA_TYPE`.
- `502 CLOUDINARY_UNAVAILABLE`: Cloudinary no respondió; el documento no cambia.

### 11b. `DELETE /{projects|articles}/{id}/files/{archivo}`

Requiere token. `{archivo}` es el valor de `archivos_adjuntos[].archivo` del detalle. Quita el adjunto del documento y lo borra de Cloudinary.

**200** `{"documento": {...}}`: el detalle sin ese adjunto. Cabecera `Cache-Control: no-store`.

**Errores:** `401 UNAUTHORIZED`, `403 FORBIDDEN` (como en 9d), `404 NOT_FOUND` (el documento o el archivo no existen).

Al borrar un documento (9e) se borran también todos sus adjuntos.

---

## 12. Reseñas (`/reviews`)

Fase 4 (RF-10). Una reseña es una **opinión sobre el semillero en general**: no se asocia a un proyecto ni a un artículo. Contrato provisional: `PROPUESTA` DEC-B43 a DEC-B45.

**Moderadores:** `superadministrador`, `administrador` y `docente_director` (los mismos revisores del contenido).

### Objeto `resena`

```json
{
  "id": "6aee0b1c9439beea66026578",
  "usuario_id": "6adcffcf84e888428a0cbc83",
  "nombre_visible": "Luis Gómez",
  "comentario": "Aprendí mucho en el semillero.",
  "calificacion": 5,
  "estado": "visible",
  "fecha": "2026-11-06T15:00:00Z"
}
```

- `usuario_id` es `null` si la escribió un visitante sin sesión.
- `calificacion` es un entero de 1 a 5, o `null`.
- `estado`: `visible` u `oculta`.

### 12a. `GET /reviews`

Público con sesión opcional. Listado paginado, de la más reciente a la más antigua.

| Quién consulta | Ve |
|---|---|
| Sin token, o cualquier rol que no modera | solo las `visible` |
| Moderadores | todas; pueden filtrar con `estado` |

**Query string** (todos opcionales)

| Parámetro | Regla |
|---|---|
| `page` | entero de 1 a 10000; por defecto 1 |
| `per_page` | entero de 1 a `RESENAS_PER_PAGE_MAX` (50); por defecto `RESENAS_PER_PAGE_DEFAULT` (10) |
| `estado` | `visible` u `oculta`. **Solo moderadores**; para los demás se ignora |

**200**
```json
{
  "data": [ { "id": "...", "nombre_visible": "Luis Gómez", "...": "..." } ],
  "meta": { "page": 1, "per_page": 10, "total": 23, "total_pages": 3 },
  "resumen": { "promedio": 4.3, "calificaciones": 18 }
}
```

- `resumen` se calcula solo con las reseñas **visibles** que tienen calificación (aunque el moderador filtre por `oculta`). `promedio` lleva un decimal, o es `null` si no hay ninguna calificación.
- Cabeceras: sin sesión, `Cache-Control: public, max-age=60`; con sesión, `no-store`. Siempre `Vary: Authorization`.

**Errores:** `400 VALIDATION_ERROR` con `fields` por parámetro (como en 6c).

### 12b. `POST /reviews`

Público, con sesión opcional. Rate limiting por IP: `RATE_LIMIT_REVIEWS_MAX` (10) por hora (DEC-B48).

| Campo | Regla |
|---|---|
| `nombre_visible` | **Solo sin sesión**: obligatorio, hasta 80 caracteres (los saltos de línea se cambian por espacios). Con sesión se ignora: se usa `nombre` + `apellido` del perfil |
| `comentario` | Obligatorio, hasta 1000 caracteres |
| `calificacion` | Opcional: entero JSON de 1 a 5 (`"5"` como texto es error) |

Cualquier otro campo (`usuario_id`, `estado`, `fecha`…) se ignora.

| Quién envía | Resultado |
|---|---|
| Con sesión válida | `estado: "visible"`, con su `usuario_id` y su nombre |
| Sin sesión | `estado: "oculta"` hasta que un moderador la apruebe. Conviene avisar: "Tu reseña se publicará cuando la revisemos" |
| Con un token vencido o inválido | `401 UNAUTHORIZED` (no se publica como anónima): pide iniciar sesión otra vez o envía sin `Authorization` |

**201** `{"resena": {...}}`. Cabecera `Cache-Control: no-store`.

**Errores:** `400 VALIDATION_ERROR`, `401 UNAUTHORIZED`, `429 RATE_LIMITED` (un envío inválido también cuenta para el límite).

### 12c. `PATCH /reviews/{id}` y `DELETE /reviews/{id}`

Solo moderadores.

- `PATCH` con `{"estado": "visible" | "oculta"}` → **200** `{"resena": {...}}`. Es lo normal para moderar (aprobar u ocultar).
- `DELETE` → **204**, borrado definitivo (para spam o contenido ofensivo).

**Errores:** `400 VALIDATION_ERROR` (`fields.estado`), `401 UNAUTHORIZED`, `403 FORBIDDEN`, `404 NOT_FOUND`.

---

## 13. Inscripciones (`/inscriptions`)

Fase 4 (RF-12, RF-13). Solicitud de un aspirante para entrar al semillero. Contrato provisional: `PROPUESTA` DEC-B47 a DEC-B52.

**Quién revisa:** `superadministrador` y `administrador`. Aceptar una solicitud **no crea la cuenta**: después el administrador la crea con `POST /auth/register` (6b), usando los datos de la solicitud.

### 13a. `POST /inscriptions`

Público, sin token. `multipart/form-data` (no JSON), con los datos y los archivos en el campo `documentos[]`. Rate limiting por IP: `RATE_LIMIT_INSCRIPTIONS_MAX` (20) por hora. No fijes la cabecera `Content-Type` a mano.

```ts
const form = new FormData();
form.append('nombre_completo', nombre);
form.append('correo', correo);
form.append('telefono', telefono);              // opcional
form.append('programa_academico', programa);
form.append('semestre', String(semestre));
form.append('carta_motivacion', carta);
form.append('acepta_tratamiento_datos', 'true'); // casilla obligatoria
for (const archivo of archivos) form.append('documentos[]', archivo);
await fetch('/api/v1/inscriptions', { method: 'POST', body: form });
```

| Campo | Regla |
|---|---|
| `nombre_completo` | Obligatorio, hasta 120 caracteres |
| `correo` | Obligatorio. Debe ser del dominio `INSCRIPCION_DOMINIOS_CORREO` (por defecto `@uptc.edu.co`). Se guarda en minúsculas |
| `telefono` | Opcional; mismas reglas que el perfil (7 a 20 dígitos, admite `+`, espacios y guiones) |
| `programa_academico` | Obligatorio, hasta 120 caracteres |
| `semestre` | Obligatorio, entero de 1 a 10 |
| `carta_motivacion` | Obligatoria, de 100 a 3000 caracteres |
| `acepta_tratamiento_datos` | Obligatorio, `true` (autorización de tratamiento de datos, Ley 1581 de 2012). No se guarda |
| `documentos[]` | De 1 a `INSCRIPCION_DOCUMENTOS_MAX` (3) archivos: PDF de hasta 3 MB, o JPEG/PNG/WebP de hasta 5 MB (se reducen a 1600 px de ancho), igual que los adjuntos (11a). Un solo archivo también puede ir en `documentos` |

- Los documentos se guardan **privados** en Cloudinary: nadie puede abrirlos sin un enlace firmado (13c).
- Se envían dos correos: confirmación al aspirante y aviso al semillero. Si el correo falla, la solicitud igual queda guardada (`201`).

**201**
```json
{
  "inscripcion": {
    "id": "6aedd218d4bb38db2a0ca934",
    "nombre_completo": "Laura Martínez",
    "correo": "laura@uptc.edu.co",
    "telefono": "+573105551234",
    "programa_academico": "Ingeniería de Sistemas",
    "semestre": 4,
    "estado_solicitud": "pendiente",
    "fecha_solicitud": "2026-11-06T13:11:54Z",
    "documentos_adjuntos": [ { "nombre_archivo": "carta.pdf" } ]
  },
  "mensaje": "Recibimos tu solicitud. Te escribiremos al correo que indicaste con la respuesta."
}
```

**Errores:**
- `400 VALIDATION_ERROR` con `fields` (datos o `documentos`: faltan, son más de 3, uno está vacío). Primero se validan los datos y después los archivos.
- `409 INSCRIPTION_PENDING`: ese correo ya tiene una solicitud pendiente. Si la anterior fue rechazada o aceptada, se puede enviar otra.
- `413 FILE_TOO_LARGE`, `415 UNSUPPORTED_MEDIA_TYPE` (un archivo inválido rechaza toda la solicitud).
- `429 RATE_LIMITED`; `502 CLOUDINARY_UNAVAILABLE` (no se guardó nada).

### 13b. `GET /inscriptions`

Solo `superadministrador` y `administrador`. Listado paginado, de la más reciente a la más antigua, **sin** la carta ni los documentos.

| Parámetro | Regla |
|---|---|
| `page` | entero de 1 a 10000; por defecto 1 |
| `per_page` | entero de 1 a `INSCRIPCIONES_PER_PAGE_MAX` (50); por defecto 20 |
| `estado_solicitud` | `pendiente`, `aceptada` o `rechazada` |

**200** `{"data": [{"id", "nombre_completo", "correo", "telefono", "programa_academico", "semestre", "estado_solicitud", "fecha_solicitud"}], "meta": {...}}`. Cabecera `Cache-Control: no-store`.

**Errores:** `400 VALIDATION_ERROR`, `401 UNAUTHORIZED`, `403 FORBIDDEN`.

### 13c. `GET /inscriptions/{id}`

Solo `superadministrador` y `administrador`. El listado (13b) más `carta_motivacion` y los documentos con **enlaces firmados que vencen** (`INSCRIPCION_ENLACE_TTL_SECONDS`, 1 hora, DEC-B50):

```json
"documentos_adjuntos": [
  {
    "nombre_archivo": "carta.pdf",
    "formato": "pdf",
    "url": "https://api.cloudinary.com/v1_1/<cloud>/image/download?...&expires_at=1793976747&signature=...",
    "expira": "2026-11-06T14:12:27Z"
  }
]
```

- La `url` descarga el archivo. Pasada la hora responde `401`: vuelve a pedir el detalle para tener enlaces nuevos. No la guardes ni la compartas.
- **200** `{"inscripcion": {...}}`. Cabecera `Cache-Control: no-store`.

**Errores:** `401 UNAUTHORIZED`, `403 FORBIDDEN`, `404 NOT_FOUND`, `502 CLOUDINARY_UNAVAILABLE`.

### 13d. `PATCH /inscriptions/{id}`

Solo `superadministrador` y `administrador`. `{"estado_solicitud": "aceptada" | "rechazada"}`.

- Solo se resuelve una solicitud `pendiente`, una sola vez y sin vuelta atrás. Si dos administradores la resuelven a la vez, gana el primero.
- Se le avisa al aspirante por correo. Aceptar **no** crea la cuenta (ver arriba).
- **200** `{"inscripcion": {...}}`: el detalle (13c).

**Errores:** `400 VALIDATION_ERROR` (`fields.estado_solicitud`; `pendiente` no es un valor admitido), `401`, `403`, `404`, `409 INSCRIPTION_CLOSED` (ya estaba resuelta).

---

## 14. Contacto (`/contact`)

Fase 4 (RF-03). Contrato provisional: `PROPUESTA` DEC-B46 a DEC-B48.

### 14a. `POST /contact`

Público, sin token. Rate limiting por IP: `RATE_LIMIT_CONTACT_MAX` (20) por hora.

| Campo | Regla |
|---|---|
| `nombre` | Obligatorio, hasta 80 caracteres (una sola línea) |
| `correo` | Obligatorio, formato válido (cualquier dominio) |
| `asunto` | Obligatorio, hasta 150 caracteres (una sola línea) |
| `mensaje` | Obligatorio, hasta 3000 caracteres |

- El mensaje se guarda (`Mensajes_Contacto`, `no_leido`) y se reenvía al buzón del semillero (`CONTACTO_CORREO_DESTINO`) con "Responder a" hacia el remitente. **No** se envía una respuesta automática al remitente.
- Si el correo falla, el mensaje igual queda guardado (`201`).

**201** `{"mensaje": "Recibimos tu mensaje. El equipo del semillero te responderá al correo que indicaste."}`

**Errores:** `400 VALIDATION_ERROR`, `429 RATE_LIMITED`.

### 14b. `GET /contact/messages` y `PATCH /contact/messages/{id}`

Buzón para `superadministrador` y `administrador` (DEC-B46).

- `GET` con `page`, `per_page` (1 a `MENSAJES_PER_PAGE_MAX`, 50; por defecto 20) y `estado` (`leido` o `no_leido`). De más reciente a más antiguo. **200** `{"data": [{"id", "nombre", "correo", "asunto", "mensaje", "estado", "fecha"}], "meta": {...}}`.
- `PATCH` con `{"estado": "leido" | "no_leido"}` → **200** `{"mensaje_contacto": {...}}`.

**Errores:** `400 VALIDATION_ERROR`, `401 UNAUTHORIZED`, `403 FORBIDDEN`, `404 NOT_FOUND`.

---

## Pendiente de confirmar con frontend

El contrato de las secciones 9 a 14 está completo y probado de punta a punta contra la base de pruebas (las inscripciones, también con Cloudinary real). Sigue siendo `PROPUESTA` (DEC-B30 a DEC-B52) hasta que lo apruebe el equipo. Puntos que conviene revisar con frontend:

- `contenido` de proyectos y artículos en Markdown, sin HTML (DEC-B37).
- Usar `permisos` del detalle de un documento para mostrar u ocultar los botones; el listado no lo trae.
- Foto de perfil recortada a 400 × 400 en el servidor: si se quiere que el usuario elija el encuadre, recortar en el navegador antes de subir (DEC-B42).
- Límites de archivos (adjuntos e inscripción): imágenes de 5 MB y PDF de 3 MB; conviene validarlos también en el navegador para avisar antes de subir.
- Reseñas: avisar al visitante que su reseña se publicará tras revisión; mostrar `resumen.promedio` (12a).
- Inscripción: casilla de autorización de datos obligatoria con el texto legal que defina el semillero; aviso de correo `@uptc.edu.co`; manejar `409 INSCRIPTION_PENDING` (13a).
- Panel de inscripciones: los enlaces de los documentos vencen en 1 hora (13c); para crear la cuenta de un aceptado se usa `POST /auth/register`.

## Fuera del backend actual

- `POST /auth/google` (login con Google, RF-17): no está registrada; responde `404`.
- Hero, bloques S-I-I-I-S, llamada a la acción y footer de la página de inicio: contenido estático del frontend (DEC-B12). El botón "Inscríbete" solo navega a la página de inscripción.
- Borrar solicitudes de inscripción o mensajes de contacto: no hay rutas (el spec no las define; DEC-B52).
