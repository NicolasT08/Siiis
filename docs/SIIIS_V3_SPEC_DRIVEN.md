# SIIIS V3 — Especificación maestra para desarrollo Spec-Driven

> **Propósito de este archivo:** servir como fuente de verdad operativa para humanos y agentes de IA que desarrollen, revisen o desplieguen la Versión 3 del sitio web del Semillero de Investigación SIIIS de la UPTC.
>
> **Modo de trabajo:** Spec-Driven Development. Ningún agente debe implementar una funcionalidad relevante únicamente por inferencia del nombre de un módulo. Debe contrastar primero esta especificación, registrar las decisiones que falten y mantener trazabilidad entre requisitos, código, pruebas y despliegue.
>
> **Revisión 23/09/2026 — backend migrado a PHP.** El hosting (Hostinger plan básico) sólo ejecuta PHP, por lo que **únicamente el backend** cambia de Python/FastAPI a PHP y pasa de Render a Hostinger. **El frontend (React + TypeScript) y la base de datos (MongoDB Atlas + Cloudinary, 8 colecciones oficiales) no cambian.** Ver sección 0.5.

---

## 0. Reglas de uso para el agente

Estas reglas son **normativas** para cualquier agente que trabaje sobre el repositorio.

### 0.1 Fuente de verdad y prioridad

Usar esta prioridad cuando existan contradicciones:

1. Decisiones explícitamente aprobadas por el equipo/cliente después de la fecha de los documentos base.
2. Este archivo, en sus secciones marcadas como `DECISION APROBADA`.
3. Charter del proyecto SIIIS V3.
4. Arquitectura de base de datos aprobada.
5. Requerimientos del software del cliente.
6. Decisiones técnicas provisionales del MD de backend.
7. Inferencias del agente.

**Nunca presentar una inferencia como una decisión aprobada.**

### 0.2 Regla de no invención

Cuando un requerimiento, campo, regla de negocio, proveedor o comportamiento no esté definido:

- No inventarlo silenciosamente.
- Marcarlo como `DECISION_REQUIRED` o `TODO_SPEC`.
- Separar el trabajo bloqueado del trabajo independiente.
- Continuar con infraestructura, pruebas o refactors que no dependan de la decisión.
- Cuando una decisión sea necesaria para avanzar, registrar exactamente qué falta decidir y qué componentes afecta.

### 0.3 Contrato de implementación

Cada cambio relevante debe incluir, como mínimo:

- requisito o caso de uso que satisface;
- componentes afectados;
- contrato API/datos afectado, cuando aplique;
- pruebas asociadas;
- manejo de errores;
- consideraciones de seguridad;
- impacto de despliegue/configuración;
- criterio de aceptación verificable.

### 0.4 Definition of Done

Una funcionalidad no se considera terminada sólo porque “funciona en local”. Debe cumplir:

- implementación integrada;
- pruebas relevantes pasando;
- validación de errores y permisos;
- documentación actualizada si cambia contrato, configuración o arquitectura;
- lint/formato sin errores;
- sin secretos en el repositorio;
- integración con las capas implicadas validada;
- criterios de aceptación cumplidos;
- sin errores críticos conocidos.

### 0.5 Alcance de la migración a PHP

| Capa | Antes | Ahora | ¿Cambia? |
|---|---|---|---|
| Frontend | React + TypeScript, build estático en Hostinger | Igual | **No** (sólo apunta a la nueva URL base de la API) |
| Base de datos | MongoDB Atlas (8 colecciones, SIIIS-BD-001) | Igual | **No** |
| Multimedia | Cloudinary | Igual | **No** |
| Backend | Python + FastAPI en Render | **PHP 8.2+ en Hostinger** (mismo dominio, bajo `/api/v1`) | **Sí** |
| Correo | Gmail (yagmail) | Gmail vía SMTP con PHPMailer | Sólo la librería |
| Google OAuth | `google-auth` (Python) | Verificación del `id_token` con JWKS de Google en PHP | Sólo la librería |

El contrato API (sección 11), los requisitos, el modelo de datos y las reglas de negocio se mantienen; sólo cambia la implementación del servidor.

### 0.6 Restricciones del backend en hosting compartido PHP

- **Sin procesos persistentes:** cada petición ejecuta PHP de cero y termina. Sin workers, colas ni WebSockets.
- **Sin estado en memoria entre peticiones:** cachés en MongoDB o en archivos de un directorio privado.
- **Tiempo de ejecución limitado** (`max_execution_time`); tareas periódicas vía **cron jobs** de hPanel.
- **Límites de subida** (`upload_max_filesize`, `post_max_size`): los límites de la aplicación deben ser ≤ los de PHP.
- **Extensiones disponibles sólo las que ofrece hPanel.** La extensión `mongodb` se activa en *hPanel → Avanzado → Configuración PHP → Extensiones*; su versión la fija Hostinger (reportada 1.18.x en 2025), por lo que la librería `mongodb/mongodb` debe fijarse a una versión compatible (ver 16).
- **Enrutamiento con `.htaccess`** (LiteSpeed/Apache).

---

# 1. Identidad del proyecto

**Proyecto:** Desarrollo e Implementación de la Versión 3 del Sitio Web del Semillero de Investigación SIIIS.

**Organización:** Semillero de Investigación SIIIS — Ingeniería de Sistemas y Computación, UPTC.

**Responsable/Líder:** Andryw Yesid Barrera Camargo.

**Patrocinador/Cliente:** Semillero de Investigación SIIIS — UPTC.

**Versión del Charter de referencia:** 1.3 (31/08/2026).

**Naturaleza:** proyecto académico de 16 semanas, ejecutado por un equipo de 4 integrantes con dedicación parcial.

---

# 2. Objetivo del producto

## 2.1 Objetivo general

Construir desde cero una plataforma web propia (V3) que reemplace la versión actual del sitio del SIIIS, mejore la visualización de proyectos, artículos y actividades y facilite la participación de la comunidad académica.

## 2.2 Objetivos específicos

- Diseñar una interfaz moderna, responsiva y alineada con la identidad del semillero.
- Implementar módulos funcionales de blog/artículos, proyectos, comentarios/reseñas, contacto e información institucional.
- Incorporar inscripción al semillero mediante formulario y recepción de documentos.
- Optimizar la base de datos.
- Incorporar una animación de carga con identidad visual del semillero.
- Mejorar la interfaz administrativa.
- Garantizar calidad del código y contenidos antes de cada entrega.

---

# 3. Alcance funcional

La plataforma debe contemplar, como mínimo, las siguientes áreas:

1. **Área institucional**
   - información del semillero;
   - misión/información institucional disponible;
   - integrantes;
   - contacto;
   - enlaces a redes sociales cuando correspondan.

2. **Publicación de contenido**
   - proyectos de investigación;
   - artículos/publicaciones;
   - noticias;
   - potencialmente eventos y otros contenidos académicos definidos por el equipo.

3. **Interacción**
   - reseñas/comentarios de usuarios;
   - consulta de publicaciones y proyectos.

4. **Autenticación y cuentas**
   - registro;
   - inicio de sesión local;
   - login con Google;
   - recuperación de contraseña;
   - sesión máxima de 8 horas;
   - perfiles y foto de perfil;
   - autorización por roles.

5. **Inscripción al semillero**
   - pantalla dedicada de inscripción;
   - formulario de inscripción;
   - recepción de documentos requeridos;
   - gestión de solicitudes.

6. **Administración**
   - administración de usuarios;
   - gestión del contenido publicado según permisos;
   - operación sensible de desactivación/eliminación de integrantes por deserción;
   - interfaz administrativa mejorada.

7. **Multimedia**
   - imágenes para portada/slider;
   - fotografías de perfil;
   - videos relacionados con el semillero;
   - otros recursos multimedia definidos posteriormente.

## 3.1 Mapa de páginas confirmado (frontend)

Reportado por el equipo. Es la estructura de navegación confirmada, no necesariamente definitiva en detalle (por ejemplo, el diseño exacto de la pantalla de carga sigue sin definir):

| Página | Backend relacionado |
|---|---|
| Inicio | `HomeController.php` (slider, videos) |
| Acerca de | Sin backend definido aún — `DECISION_REQUIRED`: ¿contenido estático en frontend o administrable vía API, como `home_content`? |
| Usuarios / miembros del semillero | `UsersController.php` (listado de integrantes) |
| Artículos | `DocumentosController.php` (`categoria = articulo`) |
| Proyectos | `DocumentosController.php` (`categoria = proyecto`) |
| Contacto | `HomeController.php` (envío de correo) |
| Inicio de sesión + paneles de administrador | `AuthController.php` + endpoints administrativos de `UsersController.php`/contenido |
| Inscripción (botón "inscribirse"/"optar por hacerlo") | `InscriptionsController.php`. El procedimiento interno de la inscripción aún no está definido; solo está confirmado que el módulo existirá. |

Pantalla de carga: confirmada como parte del alcance (RF-14), con diseño distinto al asumido originalmente. El equipo la reporta como una funcionalidad principalmente de frontend, sin impacto directo en el backend más allá de lo ya cubierto en RF-14.

---

# 4. Requisitos funcionales

