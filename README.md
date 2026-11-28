# SIIIS V3 — Sitio web del Semillero de Investigación SIIIS

Versión 3 del sitio web del **Semillero de Investigación SIIIS** (Ingeniería de Sistemas y Computación, UPTC). Reemplaza la versión actual del sitio y facilita la publicación de proyectos, artículos y noticias, la inscripción al semillero y la participación de la comunidad académica.

**Sitio publicado:** https://siiis.com.co

---

## Estructura del repositorio

```text
.
├── frontend/                # SPA en React + TypeScript + Vite
│   ├── public/              # Archivos estáticos (íconos, imágenes)
│   └── src/                 # Código fuente: app/, components/, features/, lib/, styles/
├── backend/                 # API REST en PHP (Slim 4), bajo /api/v1
│   ├── public/              # Punto de entrada web (index.php)
│   ├── src/                 # Código PSR-4 Siiis\: Controllers, Services, Repositories, Middleware,
│   │                        #   Security, Validation, Database, Config, Support
│   ├── scripts/             # Scripts de consola: índices, superadministrador, multimedia, empaquetado
│   ├── cron/                # Tareas programadas (limpieza de contadores de rate limiting)
│   ├── storage/             # Logs, contadores de rate limiting y caché (no se versiona su contenido)
│   └── tests/               # PHPUnit: Unit, Api e Integration
├── docs/                    # Spec, contrato de la API, decisiones y guía de despliegue
├── cronograma/              # Cronograma del proyecto (ProjectLibre)
└── README.md
```

---

## Stack tecnológico

| Capa | Tecnología |
|---|---|
| Frontend | React 19 · TypeScript · Vite · React Router · react-markdown + remark-gfm · lucide-react · ESLint |
| Backend | PHP 8.2 · Slim 4 · PHP-DI · mongodb/mongodb · firebase/php-jwt · PHPMailer · cloudinary_php · phpdotenv |
| Base de datos | MongoDB Atlas |
| Multimedia | Cloudinary |
| Correo | Gmail (SMTP) |
| Login con Google | Solo cuentas `@uptc.edu.co` de integrantes ya registrados en el semillero (no crea cuentas) |
| Calidad | PHPUnit · PHPStan (nivel 6) · PHP-CS-Fixer (PSR-12) |
| Hosting | Hostinger (frontend en `public_html/`, API en `public_html/api/`) |
| Control de versiones | Git / GitHub |

---

## Requisitos

- **PHP 8.2 o superior** con las extensiones `mongodb` (≥ 1.18), `fileinfo`, `curl`, `openssl` y `mbstring`
- **Composer 2**
- **Node.js** `^20.19.0` o `>=22.12.0` (lo exige Vite) con npm
- **Git**
- Acceso al cluster de **MongoDB Atlas**, con tu IP agregada en *Network Access*

---

## Puesta en marcha local

### 1. Clonar el repositorio

```powershell
git clone "URL del repositorio"
cd Siis
```

(Usa el nombre de la carpeta que haya creado `git clone`).

### 2. Backend

```powershell
cd backend
composer install
Copy-Item .env.example .env        # luego completa los valores
```

Completa en `backend/.env`, como mínimo:

