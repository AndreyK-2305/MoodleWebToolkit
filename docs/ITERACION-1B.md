# Iteración 1B — Dominio

Fecha de auditoría: 1 de septiembre de 2026.

Rama auditada: `codex/1b-dominio`.

## Estado de aceptación

La revisión posterior al primer CI detectó tres defectos adicionales y reabrió
la aceptación de 1B. Este documento incorpora sus correcciones y regresiones,
pero el veredicto final exige repetir toda la aceptación local y GitHub Actions
sobre el nuevo SHA. El resultado remoto y sus enlaces se consignan en la entrega
final, una vez que ese SHA existe.

La revisión se limitó al dominio persistente de 1B. No se implementaron el
Wizard, `FakeToolAdapter`, jobs de ejecución, ejecución de herramientas,
broadcasting funcional, SSH/SFTP ni ninguna función de 1C o de cortes
posteriores.

## Inventario de las 18 entidades

El reporte anterior indicó 17 entidades por un error de conteo en el texto. La
lista y el código ya contenían las 18 entidades del Plan Maestro. Ninguna fue
omitida ni absorbida de forma anónima por otra tabla.

`ProjectAssignment` merece una aclaración: materializa la relación muchos a
muchos entre proyectos y usuarios, pero no es una tabla pivote anónima. Tiene
modelo Eloquent, identificador propio, autor de asignación, timestamps,
relaciones, servicio de administración y restricciones de autorización. Por
eso cuenta como la entidad número 3 y cubre las responsabilidades del plan.

|   # | Entidad / tabla                                   | Responsabilidad y decisiones comprobadas                                                                                         |
| --: | ------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
|   1 | `Project` / `projects`                            | Agregado raíz, UUID público, tipo y estado cerrados, creador protegido e índices por tipo/estado.                                |
|   2 | `ProjectConfiguration` / `project_configurations` | Configuración 1:1, versión positiva y `settings` JSONB nullable.                                                                 |
|   3 | `ProjectAssignment` / `project_assignments`       | Asignación explícita usuario-proyecto, única por pareja, con `assigned_by`; sustenta policies y revocación.                      |
|   4 | `Server` / `servers`                              | Servidor perteneciente a un proyecto, UUID, rol, puerto validado y nombre único por proyecto.                                    |
|   5 | `Connection` / `connections`                      | Configuración de conexión por servidor; `secret_reference` se oculta de serializaciones. No almacena el secreto material.        |
|   6 | `MoodleInstance` / `moodle_instances`             | Instancia origen/destino; FK compuesta impide asociar un servidor de otro proyecto.                                              |
|   7 | `Execution` / `executions`                        | Intento persistente separado de jobs Laravel, UUID, estado, progreso nullable, contador de eventos y referencias de reanudación. |
|   8 | `ExecutionCommand` / `execution_commands`         | Comando lógico idempotente; unicidad por ejecución/etapa/intento/tipo y por idempotency key no nula.                             |
|   9 | `ExecutionStep` / `execution_steps`               | Estado de etapa por intento y posición; `SUCCESS` no concede reanudación.                                                        |
|  10 | `ExecutionEvent` / `execution_events`             | Evento ordenado por secuencia única dentro de una ejecución; progreso nullable.                                                  |
|  11 | `ExecutionLog` / `execution_logs`                 | Log persistente asociado a ejecución y opcionalmente a etapa, con stream restringido.                                            |
|  12 | `Checkpoint` / `checkpoints`                      | Evidencia independiente de reanudación; token cifrado y oculto, con validación explícita.                                        |
|  13 | `Conflict` / `conflicts`                          | Conflicto único por clave y ejecución, con resolución y actor opcionales.                                                        |
|  14 | `Verification` / `verifications`                  | Resultado de verificación único por clave y ejecución, con estados cerrados.                                                     |
|  15 | `Artifact` / `artifacts`                          | Evidencia por disco/ruta única, tamaño no negativo y SHA-256 validado.                                                           |
|  16 | `Tool` / `tools`                                  | Identidad estable del catálogo de herramientas, independiente de sus versiones.                                                  |
|  17 | `ToolVersion` / `tool_versions`                   | Versión y checksums únicos; la herramienta padre no puede borrarse en cascada.                                                   |
|  18 | `AuditLog` / `audit_logs`                         | Registro append-only; conserva la evidencia aunque actor, proyecto o ejecución sean eliminados.                                  |

