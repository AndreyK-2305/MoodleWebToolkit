# Iteración 1C — Wizard persistente

Fecha de entrega: 2 de septiembre de 2026.

Rama: `codex/1c-wizard`.

Base aprobada: `68698a8589e97d8e4dfb7a13ab6c61404bce3e81`
(`codex/1b-dominio`).

## Alcance implementado

La entrega sustituye el placeholder de Proyectos por una interfaz persistente
para crear, listar, consultar y continuar proyectos. El wizard contiene los
cinco pasos acordados:

1. tipo de operación y datos básicos;
2. instancias simuladas;
3. configuración funcional;
4. preflight simulado;
5. confirmación.

Se implementaron los flujos `COLLECT`, `CONSOLIDATE` e `INTEGRATE` con sus
cardinalidades y restricciones de destino. La confirmación válida deja el
proyecto en `READY` y termina el alcance de 1C.

No se implementó 1D: el wizard no crea `Execution` ni `ExecutionCommand`, no
inserta jobs, no despacha trabajo asíncrono, no publica eventos funcionales y
no cambia el proyecto a `QUEUED`. Tampoco realiza conexiones SSH/SFTP ni recibe
contraseñas, tokens, claves privadas u otros secretos.

## Decisiones de dominio y persistencia

Se reutilizaron las entidades de 1B sin añadir tablas ni migraciones:

- `Project` continúa siendo la raíz del agregado y conserva el grafo de estados
  aprobado;
- `ProjectConfiguration.settings` guarda un esquema JSONB versionado con
  `wizard_step`, opciones específicas por tipo, preflight y confirmación;
- `Server` y `MoodleInstance` materializan exclusivamente las referencias
  simuladas. Los destinos son referencias a infraestructura preparada o
  existente; la aplicación no afirma crearlos;
- `ProjectAssignment` asigna automáticamente al OPERATOR creador dentro de la
  misma transacción de creación. ADMIN conserva acceso global y no recibe una
  asignación redundante;
- `AuditLog` registra creación, cambios, preflight, aceptación de advertencias y
  confirmación sin incluir datos sensibles.

Las modificaciones indispensables al modelo de 1B se limitaron a anotaciones de
tipos para `settings` y `metadata`, y a etiquetas de presentación para los enums
de tipo y estado. No se alteró el esquema persistente de 1B.

Cada cambio relevante incrementa `ProjectConfiguration.version` e invalida el
preflight y la confirmación anteriores. Además de comparar la versión, la
confirmación calcula una huella SHA-256 determinista de datos básicos,
instancias, servidores simulados y opciones. Así se rechaza también un preflight
obsoleto si algún dato fue modificado fuera del servicio sin incrementar la
versión.

Las operaciones de escritura toman locks sobre proyecto, configuración y, para
usuarios no administradores, su asignación. La autorización se comprueba tanto
en el controlador como dentro de la transacción del servicio. Los estados
`REVIEW` y `COMPLETED` siguen bloqueando el wizard; editar un proyecto `READY`
lo devuelve a `CONFIGURING` e invalida la confirmación.

## Reglas de los tres tipos

| Tipo       | Instancias exigidas al confirmar               | Destino                                                       |
| ---------- | ---------------------------------------------- | ------------------------------------------------------------- |
| Recolectar | exactamente un origen                          | no admite destino                                             |
| Consolidar | dos o más orígenes y exactamente un destino    | Moodle preparado y validado, sólo como referencia             |
| Integrar   | exactamente un origen y exactamente un destino | Moodle consolidado existente y validado, sólo como referencia |

El paso de instancias admite guardar un conjunto incompleto como borrador, pero
rechaza exceso de cardinalidad, roles o tipos de destino incompatibles,
duplicados y UUID de instancias o servidores pertenecientes a otro proyecto.
El preflight y la confirmación exigen la composición completa.

## Preflight y confirmación

El preflight produce una lista persistida de comprobaciones con `id`,
`description`, `result` y `detail`. Los escenarios deterministas permiten
demostrar:

- `SUCCESS`: todas las comprobaciones son satisfactorias;
- `WARNING`: `simulation.capacity` requiere aceptación explícita;
- `ERROR`: `simulation.connectivity` impide la confirmación.

También se comprueban completitud, cardinalidad, condición del destino,
pertenencia de recursos al proyecto y ausencia de secretos. La confirmación
revalida en backend la configuración, versión, huella, ausencia de errores y la
igualdad exacta entre advertencias vigentes y aceptadas.

Cuando hay advertencias, `PROJECT_WARNINGS_ACCEPTED` conserva actor, proyecto,
identificadores aceptados y versión. La acción posterior
`PROJECT_CONFIGURATION_CONFIRMED` y el cambio a `READY` ocurren en la misma
transacción. Repetir una confirmación ya válida es idempotente y no duplica
auditoría ni crea efectos de ejecución.

## Correcciones posteriores a revisión

La revisión del SHA `4c0a65c` identificó tres casos no cubiertos por la primera
entrega. Se corrigieron sin ampliar el alcance hacia 1D:

- la sincronización resuelve primero todas las referencias, elimina el conjunto
  descartado y usa nombres transitorios únicos para los registros conservados
  antes de aplicar el estado final. Esto permite reemplazar una instancia
  conservando sus nombres e intercambiar nombres entre instancias o servidores
  sin violar los índices únicos de PostgreSQL;
- `base_url` admite como máximo 255 caracteres, exactamente la longitud de la
  columna existente. Se comprobaron los límites de 255 aceptado y 256 rechazado
  mediante un error de formulario controlado;
