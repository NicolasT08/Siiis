# Contrato API — SIIIS V3 · Fase 1

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
| `ACCOUNT_BLOCKED` | 403 | La cuenta está bloqueada |
| `ACCOUNT_PENDING` | 403 | La cuenta está pendiente de verificación |
| `ACCOUNT_INACTIVE` | 403 | El usuario está inactivo (p. ej. por deserción) |
| `INVALID_OR_EXPIRED_TOKEN` | 400 | El enlace de recuperación es inválido, está vencido o ya se usó |
| `NOT_FOUND` | 404 | La ruta no existe |
| `METHOD_NOT_ALLOWED` | 405 | Método HTTP no soportado en la ruta |
| `RATE_LIMITED` | 429 | Demasiados intentos; la cabecera `Retry-After` trae los segundos de espera |
| `DB_UNAVAILABLE` | 503 | La base de datos no responde |
| `INTERNAL_ERROR` | 500 | Error inesperado del servidor |

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

- Si la cuenta existe y está activa, llega un correo con el enlace `PASSWORD_RESET_URL?token=<64 caracteres hex>`. Por defecto: `http://localhost:5173/restablecer?token=...`. La ruta definitiva está pendiente (`TODO_SPEC`, DEC-B10).
- El token vence en `PASSWORD_RESET_TTL_MINUTES` (por defecto 30) y sirve una sola vez.

**Errores:** `400 VALIDATION_ERROR`, `429 RATE_LIMITED`.

---

## 6. `POST /auth/reset-password`

Público. La pantalla de restablecimiento lee `token` de la query string y lo envía junto con la nueva contraseña.

**Request**
```json
{ "token": "3f9a...(64 hex)", "password": "Nueva-Clave-2026" }
```

- `password`: mínimo `PASSWORD_MIN_LENGTH` caracteres (por defecto 8) y máximo 72 bytes. Las reglas de complejidad están pendientes (`TODO_SPEC`).

**200**
```json
{ "mensaje": "Tu contraseña fue actualizada. Inicia sesión con la nueva contraseña." }
```

Al restablecer, **se cierran todas las sesiones** del usuario.

**Errores:** `400 VALIDATION_ERROR` (con `fields.token` y/o `fields.password`) y `400 INVALID_OR_EXPIRED_TOKEN`.

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

## Fuera de esta fase

- `POST /auth/google` (login con Google, RF-17): no está registrada; responde `404`.
- Hero, bloques S-I-I-I-S, llamada a la acción y footer de la página de inicio: por ahora son contenido estático del frontend (DEC-B12). El botón "Inscríbete" solo navega a la página de inscripción.
- Registro, usuarios, documentos, reseñas, inscripciones y contacto: fases siguientes.
