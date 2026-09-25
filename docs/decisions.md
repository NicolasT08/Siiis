# Registro de decisiones — SIIIS V3 (backend)

Formato según la sección 29 del spec (`docs/SIIIS_V3_SPEC_DRIVEN.md`). Etiquetas:

- `DECISION_REQUIRED`: el equipo/cliente debe decidir; hay un comportamiento provisional.
- `TODO_SPEC`: dato pendiente de confirmar (no es una regla de negocio).
- `PROPUESTA`: implementado así, pendiente de aprobación del equipo.
- `APROBADA`: aprobada por el responsable de backend en esta fase.

---

## DEC-B01 — Contenedor de dependencias PHP-DI

- Estado: PROPUESTA (aprobada por backend para la fase 1)
- Fecha: 2026-09-25
- Autor: Backend (Nicolás Tinjaca)
- Contexto: la sección 16 del spec no incluye contenedor; las pruebas de API necesitan sustituir repositorios y `MailService` por dobles.
- Decisión: `php-di/php-di` ^7 como contenedor PSR-11 de Slim.
- Alternativas: fábrica manual sin dependencias.
- Impacto: `backend/src/Config/Container.php`, `composer.json`.
- Requiere migración: no.

## DEC-B02 — `firebase/php-jwt` ^7 en lugar de ^6

- Estado: PROPUESTA (aprobada por backend para la fase 1)
- Fecha: 2026-09-25
- Contexto: todas las versiones < 7.0.0 tienen el aviso de seguridad PKSA-y2cr-5h3j-g3ys (cifrado débil); Composer las bloquea.
- Decisión: usar `^7.0` (requiere PHP ^8.0). Se desvía de la sección 16 del spec, que indicaba `^6`.
- Impacto: `JwtService`. `JWT_SECRET` exige ≥ 32 caracteres.
- Requiere migración: no.

## DEC-B03 — `mongodb/mongodb` ^1.21.4 y `ext-mongodb` ≥ 1.21 en producción

- Estado: APROBADA (backend) · depende de TODO_SPEC en Hostinger
- Fecha: 2026-09-25
- Contexto: la rama 1.18 tiene el aviso PKSA-61k5-cqr9-b8b4 (severidad alta; nombres de BD/colección con `.` o NUL). En 1.x solo está corregido desde 1.21.4, que exige la extensión `mongodb` ^1.21. El spec reportaba la 1.18.x en Hostinger (2025).
- Decisión: `mongodb/mongodb` ^1.21.4; `config.platform.ext-mongodb` = 1.21.9 (versión local, provisional).
- Riesgo: si Hostinger ofrece una extensión < 1.21, el backend no arranca en producción.
- `TODO_SPEC`: confirmar la versión de `ext-mongodb` en Hostinger con `phpinfo()` (ver `docs/deployment.md`).
- Requiere migración: no.

## DEC-B04 — Variables de correo `MAIL_*`

- Estado: APROBADA (backend)
- Fecha: 2026-09-25
- Contexto: la sección 15 del spec usa `GMAIL_USER` / `GMAIL_APP_PASSWORD`; el prompt de la fase 1 usa `MAIL_USERNAME` / `MAIL_PASSWORD`.
- Decisión: usar `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`. Actualizar la sección 15 del spec cuando el equipo lo apruebe.
- Requiere migración: no.

## DEC-B05 — Endpoint `POST /api/v1/auth/logout`

- Estado: PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-09-25
- Contexto: no estaba en el contrato 11.3; la sección 10.13.6 lo sugiere gracias a la colección `Sesiones`.
- Decisión: `POST /api/v1/auth/logout` con JWT → marca la sesión `activa: false` → `204`.
- Impacto: `AuthController`, `docs/api.md`, frontend (botón de cerrar sesión).
- Requiere migración: no.

## DEC-B06 — Umbral de intentos fallidos de login

- Estado: DECISION_REQUIRED
- Fecha: 2026-09-25
- Contexto: `Credenciales.intentos_fallidos` existe para bloqueo por fuerza bruta, pero no está definido qué pasa al llegar al umbral.
- Provisional: se incrementa el contador en cada contraseña incorrecta y se reinicia en login exitoso. `MAX_LOGIN_ATTEMPTS` está en config, pero **no se bloquea** la cuenta (solo se registra en el log al alcanzarlo). El rate limiting por IP sigue activo.
- Falta decidir: bloqueo temporal (¿cuánto?) vs `estado_cuenta = bloqueada` (¿quién desbloquea?).
- Impacto: `AuthController`, `CredencialesRepository`.

