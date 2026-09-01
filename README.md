# Moodle Consolidation Toolkit — Plataforma web

Plataforma de administración para el kit de consolidación de instancias Moodle.
El repositorio contiene actualmente **solo la iteración 1A (Bootstrap)** del Plan
Maestro: infraestructura, autenticación, layout, tema y roles base.

La implementación y los resultados de validación están documentados en
[`docs/ITERACION-1A.md`](docs/ITERACION-1A.md).

## Stack disponible

- Laravel 13 sobre PHP 8.4 y Nginx.
- React 19, TypeScript, Inertia.js 3 y Tailwind CSS 4.
- PostgreSQL 17, Redis 7.4, Laravel Queue y Laravel Reverb.
- Mailpit para correo SMTP local.
- Docker Compose como entorno reproducible de desarrollo.

## Puesta en marcha

Requisitos: Docker Desktop con Docker Compose.

```powershell
docker compose up -d --build
docker compose exec app php artisan migrate --force
docker compose exec app php artisan app:create-admin
```

La imagen instala las dependencias fijadas por `composer.lock` y
`package-lock.json`. Si `.env` no existe, el contenedor `app` copia
`.env.example` y genera una clave local antes de iniciar PHP-FPM. No se necesita
PHP, Composer ni Node instalados en Windows.

Servicios principales:

- Aplicación: <http://localhost:8080>
- Vite/HMR: <http://localhost:5173>
- Reverb/WebSocket: `ws://localhost:8081`
- Mailpit: <http://localhost:8025>

Para detener el entorno sin borrar datos:

```powershell
docker compose down
```

## Calidad

```powershell
docker compose exec app php artisan test
docker compose exec app vendor/bin/pint --test
docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec vite npm run types:check
docker compose exec vite npm run lint
docker compose exec vite npm run build
docker compose config --quiet
powershell -File tests/Infrastructure/verify-baseline-readonly.ps1
powershell -File tests/Infrastructure/verify-baseline-integrity.ps1
```

PHPUnit usa exclusivamente el servicio PostgreSQL efímero
`postgres-testing`, con la base `moodle_toolkit_testing`. SQLite no participa en
la suite.

`BaseLine/` contiene los entregables existentes del Recolector, Consolidador e
Integrador. Está excluida de Pint y no forma parte del código que modifica la
plataforma web. Los servicios que pueden verla la montan explícitamente como
solo lectura; la prueba de infraestructura anterior debe rechazar cualquier
intento de escritura.
