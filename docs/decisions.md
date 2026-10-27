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

---

# Fase 2 — Autenticación y usuarios

Todas las decisiones de esta sección son provisionales: aprobadas por backend para implementar la fase 2,
pendientes de aprobación del equipo (Andryw Barrera, líder, y Luisa Merchán, bases de datos).

## DEC-B16 — Registro solo administrativo

- Estado: PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-10-24
- Autor: Backend (Nicolás Tinjaca)
- Contexto: RF-01 pide "registro mediante roles", pero el spec no define un rol por defecto ni un flujo de aprobación. La sección 8.2 indica que un aspirante entra por `Inscripciones` y que un administrador crea el par `Credenciales`/`Usuarios`.
- Decisión: `POST /api/v1/auth/register` está protegido; solo `superadministrador` y `administrador` crean usuarios, y la cuenta se crea con `estado_cuenta = activa`. No hay registro público: quien quiera entrar al semillero usa el módulo de inscripción.
- Alternativas: registro público con rol por defecto y cuenta `pendiente_verificacion` aprobada por un administrador.
- Impacto: `AuthController`, `UserAccountService`, `docs/api.md`.
- Requiere migración: no.

## DEC-B17 — Jerarquía de roles para gestionar usuarios

- Estado: PROPUESTA (pendiente de aprobación del equipo)
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

- Estado: DECISION_REQUIRED
- Fecha: 2026-10-24
- Contexto: RF-01 dice "únicamente 2 administradores: el docente y el estudiante líder de proyectos".
- Provisional: el código no limita la cantidad de administradores.
- Falta decidir: si el cupo es una regla de negocio, si cuenta `superadministrador` y si esos cupos los ocupan solo `docente_director` y `estudiante_lider`.

## DEC-B19 — Datos públicos de los integrantes

- Estado: PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-10-24
- Decisión: la vista pública de un integrante expone `id`, `nombre`, `apellido`, `rol`, `programa_academico`, `semestre`, `foto_perfil` (solo `url`) y `biografia`. El `telefono` y el correo nunca son públicos. Los administradores ven además `correo`, `telefono`, `estado`, `estado_cuenta` y fechas.
- Impacto: `UsersService`, `docs/api.md`.

## DEC-B20 — Egresados en la página pública de miembros

- Estado: DECISION_REQUIRED
- Fecha: 2026-10-24
- Provisional: el listado público muestra solo integrantes con `estado = true`. Un usuario inactivo responde `404` en la vista pública.
- Falta decidir: si los egresados (`estado = false`) deben mostrarse públicamente y cómo distinguirlos de quienes desertaron.

## DEC-B21 — Límites de perfil y de foto en configuración

- Estado: PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-10-24
- Contexto: la sección 13.3 pide no fijar en código límites no aprobados.
- Decisión (valores por defecto, configurables en `.env`): foto JPEG, PNG o WebP de hasta 2 MB en la carpeta de Cloudinary `siiis/perfiles`; `semestre` entre 1 y 10; `nombre` y `apellido` de hasta 80 caracteres; `programa_academico` de hasta 120; `biografia` de hasta 1000; `telefono` de 7 a 20 dígitos (se admite `+` inicial).
- Detalle de implementación: `semestre` debe llegar como número entero JSON (no texto). `telefono` debe llegar como texto (un número JSON perdería el `+` y los ceros iniciales); se guarda sin espacios, guiones, puntos ni paréntesis. Un opcional vacío se guarda como `null`.
- Variables: `PERFIL_NOMBRE_MAX_LENGTH`, `PERFIL_PROGRAMA_MAX_LENGTH`, `PERFIL_BIOGRAFIA_MAX_LENGTH`, `PERFIL_SEMESTRE_MAX`, `PERFIL_TELEFONO_MIN_DIGITS`, `PERFIL_TELEFONO_MAX_DIGITS` (las de la foto se agregan en el paso 6).
- Impacto: `Settings`, `.env.example`, `PerfilValidator`, `FileValidator`.

## DEC-B22 — Reactivación de usuarios

- Estado: PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-10-24
- Decisión: la desactivación (`DELETE /users/{id}`) es lógica: `estado = false`, `fecha_salida = ahora` y cierre de sesiones (spec 8.6 y 10.13.6). Un administrador reactiva con `PATCH /users/{id}` `{"estado": true}`, que deja `fecha_salida = null` y conserva `fecha_ingreso`.
- Impacto: `UsersService`.

## DEC-B23 — Operaciones de cuenta fuera de la fase 2

- Estado: PROPUESTA (pendiente de aprobación del equipo)
- Fecha: 2026-10-24
- Contexto: no están en el contrato 11.3.
- Propuesta para una fase futura: cambiar la contraseña estando autenticado, cambiar el correo (vive en `Credenciales`) y desbloquear cuentas `bloqueadas` (ligado a DEC-B06).
- Impacto: ninguno en la fase 2.

## DEC-B24 — `crear_superadmin.php` reutiliza `UserAccountService`

- Estado: PROPUESTA (aprobada por backend)
- Fecha: 2026-10-24
- Decisión: el script usa el mismo servicio que `POST /auth/register` para crear el par `Credenciales`/`Usuarios`, de modo que la forma de los documentos esté en un solo lugar. Su comportamiento no cambia.
- Impacto: `scripts/crear_superadmin.php`, `UserAccountService`.

## DEC-B25 — Unicidad del correo al registrar

- Estado: PROPUESTA (aprobada por backend)
- Fecha: 2026-10-24
- Contexto: el índice único de `Credenciales.correo` (spec 8.4) aún no está creado en la base real `siiis` (pendiente de la fase 1).
- Decisión: `UserAccountService` comprueba primero si el correo existe (`409 EMAIL_TAKEN`) y, además, traduce el error de clave duplicada del índice (código 11000) al mismo `409`, para cubrir dos altas simultáneas.
- Riesgo: hasta que se ejecute `scripts/crear_indices.php` en `siiis`, dos altas simultáneas con el mismo correo podrían duplicarse.
- Impacto: `UserAccountService`, `CuentasRepository`.

## DEC-B26 — Contrato de `GET /users` y `GET /users/{id}`

- Estado: PROPUESTA (pendiente de aprobación del equipo)
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

- Estado: PROPUESTA (pendiente de aprobación del equipo)
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

- Estado: PROPUESTA (pendiente de aprobación del equipo)
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

- Estado: PROPUESTA (pendiente de aprobación del equipo)
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