| ID | Requisito | Criterio técnico de cumplimiento |
|---|---|---|
| RF-01 | Registro de nuevos usuarios mediante roles | Registro + validación de rol + reglas de autorización. Roles reportados por el equipo: Superadministrador, Administrador, Activo, Egresado (ver sección 8.2 — pendiente de confirmar límite exacto de permisos y su relación con "docente"/"estudiante líder" de los requerimientos originales del cliente). Las cantidades exactas de determinados roles deben confirmarse si son una regla de negocio. |
| RF-02 | Validación de datos en formularios | Validación de esquema y validación de negocio antes de persistir. |
| RF-03 | Contacto funcional mediante correos preescritos | Endpoint de contacto + servicio centralizado de correo. |
| RF-04 | Recuperación de contraseña por correo | Token de recuperación de un solo uso, expiración y envío por correo. |
| RF-05 | Perfil con publicaciones propias, artículos de interés y previsualización de documentos | API de perfil + relaciones con publicaciones/documentos + metadatos de archivo. El mecanismo exacto de previsualización aún no está definido. |
| RF-06 | Subir/cambiar foto de perfil | Carga a almacenamiento externo + persistencia del recurso + reemplazo controlado. |
| RF-07 | Slider de imágenes del semillero | Recurso administrable/consultable para imágenes de portada. |
| RF-08 | Integrantes pueden visualizar información pública y proyectos | Listado de integrantes + consulta de proyectos por estado/perfil. |
| RF-09 | Administradores pueden eliminar/desactivar usuarios por deserción | Operación protegida por rol; estrategia de borrado físico vs. lógico pendiente de definición. |
| RF-10 | Cualquier usuario puede escribir reseñas | Crear y consultar reseñas con validación y controles antiabuso. |
| RF-11 | Página principal con videos | Recurso para videos y referencias. Definir si son enlaces externos o archivos gestionados. |
| RF-12 | Inscripción con documentos requeridos | Formulario + validación de archivos + almacenamiento externo + metadatos. |
| RF-13 | Pantalla dedicada de inscripción | Endpoints específicos para crear/consultar/gestionar solicitudes. |
| RF-14 | Animación de carga con logo SIIIS | Principalmente frontend; no convertir en lógica de negocio innecesaria de backend. |
| RF-15 | Rediseño/optimización de administración | API con operaciones administrativas protegidas por autorización. |
| RF-16 | Funcionamiento correcto en dispositivos móviles | Principalmente frontend; APIs deben mantener respuestas ligeras y consistentes. |
| RF-17 | Inicio de sesión con Google | Backend recibe el `id_token` emitido por el SDK de Google en el frontend, lo verifica contra las llaves públicas de Google (JWKS, con `firebase/php-jwt`), vincula por correo con una cuenta local existente o crea una cuenta nueva, y emite el mismo JWT propio de 8h usado en el login local. No estaba en los requerimientos formales del cliente; es un requisito confirmado por el equipo/profesor fuera de esa fuente. |

---

# 5. Requisitos no funcionales

| ID | Requisito | Regla de implementación |
|---|---|---|
| RNF-01 | Frontend con React + TypeScript | Toda UI principal debe desarrollarse con React y TypeScript. |
| RNF-02 | BD estructurada o no estructurada; recomendación MongoDB/Firebase | Decisión técnica vigente: MongoDB. |
| RNF-03 | BD optimizada y preparada para crecer | Índices, consultas eficientes, paginación y estructura consistente. |
| RNF-04 | Arquitectura híbrida | MongoDB para datos/texto/metadatos; Cloudinary para imágenes y multimedia. |
| RNF-05 | Sesión de usuario de 8 horas | JWT con expiración máxima de 8 horas y validación en solicitudes protegidas. |
| RNF-06 | Sistema liviano | Límites de archivos, paginación, respuestas acotadas, evitar cargas innecesarias y optimizar multimedia. |

---

# 6. Arquitectura de solución

## 6.1 Vista lógica

```text
┌──────────────────────────────────────────┐
│              CLIENTE WEB                 │
│           React + TypeScript              │
│  UI pública + autenticación + admin      │
└────────────────────┬─────────────────────┘
                     │ HTTPS / JSON
                     ▼
┌──────────────────────────────────────────┐
│              BACKEND / API               │
│     PHP 8.2+ (Slim 4) en Hostinger       │
│                                          │
│ Auth │ Users │ Content │ Reviews         │
│ Inscripciones │ Home │ Contacto         │
│ Seguridad │ Validación │ Integraciones  │
└───────────────┬──────────────┬───────────┘
                │              │
       ┌────────▼───────┐   ┌──▼──────────────┐
       │    MongoDB     │   │    Cloudinary    │
       │ datos/texto/   │   │ imágenes/videos/ │
       │ metadatos      │   │ multimedia       │
       └────────────────┘   └─────────────────┘
                │
                ├──────────────► Gmail
                │
                └──────────────► Google OAuth
```

## 6.2 Principios

- Separar frontend, backend y persistencia.
- No almacenar binarios multimedia innecesarios dentro de MongoDB.
- Mantener en MongoDB referencias y metadatos de recursos externos.
- Centralizar autenticación y autorización.
- Centralizar integraciones externas.
- Validar entradas en los bordes de la aplicación.
- Mantener contratos API explícitos.
- Evitar acoplamiento entre módulos.
- Diseñar pensando en crecimiento sin sobredimensionar la solución.

---

# 7. Stack tecnológico

## 7.1 Frontend — decisión vigente

- React
- TypeScript

### Responsabilidades

- interfaz pública;
- navegación;
- formularios;
- experiencia responsiva;
- estados de carga;
- animación de carga con identidad SIIIS;
- interfaz de administración;
- consumo de API;
- manejo de sesión y autorización desde cliente.

> La librería concreta de UI, router, manejo de estado, testing de frontend, etc. **no está definida en las fuentes base**. El agente debe evitar introducirlas como “oficiales” sin una decisión del equipo.

## 7.2 Backend — baseline técnico actual (PHP)

| Necesidad | Antes (Python) | Ahora (PHP) | Estado |
|---|---|---|---|
| Lenguaje / runtime | Python + Uvicorn | PHP 8.2+ (seleccionable en hPanel), ejecutado por LiteSpeed | Aprobado (restricción del hosting) |
| Framework / enrutamiento | FastAPI | **Slim 4** + `slim/psr7` | `PROPUESTA` (DECISION-012). Alternativa: PHP sin framework. Se descarta Laravel por peso. |
| Dependencias | pip / `requirements.txt` | Composer / `composer.json` + `composer.lock` | Aprobado |
| Validación de esquemas | Pydantic | Validadores propios por recurso (o `respect/validation`) | `PROPUESTA` |
| Driver MongoDB | PyMongo | Extensión PHP `mongodb` + librería `mongodb/mongodb` | Aprobado |
| JWT | `python-jose` | `firebase/php-jwt` | `PROPUESTA` |
| Hash de contraseñas | bcrypt vía `passlib` | `password_hash()` / `password_verify()` con `PASSWORD_BCRYPT` (nativo, mismo formato bcrypt) | Aprobado |
| Cargas multipart | `python-multipart` | `$_FILES` / PSR-7 `UploadedFile` | Aprobado |
| MIME real | `python-magic` | extensión `fileinfo` (`finfo`) | Aprobado |
| Rate limiting | `slowapi` | Middleware propio con contadores en archivos de `storage/ratelimit/` (no agrega colecciones al modelo oficial; usar una colección técnica en Atlas requeriría aprobación del equipo de BD) | `PROPUESTA` |
| Configuración | `pydantic-settings` + `python-dotenv` | `vlucas/phpdotenv` | `PROPUESTA` |
| Google OAuth | `google-auth` | `firebase/php-jwt` + JWKS de Google cacheadas en archivo | `PROPUESTA` |
| Cloudinary | `cloudinary` | `cloudinary/cloudinary_php` | Aprobado |
| Correo | `yagmail` | `phpmailer/phpmailer` (SMTP Gmail) | Aprobado |

## 7.3 Datos y almacenamiento

### MongoDB

Responsable de:

- usuarios;
- información documental y textual;
- publicaciones/proyectos/noticias;
- reseñas;
- inscripciones y sus metadatos;
- metadatos de archivos;
- referencias a recursos externos.

### Cloudinary

Responsable de:

- imágenes;
- fotos de perfil;
- recursos multimedia;
- potencialmente videos cuando así se decida.

MongoDB debe guardar los identificadores/URLs/metadatos necesarios para localizar esos recursos, no duplicar innecesariamente el binario.

## 7.4 Servicios externos

- **Gmail:** contacto, recuperación de contraseña e inscripciones.
- **Google Cloud Console / Google OAuth:** login con Google.
- **Git/GitHub:** control de versiones y colaboración.
- **Hostinger (plan básico, confirmado):** aloja el build estático del frontend (React) **y el backend PHP**. No soporta Python.
- **MongoDB Atlas:** base de datos en la nube (sin cambios); el backend PHP se conecta a ella de forma remota.
- ~~Render~~ — **eliminado**: ya no se necesita un proveedor aparte para el backend.

> **Actualización (23/09/2026):** como el plan básico de Hostinger sólo ejecuta PHP, el backend se reescribe en PHP y se aloja en el mismo Hostinger. Esto elimina el cold start de 30–60 s de Render y la dependencia de un segundo proveedor. MongoDB Atlas y Cloudinary siguen siendo servicios externos, alcanzados sólo desde el backend.

---

# 8. Arquitectura de datos

## 8.1 Decisión final (ya no propuesta)

Con la entrega del documento oficial *"Arquitectura para la Base de Datos... SIIIS"* (v1.0, 13/09/2026, código SIIIS-BD-001), el modelo de datos queda **cerrado**. Este documento reemplaza cualquier versión anterior (incluida la separación `users/articles/projects/reviews` y el modelo simplificado `usuarios/documentos` de dos colecciones que se manejaban como alternativas). `DECISION-001` queda **resuelta**.

Arquitectura híbrida confirmada:

- **MongoDB:** 8 colecciones — `Credenciales`, `Usuarios`, `Documentos`, `Multimedia`, `Reseñas`, `Inscripciones`, `Sesiones`, `Mensajes_Contacto`.
- **Cloudinary:** todo archivo binario (imágenes, PDFs, videos). MongoDB nunca guarda el binario, solo `{url, public_id}`.

