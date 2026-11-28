# Plan — SIIIS V3

## Estado

- Backend fases 1 a 4 terminadas; frontend integrado (ver `README.md`, `docs/decisions.md`).
- Auditoría previa al despliegue (2026-11-28): `docs/trazabilidad-requisitos.md` y `docs/auditoria-calidad.md`.

## Plan aprobado (2026-11-28) — correcciones antes de desplegar

1. [x] A-01 Respaldo: `backend/scripts/respaldar_base.php` (Extended JSON, `RESPALDO_DIR`, `RESPALDO_MAX`), docs y cron.
2. [x] A-03 Cabeceras y caché en `.htaccess` raíz y `assets/.htaccess` del frontend (compatibles con LiteSpeed).
3. [x] A-02 Error boundary global con recarga única ante fallo de chunk.
4. [x] A-04 / RNF-05 Cierre de sesión a las 8 h con `expira_en`, sesión vencida al abrir y 401.
5. [x] M-01 `connectTimeoutMS` y `socketTimeoutMS` configurables en `Mongo.php`.
6. [x] M-03 Rate limit en `POST /auth/reset-password`.
7. [x] RF-11 Campo opcional "Hoja de vida (PDF)" en la inscripción; visible en el panel.
8. [x] RF-05 Columna "Artículos de interés" y previsualización en el perfil del integrante.

Pruebas: solo las nuevas (respaldo contra `siiis_test`, rate limit de reset) + lint/analyse del backend y lint/build
del frontend. Los cambios de `frontend/` los aprobó el usuario explícitamente para este plan.

## Verificación (2026-11-28)

- `PasswordResetApiTest` (rate limit de reset y casos relacionados) y `RespaldoIntegrationTest` (contra `siiis_test`): OK.
- `composer lint` y `composer analyse`: sin errores. `npm run lint` y `npm run build`: sin errores (JS inicial 102 KB gzip).
- Lógica de vencimiento (`frontend/src/lib/sesion.ts`): comprobada con un script ad hoc (el frontend no tiene framework de pruebas).
- Pendiente en Hostinger: comprobar las cabeceras con `curl -I` (`docs/deployment.md`, "Comprobar cabeceras"), registrar los cron y volver a generar `deploy/` con `backend/scripts/empaquetar.ps1`.
- Nota: en el `.env` local, `MONGO_DB_NAME_TEST` es igual a `MONGO_DB_NAME`; corregirlo a `siiis_test` para que `--test` y las pruebas de integración funcionen sin forzar la variable.

## Pendiente después

Ver "Puede esperar" en `docs/auditoria-calidad.md`.