## Revisión estructural

Las tres migraciones separan el grafo de proyectos, el grafo de ejecuciones y
los resultados/auditoría. Los estados y catálogos cerrados usan enums PHP y
restricciones `CHECK` equivalentes en PostgreSQL.

Se verificaron las siguientes reglas de base de datos:

- índice único parcial `executions_one_active_per_project_unique` para
  `QUEUED`, `RUNNING`, `WAITING_USER_ACTION`, `CANCELLING` y `VERIFYING`;
- intento único por proyecto y secuencia de evento única por ejecución;
- claves lógicas de comandos que funcionan también cuando
  `idempotency_key` es `null`;
- intentos, posiciones, versiones y secuencias positivos, contadores no
  negativos y `progress` nullable o entre 0 y 100;
- consistencia proyecto-servidor de `MoodleInstance` mediante FK compuesta;
- referencias de reanudación completas: checkpoint validado, perteneciente a
  la ejecución anterior, mismo proyecto e intento anterior menor;
- identidad de Execution inmutable, linaje asignable una sola vez,
  `validated` monotónico y Checkpoint inmutable después de ser referenciado;
- locks `FOR SHARE` durante la creación del linaje para evitar que una
  modificación concurrente invalide lo que el trigger acaba de comprobar;
- FKs compuestas impiden que ExecutionLog o Conflict referencien una etapa de
  otra ejecución, tanto al insertar como al actualizar;
- checksums SHA-256 hexadecimales y tamaños de artefactos no negativos;
- `AuditLog` append-only y `Execution` no eliminable en la base de datos;
- proyecto `COMPLETED` y sus datos dependientes de solo lectura, incluyendo
  cambios indirectos y reparentado desde o hacia el proyecto.

### Eliminaciones y preservación de historial

Se revisaron todas las FKs y se corrigieron las cascadas con riesgo histórico:

- un proyecto sin ejecuciones puede eliminar su grafo de configuración
  (`ProjectConfiguration`, asignaciones, servidores, conexiones e instancias)
  mediante cascada intencional;
- `projects -> executions` usa `RESTRICT`, y un trigger append-only impide
  borrar una ejecución directamente. Por tanto, las cascadas declaradas desde
  ejecución hacia comandos, etapas, eventos, logs, checkpoints, conflictos,
  verificaciones y artefactos no pueden destruir historial por eliminación del
  agregado;
- `tools -> tool_versions` usa `RESTRICT` para preservar la identidad exacta de
  la versión;
- referencias históricas de `AuditLog` usan `SET NULL`, no cascada. El trigger
  append-only permite únicamente esa nulificación interna y anidada de la FK;
  una actualización directa de los mismos campos continúa rechazada;
- referencias opcionales a usuarios usan `SET NULL`, mientras el creador del
  proyecto usa `RESTRICT`;
- retirar una asignación o eliminar un usuario sí elimina la asociación de
  acceso, no el proyecto ni su historial.

## Estados, transacciones y autorización

El grafo de transiciones está centralizado en `ProjectStatus` y
`ExecutionStatus`. `ProjectExecutionManager` crea el intento persistente y deja
Project/Execution en `QUEUED`; no crea ni despacha jobs. `ExecutionLifecycle`
cambia ambos estados dentro de una transacción y no ejecuta herramientas.

El recorrido probado es:

```text
READY → QUEUED → RUNNING
RUNNING → WAITING_USER_ACTION → RUNNING
RUNNING → FAILED
RUNNING → CANCELLING → CANCELLED
RUNNING → VERIFYING → REVIEW → COMPLETED
```

También se probaron los reintentos después de `FAILED` y `CANCELLED`, las
transiciones inválidas, el rollback ante un fallo entre la inserción de
Execution y la actualización de Project, el bloqueo en `REVIEW` y el carácter
terminal de `COMPLETED`.

