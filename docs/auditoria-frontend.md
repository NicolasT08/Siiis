# Auditoría del frontend — SIIIS V3 (estado final)

- Primera auditoría: 2026-11-26 (avance aproximado 20 %: casi nada conectado a la API real).
- Esta versión: 2026-11-26, después de terminar el frontend para el despliegue (bloques A a D).
- Alcance: `frontend/` (React 19 + TypeScript + Vite), comparado con el spec (RF-01 a RF-17, RNF-01 a RNF-06),
  el chárter, `docs/api.md` y los roles de DEC-B16 a DEC-B52.

> **Conclusión corta:** el frontend usa **las 37 operaciones** del contrato de `docs/api.md` (todas menos
> `/health`), con las rutas, métodos y campos correctos, mediante un único cliente tipado (`src/lib/api.ts`).
> Tiene todas las pantallas pedidas: sitio público con datos reales, área del usuario y panel de
> administración por rol. Tipos, lint y build pasan sin errores, y **111 comprobaciones** contra el backend
> con `siiis_test` y las 5 cuentas demo salieron bien. Avance aproximado: **95 %**. Lo que falta está en la
> sección 6; lo más importante es **probarlo a mano en un navegador** (aquí no había navegador
> automatizable).

---

## 1. Verificaciones técnicas

| Verificación | Resultado | Detalle |
|---|---|---|
| `npm install` | ✅ | Se agregaron `react-markdown` 10 y `remark-gfm` 4. `npm audit --omit=dev`: **0** vulnerabilidades. Hay avisos solo en dependencias de desarrollo (`brace-expansion`, `minimatch`, `vite` 8.2.x); se corrigen con `npm audit fix`, pero no lo ejecuté para no cambiar versiones el día antes del despliegue. |
| Tipos (`tsc -b`) | ✅ | Sin errores. |
| Lint (`npm run lint`) | ✅ | Sin errores ni avisos. Se corrigieron los 2 errores y el aviso de la primera auditoría (`AppLayout`, `AuthModal`, `ImageCarousel`). |
| Build (`npm run build`) | ✅ | `dist/` con `VITE_API_URL=/api/v1`. Carga inicial: 329 kB de JS (101 kB gzip) y 77 kB de CSS. El Markdown (165 kB) y las pantallas del usuario y del panel se descargan solo al usarlas (`React.lazy`, RNF-06). |
| React + TypeScript (RNF-01) | ✅ | 5 941 líneas en `.ts/.tsx`. |
| Firebase / EmailJS / acceso directo | ✅ Ninguno | El frontend solo habla con la API. Cloudinary solo se usa para mostrar URLs públicas (logo, fotos de respaldo del carrusel, imágenes que entrega la API). |
| URL de la API | ✅ | Sin variable: `http://localhost:8000/api/v1` con `npm run dev` y `/api/v1` con `npm run build` (valor por defecto en `src/lib/api.ts`). `VITE_API_URL` lo sobrescribe (plan B en Render). `.env.example` corregido; se borró `.env.local`, que apuntaba al puerto 8001. |

**Cómo se probó.**
- **Cliente contra la API:** cargué el cliente real (`src/lib/api.ts`, `errors.ts`, `auth.ts` y `archivos.ts`) con Vite y lo ejecuté contra el backend en `siiis_test`, con las cuentas `demo.superadmin`, `demo.admin`, `demo.docente`, `demo.lider` y `demo.valentina`. Resultado: bloque A 32/32, B 29/29, C 21/21 y D 29/29.
- **Renderizado:** renderé en el servidor (SSR) las 27 rutas para descartar errores de pintado.
- **Markdown:** comprobé que no deja pasar HTML.
- **Sin navegador:** no se pudo hacer clic en una ventana real (efectos visuales, foco del teclado, móvil). Eso queda para la prueba manual (sección 6).
- **Datos de prueba:** borré lo que creé (reseñas, documentos, adjuntos y la contraseña cambiada de demo.valentina, ya restaurada), salvo un usuario de prueba inactivo (sección 6).

## 2. Tabla por requisito

### 2.1 Requisitos funcionales

