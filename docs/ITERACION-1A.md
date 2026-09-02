# Iteración 1A — Bootstrap

Fecha de cierre: 1 de septiembre de 2026.

## Alcance implementado

La primera vertical deja una base ejecutable para construir el dominio en la
iteración 1B, sin anticipar entidades ni flujos de migración.

### Aplicación

- Laravel 13.29 sobre PHP 8.4, servido por PHP-FPM y Nginx.
- React 19.2, TypeScript, Inertia.js 3, Tailwind CSS 4 y componentes shadcn/ui.
- Layout autenticado adaptable, navegación lateral, tema claro/oscuro/sistema y
  textos principales en español.
- Secciones autenticadas de Inicio, Proyectos, Manuales, Acerca de y
  Configuración. Proyectos es únicamente un placeholder y no introduce dominio
  de 1B.
- Dashboard de estado de 1A, sin proyectos ni ejecuciones simuladas.

### Autenticación y acceso

- Inicio/cierre de sesión, recuperación y cambio de contraseña, verificación de
  correo, passkeys y segundo factor provistos por Laravel Fortify.
- Registro público deshabilitado.
- Usuarios inactivos bloqueados tanto al autenticar como durante una sesión.
- Los correos se recortan y normalizan a minúsculas antes de validar, guardar y
  autenticar. Las variantes de capitalización no pueden crear duplicados.
- Una contraseña temporal obliga a cambiarla antes de entrar al resto de la
  aplicación.
- Comando interactivo e idempotente `php artisan app:create-admin` para crear o
  rehabilitar el administrador inicial.

### Roles

Se fijó un catálogo cerrado, sin permisos configurables:

| Rol        | Acceso actual en 1A                                                       |
| ---------- | ------------------------------------------------------------------------- |
| `ADMIN`    | Acceso general y administración de usuarios.                              |
| `OPERATOR` | Acceso autenticado; preparado para configurar y ejecutar proyectos en 1B. |
| `AUDITOR`  | Acceso autenticado; preparado para consulta de proyectos en 1B.           |

La administración inicial permite listar, crear, activar/desactivar y cambiar el
rol de usuarios. Se impide que un administrador se desactive o retire su propio
rol, y la comprobación del último administrador activo se protege dentro de una
transacción de base de datos.

La asignación de usuarios a proyectos se pospone deliberadamente: el modelo
`Project` pertenece a 1B y no existe todavía.

### Infraestructura Docker

| Servicio           | Función                            | Puerto del host |
| ------------------ | ---------------------------------- | --------------- |
| `nginx`            | Entrada HTTP                       | `8080`          |
| `app`              | Laravel/PHP-FPM                    | interno `9000`  |
| `vite`             | Frontend y HMR                     | `5173`          |
| `queue-worker`     | Worker Redis de Laravel Queue      | interno         |
| `reverb`           | Servidor WebSocket                 | `8081`          |
| `postgres`         | PostgreSQL 17                      | `5432`          |
| `postgres-testing` | PostgreSQL 17 efímero para PHPUnit | interno         |
| `redis`            | Colas y caché                      | `6379`          |
| `mailpit`          | SMTP y bandeja web local           | `1025` / `8025` |

Los datos de PostgreSQL y Redis usan volúmenes nombrados. La base de testing usa
`tmpfs` y se recrea con el contenedor. Las dependencias de Composer y
`node_modules` se instalan durante el build y se copian automáticamente a
volúmenes vacíos, por lo que no dependen del host. El servicio PHP-FPM normaliza
al arrancar los permisos de `storage/` y `bootstrap/cache/` para que el montaje
de Windows sea funcional.

`BaseLine/` se monta explícitamente como solo lectura en Nginx, PHP-FPM, Vite,
Reverb y el worker. La comprobación reproducible está en
`tests/Infrastructure/verify-baseline-readonly.ps1`. La prueba complementaria
`tests/Infrastructure/verify-baseline-integrity.ps1` comprueba los 131 archivos
contra la huella canónica previa a esta intervención. La prueba de solo lectura
comprueba primero que cada contenedor está activo y puede acceder al directorio;
solo acepta como resultado válido el error `read-only file system` al intentar
escribir, evitando falsos positivos si un servicio está detenido.

El workflow de CI construye y levanta la misma composición Docker del entorno
local. De esta forma usa PHP 8.4 y las dos instancias PostgreSQL declaradas por el
proyecto, sin depender del PHP, Node.js ni bases de datos instalados en el runner.

Reverb y el worker están en ejecución, pero 1A no publica eventos funcionales ni
despacha trabajos del dominio; eso comenzará en iteraciones posteriores.

## Migraciones presentes

- Tablas base de usuarios, caché, sesiones y jobs.
- Passkeys y columnas de autenticación de dos factores.
- Campos `role`, `is_active` y `must_change_password` en usuarios.

No existen migraciones para `Project`, `Server`, `MoodleInstance`, `Execution`,
`ExecutionStep`, `Artifact`, `Audit` ni ninguna otra entidad de 1B.

## Verificación realizada

| Comprobación                            | Resultado                                                        |
| --------------------------------------- | ---------------------------------------------------------------- |
| Reconstrucción destructiva              | `down -v`, sin dependencias locales, `up -d --build` aprobado    |
| Aplicación y pantalla `/login`          | HTTP 200; módulos Vite cargados sin errores CORS ni de consola   |
| Migraciones                             | 6 aplicadas en PostgreSQL                                        |
| Administrador inicial                   | Segunda ejecución actualiza; queda exactamente un `ADMIN` activo |
| PHPUnit                                 | 45 pruebas, 172 aserciones, todas aprobadas                      |
| Base PostgreSQL de PHPUnit              | `current_database() = moodle_toolkit_testing`                    |
| Laravel Pint                            | Aprobado sobre el código de la plataforma                        |
| Larastan/PHPStan                        | 0 errores                                                        |
| TypeScript `tsc --noEmit`               | Aprobado                                                         |
| ESLint con cero advertencias permitidas | Aprobado                                                         |
| Vite+ format/lint                       | 75 archivos formateados; 66 archivos sin advertencias            |
| Build de producción                     | Aprobado                                                         |
| `docker compose config --quiet`         | Aprobado                                                         |
| Servicios Docker                        | 9 servicios `healthy`, incluido worker, Reverb, app y Nginx      |
| `BaseLine/`                             | Accesible y escritura rechazada por `read-only` en 5 servicios   |
| Log Laravel tras recorrido normal       | 0 bytes; 0 errores nuevos                                        |

PHPUnit se ejecuta contra `moodle_toolkit_testing` en `postgres-testing`; la
configuración de pruebas no utiliza SQLite.

## Límite explícito de esta entrega

No se implementó 1B. Por tanto, esta entrega no incluye proyectos, servidores,
instancias Moodle, ejecuciones, etapas, artefactos, auditoría, wizard, adaptadores
de herramientas ni integración con el contenido de `BaseLine/`.

`BaseLine/` conserva 131 archivos y fue contrastada por SHA-256 con los ZIP y
fuentes originales: cero diferencias en Consolidador, Recolector, Integrador y su
README raíz.