Principio de diseño central del documento oficial: **separar los datos de autenticación de los datos de perfil**. Por eso `Credenciales` y `Usuarios` son colecciones distintas, no una sola — esto es diferente de lo que este spec asumía antes (un solo campo `passwordHash` dentro de `users`).

## 8.2 Las 8 colecciones oficiales

### `Credenciales` (autenticación — nunca datos de perfil)

| Campo | Tipo | Uso |
|---|---|---|
| `_id` | ObjectId | Identificador |
| `correo` | String | Único, usado para login |
| `password` | String | Hash bcrypt |
| `usuario_id` | ObjectId (ref Usuarios) | Relación 1 a 1 con el perfil |
| `estado_cuenta` | String | activa / bloqueada / pendiente_verificacion |
| `token_recuperacion`, `token_expiracion` | String, Date | Flujo de recuperación de contraseña (RF-04) |
| `intentos_fallidos` | Number | Soporte para bloqueo por fuerza bruta |
| `fecha_creacion`, `ultimo_acceso` | Date | Auditoría básica |

> `DECISION_REQUIRED` (nueva, no cubierta por el documento oficial): los campos para login con Google (`proveedor`, `google_id`) deben añadirse aquí, no en `Usuarios`, para mantener el principio de separación auth/perfil del documento oficial. Ver sección 12.4.

### `Usuarios` (perfil del integrante)

| Campo | Tipo | Uso |
|---|---|---|
| `_id` | ObjectId | Identificador del perfil |
| `credencial_id` | ObjectId (ref Credenciales) | Relación 1 a 1 |
| `nombre`, `apellido` | String | — |
| `rol` | String | `superadministrador`, `administrador`, `docente_director`, `estudiante_lider`, `estudiante` |
| `programa_academico`, `semestre` | String, Number | — |
| `foto_perfil` | Object `{url, public_id}` | RF-06 |
| `biografia`, `telefono` | String | Opcionales |
| `estado` | Boolean | Activo/inactivo (deserción, RF-08) — **no confundir con el rol** |
| `fecha_ingreso`, `fecha_salida` | Date | — |

> **Roles: resuelto.** Son 5, no 4 como se había reportado informalmente antes: `superadministrador`, `administrador`, `docente_director`, `estudiante_lider`, `estudiante`. El "Activo/Egresado" que se había reportado corresponde en realidad al campo `estado` (booleano de membresía), no a un rol.
>
> `DECISION_REQUIRED` (persiste, más acotada): RF-01 dice "únicamente 2 administradores: el docente y el estudiante líder de proyectos". Con 5 roles ya nombrados, falta confirmar el límite de permisos exacto entre `superadministrador` y `administrador`, y si `docente_director`/`estudiante_lider` son los únicos que pueden ocupar esos 2 cupos de admin, o si son roles aparte con sus propios permisos.

### `Documentos` (proyectos, artículos y noticias — colección unificada)

| Campo | Tipo | Uso |
|---|---|---|
| `_id` | ObjectId | Identificador |
| `categoria` | String | `proyecto`, `articulo`, `noticia` |
| `titulo`, `descripcion`, `contenido` | String | `contenido` es texto enriquecido, para el editor/previsualización de RF-05 |
| `autor_id` | ObjectId (ref Usuarios) | — |
| `integrantes` | Array\<ObjectId\> (ref Usuarios) | Relación N–M con Usuarios |
| `archivos_adjuntos` | Array `{url, public_id, nombre_archivo}` | Cloudinary |
| `estado` | String | `ejecucion`, `finalizado`, `en_revision`, `publicado` |
| `visible` | Boolean | Visibilidad pública |
| `fecha_creacion`, `fecha_actualizacion` | Date | — |

**Resuelto:** es una única colección con `categoria`, no colecciones separadas `projects`/`articles`/`reviews`. El controlador correspondiente en el backend es `DocumentosController.php`, no un controlador por categoría (ver sección 9).

### `Multimedia` (slider, videos y galería del sitio — distinta de `archivos_adjuntos`)

| Campo | Tipo | Uso |
|---|---|---|
| `_id` | ObjectId | Identificador |
| `tipo` | String | `imagen`, `video` |
| `url`, `public_id` | String | Cloudinary |
| `seccion` | String | `slider_principal`, `video_presentacion`, `galeria` |
| `descripcion` | String | Texto alternativo (accesibilidad) |
| `orden` | Number | Orden de aparición |
| `fecha_subida` | Date | — |

Resuelve RF-07 y RF-10. **Reemplaza** la colección `home_content` que este spec proponía antes como hipótesis — el nombre oficial es `Multimedia`, y los videos institucionales también se alojan en Cloudinary (no enlaces externos, como quedaba abierto antes).

### `Reseñas`

| Campo | Tipo | Uso |
|---|---|---|
| `_id` | ObjectId | Identificador |
| `usuario_id` | ObjectId (ref Usuarios), nullable | Permite reseñas de visitantes no autenticados |
| `nombre_visible`, `comentario` | String | — |
| `calificacion` | Number | 1–5, opcional |
| `estado` | String | `visible` / `oculta` (moderación) |
| `fecha` | Date | — |

### `Inscripciones`

| Campo | Tipo | Uso |
|---|---|---|
| `_id` | ObjectId | Identificador |
| `nombre_completo`, `correo`, `telefono` | String | Datos del aspirante |
| `programa_academico`, `semestre` | String, Number | — |
| `carta_motivacion` | String | — |
| `documentos_adjuntos` | Array `{url, public_id, nombre_archivo}` | Cloudinary |
| `estado_solicitud` | String | `pendiente`, `aceptada`, `rechazada` |
| `fecha_solicitud` | Date | — |

No se relaciona con `Usuarios` hasta ser aceptada; en ese momento un administrador crea el par `Credenciales`/`Usuarios` correspondiente (flujo manual, no automático).

### `Sesiones` (control de sesión de 8 horas — cambia el modelo de auth)

| Campo | Tipo | Uso |
|---|---|---|
| `_id` | ObjectId | Identificador |
| `usuario_id` | ObjectId (ref Usuarios) | — |
| `token` | String | Token de sesión emitido al iniciar sesión |
| `fecha_inicio`, `fecha_expiracion` | Date | `fecha_expiracion` = `fecha_inicio` + 8h |
| `ip_acceso` | String | Auditoría básica |
| `activa` | Boolean | Permite invalidar una sesión antes de que expire el JWT |

> **Cambio arquitectónico importante:** el diseño original de este spec asumía JWT puramente *stateless* (sin registro en base de datos). El documento oficial introduce `Sesiones` como registro server-side, lo que permite invalidar sesiones explícitamente (logout real, no solo "esperar a que expire el token") y auditar accesos por IP. Ver sección 12.1, actualizada.

### `Mensajes_Contacto`

| Campo | Tipo | Uso |
|---|---|---|
| `_id` | ObjectId | Identificador |
| `nombre`, `correo`, `asunto`, `mensaje` | String | — |
| `estado` | String | `leido` / `no_leido` |
| `fecha` | Date | — |

Resuelve RF-03: además de enviar el correo vía Gmail, queda trazabilidad en esta colección.

## 8.3 Relaciones entre colecciones

- `Credenciales` 1—1 `Usuarios` (vía `usuario_id` / `credencial_id`).
- `Usuarios` 1—N `Documentos` (vía `autor_id`).
- `Documentos` N—M `Usuarios` (vía el arreglo `integrantes`).
- `Usuarios` 1—N `Reseñas` (opcional, `usuario_id` puede ser nulo).
- `Usuarios` 1—N `Sesiones`.
- `Inscripciones` es independiente hasta su aceptación manual.
- `Documentos` y `Multimedia` comparten el mismo patrón `{url, public_id}` hacia Cloudinary.

## 8.4 Índices recomendados (oficiales)

- `Credenciales.correo` → único.
- `Usuarios.credencial_id` → único.
- `Documentos.categoria` + `Documentos.estado` → compuesto, para listados públicos filtrados.
- `Inscripciones.estado_solicitud` → simple, para el panel de administración.
- `Sesiones.token` y `Sesiones.fecha_expiracion` → para validar/expirar sesiones; candidato a índice TTL de MongoDB (expiración automática de documentos vencidos).

## 8.5 Reglas de persistencia

- Usar IDs consistentes y serialización segura.
- No confiar en el frontend para reglas de negocio.
- Validar referencias antes de persistir relaciones.
- Aplicar paginación a listados potencialmente grandes.
- Mantener consultas acotadas; no devolver documentos completos cuando el cliente necesita sólo resúmenes.
- Registrar metadatos de archivos externos de forma consistente (`{url, public_id}`).

## 8.6 Decisiones pendientes de datos (reducidas — la mayoría ya se resolvió)

`DECISION_REQUIRED` restantes:

1. Límite de permisos exacto entre `superadministrador` y `administrador`, y su relación con "el docente y el estudiante líder de proyectos" de RF-01.
2. Ubicación de los campos de Google OAuth (`proveedor`, `google_id`) dentro de `Credenciales` — propuesta razonable, no bloqueante, pero no está en el documento oficial y debe confirmarse antes de escribir el modelo.
3. Regla de eliminación de `Usuarios`: el documento oficial usa `estado: Boolean` (borrado lógico), lo que resuelve la duda anterior a favor de **borrado lógico**, no físico. Se marca aquí como resuelta, no como pendiente — queda documentado por completeness.

---

# 9. Estructura del repositorio

La siguiente estructura es el baseline del backend actual y puede evolucionar cuando el modelo sea confirmado.

