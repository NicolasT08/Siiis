# CLAUDE.md — SIIIS V3

Guía para agentes que trabajen en este repositorio (sitio web del Semillero SIIIS, UPTC).

## Fuente de verdad

- Spec: `docs/SIIIS_V3_SPEC_DRIVEN.md` (Spec-Driven Development). Leerlo antes de codificar.
- Instrucciones de la fase actual: `docs/PROMPT_CLAUDE_CODE_BACKEND_FASE1.md`.
- Decisiones pendientes/propuestas: `docs/decisions.md`. Contrato para frontend: `docs/api.md`.

## Stack

- Backend (`backend/`): PHP 8.2+, Slim 4 + slim/psr7, Composer, PSR-4 `Siiis\` → `backend/src/`, PSR-12,
  `declare(strict_types=1);` en todos los archivos.
- MongoDB Atlas vía extensión `mongodb` + `mongodb/mongodb`. JWT con `firebase/php-jwt` (HS256).
  Correo con PHPMailer (SMTP Gmail). Multimedia en Cloudinary (en fase 1 solo se leen URLs).
- Frontend (`frontend/`): React + TypeScript, lo desarrolla otro integrante. **No tocarlo.**
- Hosting: Hostinger plan básico (compartido): sin procesos persistentes, rutas vía `.htaccess`,
  código/`vendor`/`.env`/`storage` fuera de `public_html`.

## Comandos (desde `backend/`)

```bash
composer install          # dependencias
composer serve            # php -S localhost:8000 -t public  → http://localhost:8000/api/v1/health
composer test             # PHPUnit (integración se salta sin MONGO_URI; usa MONGO_DB_NAME_TEST)
composer lint             # php-cs-fixer --dry-run (PSR-12)
composer analyse          # phpstan nivel 6
php scripts/crear_indices.php
php scripts/crear_superadmin.php
php scripts/seed_multimedia.php
```

## Reglas no negociables

1. Solo se trabaja en `backend/` y `docs/`. Nunca en `frontend/`.
2. No inventar reglas de negocio: lo no definido se registra como `DECISION_REQUIRED` / `TODO_SPEC` /
   `PROPUESTA` en `docs/decisions.md` y se continúa con lo independiente.
3. No cambiar el modelo de datos (spec 8.2): colecciones y campos exactos, con tildes y mayúsculas,
   centralizados en `Siiis\Database\Collections`.
4. Ningún secreto en Git: solo `.env.example`. El `.env` real nunca se versiona.
5. Commits pequeños, uno por paso, mensajes en español. Tras cada paso: `composer test`, `composer lint`,
   `composer analyse` sin errores antes de avanzar.
6. Controladores delgados; consultas MongoDB solo en `src/Repositories/`. Respuestas JSON vía
   `JsonResponse` y `Serializer` (nunca exponer `password`, `token_recuperacion`, `token`, `public_id`).
7. Antiinyección NoSQL: castear a string/int toda entrada antes de filtrar; rechazar arrays/objetos
   donde se espera texto.
8. El rol se toma del JWT/BD, nunca del cuerpo de la petición. JWT máximo 8 h (RNF-05).
9. Errores globales en JSON `{"error":{"code","message"}}`, sin trazas si `APP_DEBUG=false`.
10. Login con Google está fuera de la fase 1: no implementarlo ni registrar su ruta.
11. Detenerse y preguntar si: falta una extensión PHP, no conecta Atlas, el spec contradice el prompt,
    se requiere cambiar colecciones/campos, o una decisión bloquea sin trabajo independiente.