- las URL con usuario o contraseña incrustados se rechazan tanto en la ruta como
  en el servicio de dominio. `simulation.no_secrets` inspecciona los datos
  persistidos y devuelve `ERROR` si encuentra un registro legado inseguro; su
  valor tampoco se entrega en las propiedades Inertia, incluidas las consultas
  de AUDITOR.

## Permisos comprobados

| Rol        | Comportamiento en 1C                                                                                |
| ---------- | --------------------------------------------------------------------------------------------------- |
| `ADMIN`    | lista y configura todos los proyectos; conserva administración global de asignaciones               |
| `OPERATOR` | crea proyectos y configura sólo los asignados; queda asignado únicamente a su propio proyecto nuevo |
| `AUDITOR`  | lista y consulta sólo proyectos asignados; no crea, modifica, ejecuta preflight ni confirma         |

La ausencia o manipulación de controles en la interfaz no amplía permisos: las
rutas directas de datos básicos, instancias, opciones, preflight y confirmación
están protegidas en el backend.

## Verificación en navegador

Se utilizó el navegador integrado con selectores semánticos sobre la aplicación
local. Para evitar que el navegador bloqueara el puerto HMR, se verificó la UI
contra los assets de producción generados y después se restauró el archivo
`public/hot` original.

Recorrido real comprobado:

1. inicio de sesión como OPERATOR temporal;
2. listado y creación de un proyecto de consolidación;
3. guardado de dos orígenes y un destino preparado;
4. recarga de la página con recuperación del paso, versión y campos guardados;
5. navegación hacia atrás y adelante entre configuración y preflight;
6. preflight determinista con advertencia de capacidad;
7. error visible al confirmar sin aceptar la advertencia;
8. aceptación explícita y transición a `READY`;
9. nueva recarga conservando `READY` y el aviso de que la ejecución pertenece a
   1D.

La consola del navegador terminó sin errores ni advertencias. El usuario y el
proyecto temporales fueron eliminados al finalizar y se comprobó que no existía
ninguna ejecución asociada.

## Pruebas de aceptación

`ProjectWizardTest` añade 18 pruebas y 254 aserciones que cubren:

- creación y configuración de los tres tipos;
- asignación atómica del OPERATOR y ausencia de asignación redundante para
  ADMIN;
- persistencia del paso y los datos al volver a abrir el proyecto;
- reemplazo conservando nombres e intercambio de nombres de instancias y
  servidores;
- límites 255/256 de URL y rechazo de credenciales incrustadas;
- preflight efectivo y serialización segura ante un registro legado inseguro;
- borradores incompletos, cardinalidades inválidas, destinos incompatibles y
  referencias entre proyectos;
- acceso de AUDITOR asignado, denegación de escritura y denegación total a no
  asignados;
- bloqueo por `ERROR`, aceptación y auditoría de `WARNING`;
- invalidación por cambios y rechazo mediante versión o huella obsoleta;
- transición idempotente a `READY`;
- ausencia de nuevas filas en `executions`, `execution_commands` y `jobs`;
- protecciones HTTP de `REVIEW` y `COMPLETED`;
- visibilidad del listado por rol.

## Comprobaciones locales ejecutadas

| Comando o comprobación                                                     | Resultado real                                                                                    |
| -------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| `php artisan migrate:fresh` con variables explícitas de `postgres-testing` | 9 migraciones aplicadas desde cero exclusivamente en la base aislada                              |
| `php artisan test`                                                         | 133 pruebas, 630 aserciones, aprobado                                                             |
| `composer lint:check`                                                      | 134 archivos, aprobado                                                                            |
| `composer types:check`                                                     | 103 archivos, 0 errores                                                                           |
| `npm run check`                                                            | 78 archivos con formato correcto; 67 sin warnings ni errores                                      |
| `npm run lint`                                                             | aprobado, cero warnings permitidos                                                                |
| `npm run types:check`                                                      | aprobado                                                                                          |
| `npm run build`                                                            | 2313 módulos transformados, aprobado                                                              |
| `docker compose config --quiet`                                            | aprobado                                                                                          |
| Integridad de `BaseLine/`                                                  | 131 archivos; huella `5a996439d8432e13abecbc4ebf57f12654d15e14afef8b1160fe55dcf82ae1d3`           |
| Sólo lectura de `BaseLine/`                                                | escritura rechazada por filesystem read-only en `app`, `queue-worker`, `reverb`, `vite` y `nginx` |

La recreación destructiva se dirigió únicamente a
`moodle_toolkit_testing`/`postgres-testing`; no se reiniciaron ni eliminaron los
volúmenes de la base de desarrollo.

## Limitaciones y pendiente de 1D

Todo el preflight es intencionalmente determinista y simulado. Los hosts y URL
son metadatos descriptivos, no conexiones. La interfaz de administración de
asignaciones no forma parte de este corte; se conservan la entidad, policy y
servicio aprobados en 1B.

Para 1D quedan `FakeToolAdapter`, contratos y proveedores de herramientas,
creación idempotente de ejecuciones desde una acción explícita, jobs y colas,
`dispatchAfterCommit`, eventos en tiempo real y broadcasting funcional. Ninguno
de esos componentes se inició en esta rama.

## GitHub Actions

El SHA final, el PR en borrador y la ejecución de GitHub Actions se consignan en
la entrega final porque el documento forma parte del mismo commit que debe
evaluar CI.