```text
siiis-v3/
├── frontend/
│   ├── src/
│   ├── public/
│   ├── tests/
│   ├── package.json
│   └── README.md
│
├── backend/                       # PHP
│   ├── public/
│   │   ├── index.php              # front controller (se despliega en public_html/api/)
│   │   └── .htaccess
│   ├── src/
│   │   ├── Config/
│   │   │   ├── Settings.php       # equivalente a config.py
│   │   │   └── Routes.php         # registro de rutas /api/v1
│   │   ├── Database/
│   │   │   └── Mongo.php          # equivalente a database.py (cliente único a Atlas)
│   │   ├── Security/              # equivalente a security.py
│   │   │   ├── PasswordHasher.php
│   │   │   ├── JwtService.php
│   │   │   ├── GoogleTokenVerifier.php
│   │   │   └── RateLimiter.php
│   │   ├── Middleware/
│   │   │   ├── AuthMiddleware.php
│   │   │   ├── RoleMiddleware.php
│   │   │   └── ErrorHandler.php
│   │   ├── Services/              # equivalente a external.py
│   │   │   ├── CloudinaryService.php
│   │   │   └── MailService.php
│   │   ├── Support/               # equivalente a utils.py
│   │   │   ├── FileValidator.php
│   │   │   ├── TokenGenerator.php
│   │   │   └── Serializer.php     # ObjectId/UTCDateTime → JSON
│   │   ├── Validation/            # equivalente a models.py (esquemas de entrada)
│   │   ├── Repositories/          # consultas a cada colección de MongoDB
│   │   └── Controllers/
│   │       ├── HealthController.php
│   │       ├── AuthController.php
│   │       ├── UsersController.php
│   │       ├── DocumentosController.php
│   │       ├── ReviewsController.php
│   │       ├── InscriptionsController.php
│   │       └── HomeController.php
│   ├── cron/
│   │   └── limpiar_ratelimit.php
│   ├── scripts/
│   │   └── crear_indices.php
│   ├── storage/                   # cache (JWKS), ratelimit, logs — no versionado
│   ├── tests/
│   │   ├── Unit/
│   │   │   ├── SecurityTest.php
│   │   │   ├── SupportTest.php
│   │   │   └── FileValidatorTest.php
│   │   └── Integration/
│   │       └── AuthTest.php
│   ├── .env.example
│   ├── composer.json
│   ├── composer.lock
│   ├── phpunit.xml
│   ├── phpstan.neon
│   ├── .php-cs-fixer.php
│   └── README.md
│
├── docs/
│   ├── architecture.md
│   ├── api.md
│   ├── database.md
│   ├── decisions.md
│   └── deployment.md
│
├── .gitignore
└── README.md
```

> La estructura de frontend detallada todavía no está definida por las fuentes. El árbol anterior para `frontend/` y `docs/` es una **propuesta de organización**, no una decisión histórica del proyecto.

---

# 10. Módulos y responsabilidades

## 10.1 `public/index.php` (antes `main.py`)

Debe encargarse de:

- cargar `vendor/autoload.php` y la configuración;
- crear la aplicación Slim (`setBasePath('/api')`);
- registrar middleware (JSON, errores, CORS si aplica) y rutas;
- manejar errores globales;
- exponer `GET /api/v1/health`;
- evitar lógica de negocio dentro del entrypoint.

## 10.2 `Config/Settings.php` (antes `config.py`)

Centralizar configuración y variables de entorno (`vlucas/phpdotenv`). Nunca hardcodear secretos.

## 10.3 `Database/Mongo.php` (antes `database.py`)

- crear una única instancia de `MongoDB\Client` por petición contra la URI de Atlas;
- exponer la base y las colecciones oficiales (sección 8.2);
- configurar `typeMap` para devolver arrays PHP;
- no contener reglas de negocio.

> Como PHP no mantiene conexiones entre peticiones, cada petición abre su conexión a Atlas. Mantener consultas acotadas y evitar múltiples clientes por petición.

## 10.4 `Security/*` (antes `security.py`)

Debe encapsular:

- `PasswordHasher::hash($password)` / `verify($password, $hash)` (bcrypt nativo);
- generación de JWT (`firebase/php-jwt`, HS256);
- decodificación/validación de JWT;
- expiración a 8 horas;
- extracción segura de identidad/rol desde el token;
- registro y validación de la sesión en la colección `Sesiones`;
- verificación del `id_token` de Google (`GoogleTokenVerifier`);
- rate limiting (`RateLimiter`).

## 10.5 `Support/*` (antes `utils.py`)

- validación de MIME real (`finfo`);
- validación de tamaño;
- generación de tokens de recuperación (`random_bytes`);
- serialización de `ObjectId` y `UTCDateTime` a JSON;
- validaciones reutilizables.

## 10.6 `Services/*` (antes `external.py`)

Centralizar integraciones:

- `CloudinaryService::upload($file, $folder)`;
- `CloudinaryService::delete($publicId)`;
- `MailService::send($to, $subject, $html)`;
- integración con Google cuando corresponda.

## 10.7 `AuthController` (antes `auth.py`)

Registro; login local; login Google; recuperación de contraseña; sesiones/JWT; validación de credenciales.

## 10.8 `UsersController` (antes `users.py`)

Perfil; integrantes; foto de perfil; consulta pública de integrantes; operaciones administrativas de usuarios.

## 10.9 `DocumentosController` (contenido)

Sobre la colección unificada `Documentos` (sección 8.2). Debe cubrir: publicación; edición; consulta; estados; integrantes; documentos relacionados; filtros y paginación.

## 10.10 `ReviewsController` (antes `reviews.py`)

Crear reseña; listar reseñas; validaciones; rate limiting.

## 10.11 `InscriptionsController` (antes `inscriptions.py`)

Creación de solicitudes; recepción y validación de documentos; almacenamiento externo; metadatos; estado de la solicitud; gestión administrativa según reglas aprobadas.

## 10.12 `HomeController` (antes `home.py`)

Contacto; slider/portada; videos; otros recursos públicos de home.

---

## 10.13 Guía de implementación PHP (backend)

Patrones obligatorios para programar el backend en PHP contra MongoDB Atlas, Cloudinary y el frontend React. No cambian el modelo de datos (sección 8) ni el contrato API (sección 11).

### 10.13.1 Convenciones de código

