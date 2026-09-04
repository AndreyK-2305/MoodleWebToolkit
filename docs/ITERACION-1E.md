# Iteración 1E — Casos especiales

Fecha: 3 de septiembre de 2026.

Rama: `codex/1e-casos-especiales`.

Base: `main` en `abee341`, merge del PR #3 de 1D que contiene
`4d4ea46aaa7a942c0442e0feb23d809f047f2c4c`.

## Alcance entregado

1E amplía el motor simulado de 1D con cuatro escenarios deterministas
configurables en el wizard: procesamiento sin incidencias, advertencia,
intervención manual y fallo recuperable. También incorpora resolución
idempotente de incidencias, reanudación desde checkpoint validado y cancelación
cooperativa.

La frontera deliberada del corte es:

```text
procesamiento simulado terminado, verificación pendiente de 1F
```

En esa frontera Project y Execution permanecen `RUNNING`, con preparación y
operación en `SUCCESS`, progreso 50 %, comandos de procesamiento cerrados y las
etapas de verificación y finalización en `PENDING`. No se implementan
verificación académica, `REVIEW`, árbol de resultados, cierre final, SSH,
procesos remotos ni herramientas reales.

## Escenarios deterministas

| Escenario           | Resultado de la unidad de operación                                                                                                                   |
| ------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| Sin incidencias     | Cierra `CONTINUE` y alcanza la frontera 1E.                                                                                                           |
| Advertencia         | Persiste un conflicto de tipo warning, deja el paso en `WAITING_USER` y la Execution en `WAITING_USER_ACTION`. Exige aceptación explícita y auditada. |
| Intervención manual | Persiste un conflicto con las decisiones permitidas. La decisión vigente se valida en backend antes de continuar.                                     |
| Fallo recuperable   | Cierra el intento en `FAILED` y emite un checkpoint validado, inmutable y privado.                                                                    |

El adaptador falso deriva siempre el mismo resultado de la configuración
persistida. Una reanudación del escenario de fallo completa la unidad en vez de
repetir el fallo, lo que permite comprobar el linaje y el trabajo reutilizado.

## Espera, resolución y continuación

Una incidencia se guarda antes de abandonar el worker. La misma transacción
deja el paso en `WAITING_USER`, cambia Project y Execution a
`WAITING_USER_ACTION`, procesa el comando reclamado y publica su evento sólo
después del commit. No queda lease ni comando en procesamiento durante la
espera; por eso una espera de 24 horas o más no se trata como abandono.

La resolución bloquea en orden Project, Execution, asignación, conflicto y
comando. Comprueba nuevamente rol, asignación, ejecución, versión del conflicto,
decisión admitida y hash de idempotencia. Una llave repetida con el mismo
contenido devuelve el resultado anterior; cambiar el contenido con la misma
llave se rechaza. Si quedan conflictos bloqueantes no se despacha trabajo. La
última resolución crea un comando `RESOLVE`, lo confirma y sólo entonces envía
un nuevo job que continúa la misma Execution sin repetir pasos `SUCCESS`.

## Fallo, checkpoint y reanudación

El cierre de fallo mantiene coherentes Project, Execution, paso y comando, y
conserva eventos, logs técnicos y auditoría. El checkpoint incluye la identidad
del adaptador y un token determinista validable. `resume_token` se cifra en
reposo y se oculta de serialización, presentadores, eventos y logs.

Reanudar exige una Execution `FAILED` y un checkpoint emitido y validado por el
adaptador. Un paso `SUCCESS` aislado no basta. La operación crea atómicamente:

- una nueva Execution con intento incrementado;
- referencias inmutables a la Execution anterior y al checkpoint;
- un `workspace_key` UUID independiente e inmutable;
- pasos nuevos, marcando `REUSED` sólo el trabajo expresamente validado;
- un comando `RESUME` persistido antes de su despacho.

El intento anterior permanece `FAILED`; ningún artefacto no validado se copia.

## Cancelación cooperativa

La solicitud HTTP no declara la parada final. Bajo lock crea como máximo un
comando `CANCEL`, registra la solicitud y pasa a `CANCELLING`. El worker vuelve
a comprobar propiedad, lease y estado, alcanza el punto seguro simulado,
limpia pasos abiertos y conflictos, cierra comandos y confirma `CANCELLED`.

