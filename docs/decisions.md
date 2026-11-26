# Registro de decisiones — SIIIS V3 (backend)

Formato según la sección 29 del spec (`docs/SIIIS_V3_SPEC_DRIVEN.md`). Etiquetas:

- `DECISION_REQUIRED`: el equipo/cliente debe decidir; hay un comportamiento provisional.
- `TODO_SPEC`: dato pendiente de confirmar (no es una regla de negocio).
- `PROPUESTA`: implementado así, pendiente de aprobación del equipo.
- `APROBADA`: aprobada por el responsable de backend en esta fase.
- `DECIDIDA`: decidida por el equipo; deja de ser provisional.

---

## DEC-B01 — Contenedor de dependencias PHP-DI

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (aprobada por backend para la fase 1)
- Fecha: 2026-09-25
- Autor: Backend (Nicolás Tinjaca)
- Contexto: la sección 16 del spec no incluye contenedor; las pruebas de API necesitan sustituir repositorios y `MailService` por dobles.
- Decisión: `php-di/php-di` ^7 como contenedor PSR-11 de Slim.
- Alternativas: fábrica manual sin dependencias.
- Impacto: `backend/src/Config/Container.php`, `composer.json`.
- Requiere migración: no.

## DEC-B02 — `firebase/php-jwt` ^7 en lugar de ^6

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (aprobada por backend para la fase 1)
- Fecha: 2026-09-25
- Contexto: todas las versiones < 7.0.0 tienen el aviso de seguridad PKSA-y2cr-5h3j-g3ys (cifrado débil); Composer las bloquea.
- Decisión: usar `^7.0` (requiere PHP ^8.0). Se desvía de la sección 16 del spec, que indicaba `^6`.
- Impacto: `JwtService`. `JWT_SECRET` exige ≥ 32 caracteres.
- Requiere migración: no.

## DEC-B03 — `mongodb/mongodb` ~1.19.1 compatible con `ext-mongodb` 1.18

- Estado: DECIDIDA (equipo, 2026-11-04) · depende de TODO_SPEC en Hostinger (DEC-B13)
- Fecha: 2026-09-25 · revisada el 2026-11-04
- Contexto: un reporte de marzo de 2025 indica que Hostinger compartido trae `ext-mongodb` 1.18.1. Antes se usaba `mongodb/mongodb` ^1.21.4, que exige la extensión ^1.21; con la 1.18 el backend no arrancaría en producción.
- Decisión: `mongodb/mongodb` `~1.19.1`, la versión más alta compatible con la extensión 1.18 (la 1.20 ya exige ^1.20). `require.ext-mongodb` = `^1.18` y `config.platform.ext-mongodb` = `1.18.1`. El código no usa nada que falte en la 1.18 (transacciones con `with_transaction`, `findOneAndUpdate`, `countDocuments`, `collation`, `typeMap`, `createIndex`); no hubo que cambiarlo.
- Avisos de seguridad permitidos (`config.audit.ignore` en `composer.json`, con su justificación):
  - **PKSA-61k5-cqr9-b8b4** (GHSA-65fr-j4p9-vc33, alta): la librería no rechaza `.` ni NUL en nombres de base de datos o colección (corregido en 1.21.4). Justificación: los nombres son constantes de `Siiis\Database\Collections` y `MONGO_DB_NAME` del `.env`; nunca vienen de la entrada del usuario.
  - **PKSA-y93m-jgy6-r9ns** (GHSA-8fxw-fmj8-xp7j, CVE-2026-88023, moderada): inyección de operadores en IDs de archivo de GridFS (corregido en 1.21.5). Justificación: el backend no usa GridFS; los archivos van a Cloudinary.
- Riesgo:
  - Se trabaja con una librería con dos avisos conocidos. La mitigación depende de que se mantengan dos reglas: **nunca** construir nombres de base o de colección a partir de la entrada, y **no usar GridFS** sin antes subir la librería a ≥ 1.21.5.
  - La 1.19 no recibirá más parches de seguridad; un aviso nuevo podría no tener corrección dentro de la rama compatible.
  - En local la extensión es la 1.21.9, así que las pruebas usan la librería 1.19.1 pero no la extensión 1.18.1.
- Salida: si Hostinger confirma una extensión ≥ 1.21, volver a `mongodb/mongodb` ^1.21.5, poner la versión real en `config.platform` y quitar ambos avisos de `audit.ignore`.
- `TODO_SPEC`: confirmar la versión real de `ext-mongodb` en Hostinger con `phpinfo()` (ver `docs/deployment.md`).
- Requiere migración: no.

## DEC-B04 — Variables de correo `MAIL_*`

- Estado: APROBADA (backend)
- Fecha: 2026-09-25
- Contexto: la sección 15 del spec usa `GMAIL_USER` / `GMAIL_APP_PASSWORD`; el prompt de la fase 1 usa `MAIL_USERNAME` / `MAIL_PASSWORD`.
- Decisión: usar `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`. Actualizar la sección 15 del spec cuando el equipo lo apruebe.
- Requiere migración: no.

## DEC-B05 — Endpoint `POST /api/v1/auth/logout`

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-09-25
- Contexto: no estaba en el contrato 11.3; la sección 10.13.6 lo sugiere gracias a la colección `Sesiones`.
- Decisión: `POST /api/v1/auth/logout` con JWT → marca la sesión `activa: false` → `204`.
- Impacto: `AuthController`, `docs/api.md`, frontend (botón de cerrar sesión).
- Requiere migración: no.

## DEC-B06 — Umbral de intentos fallidos de login

- Estado: DECIDIDA (equipo, 2026-11-04)
- Fecha: 2026-09-25 · decidida el 2026-11-04
- Contexto: `Credenciales.intentos_fallidos` existe para bloqueo por fuerza bruta, pero no está definido qué pasa al llegar al umbral.
- Provisional: se incrementa el contador en cada contraseña incorrecta y se reinicia en login exitoso. `MAX_LOGIN_ATTEMPTS` está en config, pero **no se bloquea** la cuenta (solo se registra en el log al alcanzarlo). El rate limiting por IP sigue activo.
- Decisión del equipo: se mantiene solo el rate limiting por IP ya implementado (`RATE_LIMIT_*`); **no se bloquea la cuenta** al llegar a `MAX_LOGIN_ATTEMPTS`. El contador `intentos_fallidos` y el registro en el log siguen como están.
- Impacto: `AuthController`, `CredencialesRepository`.

## DEC-B07 — Orden de verificación del login (anti-enumeración)

- Estado: APROBADA (backend)
- Fecha: 2026-09-25
- Decisión: primero se verifica la contraseña y después `estado_cuenta` / `Usuarios.estado`. Así los `403` solo los ve quien conoce la contraseña. Si el correo no existe se ejecuta un `password_verify` contra un hash ficticio para igualar tiempos. Respuesta genérica `401 INVALID_CREDENTIALS`.

## DEC-B08 — Cuenta en `pendiente_verificacion` → `403 ACCOUNT_PENDING`

- Estado: DECIDIDA (equipo, 2026-11-26)
- Fecha: 2026-09-25 · decidida el 2026-11-26
- Contexto: `estado_cuenta` admite `pendiente_verificacion`, pero no hay flujo de verificación definido.
- Comportamiento: login rechazado con `403 ACCOUNT_PENDING`. Cualquier otro valor de `estado_cuenta` distinto de `activa` (incluidos valores fuera del modelo) se trata como `403 ACCOUNT_BLOCKED`.
- Decisión del equipo: las cuentas pendientes **no se usan en esta entrega** (todas las cuentas se crean activas, DEC-B16). Se mantiene el `403 ACCOUNT_PENDING` tal como está; no hay flujo de verificación. Sin cambios de código.

## DEC-B09 — Recuperación de contraseña solo para cuentas activas

- Estado: DECIDIDA (equipo, 2026-11-26)
- Fecha: 2026-09-25 · decidida el 2026-11-26
- Comportamiento: el token y el correo solo se generan si `estado_cuenta = activa` y `Usuarios.estado = true`. La respuesta es **siempre** el mensaje genérico `200`.
- Decisión del equipo: una cuenta **bloqueada o inactiva no puede recuperar la contraseña**. Es lo que ya está implementado (también excluye las pendientes, que no se usan, DEC-B08). Sin cambios de código.

## DEC-B10 — Ruta de restablecimiento en el frontend

- Estado: DECIDIDA (equipo, 2026-11-04) · vista pendiente en el frontend
- Fecha: 2026-09-25 · decidida el 2026-11-04
- Decisión del equipo: se propone la ruta `/restablecer`. El correo enlaza a `PASSWORD_RESET_URL?token=...` (en desarrollo, `http://localhost:5173/restablecer`; en producción, el dominio del sitio + `/restablecer`).
- Pendiente: el frontend debe crear la vista `/restablecer`, que lee `token` de la query string y llama a `POST /api/v1/auth/reset-password`. No requiere cambios en el backend.

## DEC-B11 — Política de contraseñas

- Estado: DECIDIDA (equipo, 2026-11-04) · implementada
- Fecha: 2026-09-25 · decidida el 2026-11-04
- Decisión del equipo: mínimo 8 caracteres (`PASSWORD_MIN_LENGTH`), con al menos una letra y un número. Se mantiene el máximo de 72 bytes (límite de bcrypt).
- Implementación: `Siiis\Validation\PasswordRules`, usada por `POST /auth/register` (y `crear_superadmin.php`) y por `POST /auth/reset-password`. Letra = cualquier letra Unicode (incluye tildes y `ñ`); número = dígito `0`–`9`; los símbolos son opcionales. Mensaje: `"La contraseña debe tener al menos una letra y un número."` en `fields.password` (`400 VALIDATION_ERROR`).
- Impacto: las contraseñas ya guardadas no se validan de nuevo; la regla aplica al crear o restablecer.
- Requiere migración: no.

