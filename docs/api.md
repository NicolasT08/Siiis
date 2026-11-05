# Contrato API — SIIIS V3 · Fases 1 a 3

Documento para Front-End (React + TypeScript). Fuente de verdad: `docs/SIIIS_V3_SPEC_DRIVEN.md`, sección 11.

## Convenciones generales

| Tema | Regla |
|---|---|
| URL base | Producción: `https://siiis.com.co/api/v1` (o relativa `/api/v1`). Desarrollo: `http://localhost:8000/api/v1` |
| Formato | JSON UTF-8. Las peticiones con cuerpo envían `Content-Type: application/json` |
| Autenticación | `Authorization: Bearer <token>` en rutas protegidas |
| IDs | Strings hexadecimales de 24 caracteres, en el campo `id` (nunca `_id`) |
| Fechas | ISO 8601 UTC, p. ej. `2026-09-23T15:00:00Z` |
| CORS | Solo en desarrollo, para `FRONTEND_ORIGIN` (por defecto `http://localhost:5173`). En producción no hace falta (mismo dominio) |

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

**Rutas públicas con sesión opcional** (p. ej. `GET /users` y `GET /projects`): sin token, o con un token vencido o inválido, responden la vista pública (nunca `401`). Con un token válido incluyen datos adicionales (vista de administrador en `/users`; borradores propios o de revisión en `/projects`, ver sección 9).

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
- La foto anterior se borra de Cloudinary después de guardar la nueva.

**200**: `{"usuario": {...}}` con la vista de administrador (6b); `foto_perfil` es `{"url": "https://res.cloudinary.com/..."}`. Cabecera `Cache-Control: no-store`.

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
      "url": "https://res.cloudinary.com/demo/image/upload/sample.jpg",
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

Fase 3. Proyectos de la colección `Documentos` (`categoria = proyecto`). Artículos y noticias (`/articles`) siguen el mismo contrato en el paso 2 de la fase 3. Contrato provisional: `PROPUESTA` DEC-B30 a DEC-B41, pendiente de aprobación del equipo.

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
- `archivos_adjuntos[].archivo` es el identificador para quitar el adjunto (paso 3 de la fase 3).

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

## Fuera de esta fase

- `POST /auth/google` (login con Google, RF-17): no está registrada; responde `404`. Sigue fuera en la fase 2.
- Hero, bloques S-I-I-I-S, llamada a la acción y footer de la página de inicio: por ahora son contenido estático del frontend (DEC-B12). El botón "Inscríbete" solo navega a la página de inscripción.
- Artículos y noticias (`/articles`) y archivos adjuntos (`/files`): pasos 2 y 3 de la fase 3.
- Reseñas, inscripciones y contacto: fases siguientes.