La misma ruta cubre `QUEUED`, `RUNNING` y `WAITING_USER_ACTION`. Las peticiones
repetidas son idempotentes. Una carrera real entre cancelación y continuación
usa el mismo orden de locks; si gana la cancelación, el worker antiguo descarta
la unidad y no puede persistir trabajo tardío.

## Outbox, leases y recuperación

`START`, `CONTINUE`, `RESOLVE`, `RESUME` y `CANCEL` comparten el outbox de
`execution_commands`. Cada comando se persiste en la transacción de dominio y
se despacha después del commit. `ExecutionCommandLease` mantiene propietario y
vencimiento, y cada escritura vuelve a verificarlos bajo lock. Los jobs
conservan `tries=1` y timeout de 120 segundos; el `retry_after` predeterminado de
Redis es 180 segundos.

`executions:recover-dispatches` distingue comandos pendientes, reclamados y
cerrados. Redis puede recibir otra vez un comando nunca despachado; un comando
reclamado con lease vencido se cierra transaccionalmente, sin reejecutar efectos.
Una espera del usuario o la frontera 1E tienen sus comandos cerrados y quedan
fuera de la detección de abandono.

## Los dos plazos de autenticación

Son controles independientes:

| Control                     | Configuración inicial                                                             | Renovación                                                                    | Efecto al vencer                                                                                        |
| --------------------------- | --------------------------------------------------------------------------------- | ----------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| Sesión autenticada          | `SESSION_LIFETIME=120`, minutos de inactividad                                    | Consultas y navegación autenticadas                                           | Si se pierde realmente, se pausan las actualizaciones y se ofrece reautenticación en la misma pantalla. |
| Confirmación para modificar | `AUTH_PASSWORD_TIMEOUT=7200`, segundos desde login completo o última confirmación | Sólo login completo, cambio confirmado de contraseña o confirmación explícita | El seguimiento continúa en modo consulta y toda mutación protegida exige contraseña.                    |

Eventos WebSocket, catch-up HTTP, polling, recargas y navegación no renuevan la
confirmación para modificar. El backend compara
`auth.password_confirmed_at` con el plazo configurado y responde 423 antes de
todo efecto cuando falta o venció la evidencia. El límite de confirmación es de
cinco intentos por minuto.

El guard global de Inertia muestra un aviso discreto de modo de seguimiento,
intercepta la mutación pendiente y abre un modal de contraseña sin abandonar la
pantalla. Tras confirmar reenvía la visita original con el mismo método, cuerpo,
cabeceras e `Idempotency-Key`; el endpoint vuelve a autorizar rol, asignación y
estado, por lo que una acción que quedó obsoleta mientras el modal estaba
abierto se rechaza normalmente. Compartir sesión entre pestañas tampoco evita
la comprobación del servidor.

Si la sesión autenticada desaparece, el seguimiento deja de aplicar respuestas
no autenticadas y muestra las actualizaciones como pausadas. La reautenticación
se abre embebida en la misma vista para respetar el flujo completo de login,
incluido segundo factor; al recuperarse el acceso se comprueban otra vez los
permisos y se solicita el catch-up desde la última secuencia conocida. Cerrar
sesión o desactivar/revocar acceso nunca altera ni cancela la ejecución.

## Protección backend

El middleware `action.confirmed` cubre creación y configuración de proyectos,
preflight y confirmación, inicio y acciones de ejecución, administración de
usuarios y cambios sensibles de cuenta. Login, confirmación de contraseña y
logout quedan fuera para evitar ciclos. La confirmación no sustituye policies:
AUDITOR continúa en sólo lectura y un OPERATOR sin asignación recibe 403.

Las garantías previas permanecen activas: secuencias monotónicas, auditoría
append-only, constraints de PostgreSQL, checkpoints inmutables, autorización de
canal privado, recuperación de eventos y publicación exclusivamente posterior
al commit.

## Correcciones posteriores a la revisión

Las regresiones se construyeron contra el SHA revisado
`0e41502bf93ead8585b46c369c8cdf69b324379a` antes de aplicar cada corrección.

### 1. Recuperación mediante «Recordarme»

- **Reproducción previa:** una sesión descartada se recuperó con la cookie real
  de recaller. La primera consulta autenticada volvió a emitir `Login` y dejó
  `auth.password_confirmed_at` con un timestamp nuevo, por lo que una mutación
  quedaba habilitada sin volver a introducir la contraseña.