## DEC-B12 — Contenido de la página de inicio (hero, bloques S-I-I-I-S, CTA, footer)

- Estado: DECIDIDA (equipo, 2026-11-04)
- Fecha: 2026-09-25 · decidida el 2026-11-04
- Contexto: el diseño tiene hero con "Inscríbete", bloques alternados video/imagen + texto (acróstico S-I-I-I-S), CTA y footer. Solo el slider (`slider_principal`) y los videos (`video_presentacion`) tienen endpoint en el contrato.
- Decisión del equipo: los bloques de la página de inicio (hero, acróstico S-I-I-I-S, CTA y footer) son **contenido estático del frontend**. El backend solo sirve `GET /home/slider` y `GET /home/videos`; no se crean endpoints nuevos.

## DEC-B13 — Versión de `ext-mongodb` en Hostinger

- Estado: TODO_SPEC
- Supuesto actual: 1.18.1 (reporte de marzo de 2025). El backend ya es compatible (DEC-B03). Falta confirmar la versión real con `phpinfo()`; ver `docs/deployment.md`.

## DEC-B14 — Sin índice sobre `Credenciales.token_recuperacion`

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA
- Contexto: `reset-password` busca por el hash del token. La sección 8.4 no incluye ese índice.
- Decisión: no se crea (colección pequeña). Si crece, proponer un índice `sparse` al equipo de BD.

## DEC-B15 — Diferencia de tiempo en `forgot-password`

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (limitación conocida)
- Fecha: 2026-09-25
- Contexto: el cuerpo y el status de `forgot-password` son idénticos exista o no el correo, pero el envío SMTP es síncrono (hosting compartido sin colas). Una cuenta existente tarda más en responder, lo que en teoría permite enumerar correos midiendo tiempos.
- Mitigación actual: rate limiting por IP (`RATE_LIMIT_FORGOT_*`).
- Alternativa futura: responder primero y enviar después (`litespeed_finish_request()` / `fastcgi_finish_request()` si Hostinger lo permite).

---

# Fase 2 — Autenticación y usuarios

Las decisiones de esta sección fueron aprobadas por backend para implementar la fase 2 y **aprobadas por el
equipo el 2026-11-26** (Andryw Barrera, líder, y Luisa Merchán, bases de datos).

## DEC-B16 — Registro solo administrativo

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-10-24
- Autor: Backend (Nicolás Tinjaca)
- Contexto: RF-01 pide "registro mediante roles", pero el spec no define un rol por defecto ni un flujo de aprobación. La sección 8.2 indica que un aspirante entra por `Inscripciones` y que un administrador crea el par `Credenciales`/`Usuarios`.
- Decisión: `POST /api/v1/auth/register` está protegido; solo `superadministrador` y `administrador` crean usuarios, y la cuenta se crea con `estado_cuenta = activa`. No hay registro público: quien quiera entrar al semillero usa el módulo de inscripción.
- Alternativas: registro público con rol por defecto y cuenta `pendiente_verificacion` aprobada por un administrador.
- Impacto: `AuthController`, `UserAccountService`, `docs/api.md`.
- Requiere migración: no.

## DEC-B17 — Jerarquía de roles para gestionar usuarios

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-10-24
- Contexto: el spec (8.2, 8.6) deja abierto el límite de permisos entre `superadministrador` y `administrador`.
- Decisión:
  - `superadministrador` crea, edita, cambia de rol, desactiva y reactiva usuarios de cualquier rol.
  - `administrador` hace lo mismo solo con `docente_director`, `estudiante_lider` y `estudiante`, y solo puede asignar esos roles.
  - `docente_director`, `estudiante_lider` y `estudiante` solo editan su propio perfil.
  - Nadie cambia su propio `rol` ni su propio `estado`.
  - No se puede desactivar ni bajar de rol al último superadministrador activo (ni por `PATCH` ni por `DELETE`).
  - Cambiar el rol o desactivar a un usuario cierra todas sus sesiones, para que el rol del JWT no quede desactualizado.
- Impacto: `Security/Roles`, `RoleMiddleware`, `UsersService`, `AuthController`.
- Requiere migración: no.

## DEC-B18 — Cupo de 2 administradores (RF-01)

- Estado: DECIDIDA (equipo, 2026-11-26)
- Fecha: 2026-10-24 · decidida el 2026-11-26
- Contexto: RF-01 dice "únicamente 2 administradores: el docente y el estudiante líder de proyectos".
- Decisión del equipo: **el código no limita la cantidad de administradores**; el cupo es una regla de uso que
  cumplen los superadministradores al asignar roles. Es lo que ya está implementado. Sin cambios de código.

## DEC-B19 — Datos públicos de los integrantes

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-10-24
- Decisión: la vista pública de un integrante expone `id`, `nombre`, `apellido`, `rol`, `programa_academico`, `semestre`, `foto_perfil` (solo `url`) y `biografia`. El `telefono` y el correo nunca son públicos. Los administradores ven además `correo`, `telefono`, `estado`, `estado_cuenta` y fechas.
- Impacto: `UsersService`, `docs/api.md`.

## DEC-B20 — Egresados en la página pública de miembros

- Estado: DECIDIDA (equipo, 2026-11-04) · por ahora
- Fecha: 2026-10-24 · decidida el 2026-11-04
- Decisión del equipo: **por ahora** la página pública muestra solo integrantes activos (`estado = true`); un usuario inactivo responde `404` en la vista pública. Es lo que ya está implementado.
- Si más adelante se quieren mostrar egresados, habrá que distinguirlos de quienes desertaron (hoy ambos quedan con `estado = false`).

## DEC-B21 — Límites de perfil y de foto en configuración

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-10-24
- Contexto: la sección 13.3 pide no fijar en código límites no aprobados.
- Decisión (valores por defecto, configurables en `.env`): foto JPEG, PNG o WebP de hasta 2 MB en la carpeta de Cloudinary `siiis/perfiles`; `semestre` entre 1 y 10; `nombre` y `apellido` de hasta 80 caracteres; `programa_academico` de hasta 120; `biografia` de hasta 1000; `telefono` de 7 a 20 dígitos (se admite `+` inicial).
- Detalle de implementación: `semestre` debe llegar como número entero JSON (no texto). `telefono` debe llegar como texto (un número JSON perdería el `+` y los ceros iniciales); se guarda sin espacios, guiones, puntos ni paréntesis. Un opcional vacío se guarda como `null`.
- Variables: `PERFIL_NOMBRE_MAX_LENGTH`, `PERFIL_PROGRAMA_MAX_LENGTH`, `PERFIL_BIOGRAFIA_MAX_LENGTH`, `PERFIL_SEMESTRE_MAX`, `PERFIL_TELEFONO_MIN_DIGITS`, `PERFIL_TELEFONO_MAX_DIGITS` (las de la foto se agregan en el paso 6).
- Impacto: `Settings`, `.env.example`, `PerfilValidator`, `FileValidator`.

## DEC-B22 — Reactivación de usuarios

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-10-24
- Decisión: la desactivación (`DELETE /users/{id}`) es lógica: `estado = false`, `fecha_salida = ahora` y cierre de sesiones (spec 8.6 y 10.13.6). Un administrador reactiva con `PATCH /users/{id}` `{"estado": true}`, que deja `fecha_salida = null` y conserva `fecha_ingreso`.
- Impacto: `UsersService`.

## DEC-B23 — Operaciones de cuenta fuera de la fase 2

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-10-24
- Contexto: no están en el contrato 11.3.
- Propuesta para una fase futura: cambiar la contraseña estando autenticado, cambiar el correo (vive en `Credenciales`) y desbloquear cuentas `bloqueadas` (ligado a DEC-B06).
- Impacto: ninguno en la fase 2.
- **Cambio de contraseña: implementado en la fase 5, paso 2 (2026-11-19), aprobado por el equipo el
  2026-11-26.** Contrato en `docs/api.md` (6a). Reglas:
  - `POST /auth/change-password`, protegido, con `password_actual` y `password_nueva` (sin recortar). La nueva
    sigue `PasswordRules` (DEC-B11) y debe ser distinta de la actual.
  - Contraseña actual incorrecta → `400 VALIDATION_ERROR` en `fields.password_actual`. **No** suma a
    `intentos_fallidos` (`MAX_LOGIN_ATTEMPTS`) ni cierra la sesión: quien la usa ya tiene una sesión válida, y el
    abuso lo frena el rate limiting.
  - Rate limiting por IP propio (`RATE_LIMIT_CHANGE_PASSWORD_MAX`, 10, y `RATE_LIMIT_CHANGE_PASSWORD_WINDOW_SECONDS`,
    900), aplicado después de autenticar y antes de validar: los intentos inválidos cuentan, las peticiones sin sesión no.
  - Al cambiarla se cierran las demás sesiones del usuario (`SesionesRepository::desactivarOtras`) y se mantiene la
    actual; además se anula un token de recuperación pendiente (`CredencialesRepository::cambiarPassword`, el mismo
    que usa `reset-password`).
  - Cuenta `bloqueada` o `pendiente_verificacion` con una sesión aún abierta → `403 ACCOUNT_BLOCKED` /
    `ACCOUNT_PENDING`, como en el login (supuesto del backend: hoy ninguna ruta bloquea cuentas, DEC-B06).
  - Sin correo de aviso al usuario: el spec no lo pide. Si el equipo lo quiere, se agrega con `MailService`.
