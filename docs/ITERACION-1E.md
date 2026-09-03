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

## Evidencia automatizada local

La base de testing usa exclusivamente `postgres-testing` en `tmpfs`; se ejecutó
`migrate:fresh` allí. En desarrollo sólo se ejecutó `migrate` y no se borraron
datos.

- Suite PHP completa: 191 pruebas aprobadas, 1214 aserciones.
- Casos específicos 1E: 11 pruebas aprobadas, incluidas espera prolongada,
  conflictos múltiples, reanudación, cancelación y rollback.
- Autorización temporal: 10 pruebas aprobadas para expiración, ausencia de
  evidencia, login completo, contraseña incorrecta/correcta con rate limit,
  AUDITOR, OPERATOR sin asignación, acción obsoleta, pérdida de sesión y
  reautenticación con catch-up.
- Concurrencia PostgreSQL multiproceso: 7 pruebas aprobadas, incluida la carrera
  cancelación/continuación.
- Pint: 183 archivos aprobados.
- Larastan/PHPStan: 147 rutas analizadas sin errores.
- Vite Plus: 83 archivos formateados y 70 sin diagnósticos.
- TypeScript y ESLint: sin errores.
- Build de producción: 2318 módulos transformados.
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