| Req. | Estado | Dónde | Qué le falta |
|---|---|---|---|
| RF-01 Registro mediante roles | ✅ | `features/admin/AdminUserFormPage.tsx` (`POST /auth/register`) | — Solo se ofrecen los roles que el actor puede asignar (DEC-B17). |
| RF-02 Validación de formularios | ✅ | Todos los formularios; `lib/archivos.ts`, `features/auth/passwordRules.ts` | Valida en el navegador con las mismas reglas del backend y muestra los `fields` de un `400` junto a cada campo. |
| RF-03 Contacto | ✅ | `features/contact/ContactPage.tsx` | — `nombre`, `correo`, `asunto` y `mensaje` (3 000). |
| RF-04 Recuperación de contraseña | ✅ | `components/AuthModal.tsx`, `features/auth/RestablecerPage.tsx` | — `/restablecer?token=` funciona. |
| RF-05 Perfil con publicaciones y documentos | ✅ | `features/users/MemberDetailPage.tsx` | Publicaciones como autor o integrante. La previsualización de documentos es por enlace o miniatura (DECISION-007 sigue abierta en el spec). |
| RF-06 Foto de perfil | ✅ | `features/account/ProfilePage.tsx` (`FotoPerfil`) | — Valida 2 MB y JPG/PNG/WebP en el navegador. |
| RF-07 Slider | ✅ | `components/ImageCarousel.tsx` | — Usa `descripcion` como `alt`. |
| RF-08 Integrantes y proyectos | ✅ | `features/users/*`, `features/content/*` | — Listado paginado con filtro por rol; sin estado ni correo. |
| RF-09 Desactivar por deserción | ✅ | `features/admin/AdminUsersPage.tsx` | — Desactivar y reactivar. |
| RF-10 Reseñas | ✅ | `features/reviews/*`, `features/admin/AdminReviewsPage.tsx` | — Promedio, listado, formulario con aviso de revisión y moderación. |
| RF-11 Videos | ✅ | `features/home/HomePage.tsx` (`Videos`) | — |
| RF-12 Inscripción con documentos | ✅ | `features/auth/InscripcionPage.tsx` | — Multipart, carta obligatoria y hasta 2 archivos opcionales, validación de tamaño, aviso `@uptc.edu.co` y casilla con el texto legal. |
| RF-13 Pantalla de inscripción y gestión | ✅ | `InscripcionPage.tsx`, `features/admin/AdminInscriptionsPage.tsx` | — |
| RF-14 Animación de carga | ✅ | `components/PageLoader.tsx`, `app/layout/AppLayout.tsx` | — |
| RF-15 Administración mejorada | ✅ | `features/admin/*` | — |
| RF-16 Móviles | 🟡 | CSS adaptable (`styles/app.css` y los existentes) | Probar en un teléfono real; las tablas del panel tienen desplazamiento horizontal. |
| RF-17 Login con Google | ✅ | `components/GoogleLoginButton.tsx`, `components/AuthModal.tsx` (`POST /auth/google`, DEC-B61) | Solo aparece si el build tiene `VITE_GOOGLE_CLIENT_ID`; requiere el ID de cliente en Google Cloud (docs/deployment.md). |

### 2.2 Requisitos no funcionales

| Req. | Estado | Detalle |
|---|---|---|
| RNF-01 React + TypeScript | ✅ | |
| RNF-02 / RNF-04 BD y Cloudinary | ✅ | Todo pasa por la API; las subidas usan `multipart/form-data`. |
| RNF-03 Paginación | ✅ | Integrantes, proyectos, artículos, reseñas, usuarios, contenido, inscripciones y mensajes usan `page`/`per_page`/`meta`. Las páginas y filtros viven en la URL. |
| RNF-05 Sesión de 8 h | ✅ | Token en `localStorage` ("Recordarme") o `sessionStorage`. Cualquier `401` con token cierra la sesión y las rutas protegidas llevan a `/login` con el aviso "Tu sesión terminó" y la vuelta a la página pedida. |
| RNF-06 Sistema liviano | ✅ | Código dividido por ruta, imágenes con `loading="lazy"` y validación de tamaño antes de subir. |

### 2.3 Chárter

| Elemento | Estado |
|---|---|
| Inicio con slider y videos | ✅ (más las últimas noticias y las reseñas) |
| Información institucional | ✅ `AboutPage.tsx` (estático, DEC-B12). Ya dice "Universidad Pedagógica y Tecnológica de Colombia (UPTC)" (`src/config/sitio.ts`). |
| Blog | ✅ `/blog` redirige a `/noticias` (las noticias son el blog, DEC-B30). |
| Proyectos / artículos y noticias | ✅ |
| Comentarios / reseñas | ✅ |
| Contacto | ✅ |
| Redes sociales | 🟡 Configurables en `src/config/sitio.ts` (`REDES_SOCIALES`), pero **las URL están vacías**: los iconos se muestran sin enlace hasta que el semillero las confirme. |
| V3: formulario de inscripción | ✅ |
| V3: animación de carga | ✅ |
| V3: interfaz del administrador | ✅ |