Las comprobaciones de permisos se realizan en los puntos de entrada que existen
en 1B: policies y servicios de dominio. No hay endpoints HTTP de escritura de
proyectos en este corte y no se crearon endpoints de fases posteriores para
probarlos. Los servicios reciben al actor, autorizan dentro de la misma
transacción y bloquean tanto el proyecto como la asignación relevante:

| Rol        | Resultado comprobado                                                         |
| ---------- | ---------------------------------------------------------------------------- |
| `ADMIN`    | Acceso global y administración exclusiva de asignaciones.                    |
| `OPERATOR` | Lectura, configuración y control de ejecuciones sólo en proyectos asignados. |
| `AUDITOR`  | Lectura sólo en proyectos asignados; escritura y lifecycle rechazados.       |

La eliminación de `ProjectAssignment` revoca inmediatamente la consulta y el
uso de los servicios. La protección no depende de botones del frontend.

## Checkpoints, eventos y datos internos

`ExecutionStep::hasValidatedCheckpoint()` consulta checkpoints independientes:
una etapa `SUCCESS` sin checkpoint validado no es reanudable. La base rechaza
referencias parciales, checkpoints no validados, checkpoints de otra ejecución
y ejecuciones anteriores de otro proyecto. No se implementó la reanudación
completa.

Después de crear una referencia válida, la ejecución propietaria del checkpoint
no puede cambiar, la validación no puede revocarse y el checkpoint completo
queda inmutable. También quedan inmutables `project_id`, UUID e intento de la
ejecución origen, y el par `resumed_from_execution_id` / `resume_checkpoint_id`
no puede reasignarse. Los campos ordinarios de lifecycle siguen sometidos a los
servicios y al grafo de estados existente.

`resume_token` usa un cast cifrado de Laravel, se oculta con `$hidden` y no
aparece en arrays, JSON ni serializaciones anidadas de Execution. La prueba
también verifica que el valor crudo de PostgreSQL no contiene el token en texto
plano. `secret_reference` de Connection y los campos existentes de
autenticación (`password`, secretos 2FA, códigos de recuperación y
`remember_token`) se ocultan de serializaciones. No se registran esos valores en
logs de 1B.

`ExecutionEventRecorder` incrementa `last_event_sequence` y crea el evento en la
misma transacción. Una inserción fallida revierte también el contador. El
broadcasting y el procesamiento asíncrono quedan fuera de alcance.

## Método de prueba de concurrencia

Las pruebas usan PostgreSQL real y procesos independientes, no llamadas
secuenciales:

1. el proceso PHPUnit toma un advisory lock exclusivo de PostgreSQL;
2. inicia procesos PHP independientes con Symfony Process; cada worker arranca
   Laravel y abre su propia conexión a `postgres-testing`;
3. cada worker registra en la tabla de cache que está listo y espera el mismo
   advisory lock en modo compartido;
4. al confirmar PIDs distintos y que todos llegaron a la barrera, PHPUnit
   libera el lock exclusivo y los workers compiten simultáneamente;
5. se esperan todos los procesos y se comprueban sus respuestas, errores y el
   estado final persistido.

Para creación de ejecuciones compiten dos procesos: exactamente uno obtiene
éxito, el otro recibe `ExecutionAlreadyActive` y sólo queda una ejecución activa
con Project en `QUEUED`. Para eventos compiten seis procesos: se persisten y se
reportan exactamente las secuencias 1 a 6, sin duplicados, y el contador final
es 6.

La base `postgres-testing` está separada de la base habitual y monta
`/var/lib/postgresql/data` en `tmpfs`. `migrate:fresh` y las pruebas destructivas
se ejecutaron exclusivamente allí. El reinicio de Compose no usó `-v` y preservó
los volúmenes habituales.

## Matriz de aceptación