- Cambio de correo y desbloqueo de cuentas: siguen sin implementar.

## DEC-B24 — `crear_superadmin.php` reutiliza `UserAccountService`

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (aprobada por backend)
- Fecha: 2026-10-24
- Decisión: el script usa el mismo servicio que `POST /auth/register` para crear el par `Credenciales`/`Usuarios`, de modo que la forma de los documentos esté en un solo lugar. Su comportamiento no cambia.
- Impacto: `scripts/crear_superadmin.php`, `UserAccountService`.

## DEC-B25 — Unicidad del correo al registrar

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (aprobada por backend)
- Fecha: 2026-10-24
- Contexto: el índice único de `Credenciales.correo` (spec 8.4) aún no está creado en la base real `siiis` (pendiente de la fase 1).
- Decisión: `UserAccountService` comprueba primero si el correo existe (`409 EMAIL_TAKEN`) y, además, traduce el error de clave duplicada del índice (código 11000) al mismo `409`, para cubrir dos altas simultáneas.
- Riesgo: hasta que se ejecute `scripts/crear_indices.php` en `siiis`, dos altas simultáneas con el mismo correo podrían duplicarse.
- Impacto: `UserAccountService`, `CuentasRepository`.

## DEC-B26 — Contrato de `GET /users` y `GET /users/{id}`

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-10-25
- Contexto: el spec (10.13.4, 19) pide paginar con un `per_page` máximo configurable, pero no define parámetros, orden, forma de la respuesta ni qué ve cada actor en el detalle.
- Decisión:
  - Parámetros: `page` (1 a 10000, por defecto 1), `per_page` (1 a `USERS_PER_PAGE_MAX`, por defecto `USERS_PER_PAGE_DEFAULT`; 50 y 20), `rol` (lista blanca) y `estado` (`true`/`false`, solo administradores; para los demás se ignora y se fija `estado = true`, DEC-B20). Un valor inválido o enviado como arreglo responde `400 VALIDATION_ERROR` con `fields`. Un parámetro vacío usa el valor por defecto.
  - Respuesta: `{"data": [...], "meta": {"page", "per_page", "total", "total_pages"}}`. Una página fuera de rango devuelve `data: []`, no error.
  - Orden: `apellido`, `nombre` y `_id` ascendentes, con collation de MongoDB `es` (ignora tildes y mayúsculas).
  - Vistas (DEC-B19): `superadministrador` y `administrador` reciben la vista de administrador de **todos** los usuarios (la jerarquía de DEC-B17 limita gestionar, no consultar). Un integrante autenticado recibe su **propio** perfil (`GET /users/{id}` con su id) en la vista completa, para la pantalla de edición del paso 4; el de los demás, en la vista pública.
  - `GET /users/{id}` responde `404 NOT_FOUND` si el id no tiene formato válido, no existe o, en la vista pública, el usuario está inactivo.
  - Caché: sin sesión, `Cache-Control: public, max-age=60`; con sesión, `no-store`. Siempre `Vary: Authorization`.
  - Sin índice nuevo en `Usuarios` (la colección es pequeña y el spec 8.4 no lo pide). Si crece, proponer `{estado, apellido, nombre}` con la misma collation.
- Alternativas: paginación por cursor; `400` para un id con formato inválido.
- Variables: `USERS_PER_PAGE_DEFAULT`, `USERS_PER_PAGE_MAX`.
- Impacto: `UsersController`, `UsersService`, `UsersQueryValidator`, `UsuariosRepository::paginar`, `CredencialesRepository::findCuentasByIds`, `Settings`, `docs/api.md`.
- Requiere migración: no.

## DEC-B27 — Contrato de `PATCH /users/{id}`

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-10-27
- Contexto: el spec (10.8, 11.3) solo nombra la ruta; DEC-B17 fija quién puede editar a quién, pero no la forma de la petición ni los errores.
- Decisión:
  - Requiere sesión (`401` sin token válido). Edición parcial: solo se cambian los campos enviados.
  - Campos editables: los de perfil de `POST /auth/register` (`nombre`, `apellido`, `programa_academico`, `semestre`, `biografia`, `telefono`, mismas reglas de DEC-B21) y `rol`. Cualquier otro campo (`correo`, `foto_perfil`, `password`…) se ignora; `estado` se define en DEC-B28. Un opcional enviado como `null` o `""` se borra. Si no llega ningún campo editable: `400 VALIDATION_ERROR` sin `fields`.
  - Permisos (DEC-B17): cada usuario edita su propio perfil; `superadministrador` y `administrador` editan a los roles que gestionan. Un integrante que intenta editar a otro recibe `403` sin consultar la base (no revela si el id existe); un administrador recibe `404` si el usuario no existe.
  - Enviar el propio `rol` sin cambiarlo no es un cambio de rol (el formulario puede reenviar el objeto completo).
  - Cambiar el `rol` cierra todas las sesiones del usuario editado; editar solo el perfil no.
  - Quitar el rol al último superadministrador activo responde `409 LAST_SUPERADMIN`.
  - Respuesta `200 {"usuario": ...}` con la vista de administrador (la misma de `GET /users/{id}` para el propio usuario) y `Cache-Control: no-store`.
- Limitación conocida: la comprobación del último superadministrador y la actualización no son atómicas. Como nadie cambia su propio rol, solo fallaría si dos superadministradores se quitan el rol mutuamente al mismo tiempo. Si hiciera falta, se resolvería con una transacción.
- Alternativas: `PUT` con el perfil completo; `400` en lugar de ignorar campos no editables.
- Impacto: `UsersController`, `UsersService::actualizar`, `UpdateUserValidator`, `UsuariosRepository::actualizar`/`contarSuperadministradoresActivos`, `Routes`, `docs/api.md`.
- Requiere migración: no.

## DEC-B28 — Contrato de `DELETE /users/{id}` y de `estado` en `PATCH /users/{id}`

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-10-27
- Contexto: DEC-B22 fija la desactivación lógica y la reactivación por `PATCH`, pero no la respuesta, la idempotencia ni qué hace `PATCH {"estado": false}`.
- Decisión:
  - `DELETE /users/{id}` solo para `superadministrador` y `administrador` (`403` para el resto, incluso sobre sí mismos) y dentro de la jerarquía de DEC-B17. Deja `estado = false` y `fecha_salida = ahora`, y cierra todas las sesiones del usuario. Responde `200 {"usuario": ...}` (vista de administrador), no `204`, para que el frontend actualice la fila sin otra consulta.
  - Es idempotente: sobre un usuario ya inactivo responde `200` sin cambios y conserva la `fecha_salida` original.
  - `PATCH {"estado": false}` hace exactamente lo mismo que `DELETE` (mismas reglas); así el formulario de edición puede reenviar el objeto completo. `PATCH {"estado": true}` reactiva: `fecha_salida = null`, conserva `fecha_ingreso`. Las sesiones cerradas no se reabren: el usuario vuelve a iniciar sesión.
  - `estado` debe ser un booleano JSON (`true`/`false`); `"true"`, `1`, `null` u objetos → `400 VALIDATION_ERROR`. Reenviar el estado actual no es un cambio.
  - Nadie se desactiva a sí mismo (`403`). Desactivar al último superadministrador activo → `409 LAST_SUPERADMIN` (misma limitación de atomicidad que DEC-B27).
  - No se toca `Credenciales.estado_cuenta`: el login ya rechaza a los usuarios con `estado = false` (`403 ACCOUNT_INACTIVE`).
- Alternativas: `204` sin cuerpo; `409` al desactivar a un inactivo; aceptar solo `true` en `PATCH`.
- Impacto: `UsersController::destroy`, `UsersService::actualizar`/`desactivar`, `UpdateUserValidator`, `Routes`, `docs/api.md`.
- Requiere migración: no.

## DEC-B29 — Contrato de `POST /users/{id}/photo`

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-10-27
- Contexto: RF-06 y el spec (10.13.8, 13) fijan el patrón (MIME real con `finfo`, Cloudinary, `{url, public_id}`, compensación), pero no el formato de la petición, los permisos ni los códigos de error.
- Decisión:
  - `multipart/form-data` con el archivo en el campo `foto`. Requiere sesión; mismos permisos que editar el perfil (DEC-B17, DEC-B27): el propio usuario o un administrador sobre los roles que gestiona.
  - Formatos: JPEG, PNG y WebP, detectados por el contenido (`finfo`); la extensión y el `Content-Type` del cliente se ignoran. Cloudinary vuelve a comprobarlo con `allowed_formats`. Tamaño máximo `PERFIL_FOTO_MAX_BYTES` (2 MB, DEC-B21).
  - Carpeta `CLOUDINARY_PERFILES_FOLDER` (`siiis/perfiles`); las pruebas usan `siiis_test/perfiles`. Cloudinary genera un `public_id` único por subida (no se sobrescribe la foto anterior: así un fallo intermedio nunca deja al usuario sin foto).
  - Orden: validar → comprobar permisos → subir → guardar `{url, public_id}` en `Usuarios.foto_perfil` → borrar la foto anterior. Si MongoDB falla, se borra la recién subida (compensación) y se responde `500`. Si falla el borrado de la anterior, la respuesta sigue siendo `200` y queda en el log para limpiarla a mano.
  - Errores: `400 VALIDATION_ERROR` (sin archivo, campo `foto[]`, archivo vacío o subida incompleta), `413 FILE_TOO_LARGE`, `415 UNSUPPORTED_MEDIA_TYPE`, `502 CLOUDINARY_UNAVAILABLE` (Cloudinary falla o no está configurado; spec 10.13.10).
  - Respuesta `200 {"usuario": ...}` (vista de administrador; `foto_perfil` solo con `url`).