### 2.4 Pantallas

| Pantalla | Ruta | Archivo |
|---|---|---|
| Login (modal y página) | menú y `/login` | `components/AuthModal.tsx`, `features/auth/LoginPage.tsx` |
| Olvidé mi contraseña | dentro del login | `AuthModal.tsx` |
| Restablecer contraseña | `/restablecer?token=` | `features/auth/RestablecerPage.tsx` |
| Mi perfil (datos, foto, contraseña) | `/mi-perfil` | `features/account/ProfilePage.tsx` |
| Integrantes y perfil | `/usuarios`, `/usuarios/:id` | `features/users/*` |
| Proyectos | `/proyectos`, `/proyectos/:id` | `features/content/DocumentListPage.tsx`, `DocumentDetailPage.tsx` |
| Artículos y noticias | `/articulos`, `/noticias`, `/articulos/:id` | ídem |
| Crear y editar contenido, con adjuntos | `/mis-contenidos`, `/mis-contenidos/nuevo/:tipo`, `/mis-contenidos/:modulo/:id/editar` | `features/account/MyContentPage.tsx`, `DocumentEditorPage.tsx` |
| Publicar, ocultar y borrar | detalle (según `permisos`) y `/admin/contenido` | `features/content/AccionesRevision.tsx`, `features/admin/AdminContentPage.tsx` |
| Reseñas y moderación | inicio, `/resenas`, `/admin/resenas` | `features/reviews/*`, `features/admin/AdminReviewsPage.tsx` |
| Inscripción | `/inscripcion` (`/registro` redirige) | `features/auth/InscripcionPage.tsx` |
| Contacto | `/contacto` | `features/contact/ContactPage.tsx` |
| Panel: usuarios | `/admin/usuarios`, `/admin/usuarios/nuevo`, `/admin/usuarios/:id` | `features/admin/AdminUsersPage.tsx`, `AdminUserFormPage.tsx` |
| Panel: inscripciones | `/admin/inscripciones`, `/admin/inscripciones/:id` | `features/admin/AdminInscriptionsPage.tsx` |
| Panel: mensajes | `/admin/mensajes` | `features/admin/AdminMessagesPage.tsx` |
| 404 | cualquier otra ruta | `features/NotFoundPage.tsx` |

### 2.5 Token, errores y Markdown

| Tema | Estado | Detalle |
|---|---|---|
| Token | ✅ | `Authorization: Bearer` en todas las peticiones con sesión; el login y los formularios públicos no lo envían. |
| 400 | ✅ | `fields` debajo de cada campo y un aviso general. |
| 401 | ✅ | Con token: cierra la sesión y lleva al login. Sin token (credenciales malas): solo el mensaje. |
| 403 | ✅ | Mensaje "No tienes permiso…"; las rutas por rol muestran "No tienes acceso a esta sección". |
| 404 | ✅ | "No encontramos…" en los detalles; página 404 propia. |
| 409 | ✅ | Mensaje del backend (`EMAIL_TAKEN`, `INSCRIPTION_PENDING`, `LAST_SUPERADMIN`, `ATTACHMENT_LIMIT`, `INSCRIPTION_CLOSED`). |
| 413 / 415 | ✅ | Validación previa en el navegador y mensaje del backend si llega. |
| 429 | ✅ | "Hiciste demasiados intentos. Espera N minutos" con `Retry-After`. Para leerlo con la API en otro dominio, el backend expone la cabecera (DEC-B57). |
| 502 / 503 / red | ✅ | Mensajes propios. |
| Markdown | ✅ | `components/Markdown.tsx`: `react-markdown` con `skipHtml` (el HTML no se muestra), sin `dangerouslySetInnerHTML`, enlaces `javascript:` anulados y externos con `noopener noreferrer`. Comprobado con `<script>`, `onerror` y `javascript:`. |
| `permisos` | ✅ | El detalle muestra Editar, Borrar y Publicar según `permisos`; el editor no deja editar si `permisos.editar` es `false`. |

## 3. Tabla por rol