| Variable | Valor |
|---|---|
| `MONGO_URI` | Cadena `mongodb+srv://...` del cluster de Atlas |
| `MONGO_DB_NAME` | Nombre de la base (por defecto `siiis`) |
| `JWT_SECRET` | Texto aleatorio de 32 caracteres o más. Genéralo con `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` |
| `FRONTEND_ORIGIN` | Dirección exacta del frontend en desarrollo (por defecto `http://localhost:5173`, la de Vite) |
| `GOOGLE_CLIENT_ID` | ID de cliente OAuth de Google Cloud. Si lo dejas vacío, el login con Google queda desactivado |
| `GOOGLE_DOMINIO_PERMITIDO` | `uptc.edu.co` |
| `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY`, `CLOUDINARY_API_SECRET` | Credenciales de la cuenta de Cloudinary |
| `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | Cuenta de Gmail y su **contraseña de aplicación** (`MAIL_HOST` y `MAIL_PORT` ya vienen para Gmail) |

El resto de variables tiene valores por defecto y está documentado en `backend/.env.example`. Para las pruebas de integración se usa además `MONGO_DB_NAME_TEST` (por defecto `siiis_test`).

Crea los índices de la base (se puede repetir sin problema) y el primer superadministrador. El script pide los datos por consola y la contraseña no se muestra al escribirla:

```powershell
php scripts/crear_indices.php
php scripts/crear_superadmin.php
```

Opcional: datos de ejemplo para el carrusel y el video de inicio:

```powershell
php scripts/seed_multimedia.php
```

Arranca la API:

```powershell
composer serve
```

Comprueba que funciona abriendo http://localhost:8000/api/v1/health. Debe responder `{"status":"ok","db":"ok"}`.

> ⚠️ **Nunca subas el archivo `.env` a Git.** Contiene la contraseña de la base de datos y el secreto de los tokens. El `.gitignore` ya lo excluye; solo se versiona `.env.example`.

### 3. Frontend

En otra terminal, desde la raíz del repositorio:

```powershell
cd frontend
npm install
npm run dev
```

Abre http://localhost:5173. En desarrollo, el frontend usa `http://localhost:8000/api/v1` sin configurar nada más.

Solo si necesitas otra URL de la API o quieres probar el botón de Google, copia `frontend/.env.example` como `frontend/.env.local` y completa `VITE_API_URL` o `VITE_GOOGLE_CLIENT_ID` (el mismo valor que `GOOGLE_CLIENT_ID` del backend).

Si el navegador muestra un error de **CORS**, revisa que `FRONTEND_ORIGIN` en `backend/.env` coincida exactamente con la dirección del frontend. `localhost` y `127.0.0.1` cuentan como orígenes distintos.

Otros comandos del frontend:

```powershell
npm run build      # compila a frontend/dist (usa /api/v1, el mismo dominio que la API)
npm run lint       # ESLint
npm run preview    # sirve el build localmente
```

---

## Pruebas y calidad (backend)

Desde `backend/`:

```powershell
composer test       # PHPUnit (las pruebas de integración se saltan si no hay MONGO_URI)
composer lint       # PHP-CS-Fixer en modo revisión (PSR-12)
composer lint:fix   # aplica las correcciones de estilo
composer analyse    # PHPStan nivel 6
```

Las pruebas de integración usan la base `MONGO_DB_NAME_TEST`, nunca la de producción.

---

## Despliegue

El sitio se publica en Hostinger. El script `backend/scripts/empaquetar.ps1` arma la carpeta `deploy/` con la API (fuera de `public_html`) y el build del frontend:

```powershell
npm --prefix frontend run build
powershell -ExecutionPolicy Bypass -File backend\scripts\empaquetar.ps1 -Dominio siiis.com.co
```

Los pasos completos (configuración de PHP, `.env` de producción, cron, login con Google y verificación) están en [`docs/deployment.md`](docs/deployment.md).

---

## Documentación

| Documento | Contenido |
|---|---|
| [`docs/SIIIS_V3_SPEC_DRIVEN.md`](docs/SIIIS_V3_SPEC_DRIVEN.md) | Especificación del proyecto |
| [`docs/api.md`](docs/api.md) | Contrato de la API: rutas, peticiones, respuestas y códigos de error |
| [`docs/decisions.md`](docs/decisions.md) | Decisiones tomadas y pendientes |
| [`docs/deployment.md`](docs/deployment.md) | Despliegue y mantenimiento en Hostinger |

---

## Equipo

| Rol | Integrante |
|---|---|
| Líder del proyecto | Andryw Yesid Barrera Camargo |
| Desarrollo Front-End | Rafael Esteban Lozano Vargas |
| Bases de Datos | Luisa Fernanda Merchán Rojas |
| Desarrollo Back-End | Nicolás Samuel Tinjaca Topia |

**Cliente:** Semillero de Investigación SIIIS — Escuela de Ingeniería de Sistemas y Computación, UPTC.
