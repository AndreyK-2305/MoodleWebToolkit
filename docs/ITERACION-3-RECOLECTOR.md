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

## Estado actual

Iteración abierta. CollectorAdapter,
observación, validación de paquetes, SourcePackage, Moodle sintético y Playwright
COLLECT LAB permanecen pendientes. No se declara recolección real ni cierre.
Fake sigue predeterminado y las herramientas reales permanecen cerradas.

BaseLine verificada al comenzar: 423 archivos; SHA-256
`d2c80f1aa5157320ac7208f9506fcba5dcc7d4d8830fa872658df6e99486c221`.