- Pendiente para Hostinger: `upload_max_filesize` y `post_max_size` deben ser al menos 2 MB. Si el cuerpo supera `post_max_size`, PHP descarta el archivo y la API responde `400` (sin archivo) en lugar de `413`.
- Alternativas: `public_id` fijo por usuario con `overwrite`; subida firmada directa desde el navegador (DECISION-006 del spec, pensada para videos).
- Variables: `PERFIL_FOTO_MAX_BYTES`, `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY`, `CLOUDINARY_API_SECRET`, `CLOUDINARY_PERFILES_FOLDER`.
- Impacto: `FileValidator`, `CloudinaryService`, `UsersService::cambiarFoto`, `UsersController::photo`, `Routes`, `Settings`, `.env.example`, `composer.json` (`cloudinary/cloudinary_php` ^3.1), `docs/api.md`.
- Requiere migración: no.

---

# Fase 3 — Contenido (proyectos, artículos y noticias)

El spec (8.2, 10.9 y 11.3) define la colección `Documentos` y las rutas `/projects` y `/articles`, pero no
quién publica, si hay revisión ni quién edita o borra. Las decisiones DEC-B30 a DEC-B40 responden las 11 dudas
del plan de la fase 3. Backend las aprobó el 2026-11-04 con estas recomendaciones y el equipo (líder y bases
de datos) las **aprobó el 2026-11-26**. DEC-B41 recoge el contrato de las rutas.

## DEC-B30 — Rutas y categorías: las noticias van en `/articles`

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-11-04
- Contexto: `Documentos.categoria` admite `proyecto`, `articulo` y `noticia`, pero el spec 11.3 solo tiene `/projects` y `/articles`.
- Decisión: `/projects` sirve solo `categoria = proyecto` (la categoría es fija; si el cuerpo trae otra, se ignora). `/articles` sirve `articulo` y `noticia`: al crear, `categoria` es obligatoria, y el listado se filtra con `?categoria=articulo|noticia`. Un documento de otro módulo responde `404` (p. ej. un artículo pedido por `/projects/{id}`). Un solo `DocumentosController`, como pide el spec 9.
- Alternativas: una ruta `/news` (no está en el contrato).
- Impacto: `ModuloDocumentos`, `Routes`, `DocumentosController`, `DocumentoValidator`, `DocumentosQueryValidator`.
- Requiere migración: no.

## DEC-B31 — Estados por categoría y vista pública

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo). Relacionada con DECISION-010 del spec (estados definitivos).
- Fecha: 2026-11-04
- Contexto: `estado` mezcla el avance de un proyecto (`ejecucion`, `finalizado`) con la revisión (`en_revision`, `publicado`). Si el público solo viera `publicado`, nunca vería un proyecto en ejecución.
- Decisión:
  - Proyecto: `en_revision`, `ejecucion`, `finalizado`.
  - Artículo y noticia: `en_revision`, `publicado`.
  - Un estado de otra categoría responde `400 VALIDATION_ERROR` (p. ej. `publicado` en un proyecto).
  - **Vista pública: `visible = true` y `estado` distinto de `en_revision`.** `visible = false` sirve para ocultar algo ya aprobado sin borrarlo.
- Impacto: `ModuloDocumentos::estadosDe`, `DocumentosRepository::filtro`, `DocumentosService`.
- Requiere migración: no.

## DEC-B32 — Quién crea documentos

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-11-04
- Decisión: cualquier usuario con sesión, de cualquier rol, crea proyectos, artículos y noticias, y queda como autor (`autor_id` sale del token, nunca del cuerpo). Lo que crea queda en `en_revision` y con `visible = false`. Si alguien que no es revisor intenta crearlo con otro estado o con `visible: true`, recibe `403 FORBIDDEN`; reenviar `en_revision` o `false` está permitido.
- Impacto: `DocumentosService::crear`.
- Requiere migración: no.

## DEC-B33 — Revisión y publicación

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-11-04
- Decisión: hay revisión antes de publicar. Solo los **revisores** (`superadministrador`, `administrador` y `docente_director`; `Roles::REVISORES_CONTENIDO`) cambian `estado` o `visible`, en cualquier documento y en cualquier sentido (también pueden devolverlo a `en_revision`). Pueden crear un documento ya publicado. Para el contenido no se aplica la jerarquía de usuarios de DEC-B17: un revisor puede publicar el documento de un superadministrador.
- Impacto: `Roles`, `DocumentosService`.
- Requiere migración: no.

## DEC-B34 — Quién edita

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-11-04
- Decisión:
  - El **autor** edita su documento solo mientras está `en_revision`; después recibe `403` y debe pedir el cambio a un revisor.
  - Los **revisores** editan cualquier documento, siempre.
  - Los **integrantes** no editan: solo aparecen en la lista.
  - `PATCH` es parcial. Reenviar `estado` o `visible` con su valor actual no es un cambio. `autor_id`, `archivos_adjuntos` y las fechas se ignoran si llegan en el cuerpo.
  - Para que la edición de un autor no pise una publicación simultánea, la escritura exige en la misma consulta que el documento siga en `en_revision`; si ya no lo está, responde `403`.
- Alternativas: el autor también edita lo publicado y el documento vuelve a `en_revision`.
- Impacto: `DocumentosService::actualizar`, `DocumentosRepository::actualizar`.
- Requiere migración: no.

## DEC-B35 — Borrado físico

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-11-04
- Contexto: el modelo de `Documentos` no tiene un campo de "eliminado", y para ocultar ya existe `visible = false`.
- Decisión: `DELETE` borra el documento de verdad (`204`). Puede hacerlo el autor mientras está `en_revision` (con la misma condición atómica de DEC-B34) o un revisor en cualquier momento. Desde el paso 3 también se borran sus adjuntos en Cloudinary.
- Alternativas: borrado lógico con `visible = false` (no distingue "oculto" de "eliminado").
- Impacto: `DocumentosService::eliminar`, `DocumentosRepository::eliminar`.
- Requiere migración: no.

## DEC-B36 — Qué ve cada actor

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-11-04
- Decisión:
  - Sin sesión: solo lo público (DEC-B31).
  - Con sesión: lo público más lo suyo, como autor o como integrante, incluidos sus borradores.
  - Revisores: todo, y además pueden filtrar por `visible`.
  - Lo que el actor no puede ver responde `404` (no `403`) en `GET`, `PATCH` y `DELETE`: así no se revela si existe un borrador con ese id.
- Impacto: `DocumentoCriterios`, `DocumentosRepository::filtro`, `DocumentosService`.
- Requiere migración: no.

## DEC-B37 — `contenido` en Markdown y límites de texto

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo) · **pendiente de confirmar con el frontend**
- Fecha: 2026-11-04
- Contexto: el spec 8.2 dice que `contenido` es "texto enriquecido" sin definir el formato. Guardar HTML exigiría sanitizarlo en el backend (una librería nueva) para evitar XSS.
- Decisión: `contenido` es **Markdown**, opcional, de hasta 50 000 caracteres. Se guarda tal cual (sin espacios alrededor; vacío o `null` → `null`). El frontend debe mostrarlo con un renderizador de Markdown que **no interprete HTML incrustado**. `titulo` es obligatorio (máx. 200) y `descripcion` también (máx. 1000): la descripción es el resumen que aparece en los listados.
- Pendiente: confirmar con el frontend el formato y el renderizador.
- Variables: `DOCUMENTO_TITULO_MAX_LENGTH`, `DOCUMENTO_DESCRIPCION_MAX_LENGTH`, `DOCUMENTO_CONTENIDO_MAX_LENGTH`.
- Impacto: `DocumentoValidator`, `Settings`, `.env.example`.
- Requiere migración: no.

## DEC-B38 — Integrantes de un documento

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-11-04
- Decisión:
  - `integrantes` es una lista de ids de `Usuarios`, sin repetidos y de hasta 20. Se valida que existan (spec 8.5).
  - Para **agregar** a alguien, debe estar activo. Los integrantes que ya estaban y luego se desactivaron (egresados) pueden quedarse y siguen apareciendo en el documento, porque es su autoría.
  - El autor y los integrantes se muestran con su vista pública mínima: `id`, `nombre`, `apellido`, `rol` y `foto_perfil` (solo `url`). Los de toda una página se resuelven con una sola consulta (sin N+1).
  - `PATCH` con `integrantes` reemplaza la lista completa; `[]` o `null` la vacían.
- Variables: `DOCUMENTO_INTEGRANTES_MAX`.
- Impacto: `DocumentoValidator`, `DocumentosService::validarIntegrantes`, `UsuariosRepository::findResumenesByIds`, `DocumentoPresenter`.
- Requiere migración: no.

## DEC-B39 — Rutas para los archivos adjuntos

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo) · implementada en el paso 3
- Fecha: 2026-11-04
- Contexto: el spec 11.3 no tiene rutas para subir o quitar adjuntos.
- Contrato:
  - `POST`: `multipart/form-data` con el archivo en el campo `archivo` → `201 {"documento"}` con el detalle. `DELETE` → `200 {"documento"}` sin el adjunto (no `204`, para que el frontend no tenga que volver a pedir el documento).
  - Orden al subir: validar → comprobar permisos → comprobar el cupo → subir a Cloudinary → agregar en MongoDB con una sola operación atómica que vuelve a comprobar el cupo y, para el autor, que el documento siga `en_revision`. Si MongoDB falla o la condición ya no se cumple, se borra lo subido (compensación, spec 13.1).
  - Orden al quitar: quitar de MongoDB y después borrar de Cloudinary. Si falla Cloudinary, la respuesta sigue siendo `200` y el archivo queda en el log para borrarlo a mano. Lo mismo al borrar un documento con adjuntos (DEC-B35).
  - `nombre_archivo` es el nombre original del cliente, limpio (sin rutas ni caracteres de control, máximo 150 caracteres) y con la extensión del tipo real.
  - Errores: `400 VALIDATION_ERROR` (sin archivo o vacío), `403`/`404` (como en `PATCH`), `404` si `{archivo}` no existe en el documento, `409 ATTACHMENT_LIMIT`, `413 FILE_TOO_LARGE`, `415 UNSUPPORTED_MEDIA_TYPE`, `502 CLOUDINARY_UNAVAILABLE`.