| Requisito                                          | Evidencia automatizada                                                    | Resultado local |
| -------------------------------------------------- | ------------------------------------------------------------------------- | --------------- |
| 18 entidades y relaciones                          | `DomainRelationsAndPrivacyTest` + revisión de migraciones/modelos         | Aprobado        |
| Cinco estados activos cubiertos por índice parcial | data provider de `DatabaseConstraintTest`, inserción directa sin servicio | Aprobado        |
| Competencia real al crear una ejecución            | 2 procesos en `PostgreSqlConcurrencyTest`                                 | Aprobado        |
| REVIEW bloquea nuevas ejecuciones                  | `StateTransitionTest` sobre servicio                                      | Aprobado        |
| Transiciones válidas/inválidas y coherencia        | `StateTransitionTest`                                                     | Aprobado        |
| Atomicidad y rollback intermedio                   | trigger de fallo de prueba + `StateTransitionTest`                        | Aprobado        |
| COMPLETED terminal y de sólo lectura               | servicio, Eloquent, SQL, cambios indirectos y reparentado                 | Aprobado        |
| Permisos ADMIN/OPERATOR/AUDITOR                    | `AssignmentPermissionsTest`                                               | Aprobado        |
| Revocación al retirar asignación                   | `AssignmentPermissionsTest`                                               | Aprobado        |
| Token cifrado y serialización segura               | `DomainRelationsAndPrivacyTest`                                           | Aprobado        |
| Checkpoint independiente y compatible              | `CheckpointIndependenceTest`                                              | Aprobado        |
| Linaje inmutable después de referenciar            | cambios posteriores a Checkpoint y Execution origen                       | Aprobado        |
| Log/Conflict sólo usan etapas de su ejecución      | inserciones y updates incompatibles en `DatabaseConstraintTest`           | Aprobado        |
| Unicidad de comandos con nulos                     | `DatabaseConstraintTest`                                                  | Aprobado        |
| Eventos únicos y monotónicos concurrentes          | 6 procesos en `PostgreSqlConcurrencyTest`                                 | Aprobado        |
| Fallo de evento sin datos parciales                | `DomainRelationsAndPrivacyTest`                                           | Aprobado        |
| `progress` nullable y rango 0..100                 | recorder + inserciones PostgreSQL inválidas                               | Aprobado        |
| Historial protegido de cascadas                    | restricciones de borrado + `DatabaseConstraintTest`                       | Aprobado        |
| AuditLog inmutable compatible con `SET NULL`       | borrado de Project DRAFT/usuario + update directo rechazado               | Aprobado        |
| Regresión de autenticación/usuarios 1A             | suite PHPUnit completa                                                    | Aprobado        |

## Defectos encontrados y correcciones

| Defecto                                                                              | Corrección y regresión                                                                                                                      |
| ------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------- |
| El reporte decía 17 entidades.                                                       | Se corrigió el inventario: son 18; `ProjectAssignment` es una entidad explícita, no una pivote anónima.                                     |
| `ExecutionLifecycle` no autorizaba al actor.                                         | Ahora exige actor, evalúa la policy dentro de la transacción y bloquea la asignación; OPERATOR asignado pasa, AUDITOR y no asignado fallan. |
| La autorización inicial de `ProjectExecutionManager` podía quedar separada del lock. | Se revalida dentro de la transacción sobre Project y ProjectAssignment bloqueados.                                                          |
| Las FKs de reanudación aceptaban combinaciones incompatibles.                        | Trigger PostgreSQL valida pareja completa, pertenencia, validación, proyecto e intento anterior.                                            |
| Borrar Project podía encadenar el borrado del historial de Execution.                | FK Project/Execution en `RESTRICT` y Execution append-only.                                                                                 |
| Borrar Tool eliminaba versiones en cascada.                                          | FK Tool/ToolVersion en `RESTRICT`.                                                                                                          |
| Los tipos `unsigned` de Laravel no imponen signo en PostgreSQL.                      | Se añadieron `CHECK` explícitos para versiones, intentos, posiciones, secuencias, contadores y tamaños.                                     |
| El guard de `COMPLETED` sólo inspeccionaba el padre nuevo al reparentar.             | Ahora inspecciona padre anterior y nuevo; regresiones cubren mover Execution y Artifact.                                                    |
| El workflow no invocaba ESLint explícitamente.                                       | Se añadió `npm run lint` sin retirar ni debilitar otros controles.                                                                          |
| Un Checkpoint podía moverse o invalidarse después de ser referenciado.               | Checkpoint referenciado e identidad de Execution/linaje inmutables, validación monotónica y locks de lectura durante la referencia.         |
| ExecutionLog y Conflict aceptaban una etapa perteneciente a otra Execution.          | FK compuesta `(execution_step_id, execution_id)` y regresiones de inserción/actualización para ambos registros.                             |
| `AuditLog` append-only bloqueaba el `ON DELETE SET NULL` de sus propias FKs.         | Excepción estrecha para la acción referencial anidada; borrados permitidos pasan y los updates directos siguen bloqueados.                  |

