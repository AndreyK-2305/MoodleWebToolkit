# Iteración 1G — Calidad y cierre de la vertical simulada

Estado: validación integral aprobada. La entrega permanece en un PR en borrador contra `main`, sin merge.

## Resultados verificados

La [CI de la implementación](https://github.com/AndreyK-2305/MoodleWebToolkit/actions/runs/34795882061), ejecutada sobre `bf066681118555e56970ac4079b403730808951e`, terminó correctamente con las siguientes puertas. El PR incorpora además la comprobación de CI del commit final de documentación.

| Puerta                                                  | Resultado                                                                                           |
| ------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| Compose de desarrollo y de calidad                      | Ambos válidos                                                                                       |
| Instalación limpia y migraciones                        | Diez servicios saludables simultáneamente; imágenes sin montajes del código o dependencias del host |
| Pint y PHPStan                                          | Aprobados, sin errores                                                                              |
| PHPUnit con PostgreSQL, Redis y procesos independientes | 242 pruebas, 2224 aserciones                                                                        |
| Vitest                                                  | 5 pruebas aprobadas                                                                                 |
| Formato y lint                                          | Aprobados, sin advertencias ni errores                                                              |
| TypeScript de aplicación y E2E; build                   | Aprobados                                                                                           |
| BaseLine                                                | 131 archivos íntegros; seis montajes de solo lectura verificados                                    |
| `git diff --check`                                      | Aprobado                                                                                            |
| Playwright                                              | 42 casos aprobados, cero reintentos, 4,1 minutos                                                    |
| Limpieza                                                | Entorno exclusivo desmontado correctamente                                                          |

Las regresiones conservan las expectativas de las iteraciones anteriores. Las correcciones de producto se limitan a la censura de secretos en logs/eventos y a impedir que peticiones HTTP concurrentes de una sesión sobrescriban una confirmación reciente de contraseña. El resto incorpora o corrige infraestructura, auxiliares y cobertura de calidad de la vertical simulada.

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

Puertas: ambos Compose, instalación limpia, diez healthchecks, migraciones, Pint, PHPStan, PHPUnit sobre PostgreSQL, Vitest, formato/lint, TypeScript de aplicación y E2E, build, integridad y seis montajes de BaseLine solo lectura, `git diff --check` y Playwright. La CI ejecuta el mismo script sobre el SHA de la rama. Los informes quedan en `quality-results/`; trazas, capturas y vídeos se conservan solo cuando falla un caso. La CI retiene la evidencia de fallo durante tres días. La ejecución recorre los 42 casos, sin reintentos automáticos; aprobar exige que todos pasen.

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

El caso prolongado elimina la cola Redis y recupera los mismos comandos desde PostgreSQL antes de continuar. El caso dedicado a Reverb desactiva solo el temporizador de polling; conserva las consultas HTTP provocadas por las notificaciones WebSocket, que son el mecanismo real con el que React carga el estado autoritativo. La caducidad se aplica al formato JSON de sesiones configurado por la aplicación.

## Concurrencia y privacidad

Se amplían las barreras PostgreSQL multiproceso para confirmación, resolución, reanudación y cancelación; se mantienen las pruebas existentes de secuencias, inicio y finalización. Solo la prueba de cancelación difiere el despacho para observar CANCELLING antes de consumirlo.

Una regresión adicional usa dos procesos con el middleware real de sesión, PostgreSQL y locks de Redis. Retiene una petición de observación mientras otra confirma la contraseña: sin bloqueo, la primera sobrescribe la confirmación con el timestamp caducado. Se reprodujo el fallo con la estructura JSON anidada real y después pasó con ocho aserciones al activar el bloqueo de sesiones de Laravel. La serialización afecta solo a peticiones HTTP de la misma sesión; los jobs y las pruebas de concurrencia de dominio siguen siendo independientes.

Una regresión demostró que secretos sintéticos llegaban a eventos persistidos antes de la corrección. Ahora logs y eventos censuran mensajes y estructuras al guardar; el presentador y el broadcast protegen también registros heredados. El catálogo cubre claves compuestas, consultas URL codificadas y bloques PEM, conservando etiquetas no sensibles. La prueba heredada de truncamiento inserta expresamente un registro anterior a esta protección y mantiene sus aserciones de bytes y SHA-256 originales.

Se conservan las regresiones de exportación por streaming, presupuestos por unidad, cursores, recuperación, promoción atómica, integridad, traversal y enlaces simbólicos de 1F. No se introducen conexiones ni migraciones Moodle reales.

El E2E respeta el contrato previo de finalización: repetir `finalize` devuelve HTTP 200 con `created=false` y el resultado COMPLETED existente. Comprueba que no aparecen otro comando, nuevos eventos, artefactos ni timestamps. Las mutaciones de cancelación, validación y propuestas siguen rechazadas. También se corrigen expectativas del auxiliar sobre la redirección al destino solicitado, logout JSON 204, el orden de instancias por rol y los eventos creados por el worker después de reanudar.

Las trazas confirmaron HTTP 429 en login cuando casos independientes acumulaban el límite de cinco solicitudes por minuto en Redis. El reset exclusivo de calidad vacía su caché antes de cada caso; el limitador de la aplicación permanece activo y sin cambios.

El auxiliar de expiración respeta las claves anidadas de la sesión usando `Arr::set`; una clave plana con puntos ocultaba indebidamente las confirmaciones posteriores. El navegador espera la respuesta del reintento antes de consultar los comandos persistidos. La autorización de canales toma los datos del script JSON de Inertia 3 servido con la página, sin enviar una solicitud Inertia incompleta que provoque un conflicto de versión.

## Evidencia y limitaciones del entorno local

Docker Desktop no pudo iniciar en este host por un error de acceso a `dockerInference`. La línea base y las regresiones PHP se ejecutaron en contenedores Podman aislados; la VM dispone de 898 MiB y no tiene swap. La validación integrada de diez servicios y navegador quedó acreditada por la CI enlazada arriba.

Los ciclos iniciales expusieron los defectos de infraestructura y auxiliares descritos en este documento; sus resultados parciales no se usan como evidencia del cierre. La regresión local de concurrencia de sesión pasó con ocho aserciones y la suite de autenticación completa con 41 pruebas y 174 aserciones. El recorrido CLI local recuperó una ejecución después de perder Redis y avanzar más de 24 horas, usando PIDs distintos, y generó cuatro artefactos con timestamps de cierre semánticamente iguales. La CI final de implementación aprobó conjuntamente todas las pruebas, incluido el recorrido equivalente en navegador.

La primera instalación identificó un healthcheck de Nginx que resolvía localhost por IPv6; se usa explícitamente 127.0.0.1. La siguiente detectó que el proceso padre de PHPUnit heredaba variables de la base E2E mientras sus hijos usaban las de PHPUnit. El lanzador aplica ahora las variables de `phpunit.xml` antes de arrancar PHP, sin modificar las expectativas de las pruebas. La imagen crea un `.env` vacío para las utilidades Laravel que requieren que exista; los secretos siguen llegando por el entorno efímero.

Se reprodujo además un HTTP 500 al registrar una excepción esperada: PHPUnit había creado el log compartido como root y PHP-FPM no podía escribirlo. Los escritores PHP de calidad (PHPUnit, auxiliares CLI, worker, scheduler y Reverb) usan ahora `www-data`, igual que las peticiones web. La misma excepción volvió a registrarse correctamente después de corregir los permisos en el contenedor aislado; las 11 regresiones de privacidad pasaron como `www-data` con 36 aserciones. La creación inicial de `app` se realiza antes que la de los demás consumidores para evitar la carrera de Docker al poblar el volumen compartido.

BaseLine conserva 131 archivos con SHA-256 canónico `5a996439d8432e13abecbc4ebf57f12654d15e14afef8b1160fe55dcf82ae1d3`. No se modifica, ni se implementa la Iteración 2. El PR #6 está publicado como borrador contra main, sin merge.