- **Causa:** el listener de `Login` trataba por igual el login interactivo y la
  recuperación automática del `SessionGuard`.
- **Solución:** el listener consulta `SessionGuard::viaRemember()`. El login
  completo, incluso si se marcó «Recordarme», inicia el plazo; la recuperación
  por recaller elimina cualquier evidencia de confirmación heredada y exige una
  confirmación explícita antes de modificar.
- **Regresión añadida:** `ActionConfirmationTest` usa login y cookies reales
  para cubrir login con/sin remember, recuperación, consulta, mutación 423,
  contraseña incorrecta/correcta y segundo factor pendiente/completado. La
  prueba previa de consultas por más de 24 horas conserva la comprobación de que
  el seguimiento no renueva el timestamp.
- **Evidencia y límite:** la suite confirma el flujo Fortify completo y el
  recaller de Laravel sobre PostgreSQL; no se habilitó un proveedor externo de
  passkeys, que conserva su flujo existente y no fue modificado.

### 2. Checkpoint vigente al reanudar

- **Reproducción previa:** al abrir el seguimiento en `RUNNING`, el `useForm`
  fijaba `checkpoint_id=0`. Después del catch-up a `FAILED` aparecía el botón,
  pero el formulario seguía enviando el valor inicial.
- **Causa:** el cuerpo de reanudación se derivaba una sola vez al montar el
  formulario, antes de que existiera el checkpoint.
- **Solución:** el click construye el cuerpo de `router.post` con el checkpoint
  validado de la Execution renderizada en ese instante. No depende de una
  actualización asíncrona de estado inmediatamente anterior al envío.
- **Regresión añadida:** la prueba frontend abre el estado lógico sin checkpoint
  y comprueba que una actualización posterior selecciona el id validado. Las
  pruebas de dominio conservan la pertenencia, identidad, validación y creación
  de un único intento enlazado.
- **Evidencia y límite:** el payload y el linaje están cubiertos por frontend y
  PostgreSQL; el recorrido interactivo se registra por separado en la sección de
  navegador.

### 3. Aislamiento entre intentos

- **Reproducción previa:** una navegación Inertia desde el intento fallido a su
  reanudación conservaba `useState`/`useRef`; el cursor alto del intento anterior
  impedía recuperar los primeros eventos del nuevo y una respuesta tardía podía
  mezclarlos.
- **Causa:** la identidad de Execution no delimitaba el ciclo de vida del
  tracker, las peticiones ni la suscripción.
- **Solución:** un componente interno usa `execution.uuid` como `key` y una
  frontera explícita detecta el cambio de UUID incluso cuando Inertia preserva la
  instancia del componente. Cada intento reinicia estado, cursor, formularios,
  llaves de idempotencia y canal; el cleanup cancela catch-ups y abandona la
  suscripción. Además, cada respuesta se valida por UUID y generación antes de
  aplicarse.
- **Regresión añadida:** las pruebas frontend cubren cursor menor en el nuevo
  intento, rechazo de respuesta tardía, orden y deduplicación dentro del mismo
  intento. Las pruebas PHP comprueban navegación al intento enlazado y secuencias
  persistidas independientes.
- **Evidencia y límite:** el aislamiento puro se ejecuta de forma determinista en
  Vitest y el linaje en PostgreSQL; las condiciones de red tardía se fuerzan en
  la unidad frontend, no mediante latencia artificial del navegador.

### 4. Revocación efectiva de WebSocket

- **Reproducción previa:** el canal privado por proyecto sólo aplicaba la policy
  al suscribirse. Una conexión ya autorizada continuaba recibiendo el payload
  completo después de desactivar la cuenta o retirar su asignación.
- **Causa:** Reverb no vuelve a ejecutar la autorización de un canal existente
  para cada broadcast.
- **Solución:** cada sesión de base de datos recibe un canal privado opaco,
  derivado con HMAC y entregado sólo por endpoints protegidos. La suscripción
  vuelve a comprobar la policy y el formato opaco. Al distribuir cada evento,
  después del commit, el servidor consulta usuarios activos, rol/asignación
  vigente y sesiones no vencidas, y publica únicamente a esos canales. El
  endpoint HTTP entrega el canal de la sesión actual y el catch-up permite
  renovarlo tras reautenticar. Con un driver de sesión distinto de `database`
  la distribución falla cerrada.