- Decisión: sub-rutas como la de la foto de perfil, con un archivo por petición: `POST /{projects|articles}/{id}/files` (`multipart/form-data`) y `DELETE /{projects|articles}/{id}/files/{archivo}`. `{archivo}` es el identificador que devuelve el detalle en `archivos_adjuntos[].archivo` (el último segmento del `public_id`; el `public_id` completo nunca sale). Mismos permisos que editar el documento (DEC-B34).
- Impacto: `Routes`, `DocumentosController`, `DocumentosService`, `DocumentoPresenter::adjuntos`.
- Requiere migración: no.

## DEC-B40 — Tipos y tamaño de los adjuntos

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo) · implementada en el paso 3
- Fecha: 2026-11-04
- Contexto: el spec 13.3 prohíbe fijar en el código límites que el equipo no haya aprobado.
- Decisión: PDF, JPG, PNG y WebP, detectados por el contenido real (`finfo`), de hasta 5 MB por archivo y con un máximo de 10 adjuntos por documento. Todo en `.env`. Hostinger debe permitir subidas de al menos 5 MB. **Desde DEC-B42** (2026-11-05) los PDF tienen su propio límite, 3 MB (`DOCUMENTO_PDF_MAX_BYTES`); las imágenes siguen en 5 MB.
- Cloudinary: todo se sube como recurso `image`, también los PDF, con `allowed_formats` como segunda barrera tras `finfo`. Carpeta `CLOUDINARY_DOCUMENTOS_FOLDER` (`siiis/documentos`); las pruebas usan `siiis_test/documentos`.
- **Requisito de la cuenta de Cloudinary:** activar "Allow delivery of PDF and ZIP files" (*Settings → Security*). Sin ese ajuste, los PDF se suben pero al abrirlos Cloudinary responde `401 deny or ACL failure`.
  - El 2026-11-04 se probó subirlos como recurso `raw` y **no lo evita**: la cuenta bloqueó PDF como `raw` (con y sin extensión, y con descarga forzada) y como `image`. Solo se entregaron la miniatura `.jpg` del PDF y archivos que no son PDF. Por eso se mantiene `image`, que además permite miniaturas de la primera página.
  - En la cuenta de desarrollo (`backend/.env`) la descarga del PDF seguía bloqueada ese día. `DocumentosAdjuntosIntegrationTest::testElPdfSePuedeDescargar` se omite con el aviso hasta que se active el ajuste. Ver `docs/deployment.md`.
  - **2026-11-05:** activado en la cuenta de desarrollo; `DocumentosAdjuntosIntegrationTest` pasa completa, sin omitir. Falta activarlo en la cuenta del semillero.
- Variables: `DOCUMENTO_ADJUNTO_MAX_BYTES`, `DOCUMENTO_PDF_MAX_BYTES` (DEC-B42), `DOCUMENTO_ADJUNTOS_MAX`, `CLOUDINARY_DOCUMENTOS_FOLDER`.
- Impacto: `FileValidator::adjunto`, `CloudinaryService`, `DocumentosService`, `DocumentosRepository::agregarAdjunto`/`quitarAdjunto`, `Settings`, `.env.example`, `docs/deployment.md`.
- Requiere migración: no.

## DEC-B41 — Contrato de `/projects` y `/articles`

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-11-04
- Decisión:
  - Listado: `page` (1 a 10000), `per_page` (1 a `DOCUMENTOS_PER_PAGE_MAX`, por defecto `DOCUMENTOS_PER_PAGE_DEFAULT`; 50 y 12), `estado`, `autor` e `integrante` (ids, para el perfil de RF-05 y RF-08), `categoria` (solo `/articles`) y `visible` (solo revisores; para los demás se ignora). Un valor fuera de la lista blanca, un id inválido o un parámetro enviado como arreglo → `400 VALIDATION_ERROR` con `fields`. Respuesta `{"data", "meta"}`, igual que `GET /users`. Orden: más recientes primero (`fecha_creacion` y `_id` descendentes).
  - Los listados devuelven el resumen, sin `contenido` ni adjuntos (spec 10.13.4). El detalle agrega `contenido`, `archivos_adjuntos` y `permisos` (`editar`, `borrar` y `publicar` para el actor; el frontend los usa para mostrar u ocultar botones).
  - Respuestas: `POST` → `201 {"documento"}`; `PATCH` → `200 {"documento"}`; `DELETE` → `204`. `GET` sin sesión lleva `Cache-Control: public, max-age=60`; con sesión, `no-store`; siempre `Vary: Authorization`.
  - Sin índices nuevos: se usa el oficial `categoria + estado` (spec 8.4). Si la colección crece, proponer al equipo de BD `{categoria, fecha_creacion}` y uno sobre `integrantes`.
- Variables: `DOCUMENTOS_PER_PAGE_DEFAULT`, `DOCUMENTOS_PER_PAGE_MAX`.
- Impacto: `DocumentosController`, `DocumentosService`, `DocumentosQueryValidator`, `QueryReader`, `DocumentosRepository`, `DocumentoPresenter`, `Routes`, `docs/api.md`.
- Requiere migración: no.

## DEC-B42 — Optimización de espacio en Cloudinary

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-11-05
- Contexto: el plan gratuito de Cloudinary tiene un cupo de almacenamiento y de transferencia. Hasta ahora las fotos de perfil y las imágenes adjuntas se guardaban tal como las subía el usuario (una foto de celular puede pesar varios MB y tener más de 4000 px), y la API entregaba siempre el archivo original.
- Decisión:
  - **Al subir** (transformación *incoming*: Cloudinary guarda solo el resultado, no el original):
    - Foto de perfil: recorte cuadrado de `PERFIL_FOTO_LADO_PX` × `PERFIL_FOTO_LADO_PX` (400 × 400; `c_fill` con `g_auto`, que centra el recorte en lo importante de la imagen) y calidad `CLOUDINARY_CALIDAD` (`auto`). Una imagen más pequeña se amplía hasta 400 × 400, para que todas las fotos tengan el mismo tamaño.
    - Imagen adjunta: ancho máximo `DOCUMENTO_IMAGEN_MAX_ANCHO_PX` (1600 px) conservando la proporción y sin agrandar las más pequeñas (`c_limit`), con calidad `CLOUDINARY_CALIDAD`.
    - PDF: sin transformación, se guarda tal cual. Su límite baja de 5 MB a 3 MB (`DOCUMENTO_PDF_MAX_BYTES`); las imágenes adjuntas siguen en 5 MB (`DOCUMENTO_ADJUNTO_MAX_BYTES`), porque se reducen al subir y una foto de celular suele pasar de 3 MB. El límite se aplica según el tipo real del archivo (`finfo`), no según lo que declara el cliente.
  - **Al responder**: las URL de imágenes de Cloudinary (`/image/upload/`) salen con `f_auto,q_auto` (formato y calidad automáticos: el navegador recibe WebP o AVIF si los admite). Aplica a `foto_perfil.url`, a `archivos_adjuntos[].url` de imágenes y a las imágenes de `GET /home/slider`. Los PDF, los videos, los recursos `raw`, las URL de otros dominios y las que ya traen una transformación salen igual.
  - MongoDB sigue guardando el `secure_url` que devuelve Cloudinary, sin `f_auto,q_auto`: la transformación de entrega se agrega en `CloudinaryUrl::entrega` al responder, así que también aplica a los registros que ya existían y se puede cambiar sin migrar datos.
  - `CLOUDINARY_CALIDAD` admite `auto`, `auto:best`, `auto:good`, `auto:eco`, `auto:low` o un número de 1 a 100; otro valor hace fallar el arranque (`CONFIG_ERROR`). La entrega usa siempre `q_auto`.
- Notas:
  - Las fotos y adjuntos subidos antes de este cambio quedan con su tamaño original en Cloudinary (solo ganan `f_auto,q_auto` al entregarse). Si hace falta liberar espacio, se pueden volver a subir.
  - `f_auto,q_auto` genera derivados en la CDN de Cloudinary, que cuentan como transformaciones del plan (pocas, porque cada derivado se genera una vez y se cachea).
  - Verificado el 2026-11-05 con Cloudinary real (`siiis_test`): una foto de 800 × 600 queda en 400 × 400; una imagen adjunta de 2000 × 40 queda en 1600 × 32 y una de 300 × 20 no cambia; el PDF se guarda en formato `pdf` sin transformar y se descarga; las URL con `f_auto,q_auto` se descargan.