- `declare(strict_types=1);` en todos los archivos.
- PSR-4 (namespace `Siiis\`), PSR-12 (formato), PSR-7/PSR-15 (peticiones y middleware de Slim).
- Tipos en parámetros y retornos; `readonly` para objetos de configuración.
- Controladores delgados: validan entrada → llaman a un servicio/repositorio → devuelven JSON. Las consultas a MongoDB viven en repositorios (`src/Repositories/`), no en controladores.
- Respuesta JSON centralizada en un helper (`JsonResponse::ok($data, 200)`, `JsonResponse::error($code, $msg, 400, $fields)`), siempre con `Content-Type: application/json; charset=utf-8`.

### 10.13.2 Conexión a MongoDB Atlas

```php
$client = new MongoDB\Client($_ENV['MONGO_URI'], [], [
    'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
]);
$db = $client->selectDatabase($_ENV['MONGO_DB_NAME']);
```

- Un solo `Client` por petición (inyectado por el contenedor/Settings).
- La URI `mongodb+srv://` de Atlas incluye TLS; no desactivar la verificación del certificado.
- Usuario de Atlas con permisos mínimos sobre la base `siiis` (sin `atlasAdmin`).
- Nombres de colección exactamente los oficiales: `Credenciales`, `Usuarios`, `Documentos`, `Multimedia`, `Reseñas`, `Inscripciones`, `Sesiones`, `Mensajes_Contacto`. Definirlos como constantes en una sola clase (`Collections::RESENAS = 'Reseñas'`) para evitar errores de tildes/ñ.

### 10.13.3 Tipos BSON en PHP

| Dato | En MongoDB | En PHP | Hacia el frontend (JSON) |
|---|---|---|---|
| Identificadores | `ObjectId` | `MongoDB\BSON\ObjectId` | string hexadecimal de 24 caracteres (`id`, no `_id`) |
| Fechas | `Date` | `MongoDB\BSON\UTCDateTime` | ISO 8601 UTC (`2026-09-23T15:00:00Z`) |
| Booleanos / números | `Boolean` / `Number` | `bool` / `int` | igual |

- Todo `{id}` de la URL se valida antes de usarlo: si no es un ObjectId válido (`preg_match('/^[a-f0-9]{24}$/', $id)`) → `404` (o `400`), nunca una excepción sin capturar.
- `Serializer` convierte recursivamente `ObjectId` → string y `UTCDateTime` → ISO 8601, y elimina campos sensibles (`password`, `token_recuperacion`, `token`) antes de responder.

### 10.13.4 Consultas

- **Antiinyección NoSQL:** nunca pasar `$_GET`, `$_POST` o el cuerpo JSON directamente como filtro. Castear cada valor (`(string)`, `(int)`) y usar listas blancas para `categoria`, `estado`, `rol`, `seccion` y campos de ordenamiento.
- **Paginación:** `find($filtro, ['skip' => ($page-1)*$perPage, 'limit' => $perPage, 'sort' => [...], 'projection' => [...]])` + `countDocuments($filtro)`. Límite máximo de `per_page` configurable.
- **Proyecciones:** los listados devuelven sólo los campos del resumen (p. ej. `titulo`, `descripcion`, `categoria`, `estado`, `fecha_creacion`), nunca `contenido` completo.
- **Relaciones (evitar N+1):** para resolver `autor_id`/`integrantes` de una página de `Documentos`, juntar los IDs y hacer una sola consulta `Usuarios.find(['_id' => ['$in' => $ids]])`, o usar `aggregate` con `$lookup`.
- **Operaciones sobre dos colecciones** (crear `Credenciales` + `Usuarios`, aceptar una inscripción): usar transacción de Atlas (`$client->startSession()` + `withTransaction`) — Atlas lo soporta en replica sets, incluido el tier gratuito.

### 10.13.5 Índices

Los índices oficiales de la sección 8.4 se crean con un script idempotente del backend (`backend/scripts/crear_indices.php`), ejecutado una vez por SSH/cron o localmente contra Atlas:

```php
$db->Credenciales->createIndex(['correo' => 1], ['unique' => true]);
$db->Usuarios->createIndex(['credencial_id' => 1], ['unique' => true]);
$db->Documentos->createIndex(['categoria' => 1, 'estado' => 1]);
$db->Inscripciones->createIndex(['estado_solicitud' => 1]);
$db->Sesiones->createIndex(['token' => 1]);
$db->Sesiones->createIndex(['fecha_expiracion' => 1], ['expireAfterSeconds' => 0]); // TTL (candidato, ver 8.4)
```

El índice TTL de `Sesiones` sustituye la limpieza por cron: MongoDB Atlas elimina las sesiones vencidas por sí mismo.

### 10.13.6 Sesiones y JWT (flujo en PHP)

1. Login válido → generar JWT con `firebase/php-jwt` (HS256, claims `sub`, `rol`, `jti`, `iat`, `exp = iat + 28800`).
2. Insertar en `Sesiones`: `usuario_id`, `token` (se guarda el **hash SHA-256 del `jti`**, no el JWT completo — `PROPUESTA`, compatible con el campo `token` del modelo oficial), `fecha_inicio`, `fecha_expiracion`, `ip_acceso`, `activa: true`.
3. `AuthMiddleware` en cada petición protegida: leer `Authorization: Bearer`, `JWT::decode()` (valida firma y `exp`), buscar la sesión por hash de `jti` con `activa: true` y `fecha_expiracion > ahora`. Si falla → `401`.
4. Logout → `activa: false`. Desactivar un usuario (`Usuarios.estado = false`) → todas sus sesiones `activa: false`.
5. El rol se toma del token firmado/la BD, nunca del cuerpo de la petición.

> Esto requiere agregar `POST /api/v1/auth/logout` al contrato (sección 11.3) si el equipo lo aprueba.

### 10.13.7 Google OAuth en PHP

- Descargar `https://www.googleapis.com/oauth2/v3/certs`, guardar en `storage/cache/google_jwks.json` respetando `Cache-Control: max-age`.
- `JWT::decode($idToken, JWK::parseKeySet($jwks))` y comprobar: `aud === GOOGLE_CLIENT_ID`, `iss ∈ {accounts.google.com, https://accounts.google.com}`, `email_verified === true`.
- Luego seguir el flujo de 12.4.

### 10.13.8 Cargas de archivos

```php
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($uploaded->getStream()->getMetadata('uri'));
// validar $mime contra lista blanca y tamaño contra el límite configurado
$res = (new Cloudinary\Api\Upload\UploadApi())->upload($ruta, ['folder' => $carpeta, 'resource_type' => 'auto']);
// persistir ['url' => $res['secure_url'], 'public_id' => $res['public_id']]
```

- Nunca mover el archivo a `public_html`; se sube a Cloudinary desde el temporal de PHP.
- Si la escritura en MongoDB falla después de subir, llamar a `destroy($public_id)` (compensación).

### 10.13.9 Integración con React

- Mismo dominio en producción (`https://siiis.com.co/api/v1`) → sin CORS.
- En desarrollo, middleware CORS que sólo acepta `FRONTEND_ORIGIN` y responde a `OPTIONS` (preflight) con `204`.
- Las respuestas mantienen la forma que el frontend ya espera según el contrato de la sección 11; cualquier cambio de forma se coordina con Front-End antes de implementarse.

### 10.13.10 Errores y logs

- `ErrorHandler` de Slim: excepciones → JSON `{"error": {"code", "message"}}` con el status adecuado; en producción sin trazas.
- Fallos de Atlas (`MongoDB\Driver\Exception\ConnectionTimeoutException`) → `503` con código `DB_UNAVAILABLE`.
- Fallos de Cloudinary/SMTP/Google → `502` con código propio del servicio.
- Logs en `storage/logs/app-YYYY-MM-DD.log`, sin contraseñas, tokens ni contenido sensible.

---

# 11. Contrato API

## 11.1 Prefijo

Todas las rutas del backend deben usar:

```text
/api/v1
```

## 11.2 Convenciones

- JSON para operaciones de datos.
- `multipart/form-data` para cargas de archivos.
- HTTP status codes semánticos.
- Respuestas consistentes y serializables.
- Errores con estructura estable para facilitar consumo desde React.
- No exponer stack traces ni secretos.

## 11.3 Endpoints preliminares

> Son un mapa inicial. Los nombres definitivos deben alinearse con el modelo y contratos aprobados. **La migración a PHP no cambia este contrato:** el frontend consume las mismas rutas.
>
> **Nota Hostinger:** verificar en Fase 0 que `PATCH`/`DELETE` y la cabecera `Authorization` llegan a PHP. Si algún método es filtrado, usar `POST` con `X-HTTP-Method-Override` (`MethodOverrideMiddleware` de Slim).

```text
GET    /api/v1/health

POST   /api/v1/auth/register
POST   /api/v1/auth/login
POST   /api/v1/auth/google
POST   /api/v1/auth/forgot-password
POST   /api/v1/auth/reset-password
GET    /api/v1/auth/me

GET    /api/v1/users
GET    /api/v1/users/{id}
PATCH  /api/v1/users/{id}
POST   /api/v1/users/{id}/photo
DELETE /api/v1/users/{id}

GET    /api/v1/projects
GET    /api/v1/projects/{id}
POST   /api/v1/projects
PATCH  /api/v1/projects/{id}
DELETE /api/v1/projects/{id}

GET    /api/v1/articles
GET    /api/v1/articles/{id}
POST   /api/v1/articles
PATCH  /api/v1/articles/{id}
DELETE /api/v1/articles/{id}

GET    /api/v1/reviews
POST   /api/v1/reviews

POST   /api/v1/inscriptions
GET    /api/v1/inscriptions/{id}
GET    /api/v1/inscriptions
PATCH  /api/v1/inscriptions/{id}

GET    /api/v1/home/slider
GET    /api/v1/home/videos
POST   /api/v1/contact
```

### Reglas de autorización

- Endpoints públicos sólo exponen información que sea explícitamente pública.
- Endpoints autenticados requieren JWT válido.
- Operaciones administrativas requieren rol autorizado.
- Nunca confiar en un `role` enviado por el cliente como autoridad.
- Las reglas de roles deben vivir en backend además de reflejarse en frontend.

---

# 12. Autenticación y autorización

## 12.1 JWT

- Duración máxima de sesión: **8 horas**.
- Incluir identificador de usuario y rol/claims mínimos necesarios.
- Validar expiración (`exp`) en cada solicitud protegida.
- No guardar secretos en código fuente.
- Implementación PHP: `firebase/php-jwt` + registro en la colección `Sesiones` (ver 10.13.6).

## 12.2 Contraseñas

- Nunca almacenar contraseñas en texto plano.
- Hash con bcrypt: `password_hash($p, PASSWORD_BCRYPT)` / `password_verify()`; rehash con `password_needs_rehash()`.
- Comparación mediante función de verificación segura.

## 12.3 Recuperación de contraseña

Flujo requerido:

```text
usuario solicita recuperación
        ↓
generación de token de un solo uso
        ↓
envío por Gmail
        ↓
usuario abre enlace / envía token
        ↓
backend valida token y expiración
        ↓
nueva contraseña → hash → persistencia
        ↓
token invalidado
```

## 12.4 Google OAuth

El proyecto contempla login con Google (RF-17). No es un requisito de las fuentes formales del cliente; es una decisión del equipo, confirmada explícitamente para incluirse.

Flujo aprobado:

```text
frontend: usuario acepta el botón de Google (SDK de Identity Services)
        ↓
frontend obtiene id_token de Google y lo envía a POST /api/v1/auth/google
        ↓
backend verifica id_token contra las llaves públicas de Google (JWKS, firebase/php-jwt): firma, aud, iss, exp
        ↓
backend extrae correo/nombre/foto del token ya verificado
        ↓
¿existe un usuario con ese correo?
   sí → vincular googleId a la cuenta existente (no duplicar usuario)
   no → crear cuenta nueva con authProvider="google"
        ↓
backend emite el mismo JWT propio de 8h que usa el login local
```

Reglas de seguridad:

- El backend nunca confía en datos de identidad que mande el frontend sin verificar contra Google.
- Una cuenta creada por Google no tiene `passwordHash` a menos que el usuario configure una contraseña después.
- El rol asignado por defecto a una cuenta nueva creada vía Google es `DECISION_REQUIRED` (ver DECISION-011); no debe asumirse un rol con privilegios.


---

# 13. Gestión de archivos y multimedia

## 13.1 Regla general

Antes de almacenar cualquier archivo:

1. validar tipo MIME real;
2. validar tamaño;
3. aplicar reglas de negocio del recurso;
4. subir a almacenamiento externo cuando corresponda;
5. persistir sólo URL/ID/metadatos necesarios;
6. limpiar recursos externos si una operación posterior falla y deja una carga huérfana, cuando sea posible.

## 13.2 Cloudinary

Funciones base:

```php
CloudinaryService::upload(UploadedFileInterface $file, string $folder): array // ['url' => ..., 'public_id' => ...]
CloudinaryService::delete(string $publicId): void
```

> Límite del hosting: archivos grandes (sobre todo videos) pueden superar `post_max_size` o `max_execution_time` de PHP. `PROPUESTA` (DECISION-006): para videos, subida firmada directa del navegador a Cloudinary, con la firma generada por PHP y el `public_id` registrado luego en MongoDB.

La estructura de carpetas de Cloudinary debe ser consistente, por ejemplo por dominio funcional (`profile`, `projects`, `inscriptions`, `home`) sólo si el equipo adopta esa convención.

## 13.3 Restricciones pendientes

No fijar en código tamaños máximos o extensiones no aprobadas por el equipo cuando el requerimiento aún no las defina. Centralizar esos límites en configuración.

---

# 14. Correo y comunicaciones

## Gmail

Se utilizará para:

- contacto;
- recuperación de contraseña;
- notificaciones/recepción relacionadas con inscripción, según flujo aprobado.

Implementación: PHPMailer contra `smtp.gmail.com` (587 STARTTLS o 465 SSL) con contraseña de aplicación. `TODO_SPEC`: verificar en Fase 0 que Hostinger permite la conexión saliente; si no, alternativa: SMTP del correo del dominio incluido en Hostinger (DECISION-013).

### Reglas

- centralizar envío;
- no duplicar credenciales en módulos;
- usar variables de entorno;
- manejar fallas de proveedor sin exponer información sensible;
- registrar errores técnicos sin almacenar contenido sensible innecesario.

---

# 15. Variables de entorno

Archivo de referencia `.env.example`:

```dotenv
# MongoDB
MONGO_URI=
MONGO_DB_NAME=siiis

# JWT / sesión
JWT_SECRET=

# Cloudinary
CLOUDINARY_CLOUD_NAME=
CLOUDINARY_API_KEY=
CLOUDINARY_API_SECRET=

# Gmail
GMAIL_USER=
GMAIL_APP_PASSWORD=

# Google OAuth
GOOGLE_CLIENT_ID=

# Frontend / CORS
FRONTEND_ORIGIN=http://localhost:5173

# Aplicación PHP
APP_ENV=production
APP_DEBUG=false

# SMTP (PHPMailer)
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
```

> Las claves son las mismas que en la versión Python (más las de la aplicación PHP y SMTP). En Hostinger no hay panel de variables de entorno: el archivo `.env` se crea a mano en el servidor, **fuera de `public_html`**.

### Reglas

- `.env` real nunca se versiona.
- `.env.example` sí se versiona sin secretos.
- Producción usa secretos del entorno/proveedor de despliegue.
- Rotar credenciales comprometidas inmediatamente.

---

# 16. Dependencias backend actuales

Baseline del backend PHP (`composer.json`):

```json
{
  "require": {
    "php": ">=8.2",
    "ext-mongodb": "*",
    "ext-fileinfo": "*",
    "ext-curl": "*",
    "mongodb/mongodb": "<versión compatible con la extensión de Hostinger>",
    "slim/slim": "^4",
    "slim/psr7": "^1",
    "vlucas/phpdotenv": "^5",
    "firebase/php-jwt": "^6",
    "phpmailer/phpmailer": "^6",
    "cloudinary/cloudinary_php": "^2"
  },
  "require-dev": {
    "phpunit/phpunit": "^11",
    "phpstan/phpstan": "^1",
    "friendsofphp/php-cs-fixer": "^3"
  },
  "autoload": { "psr-4": { "Siiis\\": "src/" } }
}
```

> **Compatibilidad crítica:** la versión de `mongodb/mongodb` debe coincidir con la versión de la extensión `mongodb` que ofrece Hostinger (revisar con `phpinfo()`; p. ej. extensión 1.18.x → librería 1.18.x). Fijar ambas en `composer.lock` y documentarlas. Además, `platform` en `composer.json` debe reflejar la versión de PHP y de la extensión de producción para que `composer install` en local no resuelva versiones incompatibles.
>
> Equivalencias de pruebas y calidad: Pytest → PHPUnit; Ruff/Black → PHP-CS-Fixer (PSR-12) + PHPStan; `mongomock` → base de pruebas en Atlas (cluster/BD separada) o dobles de prueba de los repositorios.

---

# 17. Infraestructura de desarrollo y producción

## 17.1 Entorno local

Requisitos mínimos:

- equipo de desarrollo de cada integrante;
- Git;
- PHP 8.2+ con la extensión `mongodb` **en la misma versión que Hostinger**, `fileinfo` y `curl` (XAMPP/Laragon, paquetes del sistema o Docker `php:8.2`);
- Composer;
- servidor local de la API: `php -S localhost:8000 -t backend/public`;
- Node.js/npm o el gestor oficial que adopte el frontend;
- acceso a MongoDB;
- credenciales de Cloudinary;
- credenciales Gmail;
- credenciales/configuración Google OAuth cuando se implemente.

## 17.2 Control de versiones

- Repositorio Git/GitHub compartido.
- Commits pequeños y trazables.
- Pull requests para cambios relevantes.
- No subir secretos ni archivos temporales.
- Documentar decisiones de arquitectura en `docs/decisions.md`.

## 17.3 Arquitectura objetivo de producción

La arquitectura de producción debe entenderse como un **único sistema web** con un único punto de consumo para el frontend, no como un único endpoint HTTP literal.

```text
                    INTERNET
                        │
                        ▼
                 https://siiis.com.co
                        │
        ┌───────────────┴──── HOSTINGER (plan básico) ────┐
        │                                                 │
        ▼                                                 ▼
  Frontend React                                  API Backend PHP
  (sitio compilado, public_html/)                 (public_html/api/index.php
        │                                          + código fuera de public_html)
        │                                                 │
        └────── llamadas ────► /api/v1/* ─────────────────┤
                                                          ├── MongoDB Atlas (remoto)
                                                          ├── Cloudinary
                                                          └── Gmail
```

**Regla de consumo:** el navegador consume únicamente la API mediante una única URL base: `https://siiis.com.co/api/v1`.

Debajo de esa base existen múltiples rutas (`/auth/login`, `/auth/google`, `/users/...`, `/projects`, `/articles`, `/reviews`, `/inscriptions`, `/home/...`). **“Un solo endpoint” = un solo punto de entrada/base URL de la API**, no una sola operación.

Como frontend y API comparten dominio, **no se requiere CORS en producción** (sí en desarrollo, con `FRONTEND_ORIGIN`).

## 17.4 Qué corre en Hostinger

1. El frontend React compilado (sin cambios).
2. El backend PHP.
3. El dominio `siiis.com.co` y HTTPS.
4. La configuración (`.env`) y archivos propios de la aplicación.
5. Cron jobs de mantenimiento del backend.

El frontend **no** se conecta directamente a MongoDB Atlas, Cloudinary ni Gmail. Esas integraciones salen desde el backend PHP.

Estructura en el servidor (`PROPUESTA`; verificar que el plan permita carpetas hermanas de `public_html`):

```text
domains/siiis.com.co/
├── public_html/
│   ├── index.html, assets/...      # build de React (sin cambios)
│   ├── .htaccess                   # /api → PHP; resto → SPA
│   └── api/
│       ├── index.php               # require del bootstrap en ../../siiis-api/
│       └── .htaccess
└── siiis-api/                      # src/, vendor/, storage/, cron/, .env
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

`public_html/api/.htaccess` (referencia):

```apacheconf
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]
```

La segunda regla evita que LiteSpeed/Apache descarte la cabecera `Authorization` (necesaria para el JWT).

## 17.5 Restricción de proveedores (actualizada)

> Reemplaza la versión anterior (Hostinger para frontend + Render para backend).

- **Hostinger (plan básico):** frontend estático + backend PHP.
- **MongoDB Atlas:** datos (sin cambios).
- **Cloudinary:** multimedia (sin cambios).
- **Gmail:** correo (sin cambios).
- **Render:** eliminado.

## 17.6 Verificaciones obligatorias en Hostinger (Fase 0)

Documentar resultados en `docs/deployment.md`:

- [ ] Versión de PHP seleccionada (≥ 8.2).
- [ ] Extensión `mongodb` activada en hPanel y **su versión exacta** (`phpinfo()`); fijar `mongodb/mongodb` compatible.
- [ ] Extensiones `fileinfo`, `curl`, `openssl`, `mbstring` activas.
- [ ] **Conexión saliente a MongoDB Atlas** (puerto 27017, URI `mongodb+srv://`) desde el servidor. Probar con un script de `ping` antes de escribir lógica.
- [ ] **Lista de acceso IP de Atlas:** obtener la IP saliente del servidor de Hostinger y agregarla en *Atlas → Network Access*. En hosting compartido la IP puede cambiar si Hostinger migra la cuenta de servidor; si no hay IP estable, la alternativa es `0.0.0.0/0` con credenciales fuertes y usuario de BD con permisos mínimos (`DECISION_REQUIRED`, DECISION-015).
- [ ] Conexión saliente a `smtp.gmail.com:587/465`, `api.cloudinary.com` y `www.googleapis.com`.
- [ ] `upload_max_filesize`, `post_max_size`, `max_execution_time`, `memory_limit`.
- [ ] Disponibilidad de SSH/Composer/despliegue Git (DECISION-014). Sin SSH: `composer install --no-dev` en local/CI y subir `vendor/`.
- [ ] Métodos `PATCH`/`DELETE` y cabecera `Authorization` llegan a PHP.
- [ ] Cron jobs disponibles.