- **Regresión añadida:** `RealtimeRevocationTest` realiza handshakes y frames
  RFC 6455 contra Reverb real. Verifica recepción previa, ausencia posterior a
  desactivación, retiro de asignación, cambio de rol y eliminación de sesión;
  también confirma continuidad para el administrador y rechazo de reconexión.
- **Evidencia y límite:** son sockets Reverb reales, no `Event::fake`; las
  mutaciones de autorización se hacen en la base aislada de testing. La prueba
  de logout elimina la sesión persistida y el flujo HTTP separado comprueba la
  pérdida de autenticación; no intenta retirar de la red bytes emitidos antes
  del commit de revocación.

### 5. Upgrade con proyectos completados

- **Reproducción previa:** una base anterior a 1E con una ejecución de un Project
  `COMPLETED` falló al rellenar `workspace_key`; PostgreSQL devolvió SQLSTATE
  `23514` desde `executions_completed_project_read_only`.
- **Causa:** el backfill legítimo de esquema era una actualización de la misma
  tabla protegida por el trigger de inmutabilidad de 1D.
- **Solución:** en PostgreSQL la migración elimina exclusivamente ese trigger
  durante el backfill y lo recrea en un `finally` antes de terminar. El `DROP
TRIGGER` conserva un lock `ACCESS EXCLUSIVE` hasta el commit, de modo que no
  existe una ventana para escrituras de aplicación. Después se aplica `NOT
NULL` y permanecen las nuevas restricciones de 1E.
- **Regresión añadida:** `Iteration1EMigrationUpgradeTest` crea un schema aislado,
  ejecuta todas las migraciones previas, inserta estados válidos y un linaje de
  reanudación, ejecuta la migración real de 1E, hace rollback y la aplica de
  nuevo. Después de cada aplicación vuelve a intentar una escritura prohibida
  sobre el proyecto completado.
- **Evidencia y límite:** el upgrade conserva tres ejecuciones, tres workspaces
  UUID únicos, historial y referencias de linaje; la escritura posterior vuelve
  a fallar con SQLSTATE `23514` antes y después del rollback/reaplicación. La
  prueba consta de 13 aserciones sobre PostgreSQL real en un schema desechable,
  no `migrate:fresh`, y no toca la base de desarrollo.

### 6. Estado visible durante la reconexión Reverb

- **Reproducción previa:** al detener Reverb con una pantalla de seguimiento
  abierta, el catch-up HTTP continuaba, pero la insignia permanecía en `Tiempo
  real conectado` durante los intentos de reconexión.
- **Causa:** el componente sólo atendía la suscripción confirmada y el callback
  de error del canal. Pusher cambia primero la conexión global a `connecting`,
  estado que no llegaba a React.
- **Solución:** el tracker escucha `state_change`, baja el indicador para todo
  estado que no sea la conexión vigente y sólo vuelve a marcar tiempo real
  después de que el canal privado confirma su resuscripción. El polling de 15
  segundos permanece independiente.
- **Regresión añadida:** Vitest cubre `connecting`, `disconnected`, `unavailable`
  y `failed`, además de la recuperación posterior mediante `subscribed`.
- **Evidencia y límite:** en el navegador real se detuvo Reverb, la pantalla
  cambió a `Recuperación activa` y siguió consultando por HTTP; al levantar el
  servicio, regresó a `Tiempo real conectado` tras el backoff de Pusher y sin
  recarga. No se alteraron datos ni volúmenes durante esta comprobación.

## Evidencia automatizada local

La base de testing usa exclusivamente `postgres-testing` en `tmpfs`; se ejecutó
`migrate:fresh` allí. En desarrollo sólo se ejecutó `migrate` y no se borraron
datos.

- Suite PHP completa: 197 pruebas aprobadas, 1278 aserciones.
- Casos específicos 1E: 11 pruebas aprobadas, incluidas espera prolongada,
  conflictos múltiples, reanudación, cancelación y rollback.
- Autorización temporal: 13 casos aprobados, incluidos login completo con y sin
  remember, recaller real, segundo factor, contraseña incorrecta/correcta con
  rate limit, permisos, acción obsoleta y pérdida/recuperación de sesión.
- Upgrade, rollback y reaplicación pre-1E: 1 prueba y 13 aserciones aprobadas en
  un schema PostgreSQL aislado.
