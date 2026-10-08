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

Iteración abierta. CollectorAdapter,
observación, validación de paquetes, SourcePackage, Moodle sintético y Playwright
COLLECT LAB permanecen pendientes. No se declara recolección real ni cierre.
Fake sigue predeterminado y las herramientas reales permanecen cerradas.

BaseLine verificada al comenzar: 423 archivos; SHA-256
`d2c80f1aa5157320ac7208f9506fcba5dcc7d4d8830fa872658df6e99486c221`.
