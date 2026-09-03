# Iteración 1D — Motor asíncrono simulado

Fecha: 2 de septiembre de 2026.

Rama: `1D-WIzard`.

Base: `main` en `52aa6c7`, merge de 1C que contiene
`9e3b4f75b18273ce31b08d09b8d5fdeb6e235943`.

## Alcance entregado

1D añade el inicio independiente posterior a la confirmación del wizard. La
confirmación sigue terminando exclusivamente en `READY`; sólo
`POST /projects/{project}/executions` crea un intento lógico y lo envía a la
cola.

Se definieron los contratos `ToolAdapter`, `ExecutionProvider`,
`ConnectionProvider`, `ArtifactStorage`, `SecretStore` y `ProcessRunner`. Las
implementaciones activas de este corte son:

- `FakeToolAdapter`: declara el plan y produce únicamente eventos normalizados;
- `FakeExecutionProvider`: orquesta una unidad simulada acotada y persiste sus
  efectos;
- `LocalArtifactStorage`: usa Laravel Filesystem, valida rutas relativas y
  calcula tamaño y SHA-256.

No existen conexiones SSH, credenciales reales ni ejecución de comandos del
sistema. `BaseLine/` no fue modificada ni ejecutada.

## Inicio atómico e idempotente

El cliente envía `Idempotency-Key` y `configuration_version`. La acción toma un
lock de la fila del proyecto y, dentro de la misma transacción:

1. vuelve a autorizar al actor;
2. bloquea la asignación del OPERATOR;
3. busca una operación previa con el mismo ámbito y llave;
4. comprueba `READY`, ausencia de otra ejecución activa y los bloqueos de
   `REVIEW`/`COMPLETED`;
5. recalcula el fingerprint y el preflight, compara los checks persistidos y
   verifica la confirmación y sus advertencias;
6. crea `Execution`, cuatro `ExecutionStep`, el `ExecutionCommand START` y el
   registro de auditoría;
7. deja Project y Execution en `QUEUED`.

El hash de solicitud incluye operación, UUID público del proyecto y versión de
configuración. La unicidad `(idempotency_scope, idempotency_key)` complementa
las restricciones de 1B sin sustituir el lock ni el índice parcial de ejecución
activa.

- Misma llave y mismo contenido: devuelve el UUID original con HTTP 200, aunque
  el intento haya avanzado.
- Misma llave y contenido diferente: HTTP 409.
- Llave diferente con ejecución activa: HTTP 409 y ningún registro adicional.
- AUDITOR o usuario no asignado: HTTP 403, también en replays.

El despacho ocurre después de la transacción propia. Si la acción es llamada
dentro de una transacción exterior, se registra con `DB::afterCommit`; un
rollback exterior elimina registros y callback. Un fallo de Redis después del
commit devuelve HTTP 503 con el UUID recuperable, deja un log técnico y conserva
el comando como pendiente. Repetir la misma solicitud o ejecutar
`php artisan executions:recover-dispatches` vuelve a despacharlo sin crear otra
Execution. El scheduler de Compose revisa ese outbox cada minuto.

## Worker y límite deliberado de la demostración

`RunExecutionUnit` usa la cola `executions`, `tries=1`, timeout de 120 segundos y
`failOnTimeout`. `REDIS_QUEUE_RETRY_AFTER=180` mantiene el timeout del broker por
encima del timeout del job. El worker de Compose usa los mismos valores.

Antes de trabajar, `FakeExecutionProvider` bloquea el comando y persiste
`processing_started_at`. Una segunda entrega sale sin producir eventos ni
efectos. Después cambia Project/Execution de `QUEUED` a `RUNNING` usando el ciclo
de vida del dominio, procesa sólo `prepare`, marca ese paso `SUCCESS` y conserva
los otros tres en `PENDING`.

La demostración termina así:

```text
Project RUNNING
└── Execution RUNNING (25 %)
    ├── Preparación simulada SUCCESS
    ├── Operación simulada PENDING
    ├── Verificación simulada PENDING
    └── Finalización PENDING
```

No se fuerza `VERIFYING`, `REVIEW` ni `COMPLETED`. Tampoco se implementan
conflictos, intervención manual, resume/checkpoints, cancelación cooperativa,
verificación académica, árbol de resultados ni cierre final.

## Eventos, Reverb y recuperación

`NormalizedToolEvent` es la frontera del adaptador. El orden implementado es:

```text
FakeToolAdapter
→ NormalizedToolEvent
→ ExecutionEventRecorder
→ PostgreSQL y commit
→ ExecutionEventBroadcast
→ canal privado projects.{uuid}
→ Reverb
→ React
```