| Rol | Puede (backend) | Frontend |
|---|---|---|
| Visitante | Ver todo lo público; reseña que queda oculta; contacto; inscripción; login y recuperación | ✅ Sin menú de usuario. Las rutas protegidas llevan al login. |
| `estudiante` | Lo anterior + perfil propio, foto, contraseña; crear contenido (queda en revisión); editar y borrar lo suyo mientras está en revisión; reseña visible | ✅ Menú "Mi perfil" y "Mis contenidos"; sin "Administración". El editor no ofrece estado ni visibilidad. |
| `estudiante_lider` | Igual que `estudiante` (DEC-B17, B18) | ✅ Igual. |
| `docente_director` | Lo anterior + revisar y publicar todo el contenido y moderar reseñas | ✅ "Administración" con Contenido y Reseñas; el editor ofrece estado y visibilidad; sin Usuarios, Inscripciones ni Mensajes (y sus rutas responden "sin acceso"). |
| `administrador` | Lo anterior + usuarios `docente_director`, `estudiante_lider` y `estudiante`, inscripciones y mensajes | ✅ Todas las secciones; botones y selector de rol solo para esos tres roles. |
| `superadministrador` | Todo, sobre cualquier rol | ✅ Todas las secciones; nadie se edita a sí mismo desde el panel (lo envía a "Mi perfil"). |

## 4. Llamadas a la API que no coinciden con el contrato

**Ninguna.** Las 4 llamadas que no coincidían en la primera auditoría (`/proyectos`, `/usuarios`, `/contacto` e `/inscripciones`, con rutas y campos equivocados) ahora usan `/projects`, `/users`, `/contact` e `/inscriptions` con los campos exactos. Las 37 operaciones de `docs/api.md` (todas menos `/health`) están en `src/lib/api.ts` y cada una se usa desde al menos una pantalla.

## 5. Despliegue

- **Build de producción:** `frontend/dist/` (`npm run build`, con `/api/v1` por defecto), con `dist/.htaccess` de la SPA tal como en `docs/deployment.md` (sale de `frontend/public/.htaccess`).
- **Ojo en Windows:** no compiles con `VITE_API_URL=/api/v1 npm run build` desde **Git Bash**, porque convierte `/api/v1` en `D:/Program Files/Git/api/v1`. Usa PowerShell o simplemente `npm run build` (ya usa `/api/v1`).
- **Subida:** el contenido de `frontend/dist/` va a `public_html/` sin borrar `api/` (paso F2 de `docs/checklist-manana.md`).
- **Empaquetado:** `backend/scripts/empaquetar.ps1` copia el contenido de `frontend/dist` a `deploy/public_html/` junto a `api/`, sin pisarla, y se detiene si el build es de desarrollo o se compiló mal desde Git Bash (2026-11-26).

## 6. Resumen en lenguaje sencillo

**¿Cuánto lleva?** Alrededor del **95 %**. El sitio público muestra datos reales, cada persona tiene su área
(perfil y contenidos) y el panel de administración cubre usuarios, contenido, reseñas, inscripciones y
mensajes, respetando lo que cada rol puede hacer.

**Lo que falta, de más a menos importante:**

| # | Pendiente | Esfuerzo |
|---|---|---|
| 1 | **Prueba manual en el navegador** (escritorio y teléfono) con las 5 cuentas demo: recorrer el menú, crear, editar y publicar un proyecto, subir un adjunto y una foto, y moderar una reseña. Aquí solo se probó la API y el renderizado, no los clics en una ventana real. | 1–2 horas |
| 2 | URL oficiales de las redes sociales en `src/config/sitio.ts`. | 5 min (cuando el semillero las tenga) |
| 3 | `npm audit fix` (solo dependencias de desarrollo) después de la entrega. | 15 min |
| 4 | Mejoras opcionales: recortar la foto en el navegador antes de subirla (DEC-B42) y mostrar los PDF dentro de la página (hoy se abren en otra pestaña). | 0,5–1 día |

**Hallazgo del backend (corregido el 2026-11-26, DEC-B59):** un archivo que empezaba como PDF pero estaba dañado
producía `502 CLOUDINARY_UNAVAILABLE` ("intenta más tarde"); ahora responde `415 UNSUPPORTED_MEDIA_TYPE` con un
mensaje claro, que el frontend ya muestra.

**Datos que quedaron en `siiis_test`:** un usuario de prueba **inactivo**, `demo.prueba.frontend.<número>@example.com`
(no hay ruta para borrar usuarios). Lo borra `php scripts/seed_demo.php --test --limpiar`, porque sigue el
patrón `demo.*@example.com`.