## 17.7 Despliegue recomendado (todo en Hostinger)

**Backend (PHP):**
1. Activar PHP 8.2+ y la extensión `mongodb` en hPanel.
2. Agregar la IP del servidor en la lista de acceso de MongoDB Atlas.
3. `composer install --no-dev --optimize-autoloader` (por SSH o en local/CI).
4. Subir `siiis-api/` (código + `vendor/`) por Git deploy, FTP o Administrador de archivos.
5. Crear el `.env` de producción en `siiis-api/` (fuera de `public_html`), `APP_DEBUG=false`.
6. Subir `public_html/api/index.php` y su `.htaccess`.
7. Verificar `GET https://siiis.com.co/api/v1/health` (incluye `ping` a Atlas).

**Frontend (sin cambios de código):**
8. Compilar el build de producción de React con la URL base de la API `https://siiis.com.co/api/v1` (o `/api/v1`).
9. Subir el build a `public_html/` **sin borrar** `api/` ni el `.htaccess`.
10. Configurar HTTPS en Hostinger para el dominio.

**Ambos:**
11. Registrar cron jobs de mantenimiento (p. ej. limpieza de contadores de rate limiting).
12. Verificar el flujo completo end-to-end (login local y Google, carga de archivo, CRUD básico).

La implementación exacta debe documentarse en `README.md` y no dejarse implícita.