- Revocación Reverb real: 2 pruebas aprobadas, 23 aserciones, con conexiones
  WebSocket y distribución posterior a la revocación.
- Estado frontend: 5 pruebas Vitest aprobadas para checkpoint, cursor, respuesta
  tardía, orden, deduplicación y estado de reconexión.
- Concurrencia PostgreSQL multiproceso: 7 pruebas aprobadas, incluida la carrera
  cancelación/continuación.
- Pint: 187 archivos aprobados.
- Larastan/PHPStan: 148 rutas analizadas sin errores.
- Vite Plus: 85 archivos formateados y 72 sin diagnósticos.
- TypeScript y ESLint: sin errores.
- Build de producción: 2319 módulos transformados.
- Docker Compose: configuración válida y diez servicios saludables.
- Cola: `retry_after=180 s` supera el timeout de unidad de `120 s`.
- `BaseLine/`: 131 archivos, SHA-256 canónico
  `5a996439d8432e13abecbc4ebf57f12654d15e14afef8b1160fe55dcf82ae1d3`;
  escritura rechazada en `app`, `queue-worker`, `scheduler`, `reverb`, `vite` y
  `nginx`.

## Evidencia del recorrido de navegador

El recorrido manual se ejecutó en la aplicación levantada por Docker Compose,
con Redis, queue worker y Reverb activos:

- un escenario `WARNING` alcanzó `WAITING_USER_ACTION` al 35 % sin retener el
  worker;
- aceptar la advertencia continuó sobre la misma Execution y llegó a la
  frontera 1E al 50 %, dejando verificación y finalización pendientes;
- cancelar mostró primero `CANCELLING` y el worker confirmó luego `CANCELLED`
  en su punto seguro, sin trabajo tardío;
- con el plazo de confirmación reducido temporalmente para la prueba, la UI
  mantuvo el seguimiento, bloqueó una creación, conservó nombre y descripción
  detrás del modal y reintentó exactamente esa creación después de confirmar;
- el proyecto `Formulario preservado 1E` se creó una sola vez y el aviso de
  seguimiento desapareció tras la recuperación;
- un intento con fallo recuperable pasó de la pantalla inicial sin checkpoint a
  `FAILED` con checkpoint y eventos `#1–#9` sin recargar; la reanudación envió el
  checkpoint vigente y PostgreSQL registró exactamente un intento enlazado;
- el recorrido descubrió que `preserveState` de Inertia podía conservar el
  cursor del intento anterior aunque el componente tuviera `key`; se añadió la
  frontera explícita por UUID y se repitió la prueba con el bundle reconstruido;
- en la repetición final, el proyecto `Validación correctiva 1E - aislamiento`
  navegó de la Execution
  `a0e06878-3f04-4081-8c5d-dac523abbee1` a
  `73be7ef5-4262-4a90-b813-d3541839929d` sin recarga: la vista mostró sólo los
  eventos nuevos `#1–#6`, comenzando en `execution.resumed`, y mantuvo
  `Tiempo real conectado`;
- PostgreSQL confirmó dos workspaces diferentes, linaje `4 → 5`, checkpoint
  `2` y secuencias independientes `1–9` / `1–6`;
- durante el cierre técnico, la Execution reanudada pasó en vivo por
  `CANCELLING` y terminó en `CANCELLED` cuando el worker confirmó su punto
  seguro, sin trabajo tardío;
- al detener Reverb, el seguimiento cambió a `Recuperación activa` y mantuvo el
  catch-up HTTP; al levantarlo recuperó `Tiempo real conectado` tras el backoff
  y sin recargar la página;
- la consola del navegador no registró advertencias ni errores.

La configuración temporal se restauró a `AUTH_PASSWORD_TIMEOUT=7200` antes de
cerrar el recorrido. La espera de dos horas y el caso de más de 24 horas se
validaron con reloj controlado en las pruebas automatizadas. El CI del SHA
publicado se registrará en la entrega y en el PR draft.

## Limitaciones conservadas

La implementación sólo simula las unidades. Una futura operación remota de
larga duración necesitará supervisión independiente del request web y del job:
perder comunicación con un ejecutor real no demuestra que el proceso remoto
haya terminado o fallado. Esa supervisión, las integraciones reales y toda la
verificación o finalización de 1F quedan expresamente fuera de este corte.
