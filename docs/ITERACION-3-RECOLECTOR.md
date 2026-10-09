# Iteración 3 — Recolector real

## Base y alcance

Rama `codex/3-recolector-real`, creada desde `main` en
`05f858bde44c4162bbec4a8710171b4b42d285f0`, después del merge del
[PR #8](https://github.com/AndreyK-2305/MoodleWebToolkit/pull/8).
Contiene como ancestro `d330fcc2e5c3cd2d24d1836977f47a1380e5a518`.
La [CI de esa base](https://github.com/AndreyK-2305/MoodleWebToolkit/actions/runs/37813277514)
terminó en `success`. El alcance es exclusivamente COLLECT LAB.

## Contrato observado

Recolector `7.4.2-linux`: árbol de 29 archivos, hash canónico
`4daaa16d278991f098b7f9c85361f7800193c4825e1f8cb6cb2c4b2870103c2e`.
`FILES.sha256` declara 28 archivos. La distribución disponible es un árbol;
no se registra como ZIP original.

El wrapper acepta argumentos cerrados para paquete, configuración Moodle, workers,
salida, temporal y reutilización. Usa Bash y PHP CLI con DOM/ZipArchive.
El modo foreground usa `tee` sobre un log propio: la integración debe evitar
persistir salida cruda fuera de la captura sanitizada del supervisor.
`scripts/source-export.php` es no interactivo y admite `scope=lab`.

Estado agregado: schema 1.0, versión, source_id, state, stage, exit_code y contadores
de cursos. La web debe derivar progreso únicamente de unidades válidas.
Un estado exitoso no sustituye la evidencia terminal íntegra ni la validación del paquete.

Los checkpoints y `run-manifest.json` atan hashes y fingerprint al origen.
También se valida el hash de la ruta real de config.php: cambiar de workspace
cambia esa ruta. No se habilita reanudación entre workspaces sin una estrategia
validada. `--restart` descarta trabajo, no significa reanudar.

## Corte 1 — política durable de duración

Se elimina `min(86400, timeout)`. `wall_timeout_seconds=null` significa sin límite
artificial; cero, negativos, booleanos y fracciones se rechazan. Los comandos con
`timeout` conservan su plazo explícito sin recorte. La CPU predeterminada tampoco
impone 24 horas. Memoria, cantidad de procesos y tamaño de archivo siguen acotados.

La política separa arranque, heartbeat, inactividad, duración, gracia de cancelación
y recursos; se fija en command_sha256 y evidencia durable. Cambiarla impide
reutilizar una clave de idempotencia. Arranque y gracia aceptan hasta 30 segundos;
heartbeat hasta 60, para preservar las unidades Laravel de 120 segundos.

El supervisor mide plazos con reloj monotónico. Heartbeat no reinicia inactividad;
la salida observada sí. El comando real deberá fijar esa política considerando
las etapas largas sin salida del contrato real.

Validación del corte: 117 pruebas, 553 aserciones, PHP 8.4 Linux/PostgreSQL 17,
incluidas las cuatro pruebas multiproceso y la captura de salida fragmentada.
Pint y Larastan/PHPStan completos pasan. Reporte local: `quality-results/it3-cut1.xml`.
El runtime local usa Podman debido al fallo de arranque
del socket de inferencia de Docker Desktop; no acredita la puerta Docker de CI.

## Corte 2 — configuración y secretos

`collector-lab.v1` conserva revisiones inmutables con actor, fecha y SHA-256.
La configuración referencia un perfil administrado; la UI no recibe rutas ni
valores de credenciales. Workers (1–4), nombre lógico y estimación/margen de
capacidad son parámetros cerrados. Cambiar el perfil, su revisión de credencial
o los parámetros invalida preflight y confirmación. La comparación usa JSON
canónico para tolerar el orden de claves de PostgreSQL jsonb.

`SecretProvider` tiene implementaciones de testing y archivo LAB versionado.
LAB exige un archivo regular 0600, un único enlace y ámbito explícito;
el valor solo se entrega a callbacks del backend. La configuración Moodle se
materializa en `input` con 0600 y se elimina en `finally`, incluso si falla el
consumidor. SIGKILL no ejecuta finally: un residuo bloquea cualquier reutilización
hasta comprobar terminación y realizar limpieza conservadora. AWS no está habilitado.

Validación del corte: 35 pruebas y 411 aserciones, incluidos los casos del wizard
demostrativo; Pint y PHPStan completos pasan. Reporte: `quality-results/it3-cut2.xml`.

## Corte 3 — preflight y pantalla LAB

COLLECT selecciona entre el wizard demostrativo y el perfil LAB persistido.
La pantalla LAB acepta únicamente claves de perfiles administrados y parámetros
no sensibles; reutiliza la autorización de acción vigente y los endpoints de
confirmación. Los errores técnicos no se trasladan a las props de Inertia.

El preflight LAB comprueba catálogo habilitado, distribución/hash/manifiesto,
capabilities, runtime, rutas, referencia de credencial, identidad sintética,
conexión PostgreSQL, Moodle 4.5, cursos/usuarios/OAuth y capacidad con margen.
Persiste checks y fingerprint; un ERROR bloquea confirmación y un WARNING exige
aceptación auditada. No inicia recolección. La integración permanece bloqueada
hasta instalar CollectorAdapter y acreditar todos los demás checks.

Moodle 4.5 requiere el perfil PHP 8.3 para este laboratorio; PHP 8.4 del runtime
general se bloquea en ese preflight. Referencias oficiales:
[requisitos 4.5](https://moodledev.io/general/releases/4.5) y
[política PHP](https://moodledev.io/general/development/policies/php).

Validación: 49 pruebas PHP/538 aserciones; Pint, PHPStan, TypeScript aplicación/E2E,
ESLint y Vite Plus pasan después de instalar desde package-lock.json.
Reporte: `quality-results/it3-cut3.xml`.

## Corte 4 — propiedad del runtime

El host de destino se fija al crear RemoteOperation, forma parte del hash del
comando y conserva la protección inmutable de PostgreSQL. Un runner distinto
rechaza el lanzamiento antes de reclamarlo. `TOOL_RUNNER_HOST_ID` permite dirigir
los despachos al hostname estable del contenedor runner; null conserva el uso
del proceso local para las pruebas existentes.

El scheduler despacha reconciliación a `redis-tool-runs/tool-runs`, con unidades
de 120 segundos y exclusión de trabajos duplicados. La inspección de `/proc`
ocurre en el runner. El modo sync mantiene la inspección local de pruebas.
Las regresiones también detectaron una carrera benigna de mkdir; se conserva
la comprobación final del directorio sin convertir esa carrera en un warning fatal.

Validación: 36 pruebas, 184 aserciones (incluidas cuatro multiproceso), Pint y
PHPStan completos. Reporte: `quality-results/it3-cut4.xml`.

## Corte 5 — parser de señales

`CollectorEventParser` interpreta el contrato cerrado `collector-event.v1`.
Solo calcula porcentaje para `course-backups` con total/completados/fallos válidos;
las demás etapas o denominadores desconocidos conservan progress=null. Ignora
porcentajes y ETA suministrados por el proceso. Una señal de paquete validado
no declara éxito de Execution ni sustituye exit.json.

El parser valida operation_uuid y secuencia, descarta duplicados/eventos atrasados
y permite restaurar el cursor. Tolera fragmentación y UTF-8 inválido; una línea
truncada o demasiado grande produce un log genérico sin persistir su contenido.
Los mensajes desconocidos también se convierten en logs genéricos sanitizados.
El cursor y offsets de lectura se conectarán al observador durable del adaptador.

Validación: 7 pruebas, 29 aserciones, Pint y PHPStan completos.
Reporte: `quality-results/it3-cut5.xml`.

## Corte 6 — render no interactivo por ejecución

`CollectorRuntimeConfiguration` genera `state/collector-runtime.json` bajo el
schema `collector-runtime.v1`. Conserva identidad de proyecto/ejecución, revisión,
perfil no sensible, referencia opaca de credencial, hashes de distribución y
archivos, workers y scope=lab. Notificaciones y reutilización permanecen cerradas.

La aprobación usa ExecutionRuntimeConfiguration con actor/fecha y contenido
canónico fijado por SHA-256. La revisión aprobada no se sustituye; se revalida el
archivo regular 0600 antes de aplicarla. Un archivo residual sin aprobación se
bloquea y requiere reconciliación. No se materializa todavía el valor de la
credencial en ese render persistente.

Validación: 13 pruebas, 73 aserciones, incluyendo Unicode, alteración de contenido,
idempotencia, roles y secreto efímero. Pint/PHPStan completos pasan.
Reporte: `quality-results/it3-cut6.xml`.

## Corte 7 — runtime PHP aislado

Laravel conserva PHP 8.4: el lock aprobado contiene dependencias que requieren
esa versión. El target optativo `collector-lab` incorpora un CLI PHP 8.3 separado,
su ini y extensiones. El preflight ejecuta un probe cerrado de tres segundos,
comprueba Linux, wrappers y extensiones de Moodle; el PHP web no lo sustituye.
El bridge no podrá cargar las dependencias Laravel desde ese CLI.

Moodle sintético se fija a `v4.5.10`, commit
`b2c2f2a0c5f9a141896df6c322a0b6b29bb77646`. El objeto del tag anotado es
`3c37d509bd162f34b9eb870082d8c55e3b510c37`; el build compara HEAD con el commit,
no con el objeto del tag. Solo la preparación descarga dependencias públicas;
la ejecución LAB deberá operar sin Internet público.

El materializador de configuración es PHP independiente del framework, comparte
las restricciones de input, archivo privado y limpieza con el backend. El render
fija además la huella del ámbito de referencias y el preflight incluye la política
del runtime. El contexto Docker excluye todo storage del host y crea storage nuevo
antes del descubrimiento de paquetes.

Pruebas del corte: 22 pruebas y 104 aserciones; Pint/PHPStan completos pasan.
El target LAB compiló y su probe positivo comprobó PHP 8.3.35 y las doce
extensiones requeridas desde Laravel 8.4.
Reporte: `quality-results/it3-cut7.xml`. La regresión PHP ejecutó 490 pruebas:
solo fallaron tres por Redis/Reverb ausentes en el entorno manual. Después de
iniciar esos servicios, las tres pasan con 31 aserciones. La puerta completa final
todavía requiere una corrida única con todos los servicios.

## Trabajo restante

## Corte 8 — bridge y comando cerrado

El bridge PHP 8.3 vive fuera de BaseLine, sin autoload de Laravel. Comprueba
identidades, configuración aprobada, ámbito LAB y los 29 archivos de la copia
desplegada antes de materializar credenciales. Ejecuta source-export.php y el
auditor original con argv; descarta stdout/stderr crudos de sus hijos y expone
solo etapas permitidas y contadores enteros. Conserva checkpoints originales
como evidencia privada; no habilita reutilización entre workspaces.

`collector.742.lab` acepta únicamente project_uuid, execution_uuid y runtime_sha256.
Ambas flags son obligatorias incluso si otra definición intenta usar esa clave.
La política fija wall/CPU=null, heartbeat=10s, stall=7200s y recursos explícitos.
La cancelación actúa sobre el grupo aislado del proceso, no sobre el servidor Moodle.
El entrypoint aplica el scan dir del target después del gate del runtime web;
PHPRC se hereda por los hijos Moodle para mantener la misma ABI PHP 8.3.

Salida declarada: paquete, sidecar, manifiesto, inventario, inventario visual y
auditoría sanitizada. Una señal package_validated todavía requiere exit.json
íntegro y validación del dominio antes de registrar SourcePackage.

Validación: 25 pruebas/126 aserciones del bridge y configuración; 76 pruebas/
3745 aserciones del runner y captura sanitizada. Pint/PHPStan completos pasan.
La imagen quality compiló con instalación limpia y build de producción.
Reportes: `quality-results/it3-cut8.xml`, `quality-results/it3-cut8-runner.xml`.

## Trabajo restante de integración

## Corte 9 — Moodle sintético y exportación real

El overlay optativo `compose.collector-lab.yaml` prepara Moodle 4.5.10 en
volúmenes exclusivos: dos cursos, tres usuarios sintéticos más admin/guest,
dos páginas, dos carpetas con archivos pequeños, temas Boost/Classic y OAuth
sintético HTTPS. PostgreSQL usa tmpfs. La identidad del fixture se fija solo
después de terminar la semilla; una instalación incompleta no se considera lista.

El inicializador genera localmente la referencia privada versionada y una copia
separada para PostgreSQL, ambas 0600 y con el UID de su consumidor. No se
transportan valores mediante argv ni archivos del host. La configuración Moodle
se materializa durante la instalación y se retira en finally. El config.php público
del código sintético es un guard sin parámetros de conexión: satisface los requires
internos de plugins solo después de cargar la configuración CLI efímera.

El perfil se instaló desde volúmenes nuevos mediante Docker Compose conectado
al API compatible de Podman 5.8.2. Ambos inicializadores finalizaron con exit=0;
se observaron dos cursos, cinco cuentas, dos recursos por tipo y un issuer OAuth.
Docker Desktop continúa sin motor disponible; las puertas finales también deben
ejecutarse en el CI Docker de la rama.

La suite LAB explícita ejecuta PHP 8.3 y la copia verificada del Recolector,
no BaseLine. Pasó 1 prueba con 37 aserciones: ZIP auditado, dos MBZ, producer
7.4.2-linux, capability theme_inventory=1.0, SHA-256 y limpieza privada.
La regresión del corte pasó 25 pruebas/126 aserciones; Pint/PHPStan completos,
Compose config y build de imagen/producción pasan.
Reportes: `quality-results/it3-cut9-lab.xml`, `it3-cut9.xml`,
`it3-cut9-fixture.json`. La suite `tests/Laboratory` requiere flags y origen
sintético explícitos; la suite Feature ordinaria conserva Fake predeterminado.

## Trabajo restante de integración Web

## Corte 10 — auditoría del dominio y compatibilidad de paquetes

`CollectorPackageInspector` acredita archivo regular exclusivo, SHA-256 y tamaño,
estructura ZIP y metadata antes de ejecutar el auditor original desde otra copia
verificada. Rechaza traversal, entradas duplicadas/cifradas, enlaces, archivos
especiales, productor desconocido y capabilities incompatibles. Escanea la
referencia privada del perfil en bloques con solape; sus valores no aparecen en
mensajes de rechazo. El reporte crudo del auditor se limita al área privada
temporal, se descarta y se retira; el dominio conserva solo identidad, hashes,
versión, capabilities, contadores y número de advertencias.

El contrato acepta producer 7.4.1-linux sin cambiarlo a 7.4.2. Una ausencia legacy
de capabilities no se convierte en soporte inventado. La identidad obligatoria
incompleta se bloquea; un nombre ausente puede mostrarse mediante el source_id
verificado. 7.4.2 requiere theme_inventory=1.0. SourcePackage ahora bloquea
productores/schemas desconocidos, operaciones fallidas y schema 1.0 sin auditoría
vinculada a artefacto, manifiesto, proyecto y ejecución.

La prueba LAB pasó 1 prueba/58 aserciones. Incluye exportación real y auditoría
independiente, variante sintética legacy cambiando solamente metadata/hashes del
manifiesto y conservando los MBZ, backup/hash alterados, hardlink, traversal,
productor desconocido y secreto de pruebas fragmentado entre bloques. Esa variante
prueba compatibilidad de schema; no afirma haber ejecutado el binario 7.4.1.
La regresión del corte pasó 25 pruebas/106 aserciones y la extensión del caso de
capability null pasó 3 pruebas/18 aserciones. Pint/PHPStan completos pasan.
Reportes: `quality-results/it3-cut10.xml`, `it3-cut10-lab.xml`.

## Trabajo restante de integración Web

## Corte 11 — outbox después de commit

ExecutionEventRecorder inserta el evento y una fila única de outbox en la misma
transacción PostgreSQL. Solo después del commit intenta publicarlo; si el callback
se pierde, el scheduler recupera la fila. Si falla Reverb, guarda únicamente un
código cerrado y un próximo reintento con backoff. La ejecución y el evento siguen
disponibles para polling. Reverb tiene límites de conexión/respuesta breves,
independientes del proceso del Recolector.

Las entregas repetidas de una fila publicada no vuelven a emitirla. Una pérdida
del proceso después del envío y antes del commit de entrega puede repetir el
mismo evento: el contrato conserva execution_uuid/sequence para la deduplicación
del cliente. PostgreSQL sigue siendo la fuente de verdad. La migración no altera
eventos anteriores; rollback y reaplicación preservan eventos y ejecuciones.

Validación del outbox: 5 pruebas/38 aserciones de commit, rollback, falla de
transporte, callback perdido, reintento y upgrade/rollback. La regresión PHP
completa pasó 502 pruebas/6616 aserciones con PostgreSQL, Redis y Reverb activos;
Fake y las iteraciones anteriores permanecen verdes. Pint (334 archivos) y
PHPStan completos pasan.
Reportes: `quality-results/it3-cut11.xml`, `it3-cut11-regression.xml`.

## Trabajo restante de integración Web

Iteración abierta. CollectorAdapter,
observación durable, registro validado de SourcePackage y Playwright
COLLECT LAB permanecen pendientes. No se declara recolección real ni cierre.
Fake sigue predeterminado y las herramientas reales permanecen cerradas.

BaseLine verificada al comenzar: 423 archivos; SHA-256
`d2c80f1aa5157320ac7208f9506fcba5dcc7d4d8830fa872658df6e99486c221`.

## Corte 12 — observación durable y lectura incremental

El observador persiste en la misma transacción el cursor de stdout, los eventos,
el progreso nullable y el outbox. Lee lotes acotados y vuelve a leer una línea
parcial después de reiniciar. Nunca guarda fragmentos crudos. Deduplica las
secuencias del bridge; no interpreta sus señales como terminación de Execution.
El porcentaje procede únicamente de cursos completados y total conocidos.

Un prefijo SHA-256 y la identidad del archivo impiden continuar silenciosamente
tras truncamiento, sustitución o alteración. Enlaces y archivos especiales quedan
bloqueados. PostgreSQL impide retroceder el cursor, cambiar su identidad o
modificar/eliminar una revisión de configuración. Las advertencias de lectura
se emiten cuando cambia el estado, sin repetirlas en cada consulta.

Validación: 40 pruebas/224 aserciones de lector, observador, parser, configuración
y outbox; incluye rollback, reinicio, UTF-8 fragmentado, texto privado, duplicados,
truncamiento, lotes, líneas excesivas, aislamiento por Execution y rollback/reapply
de la migración. Pint y PHPStan pasan. Reporte: `quality-results/it3-cut12.xml`.
La conexión del observador con CollectorAdapter y cierre de paquetes sigue pendiente.

## Corte 13 — terminación verificable y captura idempotente

COLLECT requiere revalidar la evidencia durable y sus hashes en el hostname del
runner, además de comprobar que no quede un proceso activo en el grupo registrado.
La ausencia del PID y el estado TERMINATED de PostgreSQL por sí solos no autorizan
captura. Se valida nuevamente launch/exit y la identidad del productor.

Capturas repetidas devuelven el mismo Artifact si su archivo y el enlace del
almacenamiento conservan inode, tamaño, SHA-256 y metadata. Un enlace adicional,
una sustitución, un cambio de contenido o un archivo especial bloquean la captura.
El registro SourcePackage también es idempotente por artefacto y conserva el
producer original. La validación y binding bloquean contratos existentes
desconocidos; schema 1.0 exige auditoría y capabilities acreditadas. Índices únicos
protegen las identidades de captura de COLLECT y de sus paquetes auditados.

Validación: 53 pruebas/214 aserciones del runner, descriptores, almacenamiento y
SourcePackage. Incluye proceso registrado real, exit/log alterados, enlace ajeno,
FIFO, captura duplicada y registro legacy repetido. Pint y PHPStan pasan.
Reporte: `quality-results/it3-cut13.xml`. El flujo Web continúa pendiente.

## Corte 14 — ciclo durable del paquete real

La preparación de COLLECT fija cuota, revisión, distribución y runtime aprobado
antes del lanzamiento. El binding real solo admite 7.4.2 y referencias de su
configuración inmutable. La auditoría posterior utiliza ese perfil fijado, aunque
la configuración vigente del proyecto cambiara.

El ciclo separa la operación registrada del worker Laravel, observa el stdout
mediante el cursor durable y exige terminación íntegra antes de retirar cualquier
configuración privada residual. Guarda una auditoría independiente inmutable;
la captura puede recuperarse sin sustituirla. Manifiesto, inventario, evidencia
visual, sidecar y validación deben coincidir con el ZIP auditado. La captura y el
registro fuente se conservan al repetir la reconciliación. REVIEW se alcanza con
verificación aprobada, sin checkpoints simulados ni cierre automático.

La prueba explícita LAB pasó 1 prueba/29 aserciones: proceso registrado separado,
dos cursos, seis artefactos, SourcePackage VALID, hashes, inmutabilidad PostgreSQL,
reobservación/captura repetidas, configuración privada retirada y rechazo de ZIP
alterado. La regresión pasó 44 pruebas/164 aserciones; Pint y PHPStan pasan.
Reportes: `quality-results/it3-cut14.xml`, `it3-cut14-lab.xml`.

Este corte verifica el ciclo del dominio mediante el harness LAB. CollectorAdapter,
routing Web, controles, cierre, nuevo intento y Playwright todavía están pendientes.

## Corte 15 — routing, cancelación y recuperación del runner

La identidad inmutable del binding elige CollectorAdapter y LocalProvider aun si
cambia la configuración vigente. Nunca sustituye una ejecución real por Fake.
START y CANCEL se procesan en la cola del runner. Una falla del worker conserva
la operación registrada; la reconciliación redespacha únicamente un lanzamiento
que todavía no fue reclamado. Cerrar los flags antes del lanzamiento no reclama
ni crea un PID. Una identidad reclamada sin evidencia no se vuelve a ejecutar.

La cancelación antes del proceso y la cancelación del grupo real mantienen
evidencia verificable. La publicación exige también ausencia del supervisor
marcado con la identidad de la operación. Una falla de limpieza o lectura queda
pendiente con backoff y finalmente requiere intervención; no produce éxito.
La auditoría durable es obligatoria para registrar y revalidar el paquete real.

Validación secuencial: 64 pruebas/303 aserciones; LAB real 1 prueba/29 aserciones.
La regresión completa previa al último control de lanzamiento pasó 527
pruebas/6745 aserciones. Pint y PHPStan pasan. Reportes:
`quality-results/it3-cut15.xml`, `it3-cut15-lab.xml`, `it3-cut15-regression.xml`.
La finalización, el nuevo intento y la interfaz real siguen pendientes; la
confirmación HTTP permanece cerrada durante este corte.

## Corte 16 — cierre incremental del paquete real

CollectorAdapter inicia la unidad registrada y entrega su evento normalizado;
el provider persiste ese registro una sola vez. FINALIZE utiliza las etapas
incrementales existentes, revalidando en cada job la terminación, auditoría,
paquete y seis salidas declaradas. Un manifiesto alterado bloquea el cierre.
Los informes muestran SourcePackage, productor, capacidades y auditoría real;
no generan propuestas académicas simuladas. El cierre conserva diez artefactos
y mantiene progreso indeterminado cuando no existe una unidad global acreditada.
El nombre aprobado del paquete se aplica al parámetro real del productor.

Validación: 19 pruebas/138 aserciones, más LAB real 1 prueba/46 aserciones,
incluyendo cierre completo, manifiesto alterado y ausencia de secretos en
los cuatro informes. Pint y PHPStan pasan. Reportes:
`quality-results/it3-cut16.xml`, `it3-cut16-lab.xml`.

## Corte 17 — nuevo intento con linaje propio

El nuevo intento requiere el último COLLECT real fallido/cancelado, ninguna
operación sin terminación, autorización vigente, aceptación LAB, revisión actual
y preflight real nuevamente evaluado. Crea otra Execution, workspace, runtime,
binding y START. No copia backups, pasos ni checkpoints. PostgreSQL conserva
`retried_from_execution_id` inmutable y restringe proyecto, orden y productor;
el linaje de resume con checkpoint de Fake conserva su contrato anterior.
Rollback retira el guard pero conserva la columna/FK histórica; reapply restaura
la validación sin borrar linaje.

Validación: 20 pruebas/165 aserciones de routing y recuperación, incluyendo
autorización, flags, revisión, idempotencia y rollback/reapply. LAB real pasó
1 prueba/20 aserciones: cancelación del grupo registrado, terminación acreditada,
nueva operación, nueva secuencia desde 1, evidencia anterior conservada y paquete
del nuevo intento auditado. Pint y PHPStan pasan. Reportes:
`quality-results/it3-cut17.xml`, `it3-cut17-lab.xml`.

## Corte 18 — integración HTTP e interfaz real

La integración instalada admite confirmar e iniciar COLLECT LAB. Ambas flags
siguen cerradas por defecto y el catálogo requiere autorización explícita mediante
`collector:enable-laboratory`, tras comprobar Moodle sintético, PHP 8.3 y hashes.
El seguimiento distingue el paquete auditado de la revisión simulada: publica
productor, schema, hashes, cursos, metadata y descargas, con estado de reconciliación
y diálogo de nueva exportación. Las props no contienen perfiles físicos ni secretos.

El recorrido HTTP real pasó 1 prueba/63 aserciones: crear, configurar, preflight,
confirmar, inicio duplicado, proceso separado, polling, SourcePackage, rechazo de
alteración y cierre. La regresión específica pasó 34 pruebas/223 aserciones.
Pint/PHPStan, Vite Plus, ESLint, TypeScript aplicación/E2E, Vitest (5 pruebas) y
build pasan. Reportes: `quality-results/it3-cut18.xml`, `it3-cut18-lab.xml`.
La ejecución desde navegador mediante Playwright y las puertas finales permanecen
pendientes; este corte todavía no declara la iteración terminada.