## 17.8 Dependencias externas y costos

- **Hostinger:** hosting de producción del frontend y del backend (ya contratado).
- **MongoDB Atlas:** almacenamiento de datos.
- **Cloudinary:** almacenamiento/entrega de imágenes y archivos admitidos.
- **Gmail:** envío de correo.

No asumir que todos estos servicios permanecerán siempre en un plan gratuito; documentar los límites del plan realmente utilizado y evitar introducir proveedores adicionales sin una decisión explícita del equipo.

---

# 18. Seguridad mínima obligatoria

El proyecto identifica como riesgos relevantes:

- inyección SQL/NoSQL;
- XSS;
- manejo incorrecto de credenciales;
- pérdida de información;
- integración insegura entre módulos;
- archivos maliciosos o inesperados.

### Controles obligatorios

- validación de entrada;
- sanitización/serialización apropiada;
- autorización en servidor;
- hashing de contraseñas;
- JWT con expiración;
- límites de carga;
- validación MIME;
- rate limiting para endpoints sensibles cuando corresponda;
- CORS explícito;
- secretos fuera de Git;
- manejo global de errores sin filtrar detalles internos;
- pruebas de seguridad funcionales básicas antes de producción.

### Controles específicos del backend PHP

- **Inyección NoSQL:** nunca pasar arrays de entrada del usuario directamente como filtros de MongoDB (en PHP, `campo[$ne]=x` en un formulario llega como array). Convertir y validar tipos (`(string)`, `new ObjectId()`) antes de construir consultas.
- Código de aplicación, `vendor/`, `.env`, `storage/` y logs **fuera de `public_html`**.
- `display_errors = Off` en producción; errores a log.
- Comparaciones de tokens con `hash_equals()`.

---

# 19. Rendimiento y escalabilidad

El Charter y la arquitectura requieren una solución liviana, optimizada y escalable.

## Reglas prácticas

- Paginar listados.
- Seleccionar sólo los campos necesarios.
- Crear índices según patrones de consulta reales.
- No descargar multimedia innecesariamente.
- Usar Cloudinary para transformación/entrega de multimedia.
- Evitar N+1 consultas cuando se trabajen relaciones.
- No cargar toda la colección de usuarios/proyectos para una página.
- Mantener payloads API pequeños.
- Aplicar límites de tamaño para archivos.
- Medir antes de optimizar; documentar cambios relevantes.

---

# 20. Pruebas

## 20.1 Pirámide mínima

### Unitarias

Cubrir al menos:

- hash/verify de contraseña;
- creación/expiración de JWT;
- validación de archivos;
- funciones utilitarias.

### Integración

Cubrir:

- auth + MongoDB;
- permisos por rol;
- CRUD principal de contenido;
- reseñas;
- inscripción;
- cargas a servicios externos mediante mocks cuando corresponda.

### API

Validar:

- códigos HTTP;
- esquemas de respuesta;
- errores;
- autenticación;
- autorización;
- validaciones de entrada.

### Frontend/E2E

Debe validarse al menos el recorrido de:

- navegación pública;
- login;
- recuperación de contraseña;
- consulta de proyectos/artículos;
- reseñas;
- inscripción;
- flujo administrativo principal.

## 20.2 Criterios de calidad del producto

Según el Charter:

- **100 %** de módulos operativos y desplegados sin errores críticos para estado “Bueno”.
- **0 errores críticos** en el producto final.
- **≥95 %** de cumplimiento de historias/requisitos para estado “Bueno”.
- **≥90 %** de observaciones del cliente atendidas oportunamente.
- Carta de aceptación aprobada y firmada al cierre.

> El documento fuente contiene una inconsistencia en la categoría “Malo” de cumplimiento de requisitos (muestra `85%`), por lo que este umbral debe confirmarse antes de convertirlo en una regla automática.

---

# 21. Criterios de aceptación por módulo

Cada módulo debe definir una tabla equivalente a esta antes de implementarse:

| Campo | Contenido esperado |
|---|---|
| Requisito | RF/RNF aplicable |
| Actor | Público / usuario / administrador / etc. |
| Precondiciones | Qué debe cumplirse antes |
| Entrada | Campos y formato |
| Flujo principal | Secuencia esperada |
| Errores | Errores esperados y códigos |
| Seguridad | Auth / rol / validaciones |
| Persistencia | Colección/documento afectado |
| Integraciones | Cloudinary / Gmail / Google, si aplica |
| Pruebas | Unitarias + integración + E2E si aplica |
| Done | Condiciones objetivas |

---

# 22. Flujo Spec-Driven Development

El agente debe seguir este ciclo para cada funcionalidad:

```text
1. LEER SPEC
      ↓
2. IDENTIFICAR REQUISITOS Y REGLAS
      ↓
3. IDENTIFICAR DEPENDENCIAS Y DECISIONES PENDIENTES
      ↓
4. ACTUALIZAR / CREAR ESPECIFICACIÓN DEL CASO DE USO
      ↓
5. DEFINIR CONTRATO (datos + API + permisos)
      ↓
6. ESCRIBIR PRUEBAS
      ↓
7. IMPLEMENTAR
      ↓
8. EJECUTAR PRUEBAS + LINT + VALIDACIONES
      ↓
9. REVISAR SEGURIDAD / RENDIMIENTO
      ↓
10. DOCUMENTAR DECISIONES / CAMBIOS
      ↓
11. MARCAR REQUISITO COMO DONE
```

### Regla adicional

El agente no debe comenzar por “hacer UI” o “hacer endpoint” sin definir qué requisito satisface y qué contrato debe cumplir.

---

# 23. Orden de implementación

## Fase técnica 0 — Bootstrap

1. `git init` / repositorio remoto.
2. `.gitignore`.
3. `composer.json` + autoload PSR-4.
4. `.env.example`.
5. `Settings.php`.
6. `Mongo.php` (conexión a Atlas).
7. `Security/*`.
8. `Support/*`.
9. `Services/*`.
10. `public/index.php` + `/api/v1/health`.
11. PHPUnit / PHP-CS-Fixer / PHPStan.
12. `scripts/crear_indices.php` (índices oficiales de 8.4).
13. **Despliegue temprano a Hostinger del `health` con `ping` a Atlas** y checklist 17.6.

## Fase técnica 1 — Contratos y arquitectura

Antes de lógica de negocio:

- confirmar modelo de datos;
- confirmar convenciones de nombres;
- confirmar roles;
- definir estados;
- definir contrato de archivos;
- definir estrategia de videos;
- definir eliminación/desactivación;
- definir contrato API base.

## Fase técnica 2 — Autenticación y usuarios

Orden recomendado:

1. usuarios/modelos;
2. registro;
3. login;
4. JWT;
5. `/me`;
6. roles;
7. Google OAuth;
8. recuperación de contraseña;
9. perfil/foto;
10. administración de usuarios.

## Fase técnica 3 — Contenido

- proyectos/documentos;
- artículos/noticias;
- integrantes y relaciones;
- consultas públicas;
- archivos asociados.

## Fase técnica 4 — Interacción e inscripción

- reseñas;
- inscripción;
- documentos de inscripción;
- correo relacionado.

## Fase técnica 5 — Home y contenido multimedia

- slider;
- videos;
- contacto;
- recursos públicos.

## Fase técnica 6 — Frontend e integración

- UI pública;
- autenticación;
- perfiles;
- publicaciones;
- reseñas;
- inscripción;
- administración;
- loading animation;
- responsive/mobile.

## Fase técnica 7 — Hardening y despliegue

- pruebas integrales;
- seguridad;
- rendimiento;
- documentación;
- despliegue;
- smoke tests;
- aceptación.

---

# 24. Cronograma del proyecto

El Charter vigente establece **8 fases / 16 semanas**:

| Fase | Duración | Resultado |
|---|---:|---|
| 1 | 1 semana | Reunión inicial y definición de requisitos |
| 2 | 2 semanas | Arquitectura, wireframes y BD |
| 3 | 3 semanas | Lógica del sistema y backend |
| 4 | 3 semanas | Interfaces frontend |
| 5 | 2 semanas | Integración y pruebas |
| 6 | 1 semana | Correcciones y pruebas finales |
| 7 | 1 semana | Documentación |
| 8 | 1 semana | Publicación, verificación y cierre |

**Total:** 16 semanas.

El MD anterior menciona que existía un documento de 5 fases / 12 semanas. Esa discrepancia debe considerarse resuelta a favor del Charter más reciente salvo nueva aprobación explícita.

---

# 25. Roles del equipo

| Rol | Responsable | Dedicación estimada |
|---|---|---:|
| Líder del proyecto | Andryw Yesid Barrera Camargo | 6–8 h/semana |
| Desarrollo Front-End | Rafael Esteban Lozano Vargas | 8–10 h/semana |
| Bases de Datos | Luisa Fernanda Merchán Rojas | 6–8 h/semana |
| Desarrollo Back-End | Nicolás Samuel Tinjaca Topia | 8–10 h/semana |

### Reglas de colaboración

- cambios que afecten arquitectura/BD se documentan;
- contratos entre frontend/backend se coordinan;
- ningún módulo debe depender de conocimiento no documentado de otro integrante;
- el trabajo debe mantenerse trazable en Git.