## DEC-B07 — Orden de verificación del login (anti-enumeración)

- Estado: APROBADA (backend)
- Fecha: 2026-09-25
- Decisión: primero se verifica la contraseña y después `estado_cuenta` / `Usuarios.estado`. Así los `403` solo los ve quien conoce la contraseña. Si el correo no existe se ejecuta un `password_verify` contra un hash ficticio para igualar tiempos. Respuesta genérica `401 INVALID_CREDENTIALS`.

## DEC-B08 — Cuenta en `pendiente_verificacion` → `403 ACCOUNT_PENDING`

- Estado: DECISION_REQUIRED
- Fecha: 2026-09-25
- Contexto: `estado_cuenta` admite `pendiente_verificacion`, pero no hay flujo de verificación definido.
- Provisional: login rechazado con `403 ACCOUNT_PENDING`. Cualquier otro valor de `estado_cuenta` distinto de `activa` (incluidos valores fuera del modelo) se trata como `403 ACCOUNT_BLOCKED`.
- Falta decidir: cómo y quién verifica una cuenta.

## DEC-B09 — Recuperación de contraseña solo para cuentas activas

- Estado: DECISION_REQUIRED
- Fecha: 2026-09-25
- Provisional: el token y el correo solo se generan si `estado_cuenta = activa` y `Usuarios.estado = true`. La respuesta es **siempre** el mensaje genérico `200`.
- Falta decidir: si una cuenta bloqueada/pendiente puede recuperar la contraseña.

## DEC-B10 — Ruta de restablecimiento en el frontend

- Estado: TODO_SPEC
- Fecha: 2026-09-25
- Provisional: `PASSWORD_RESET_URL=http://localhost:5173/restablecer`; el correo enlaza a `PASSWORD_RESET_URL?token=...`.
- Falta: la ruta definitiva que defina Front-End.

## DEC-B11 — Política de contraseñas

- Estado: TODO_SPEC
- Provisional: solo longitud mínima `PASSWORD_MIN_LENGTH` (8) y máxima 72 bytes (límite de bcrypt).
- Falta: reglas de complejidad, si las hay.

## DEC-B12 — Contenido de la página de inicio (hero, bloques S-I-I-I-S, CTA, footer)

- Estado: DECISION_REQUIRED
- Fecha: 2026-09-25
- Contexto: el diseño tiene hero con "Inscríbete", bloques alternados video/imagen + texto (acróstico S-I-I-I-S), CTA y footer. Solo el slider (`slider_principal`) y los videos (`video_presentacion`) tienen endpoint en el contrato.
- Provisional: textos estáticos en el frontend; no se crean endpoints nuevos.
- Falta decidir: si esos bloques deben ser administrables, y si sus imágenes vienen de `Multimedia` con `seccion = galeria` (requeriría un endpoint o parámetro nuevo).

## DEC-B13 — Versión de `ext-mongodb` en Hostinger

- Estado: TODO_SPEC
- Ver DEC-B03 y `docs/deployment.md`.

## DEC-B14 — Sin índice sobre `Credenciales.token_recuperacion`

- Estado: PROPUESTA
- Contexto: `reset-password` busca por el hash del token. La sección 8.4 no incluye ese índice.
- Decisión: no se crea (colección pequeña). Si crece, proponer un índice `sparse` al equipo de BD.

## DEC-B15 — Diferencia de tiempo en `forgot-password`

- Estado: PROPUESTA (limitación conocida)
- Fecha: 2026-09-25
- Contexto: el cuerpo y el status de `forgot-password` son idénticos exista o no el correo, pero el envío SMTP es síncrono (hosting compartido sin colas). Una cuenta existente tarda más en responder, lo que en teoría permite enumerar correos midiendo tiempos.
- Mitigación actual: rate limiting por IP (`RATE_LIMIT_FORGOT_*`).
- Alternativa futura: responder primero y enviar después (`litespeed_finish_request()` / `fastcgi_finish_request()` si Hostinger lo permite).
