# Iteración 1G — Calidad y cierre de la vertical simulada

Estado: implementación en validación. Las comprobaciones pendientes no constituyen evidencia de aprobación.

## Punto de partida

Rama solicitada: `1g/calidad-cierre`, creada desde `main` en `f70f1915ad4034d36dc3f4423c2fc1867dcbb41f`, que incorpora 1F mediante el PR #5.
La [CI de main](https://github.com/AndreyK-2305/MoodleWebToolkit/actions/runs/34700955523) aprobó 227 pruebas PHP (2111 aserciones), cinco pruebas Vitest y las puertas anteriores. Antes de editar se reprodujeron localmente las 227 pruebas PHP, separando las dos que necesitan Reverb, y las cinco pruebas Vitest.

## Ejecución reproducible

Requisitos: Docker con Compose y PowerShell 7. No requiere PHP, Node ni navegadores instalados en el host.

```powershell
pwsh -File tests/Infrastructure/run-quality.ps1
```

El script genera un proyecto exclusivo `mt1g-*` y rechaza prefijos con recursos existentes. Construye imágenes con los archivos lock, sin montar dependencias, código, `.env` ni datos de desarrollo. Genera secretos efímeros, exige diez servicios saludables y desmonta solamente el proyecto que acaba de crear. Las bases de pruebas utilizan tmpfs y el almacenamiento de artefactos tiene un volumen exclusivo. No se publican puertos del host.

Los diez servicios son PostgreSQL de navegador, PostgreSQL de PHPUnit, Redis, aplicación, worker, scheduler, Reverb, Vite, Mailpit y Nginx. Playwright se ejecuta en un contenedor adicional al terminar las puertas. Comparte la red de Nginx para acceder a HTTP y WebSocket mediante localhost.

Puertas: ambos Compose, instalación limpia, diez healthchecks, migraciones, Pint, PHPStan, PHPUnit sobre PostgreSQL, Vitest, formato/lint, TypeScript de aplicación y E2E, build, integridad y seis montajes de BaseLine solo lectura, `git diff --check` y Playwright. La CI ejecuta el mismo script. Los informes quedan en `quality-results/`; trazas, capturas y vídeos se conservan solo cuando falla un caso. La CI retiene la evidencia de fallo durante tres días.

## Cobertura de navegador

Los escenarios usan usuarios y contraseñas sintéticos generados para cada prueba. El auxiliar CLI rechaza cualquier ejecución fuera de `APP_ENV=testing`, `QUALITY_HARNESS=1` y la base exclusiva `moodle_toolkit_e2e`; no registra rutas web.

| Área              | Comprobaciones                                                                                                                                            |
| ----------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Autenticación     | Credenciales erróneas y válidas, cierre de sesión, inactivos, contraseña temporal, navegación y temas                                                     |
| Wizard            | COLLECT, CONSOLIDATE e INTEGRATE; SUCCESS, WARNING y ERROR; recargas, navegación, URLs, sustitución e intercambio de nombres, invalidación e idempotencia |
| Ejecución         | Tres verticales completas, doble clic y solicitudes concurrentes, conflictos WARNING/INTERVENTION, fallo y checkpoint, cancelación cooperativa            |
| Tiempo real       | Reverb real, reinicio, corte de red, nueva pestaña, recarga y polling sin WebSocket                                                                       |
| Autorización      | ADMIN, OPERATOR asignado y ajeno, AUDITOR; configuración, inicio, usuarios, consulta, canales, resolución, reanudación, cierre y descarga                 |
| Sesión temporal   | Expiración, contraseña incorrecta, reconfirmación conservando payload y clave, remember-me, revocación de rol/asignación/actividad                        |
| Duración          | Avance de reloj superior a 24 horas entre procesos worker distintos, cierre del navegador, sesión nueva y cierre de la misma ejecución                    |
| Revisión y cierre | Propuestas inválidas y válidas, validación, cierre por varias unidades, cuatro artefactos, hashes y descargas, inmutabilidad de COMPLETED                 |

El coordinador de pruebas inicia procesos independientes que ejecutan `queue:work` contra Redis real. Permite observar estados intermedios y avanzar el reloj de Carbon por proceso; no sustituye los jobs ni sus servicios de dominio. La simulación de más de 24 horas verifica persistencia y expiración lógica, no constituye una prueba de carga de 24 horas reales.

## Concurrencia y privacidad

Se amplían las barreras PostgreSQL multiproceso para confirmación, resolución, reanudación y cancelación; se mantienen las pruebas existentes de secuencias, inicio y finalización. Solo la prueba de cancelación difiere el despacho para observar CANCELLING antes de consumirlo.

Una regresión demostró que secretos sintéticos llegaban a eventos persistidos antes de la corrección. Ahora logs y eventos censuran mensajes y estructuras al guardar; el presentador y el broadcast protegen también registros heredados. El catálogo cubre claves compuestas, consultas URL codificadas y bloques PEM, conservando etiquetas no sensibles. La prueba heredada de truncamiento inserta expresamente un registro anterior a esta protección y mantiene sus aserciones de bytes y SHA-256 originales.

Se conservan las regresiones de exportación por streaming, presupuestos por unidad, cursores, recuperación, promoción atómica, integridad, traversal y enlaces simbólicos de 1F. No se introducen conexiones ni migraciones Moodle reales.

## Evidencia y limitaciones del entorno local

Docker Desktop no inicia en este host por un error de acceso a `dockerInference`. La línea base y las regresiones PHP se ejecutaron en contenedores Podman aislados; la VM dispone de 898 MiB y no tiene swap. La validación integrada de diez servicios y navegador debe quedar acreditada por la CI antes de declarar el cierre.

BaseLine conserva 131 archivos con SHA-256 canónico `5a996439d8432e13abecbc4ebf57f12654d15e14afef8b1160fe55dcf82ae1d3`. No se modifica, ni se implementa la Iteración 2. El PR se publicará como borrador contra main, sin merge.