`ExecutionEventRecorder` conserva el contador y la secuencia monotónica de 1B.
El broadcast se registra con `DB::afterCommit`; si hay rollback no se emite. Un
fallo de broadcasting se reporta técnicamente y no invalida el estado ya
confirmado en PostgreSQL.

ADMIN y usuarios asignados, incluido AUDITOR, pueden autorizar el canal. El
endpoint de catch-up aplica la misma policy de lectura. React no aplica un
evento recibido como verdad aislada: pide todos los eventos posteriores a su
última secuencia, deduplica por secuencia y actualiza estado/pasos desde el
snapshot persistido. La carga inicial, una brecha, la resuscripción y un polling
de respaldo recuperan eventos faltantes. `progress=null` se representa como
progreso indeterminado.

Los eventos funcionales viven en `execution_events`. Fallos de infraestructura
de dispatch o worker se guardan por separado en `execution_logs`; no se incluyen
secretos ni mensajes arbitrarios de excepciones en la interfaz funcional.

## Pruebas automatizadas

La batería específica `ExecutionEngineTest` cubre:

- HTTP → persistencia → dispatch → worker → `RUNNING`;
- replay después de que la ejecución avanzó;
- colisión de contenido y llave diferente durante ejecución activa;
- preflight/confirmación obsoletos;
- rollback externo sin job ni broadcast;
- fallo posterior al commit y recuperación con la misma llave;
- entrega duplicada sin trabajo ni eventos duplicados;
- permisos de inicio, lectura y canales para ADMIN, OPERATOR y AUDITOR;
- catch-up persistente, paginación ordenada y progreso indeterminado;
- seguridad e integridad de `LocalArtifactStorage`.

`PostgreSqlConcurrencyTest` ejecuta procesos PHP independientes detrás de una
barrera mediante advisory locks. Dos procesos envían peticiones al kernel HTTP
al mismo tiempo, cada uno con conexión PostgreSQL propia:

- misma llave: respuestas 201/200 y una sola Execution/Command;
- llaves diferentes: una respuesta exitosa, una 409 y una sola ejecución
  activa;
- los seis escritores concurrentes de eventos de 1B continúan produciendo
  exactamente las secuencias 1 a 6.

## Validación local

La aceptación se ejecuta exclusivamente contra `postgres-testing`, cuyo
directorio de datos es `tmpfs`. La base de desarrollo no se destruye. Los
resultados finales comprobados del SHA publicado se completan en la entrega y
en GitHub Actions.

Comandos de aceptación:

```powershell
docker compose up -d --build --wait --wait-timeout 300
docker compose exec -T app php artisan migrate:fresh --env=testing --force --no-interaction
docker compose exec -T app composer lint:check
docker compose exec -T app composer types:check
docker compose exec -T app php artisan test
docker compose exec -T vite npm run check
docker compose exec -T vite npm run lint
docker compose exec -T vite npm run types:check
docker compose exec -T vite npm run build
docker compose config --quiet
powershell -File tests/Infrastructure/verify-baseline-integrity.ps1
powershell -File tests/Infrastructure/verify-baseline-readonly.ps1
```

Resultados comprobados antes de publicar:

- 154 pruebas PHP aprobadas, con 873 aserciones;
- Pint aprobado sobre 164 archivos y Larastan sin errores sobre 132 rutas;
- Vite Plus aprobó formato y diagnósticos, ESLint y TypeScript finalizaron sin
  errores y el build procesó 2317 módulos;
- Docker Compose válido, servicios `app`, `queue-worker`, `scheduler`, `reverb`,
  `vite` y `nginx` saludables;
- `BaseLine/` intacta (131 archivos, SHA-256 canónico
  `5a996439d8432e13abecbc4ebf57f12654d15e14afef8b1160fe55dcf82ae1d3`) y
  montada en modo read-only en los seis servicios;
- recorrido real en navegador aprobado: confirmación en `READY`, inicio HTTP,
  consumo por el worker Redis, estado `RUNNING` al 25 %, seis eventos recibidos
  mediante el canal privado de Reverb y recuperación idéntica tras recargar.

## Continuación posterior

El siguiente corte podrá despachar más unidades del plan y formalizar su
programación usando el mismo `Execution` lógico y comandos independientes. Las
implementaciones reales de conexión, procesos y herramientas deben sustituir
los bindings simulados sin exponer comandos, SSH o secretos a React. Ninguna de
esas funciones forma parte de 1D.