- Alternativas: guardar la URL ya transformada en MongoDB (no beneficia a los registros existentes y obliga a migrar si cambia); transformar solo al entregar (ahorra transferencia pero no almacenamiento); un único límite de 3 MB para todos los adjuntos (rechazaría muchas fotos de celular que igual se reducen al subir).
- Variables: `PERFIL_FOTO_LADO_PX`, `DOCUMENTO_IMAGEN_MAX_ANCHO_PX`, `CLOUDINARY_CALIDAD`, `DOCUMENTO_PDF_MAX_BYTES`.
- Impacto: `CloudinaryService` (`transformacionFotoPerfil`, `transformacionImagenAdjunta`, parámetro de transformación en `upload`), `Support/CloudinaryUrl`, `UsuarioPresenter::foto`, `DocumentoPresenter::adjuntos`, `HomeController`, `FileValidator::adjunto` (límite por tipo), `UsersService::cambiarFoto`, `DocumentosService::agregarAdjunto`, `Settings`, `.env.example`, `docs/api.md`, `docs/deployment.md`.
- Requiere migración: no.

---

# Fase 4 — Reseñas, inscripciones y contacto

El spec (8.2, 10.10 a 10.12, 11.3, 13 y 14) define las colecciones `Reseñas`, `Inscripciones` y
`Mensajes_Contacto`, las rutas y que haya validación y rate limiting, pero casi ninguna regla de negocio
(DECISION-005, resuelta el 2026-11-26 en DEC-B49). DEC-B43 a DEC-B52 responden las 18 dudas del plan de la
fase 4 (aprobado el 2026-11-06 con cuatro cambios del responsable de backend) y el equipo las **aprobó el
2026-11-26**. No hay colecciones, campos ni índices nuevos.

## DEC-B43 — Reseñas: qué son, quién escribe y cómo empiezan

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-11-06
- Contexto: RF-10 dice "cualquier usuario puede escribir reseñas" y el modelo permite `usuario_id` nulo
  (visitantes), pero no dice sobre qué es la reseña ni si se publica de inmediato.
- Decisión:
  - Una reseña es una **opinión sobre el semillero en general** (así lo describe el diccionario de datos de
    BD). No se relaciona con `Documentos`.
  - `POST /reviews` es público. Con sesión válida, `usuario_id` es el del JWT y `nombre_visible` sale del
    perfil (`nombre` + `apellido`): el cuerpo no puede cambiarlos. Sin sesión, `usuario_id = null` y
    `nombre_visible` es obligatorio. Si la petición trae `Authorization` pero el token no es válido, responde
    `401` (para que el frontend pida iniciar sesión otra vez y no la publique como anónima sin avisar).
  - Estado inicial: las de integrantes con sesión salen `visible`; las de visitantes quedan `oculta` hasta que
    un moderador las apruebe (DEC-B44). El sitio es institucional y el formulario es anónimo.
  - `comentario` obligatorio, máximo `RESENA_COMENTARIO_MAX_LENGTH` (1000); `nombre_visible` máximo
    `RESENA_NOMBRE_MAX_LENGTH` (80); `calificacion` opcional, entero de 1 a 5 (un texto como `"5"` se
    rechaza, igual que `semestre`).
  - Rate limiting por IP para todos, con o sin sesión (DEC-B48).
- Variables: `RESENA_NOMBRE_MAX_LENGTH`, `RESENA_COMENTARIO_MAX_LENGTH`.
- Impacto: `ReviewsController`, `ResenasService`, `ResenaValidator`, `ResenasRepository`, `Routes`.
- Requiere migración: no.

## DEC-B44 — Moderación de reseñas

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-11-06
- Contexto: el spec solo tiene `GET` y `POST /reviews`, pero el campo `estado` (`visible`/`oculta`) existe
  "para moderación".
- Decisión: se agregan dos rutas para los **moderadores**, que son los mismos revisores del contenido
  (`superadministrador`, `administrador`, `docente_director`; `Roles::MODERADORES_RESENAS`):
  - `PATCH /reviews/{id}` con `{"estado": "visible" | "oculta"}` → `200 {"resena"}`.
  - `DELETE /reviews/{id}` → `204`, borrado físico (para spam u ofensas; ocultar es la opción normal).
  - El resto recibe `403` (sin sesión, `401`); un id inexistente o inválido, `404`.
  - Nadie más edita una reseña, ni siquiera su autor.
- Impacto: `ReviewsController`, `ResenasService`, `Roles`, `RoleMiddleware`, `Routes`.
- Requiere migración: no.

## DEC-B45 — Contrato de `GET /reviews`

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-11-06
- Decisión:
  - Público con sesión opcional. Parámetros: `page` (1 a 10000), `per_page` (1 a `RESENAS_PER_PAGE_MAX`, por
    defecto `RESENAS_PER_PAGE_DEFAULT`; 50 y 10) y `estado` (solo moderadores; para los demás se ignora y se
    fija `visible`). Errores de la query como en `GET /users`.
  - Orden: más recientes primero (`fecha` y `_id` descendentes).
  - Respuesta `{"data", "meta", "resumen"}`; `resumen` = `{"promedio", "calificaciones"}` calculado solo con
    las reseñas **visibles** que tienen calificación (`promedio` con un decimal, `null` si no hay ninguna).
  - Cada reseña: `id`, `usuario_id` (o `null`), `nombre_visible`, `comentario`, `calificacion`, `estado` y
    `fecha`.
  - Caché igual que el contenido (DEC-B41): sin sesión `public, max-age=60`; con sesión `no-store`; siempre
    `Vary: Authorization`.
  - Sin índices nuevos (colección pequeña). Si crece, proponer al equipo de BD `{estado, fecha}`.
- Variables: `RESENAS_PER_PAGE_DEFAULT`, `RESENAS_PER_PAGE_MAX`.
- Impacto: `ReviewsController`, `ListadoQueryValidator` (compartido con el buzón y las inscripciones), `ResenasRepository`, `ResenaPresenter`, `Support/Paginacion`.
- Requiere migración: no.

## DEC-B46 — Contacto: un correo con plantilla fija y buzón para administradores

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-11-06
- Contexto: RF-03 habla de "correos preescritos"; el modelo guarda `estado` (`leido`/`no_leido`), pero el
  spec no tiene rutas para leer los mensajes.
- Decisión:
  - `POST /contact` (público) recibe `nombre`, `correo`, `asunto` y `mensaje`; guarda el mensaje en
    `Mensajes_Contacto` como `no_leido` y envía **un** correo con plantilla fija al buzón del semillero
    (`CONTACTO_CORREO_DESTINO`; si está vacío, `MAIL_FROM_ADDRESS` y luego `MAIL_USERNAME`), con "Responder a"
    hacia el remitente. **No hay respuesta automática al remitente**: permitiría usar el formulario para enviar
    correos del semillero a direcciones ajenas.
  - Saltos de línea y espacios repetidos de `nombre` y `asunto` se reducen a un espacio; el HTML del correo se
    escapa. Límites: `nombre` 80, `asunto` 150 y `mensaje` 3000 caracteres.
  - Respuesta `201 {"mensaje": "..."}` (texto para mostrar), sin devolver el registro.
  - Buzón para `superadministrador` y `administrador`: `GET /contact/messages` (paginado, más recientes
    primero, filtro `estado`; incluye el mensaje completo porque es lo que se lee en el panel y está limitado a
    3000 caracteres) y `PATCH /contact/messages/{id}` con `{"estado": "leido" | "no_leido"}`.
  - `POST /contact` vive en `HomeController`, como dice el spec (10.12); el buzón, en
    `MensajesContactoController`.
- Variables: `CONTACTO_CORREO_DESTINO`, `CONTACTO_NOMBRE_MAX_LENGTH`, `CONTACTO_ASUNTO_MAX_LENGTH`,
  `CONTACTO_MENSAJE_MAX_LENGTH`, `MENSAJES_PER_PAGE_DEFAULT`, `MENSAJES_PER_PAGE_MAX`.
- Impacto: `HomeController`, `MensajesContactoController`, `ContactoService`, `ContactoValidator`,
  `MensajesContactoRepository`, `MailService` (parámetro "Responder a"), `Routes`.
- Requiere migración: no.

