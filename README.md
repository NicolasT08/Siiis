# SIIIS V3 — Sitio web del Semillero de Investigación SIIIS

Versión 3 del sitio web del **Semillero de Investigación SIIIS** (Ingeniería de Sistemas y Computación, UPTC). Reemplaza la versión actual del sitio y facilita la publicación de proyectos, artículos y noticias, la inscripción al semillero y la participación de la comunidad académica.


---

## Estructura del repositorio

```text
.
├── frontend/     # Interfaz web (páginas públicas, login, administración)
├── backend/      # API REST en Python + FastAPI
└── README.md
```

---

## Stack tecnológico

| Capa | Tecnología |
|---|---|
| Frontend | HTML, CSS y JavaScript. El spec contempla migrar a React + TypeScript |
| Backend | Python 3.12+ · FastAPI · Uvicorn · PyMongo · PyJWT · bcrypt |
| Base de datos | MongoDB Atlas |
| Multimedia | Cloudinary |
| Correo | Gmail (SMTP) |
| Calidad | pytest · ruff · mypy |
| Control de versiones | Git / GitHub |

> **Nota:** existe además una versión del backend en **PHP** (Slim 4), con el mismo contrato de API y la misma base de datos, pensada para el despliegue en Hostinger, que solo ejecuta PHP. Para la primera entrega se usa la versión en Python.

---

## Requisitos

- **Python 3.12 o superior** (probado con 3.14)
- **Git**
- Un navegador moderno
- Acceso a un cluster de **MongoDB Atlas**, con tu IP agregada en *Network Access*
- *(Opcional)* Node.js, si el frontend migra a React + TypeScript

---

## Puesta en marcha rápida

### 1. Clonar el repositorio

```powershell
git clone <URL_DEL_REPOSITORIO>
cd <CARPETA_DEL_REPOSITORIO>
```

### 2. Backend

```powershell
cd backend
py -3.14 -m venv .venv                                  # o: python -m venv .venv
.venv\Scripts\python -m pip install -r requirements-dev.txt
copy .env.example .env                                  # luego completa los valores
```

Completa en `backend/.env`, como mínimo:

| Variable | Valor |
|---|---|
| `MONGO_URI` | Cadena `mongodb+srv://...` de Atlas |
| `JWT_SECRET` | Texto aleatorio de 32 caracteres o más. Genéralo con `python -c "import secrets; print(secrets.token_hex(32))"` |
| `FRONTEND_ORIGIN` | Dirección exacta desde la que abres el frontend (por ejemplo `http://127.0.0.1:5500` con Live Server) |
| `APP_ENV` | `development` |

Crea el primer usuario administrador:

```powershell
.venv\Scripts\python -m scripts.crear_superadmin
```

Arranca la API:

```powershell
.venv\Scripts\uvicorn app.main:app --reload --port 8001 --no-proxy-headers
```

Comprueba que funciona abriendo http://localhost:8001/api/v1/health. Debe responder `{"status":"ok","db":"ok"}`.

> ⚠️ **Nunca subas el archivo `.env` a Git.** Contiene la contraseña de la base de datos y el secreto de los tokens. El `.gitignore` ya lo excluye.

### 3. Frontend

<!-- TODO (Front-End): completar con los pasos reales del frontend. -->

1. Abre la carpeta `frontend/` en VS Code.
2. Sírvela con **Live Server**, o con el servidor que defina el equipo de frontend.
3. Configura la URL base de la API en el frontend: `http://localhost:8001/api/v1`.

Si el navegador muestra un error de **CORS**, revisa que `FRONTEND_ORIGIN` en `backend/.env` coincida exactamente con la dirección del frontend. `localhost` y `127.0.0.1` cuentan como orígenes distintos.

---

## Endpoints disponibles (fase 1)

| Método | Ruta | Descripción | Requiere login |
|---|---|---|---|
| GET | `/api/v1/health` | Estado de la API y de la base de datos | No |
| POST | `/api/v1/auth/login` | Iniciar sesión (`correo`, `password`) | No |
| GET | `/api/v1/auth/me` | Datos del usuario autenticado | Sí |
| POST | `/api/v1/auth/logout` | Cerrar sesión | Sí |
| POST | `/api/v1/auth/forgot-password` | Solicitar enlace de recuperación | No |
| POST | `/api/v1/auth/reset-password` | Restablecer contraseña con el token | No |
| GET | `/api/v1/home/slider` | Imágenes del carrusel de inicio | No |
| GET | `/api/v1/home/videos` | Videos de presentación | No |

Las rutas protegidas se llaman con la cabecera `Authorization: Bearer <token>`.

El contrato completo, con ejemplos de peticiones, respuestas y códigos de error, está en [`backend/docs/api.md`](backend/docs/api.md).

---

## Pruebas y calidad (backend)

```powershell
cd backend
.venv\Scripts\python -m pytest                 # todas las pruebas
.venv\Scripts\python -m pytest -m "not integration"   # sin conexión a Atlas
.venv\Scripts\ruff check .
.venv\Scripts\ruff format --check .
.venv\Scripts\mypy
```

---

## Equipo

| Rol | Integrante |
|---|---|
| Líder del proyecto | Andryw Yesid Barrera Camargo |
| Desarrollo Front-End | Rafael Esteban Lozano Vargas |
| Bases de Datos | Luisa Fernanda Merchán Rojas |
| Desarrollo Back-End | Nicolás Samuel Tinjaca Topia |

**Cliente:** Semillero de Investigación SIIIS — Escuela de Ingeniería de Sistemas y Computación, UPTC.