---

# 26. Riesgos técnicos y mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|---|---|---|---|
| Retraso de información institucional | Media | Medio | Solicitud anticipada + fechas límite + seguimiento |
| Curva de aprendizaje tecnológico | Media | Medio | Documentación oficial + capacitación + apoyo entre integrantes |
| Descoordinación front/back/BD | Media | Alto | Contratos API + reuniones + Git |
| Poco tiempo disponible del equipo | Alta | Alto | Planificación realista + distribución + holguras |
| Cambios de alcance | Media | Alto | Control de cambios y evaluación de impacto |
| Pérdida de información | Baja | Alto | Git/GitHub + copias de seguridad |
| Falla del hosting | Baja | Medio | Probar despliegue temprano + proveedor confiable |
| Vulnerabilidades | Media | Alto | Validación + pruebas + buenas prácticas de seguridad |
| Ausencia de integrantes | Baja | Alto | Documentación continua + conocimiento compartido |
| Incompatibilidad tecnológica | Baja | Medio | Prueba de concepto temprana |
| El plan de Hostinger contratado no es VPS (sólo PHP) | Confirmado | Alto | **Resuelto:** backend reescrito en PHP y alojado en Hostinger (ver 0.5 y 17) |
| Versión antigua de la extensión `mongodb` en Hostinger | Media | Medio | Fijar `mongodb/mongodb` compatible y `platform` en Composer (sección 16) |
| Conexión Hostinger → Atlas bloqueada o IP cambiante | Media | Alto | Probar en Fase 0; lista de acceso IP en Atlas (DECISION-015) |
| Límites de PHP compartido (subidas, tiempo) | Media | Medio | Límites configurables; subida firmada a Cloudinary para archivos grandes |
| Curva de aprendizaje por cambio a PHP | Media | Medio | Stack mínimo (Slim), contratos y reglas sin cambios |

---

# 27. Observabilidad y diagnóstico

La primera versión debe permitir al menos:

- endpoint `/api/v1/health`;
- logs útiles para errores del servidor;
- identificación de fallas de servicios externos;
- mensajes de error consistentes;
- trazabilidad de operaciones administrativas sin registrar secretos.

No introducir una plataforma de observabilidad externa como requisito obligatorio si el equipo no la ha aprobado.

---

# 28. Documentación que debe mantenerse

El repositorio debe conservar como mínimo:

- `README.md` raíz;
- `backend/README.md`;
- arquitectura;
- modelo de datos;
- API;
- despliegue;
- decisiones técnicas (`docs/decisions.md`);
- variables de entorno de ejemplo;
- manual técnico;
- manual de usuario.

Cualquier cambio que contradiga esta especificación debe reflejarse también en la documentación y en el registro de decisiones.

---

# 29. Registro de decisiones

Usar este formato para cada decisión importante:

```md
## DEC-XXX — <título>

- Estado: proposed | approved | rejected
- Fecha: YYYY-MM-DD
- Autor: <persona/equipo>
- Contexto: <qué problema se resuelve>
- Decisión: <qué se decidió>
- Alternativas: <opciones consideradas>
- Impacto: <archivos, módulos, infraestructura>
- Requiere migración: sí/no
```

---

# 30. Decisiones pendientes actuales

| ID | Tema | Estado | Bloquea |
|---|---|---|---|
| DECISION-001 | Modelo MongoDB unificado vs colecciones separadas | `DECISION_REQUIRED` | modelos y lógica de recursos |
| DECISION-002 | Cronograma definitivo | `APPROVED_BY_CHARTER` provisional | planificación, no bootstrap técnico |
| DECISION-003 | Campos exactos de entidades | `DECISION_REQUIRED` | `Validation/*` y contratos |
| DECISION-004 | Borrado físico vs lógico de usuarios | `DECISION_REQUIRED` | `UsersController` |
| DECISION-005 | Formatos/tipos/tamaños de documentos de inscripción | `DECISION_REQUIRED` | `InscriptionsController` |
| DECISION-006 | Implementación de videos | `DECISION_REQUIRED` | `HomeController` / almacenamiento |
| DECISION-007 | Previsualización de documentos | `DECISION_REQUIRED` | perfil/contenido |
| DECISION-008 | Hosting del backend — **Resuelto (23/09/2026):** plan básico sólo ejecuta PHP; backend reescrito en PHP y alojado en Hostinger. Render eliminado | `RESUELTO` | despliegue (ver 17) |
| DECISION-009 | Librerías concretas de frontend | `DECISION_REQUIRED` | frontend |
| DECISION-010 | Estados definitivos de contenido/proyectos | `DECISION_REQUIRED` | filtros, workflow y API |
| DECISION-011 | Rol por defecto para cuentas creadas vía login con Google (RF-17) | `DECISION_REQUIRED` | `AuthController` |
| DECISION-012 | Framework PHP (Slim 4 vs PHP sin framework) y librerías de 7.2 | `PROPUESTA` | estructura del backend |
| DECISION-013 | SMTP: Gmail vs correo del dominio en Hostinger (según prueba de Fase 0) | `DECISION_REQUIRED` | `MailService` |
| DECISION-014 | Disponibilidad de SSH/Composer/Git deploy en el plan | `TODO_SPEC` (verificar en Fase 0) | flujo de despliegue |
| DECISION-015 | Acceso de red a Atlas desde Hostinger (IP fija en allowlist vs `0.0.0.0/0`) | `DECISION_REQUIRED` | conexión a BD en producción |

---

# 31. Checklist de agente antes de codificar

```text
[ ] ¿Qué requisito estoy implementando?
[ ] ¿Está definido o tengo una DECISION_REQUIRED?
[ ] ¿Qué actor puede ejecutar la operación?
[ ] ¿Qué datos entran y salen?
[ ] ¿Cuál es el contrato API?
[ ] ¿Qué colección/documento se modifica?
[ ] ¿Qué rol/autorización requiere?
[ ] ¿Qué validaciones necesita?
[ ] ¿Qué errores debo manejar?
[ ] ¿Hay archivos o servicios externos?
[ ] ¿Qué pruebas demuestran que funciona?
[ ] ¿Qué impacto tiene en frontend/backend/BD/infraestructura?
[ ] ¿Tengo que actualizar documentación o decisiones?
```

---

# 32. Checklist de revisión antes de merge

```text
[ ] Requisito trazado a RF/RNF o decisión aprobada.
[ ] Código formateado y lint sin errores.
[ ] Pruebas nuevas o ajustadas.
[ ] Pruebas existentes siguen pasando.
[ ] Autorización verificada.
[ ] Validación de entrada verificada.
[ ] Errores manejados.
[ ] No hay secretos en el diff.
[ ] Migraciones/índices/contratos revisados.
[ ] Documentación actualizada si aplica.
[ ] No se introdujo una decisión no aprobada como comportamiento definitivo.
```

---

# 33. Checklist de release

```text
[ ] Todos los módulos del alcance implementados.
[ ] Integración frontend/backend validada.
[ ] MongoDB y Cloudinary operativos.
[ ] Correo operativo.
[ ] Login Google validado si está incluido en el release.
[ ] Variables de entorno de producción configuradas.
[ ] CORS de producción configurado.
[ ] Health check operativo.
[ ] Pruebas funcionales y de usabilidad realizadas.
[ ] Sin errores críticos conocidos.
[ ] Documentación técnica y de usuario actualizada.
[ ] Smoke test en producción realizado.
[ ] Observaciones del cliente atendidas.
[ ] Acta/carta de aceptación preparada.
```

---

# 34. Fuentes de este documento

Este archivo consolida y estructura la información disponible en:

1. **Charter SIIIS V3** — objetivo, alcance, nuevos agregados, entregables, roles, riesgos, cronograma, recursos, métricas y criterios de éxito.
2. **Markdown/Guía de arranque Backend SIIIS V3** — stack backend, estructura inicial, requisitos funcionales/no funcionales ya mapeados, variables de entorno, integraciones y orden de implementación.
3. **Arquitectura Base de Datos SIIIS** — arquitectura híbrida MongoDB + Cloudinary y modelo documental propuesto.

Cuando una decisión técnica de este archivo no aparece explícitamente en las fuentes anteriores, debe considerarse **propuesta de organización/implementación**, no requisito histórico del proyecto.

---

# 35. Resumen ejecutivo para agentes

```text
PRODUCTO:
  Sitio web V3 del Semillero SIIIS (UPTC), construido desde cero.

FRONTEND:
  React + TypeScript.

BACKEND:
  PHP 8.2+ (Slim 4 propuesto) + Composer, alojado en Hostinger bajo /api/v1.
  Conexión remota a MongoDB Atlas con la extensión mongodb + mongodb/mongodb.

DATOS:
  MongoDB.

MULTIMEDIA:
  Cloudinary.

EMAIL:
  Gmail.

AUTH:
  JWT (8h) + login Google.

COLABORACIÓN:
  Git/GitHub.

DEPLOY:
  Todo en Hostinger (plan básico): frontend estático + backend PHP, mismo dominio.
  Un solo punto de consumo para el frontend: https://siiis.com.co/api/v1.
  Frontend y base de datos (MongoDB Atlas + Cloudinary) sin cambios.

MÓDULOS MÍNIMOS:
  institucional, blog/artículos, proyectos, reseñas/comentarios, contacto,
  integrantes, autenticación, administración, inscripción y multimedia.

PRIORIDAD TÉCNICA:
  infraestructura → contratos/BD → auth/users → contenido → reseñas/inscripción
  → home/multimedia → frontend/integración → hardening/deploy.

REGLA CLAVE:
  No inventar reglas o campos no definidos; registrar DECISION_REQUIRED.

DEFINITION OF DONE:
  código + pruebas + seguridad + integración + documentación + aceptación.
```