## DEC-B47 — Si el correo falla, la operación igual responde `201`

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-11-06
- Decisión: en contacto e inscripciones el registro en MongoDB es la fuente de verdad (el modelo lo pide "para
  trazabilidad"). Si el SMTP falla después de guardar, la API responde `201` igualmente y el error queda en el
  log con el id del registro. El envío es síncrono (DEC-B15): la respuesta tarda unos segundos más.
- Impacto: `ContactoService`, `InscripcionesService`.
- Requiere migración: no.

## DEC-B48 — Rate limiting de las rutas públicas

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-11-06
- Contexto: dentro de la universidad muchos estudiantes comparten la misma IP pública; un límite muy bajo
  bloquearía a gente real.
- Decisión: mismo `RateLimiter` de archivos del login (spec 7.2), por IP, con ventanas de 1 hora:
  20 inscripciones, 20 mensajes de contacto y 10 reseñas. El intento cuenta antes de validar (un envío inválido
  también consume cupo, como en el login). Al pasarse: `429 RATE_LIMITED` con `Retry-After`.
- Variables: `RATE_LIMIT_INSCRIPTIONS_MAX`, `RATE_LIMIT_INSCRIPTIONS_WINDOW_SECONDS`, `RATE_LIMIT_CONTACT_MAX`,
  `RATE_LIMIT_CONTACT_WINDOW_SECONDS`, `RATE_LIMIT_REVIEWS_MAX`, `RATE_LIMIT_REVIEWS_WINDOW_SECONDS`.
- Impacto: `RateLimiter::limitar` (reutilizado por `AuthController`), controladores de la fase 4.
- Requiere migración: no.

## DEC-B49 — Documentos de la inscripción (DECISION-005)

- Estado: DECIDIDA (equipo, 2026-11-26) · resuelve DECISION-005 del spec
- Fecha: 2026-11-06 · decidida el 2026-11-26
- Contexto: el spec no dice qué documentos se piden ni sus formatos o tamaños.
- Decisión: de 1 a `INSCRIPCION_DOCUMENTOS_MAX` (3) archivos en el campo `documentos[]` de un solo
  `multipart/form-data`, con las mismas reglas de los adjuntos (DEC-B40, DEC-B42): PDF de hasta 3 MB guardado
  tal cual, y JPG, PNG o WebP de hasta 5 MB reducidas a 1600 px de ancho con calidad automática. El tipo se
  detecta por el contenido. Si una subida falla, se borran las anteriores; si falla el guardado en MongoDB, se
  borran todas.
- Notas: con 3 archivos la petición puede llegar a unos 15 MB: Hostinger debe permitir `post_max_size` y
  `upload_max_filesize` suficientes (ver `docs/deployment.md`).
- Decisión del equipo (DECISION-005, 2026-11-26): la inscripción lleva de 1 a 3 archivos PDF o imagen: la
  **carta de motivación es obligatoria** y el **certificado de matrícula o de notas es opcional**.
  - Cómo se cumple sin cambiar el contrato ni el modelo: el backend ya exige al menos 1 archivo y como máximo 3,
    con los formatos y tamaños de arriba. `Inscripciones.documentos_adjuntos` solo guarda `{url, public_id,
    nombre_archivo}` (sin un "tipo de documento"), así que el backend **no distingue** cuál archivo es la carta y
    cuál el certificado.
  - El frontend pide la carta en un campo obligatorio y el certificado en uno opcional, y los envía en ese orden
    en `documentos[]`; el administrador comprueba el contenido al revisar la solicitud (13c).
  - Si se quiere guardar qué tipo es cada archivo, hay que pedir un campo nuevo al equipo de BD (cambio de
    modelo, spec 8.2).
- Variables: `INSCRIPCION_DOCUMENTOS_MAX`, `CLOUDINARY_INSCRIPCIONES_FOLDER`.
- Impacto: `InscriptionsController`, `InscripcionesService`, `FileValidator`, `CloudinaryService`.
- Requiere migración: no.

## DEC-B50 — Documentos de la inscripción privados con enlaces firmados

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo; verificada con Cloudinary real el 2026-11-06)
- Fecha: 2026-11-06 · corregida el 2026-11-06 tras la prueba con Cloudinary real
- Contexto: son datos personales del aspirante (Ley 1581 de 2012, habeas data). Una URL pública de Cloudinary
  la puede abrir cualquiera que la consiga.
- Hallazgo (prueba real en la cuenta gratuita, `siiis_test/inscripciones`): al subir un recurso
  `authenticated`, la `secure_url` que devuelve Cloudinary **ya trae una firma** (`/s--XXXXXXXX--/`) que no
  vence: esa URL abre el archivo para siempre (`200`). La misma URL sin el segmento `s--…--` responde `401`, y la
  ruta pública `/upload/` equivalente, `404`. Los enlaces de descarga firmados con vencimiento
  (`privateDownloadUrl` con `expires_at`) sí funcionan en el plan gratuito: válidos → `200` (PDF completo; imagen
  ya reducida a 1600 px); vencidos o con la firma alterada → `401`; tras borrar el archivo → `404`. La entrega de
  PDF también funciona para recursos privados.
- Decisión (aprobada por el responsable de backend el 2026-11-06):
  - Los documentos se suben como recursos privados (`type: authenticated`), con las mismas transformaciones de
    DEC-B42.
  - MongoDB guarda `{url, public_id, nombre_archivo}` sin cambiar campos, pero `url` es la `secure_url`
    **sin el segmento de firma**: por sí sola no abre nada (`401`). Así una copia de la base, un respaldo o un log
    no exponen los documentos.
  - `GET /inscriptions/{id}` entrega a los administradores, en `documentos_adjuntos[].url`, un enlace de descarga
    firmado generado al responder a partir del `public_id`, que vence en `INSCRIPCION_ENLACE_TTL_SECONDS` (3600),
    y `expira` con la fecha de vencimiento. Nunca sale el `public_id` ni la URL guardada.
  - El borrado de un documento privado indica `type: authenticated`.
- Notas: el enlace firmado apunta a `api.cloudinary.com/.../image/download` e incluye el `api_key` (que es
  público en Cloudinary) y la firma, nunca el API secret. Verificado también por HTTP real el 2026-11-06
  (`php -S` + `curl`): detalle con enlaces → PDF y PNG descargables (`200`); firma alterada → `401`; URL
  guardada → `401`.
- Alternativa descartada: guardar la `secure_url` tal cual (firma que nunca vence).
- Variables: `INSCRIPCION_ENLACE_TTL_SECONDS`.
- Impacto: `CloudinaryService` (`uploadPrivado`, `enlacePrivado`, `delete` con tipo, `Support/CloudinaryUrl::sinFirma`), `InscripcionPresenter`.
- Requiere migración: no.

## DEC-B51 — Datos de la inscripción

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-11-06
- Decisión:
  - Obligatorios: `nombre_completo` (máximo 120), `correo`, `programa_academico` (como en el perfil),
    `semestre` (entero de 1 a `PERFIL_SEMESTRE_MAX`) y `carta_motivacion` (de 100 a 3000 caracteres).
    `telefono` opcional, con las reglas del perfil.
  - El correo debe ser del dominio `INSCRIPCION_DOMINIOS_CORREO` (por defecto `uptc.edu.co`; varios separados
    por comas; vacío = cualquier dominio). Se guarda en minúsculas.
  - Autorización de tratamiento de datos: la petición debe traer `acepta_tratamiento_datos=true`; **no se
    guarda** porque el modelo no tiene ese campo. Si el equipo quiere guardarla, debe pedírsela al equipo de BD.
  - Duplicados: si el correo ya tiene una solicitud `pendiente`, `409 INSCRIPTION_PENDING`; si la anterior fue
    rechazada o aceptada, se permite una nueva. Sin índice único (no está en 8.4): dos envíos simultáneos con el
    mismo correo podrían crear dos solicitudes; el administrador rechaza una.
- Formulario (`multipart/form-data`): todo llega como texto, así que `semestre` se acepta como dígitos y
  `acepta_tratamiento_datos` como `true`/`1`. Un campo enviado como arreglo (`nombre_completo[$ne]=x`) es un
  error del campo. Un texto que no sea UTF-8 válido responde `400` en su campo: lo encontró la prueba por HTTP
  real (un `curl` desde la consola de Windows envía Latin-1) y antes producía un `500` del driver de MongoDB.
  Con JSON no ocurre porque `json_decode` ya exige UTF-8; la comprobación quedó en `InputReader` y
  `PerfilValidator`, así que cubre todos los formularios.
- Variables: `INSCRIPCION_DOMINIOS_CORREO`, `INSCRIPCION_NOMBRE_MAX_LENGTH`,
  `INSCRIPCION_CARTA_MIN_LENGTH`, `INSCRIPCION_CARTA_MAX_LENGTH`.
- Impacto: `InscripcionValidator`, `InscripcionesService`, `InscripcionesRepository`.
- Requiere migración: no.

## DEC-B52 — Revisión de inscripciones y correos

- Estado: DECIDIDA (equipo, 2026-11-26) · antes PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-11-06
- Contexto: el spec se contradice: 8.2 dice que aceptar es un flujo manual y 10.13.4 habla de una transacción
  al aceptar una inscripción.
- Decisión:
  - Revisan `superadministrador` y `administrador` (quienes crean las cuentas, DEC-B16): `GET /inscriptions`
    (paginado, filtro `estado_solicitud`, sin carta ni documentos), `GET /inscriptions/{id}` (completo) y
    `PATCH /inscriptions/{id}` con `{"estado_solicitud": "aceptada" | "rechazada"}`.
  - Solo se pasa de `pendiente` a `aceptada` o `rechazada`, sin vuelta atrás (`409 INSCRIPTION_CLOSED`). El
    cambio es atómico (condición `estado_solicitud = pendiente`).
  - Aceptar **no crea la cuenta**: el administrador la crea después con `POST /auth/register` (flujo manual de
    8.2). No hay transacción.
  - Correos: al aspirante cuando se recibe la solicitud, al semillero (`INSCRIPCION_CORREO_AVISO`; si está
    vacío, el mismo destino del contacto) cuando llega una nueva, y al aspirante cuando se acepta o rechaza.
    Si fallan, aplica DEC-B47.
  - No hay `DELETE` (el spec no lo tiene).
  - **Conservación (equipo, 2026-11-26):** las solicitudes **rechazadas** se conservan **6 meses** y luego un
    administrador las **borra manualmente** (el documento en `Inscripciones` y sus archivos privados en
    Cloudinary, `siiis/inscripciones`). No hay ruta ni cron para esto; el procedimiento está en
    `docs/deployment.md` ("Mantenimiento"). `Inscripciones` no guarda la fecha de resolución (y agregarla cambiaría
    el modelo), así que los 6 meses se cuentan desde `fecha_solicitud`: una solicitud nunca se borra antes de
    tiempo, como mucho se guarda unos días más.
- Variables: `INSCRIPCION_CORREO_AVISO`, `INSCRIPCIONES_PER_PAGE_DEFAULT`, `INSCRIPCIONES_PER_PAGE_MAX`.
- Impacto: `InscriptionsController`, `InscripcionesService`, `InscripcionesRepository`, `Routes`.
- Requiere migración: no.

---

# Entrega en Hostinger (2026-11-26)

Decisiones del equipo para el despliegue con el profesor y resultado de la revisión de seguridad previa a
producción. Además, todas las decisiones que estaban en `PROPUESTA` en las fases 1 a 5 (DEC-B01 a DEC-B52)
quedaron **aprobadas por el equipo** el 2026-11-26.