## Comprobaciones locales ejecutadas

| Comando o comprobación                                                     | Resultado real                                                                                                |
| -------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| `docker compose down --remove-orphans`                                     | Exit 0; no se eliminaron volúmenes.                                                                           |
| `docker compose up -d --build --wait --wait-timeout 300`                   | 9 servicios `healthy`.                                                                                        |
| `GET http://127.0.0.1:8080/login`                                          | HTTP 200 a través de Nginx.                                                                                   |
| `php artisan migrate:fresh` con variables explícitas de `postgres-testing` | 9 migraciones aplicadas desde cero.                                                                           |
| `php artisan test tests/Feature/Domain`                                    | 70 pruebas, 204 aserciones, aprobado después de las correcciones.                                             |
| `php artisan test`                                                         | 115 pruebas, 376 aserciones, aprobado después de las correcciones.                                            |
| `composer lint:check`                                                      | 127 archivos, aprobado.                                                                                       |
| `composer types:check`                                                     | 97 archivos, 0 errores.                                                                                       |
| `npm run check`                                                            | 76 archivos con formato correcto; 66 archivos sin warnings ni errores.                                        |
| `npm run lint`                                                             | Aprobado, cero warnings permitidos.                                                                           |
| `npm run types:check`                                                      | Aprobado.                                                                                                     |
| `npm run build`                                                            | 2312 módulos transformados, aprobado.                                                                         |
| Integridad de `BaseLine/`                                                  | 131 archivos; huella `5a996439d8432e13abecbc4ebf57f12654d15e14afef8b1160fe55dcf82ae1d3`.                      |
| Sólo lectura de `BaseLine/`                                                | `app`, `queue-worker`, `reverb`, `vite` y `nginx` accesibles; escritura rechazada por `read-only filesystem`. |

La prueba de sólo lectura considera error que un contenedor no responda, que el
directorio no sea accesible, que la escritura tenga éxito o que falle por un
motivo diferente a un filesystem de sólo lectura. También comprueba que no quede
una sonda residual.

## GitHub Actions

El workflow mantiene la construcción limpia, migraciones, PHPUnit, Pint,
Larastan, Vite Plus, TypeScript, build y las dos verificaciones de `BaseLine/`.
La auditoría añadió la invocación explícita de ESLint. El resultado remoto no se
anticipa dentro del mismo commit que debe evaluar: la respuesta de entrega
identifica el SHA inmutable, el PR y la ejecución de CI correspondiente.

El run `33586608426` aprobó el SHA anterior
`ee60f850553641c6a6346627dd6d601f86a95e45`, pero quedó superado por los tres
hallazgos posteriores y no se usa como aceptación de estas correcciones. Se
requiere un nuevo run sobre el nuevo SHA.

## Desviaciones y limitaciones

El Plan Maestro no fija todos los campos escalares de las entidades. Se mantuvo
un esquema mínimo con columnas explícitas para identidad, estado, relaciones,
idempotencia, checksums y auditoría, y JSONB sólo para configuración, metadata o
payloads variables.

No hay endpoints HTTP de escritura de proyectos en 1B. Por ello la aceptación
de autorización se concentra en policies y servicios existentes, sin inventar
endpoints de 1C. No se realizó una prueba contra infraestructura SSH ni contra
herramientas reales porque ambas pertenecen a cortes posteriores.

## Pendiente para 1C y cortes posteriores

Para 1C quedan el Wizard persistente, sus tres tipos de flujo, instancias
simuladas, preflight estructurado, confirmación de warnings y la interfaz de
creación/configuración. No se comenzó ninguna de esas funciones.

También permanecen fuera de 1B: `FakeToolAdapter`, contratos y proveedores de
herramientas, endpoints idempotentes, jobs, `dispatchAfterCommit`, broadcasting
funcional, intervención, reanudación completa, cancelación cooperativa,
verificación funcional, artefactos descargables, SSH/SFTP y ejecución de
`BaseLine/`.