## DEC-B53 — Login con Google (RF-17) fuera de esta entrega

- Estado: DECIDIDA (equipo, 2026-11-26)
- Decisión: RF-17 no se entrega ahora. `POST /auth/google` sigue sin registrarse (responde `404`) y DECISION-011
  (rol por defecto de una cuenta creada con Google) queda abierta para cuando se retome.
- Impacto: ninguno (ya era así).

## DEC-B54 — Acceso de red a MongoDB Atlas (DECISION-015)

- Estado: DECIDIDA (equipo, 2026-11-26) · resuelve DECISION-015 del spec
- Contexto: en el hosting compartido la IP saliente puede cambiar si Hostinger mueve la cuenta de servidor.
- Decisión: la lista de acceso de Atlas permite `0.0.0.0/0`, con una **contraseña fuerte** para el usuario de
  base de datos de la aplicación.
- Riesgo y mitigaciones (recomendación de backend): cualquiera en Internet puede intentar conectarse, así que la
  seguridad depende solo de la credencial. Usar una contraseña larga y aleatoria (24+ caracteres, sin reutilizar),
  un usuario con rol `readWrite` **solo** sobre la base `siiis` (no `atlasAdmin` ni acceso a otras bases), el
  usuario de pruebas aparte para `siiis_test`, y rotarla si se filtra. Si Hostinger confirma una IP estable, se
  puede cambiar a esa IP sin tocar el código.
- Impacto: solo configuración de Atlas y `MONGO_URI`.

## DEC-B55 — Credenciales de correo y Cloudinary de desarrollo en la entrega

- Estado: DECIDIDA (equipo, 2026-11-26) · temporal
- Decisión: las variables `MAIL_*` y `CLOUDINARY_*` de desarrollo se usan en producción **hasta que el semillero
  tenga sus propias cuentas**. Reemplaza la indicación anterior de `docs/deployment.md` (paso 5).
- Riesgo: son cuentas personales; los correos salen desde ese buzón y los archivos (incluidos los documentos
  privados de inscripción) quedan en esa cuenta de Cloudinary, junto a `siiis_test/`. Los datos de producción van
  a las carpetas `siiis/…`, distintas de las de pruebas.
- Salida: cuando el semillero tenga sus cuentas, cambiar las variables en el `.env` de producción. Los archivos ya
  subidos siguen en la cuenta vieja (sus URL dejan de servir si se borra esa cuenta); migrarlos sería un paso
  aparte. La cuenta de desarrollo ya tiene activada la entrega de PDF (DEC-B40).
- Impacto: solo `.env` de producción.

## DEC-B56 — Endurecimiento para producción (revisión de seguridad)

- Estado: PROPUESTA (revisión de seguridad de backend, 2026-11-26)
- Cambios:
  - `SecurityHeadersMiddleware` en todas las respuestas, también las de error: `X-Content-Type-Options: nosniff`,
    `X-Frame-Options: DENY`, `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'`,
    `Referrer-Policy: no-referrer`; `Cache-Control: no-store` cuando la ruta no fija su propia caché (las
    respuestas de error y algunas rutas no llevaban ninguna); `Strict-Transport-Security: max-age=15552000` solo con
    `APP_ENV=production` (sin `includeSubDomains`).
  - `APP_DEBUG=true` con `APP_ENV=production` (o sin `APP_ENV`, que vale `production`) hace que la API no arranque:
    con debug, un `500` devuelve la traza y las rutas del servidor.
  - `public/index.php` fija `zend.exception_ignore_args=1`: las trazas que se guardan en `storage/logs` no
    incluyen los argumentos de las funciones (podían incluir fragmentos de contraseñas).
  - `ObjectIdValidator` y el token de `reset-password` usan `\z` en lugar de `$`: con `$`, un id con un salto de
    línea final (`/users/<id>%0A`) pasaba la validación y el driver lanzaba una excepción (`500` en vez de `404`).
- Impacto: `Middleware/SecurityHeadersMiddleware`, `Config/Application`, `Config/Settings`, `public/index.php`,
  `Support/ObjectIdValidator`, `Services/PasswordResetService`. Sin cambios de contrato.

## DEC-B57 — CORS en producción solo con `CORS_ORIGIN` (plan B)

- Estado: PROPUESTA (backend, 2026-11-26)
- Contexto: en Hostinger frontend y API comparten dominio y no hay CORS (spec 10.13.9). En el plan B (DEC-B58) la
  API vive en otro dominio (Render) y el navegador necesita CORS.
- Decisión: nueva variable `CORS_ORIGIN`, un origen exacto con `https` (sin ruta, sin `*`; si no, la API no
  arranca). En desarrollo sigue mandando `FRONTEND_ORIGIN`; fuera de desarrollo, CORS solo se activa si
  `CORS_ORIGIN` tiene valor. Las respuestas CORS exponen `Retry-After` para que el frontend lea la espera de un `429`.
- Impacto: `Settings::origenCors`, `Application`, `CorsMiddleware`, `.env.example`.

## DEC-B58 — Plan B: backend en Render con Docker si Hostinger no tiene `ext-mongodb`

- Estado: PROPUESTA (backend, 2026-11-26) · contingencia, no desplegada
- Contexto: DECISION-008 eligió Hostinger y descartó Render. Si mañana el plan compartido no ofrece la extensión
  `mongodb`, el backend no puede arrancar allí.
- Decisión: `backend/Dockerfile` (PHP 8.2 + Apache + `ext-mongodb`) para desplegar **solo el backend** en Render;
  el frontend sigue en Hostinger y llama a la URL de Render con `CORS_ORIGIN` configurado (DEC-B57). Pasos en
  `docs/deployment.md` ("Plan B").
- Limitaciones: el disco del contenedor es efímero (logs y contadores de rate limiting se pierden en cada
  reinicio); el plan gratuito se duerme tras un rato sin uso (la primera petición tarda); la IP real del visitante
  depende de configurar `TRUSTED_PROXIES` con la red interna de Render.

## DEC-B59 — Archivo rechazado por Cloudinary: 415, no 502

- Estado: PROPUESTA (backend, 2026-11-26) · aprobada por el usuario para la entrega
- Contexto: un archivo que empieza como PDF (pasa `finfo`) pero está mal formado llegaba a Cloudinary, que responde
  `400 Invalid PDF file`; la API lo convertía en `502 CLOUDINARY_UNAVAILABLE` ("intenta más tarde"), un mensaje
  engañoso: reintentar no sirve, el problema es el archivo. Se encontró al probar el frontend.
- Decisión:
  - `CloudinaryService` distingue los `400` de Cloudinary (`BadRequest` del SDK) cuyo mensaje dice que el archivo
    es inválido o está dañado (`Invalid PDF file`, `Invalid image file`, `Invalid Image or file`,
    `Unsupported file format`, `… file format … not allowed`, `Corrupt file`) y lanza `ArchivoRechazadoException`
    (subclase de `CloudinaryException`).
  - Foto de perfil, adjuntos de documentos e inscripciones responden entonces `415 UNSUPPORTED_MEDIA_TYPE` con un
    mensaje claro ("El archivo está dañado o no es un PDF o una imagen válida…"; en la inscripción nombra el
    archivo). Se mantienen las compensaciones: la foto anterior no se toca, el documento no cambia y la inscripción
    borra de Cloudinary lo que ya había subido.
  - `502` queda solo para fallas reales: Cloudinary no responde, credenciales o configuración (otros `400`, como
    una transformación inválida, siguen siendo `502`).
- Impacto: `Services/CloudinaryService`, `Services/ArchivoRechazadoException`, `Services/CloudinaryException` (deja
  de ser `final`), `UsersService`, `DocumentosService`, `InscripcionesService`, `docs/api.md`. Pruebas: unitaria del
  clasificador, API de las tres rutas e integración con Cloudinary real (PDF dañado).

## DEC-B60 — Carga inicial de fotos y videos del inicio (`scripts/cargar_multimedia.php`)

- Estado: PROPUESTA (backend, 2026-11-26) · pedida por el usuario para la entrega
- Contexto: en producción `Multimedia` empieza vacía y `seed_multimedia.php` solo registra URL ya subidas (de
  ejemplo).
- Decisión: script de consola que sube a Cloudinary (`siiis/home/slider/…` y `siiis/home/videos/…`; con `--test`,
  `siiis_test/home`) las fotos y videos de una carpeta local (`slider/`, `videos/`, `descripciones.json` opcional) y
  los registra en `Multimedia` con los campos del modelo (`tipo`, `url`, `public_id`, `seccion`, `descripcion`,
  `orden`, `fecha_subida`). Sin campos ni colecciones nuevas.
  - Orden por nombre de archivo (orden natural); `public_id` estable a partir del nombre sin el prefijo de orden.
  - Idempotente: lo que ya está (misma sección y `public_id`) no se vuelve a subir; solo se actualizan `orden` y
    `descripcion`. `overwrite=false` en Cloudinary evita duplicados si una corrida se cortó entre la subida y el
    guardado.
  - Contra la base real muestra el plan y exige escribir `si`; `--simular` no escribe nada; `--si` omite la
    pregunta.
  - Imágenes reducidas a 1920 px de ancho con `CLOUDINARY_CALIDAD` (como DEC-B42); videos tal cual. Límites del plan
    gratuito: imagen 10 MB, video 100 MB.
- Impacto: `scripts/cargar_multimedia.php`, `scripts/multimedia/PlanMultimedia.php` (prueba unitaria),
  `scripts/empaquetar.ps1` y `Dockerfile` (se incluyen en producción), `docs/deployment.md`,
  `docs/checklist-manana.md`.
