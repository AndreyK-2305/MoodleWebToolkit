# Iteración 3 — Recolector real en COLLECT LAB

## Base, alcance y estado

Rama `codex/3-recolector-real`, creada desde `main` en
`05f858bde44c4162bbec4a8710171b4b42d285f0`, después del merge del
[PR #8](https://github.com/AndreyK-2305/MoodleWebToolkit/pull/8).
Contiene el ancestro `d330fcc2e5c3cd2d24d1836977f47a1380e5a518` y la
[CI de la base](https://github.com/AndreyK-2305/MoodleWebToolkit/actions/runs/37813277514)
aprobó. Este informe sustituye las notas provisionales de implementación.

**Estado: implementada y entregada para revisión; aceptación de IT3 pendiente.**
El recorrido real de navegador y la recuperación durante reinicios están
acreditados en Moodle sintético. La última regresión general obtuvo 535 pruebas
PHP aprobadas y 38 pruebas de navegador aprobadas, cuatro fallidas y dos casos
LAB omitidos en esa puerta. A petición del usuario se detienen las baterías
locales completas y se entrega el código en un PR borrador con estos pendientes
visibles. No se declara IT3 aceptada ni CI verde sobre el SHA de entrega.

Las puertas locales se identifican por separado: un resultado con working tree
modificado es un candidato. La evidencia resumida y sus huellas se conservan en
[`evidence/it3-review-validation.json`](evidence/it3-review-validation.json).
El PR y el SHA de entrega se informan fuera de este commit para evitar una
referencia circular. CI se ejecuta al abrir el PR y conserva resultados del
checkout exacto; su resultado queda pendiente en esta entrega para revisión.

El alcance es exclusivamente 3A–3H. Fake sigue siendo el modo predeterminado.
`TOOL_RECOLECTOR_742_ENABLED` y `TOOL_LOCAL_RUNNER_ENABLED` siguen en `false` por
defecto; el catálogo conserva Recolector `LABORATORY`, Consolidador `BLOCKED` e
Integrador `INCOMPATIBLE`. No se implementó Iteración 4 ni se conectó Moodle
institucional o AWS.

## Distribución y contrato comprobado

| Identidad                    | Valor                                                              |
| ---------------------------- | ------------------------------------------------------------------ |
| Recolector                   | `7.4.2-linux`                                                      |
| Distribución                 | Árbol `BaseLine/Recolector/Recolector-v7.4.2`; 29 archivos         |
| Manifiesto                   | `FILES.sha256`, 28 entradas verificadas                            |
| SHA-256 canónico del árbol   | `4daaa16d278991f098b7f9c85361f7800193c4825e1f8cb6cb2c4b2870103c2e` |
| BaseLine completa            | 423 archivos                                                       |
| SHA-256 canónico de BaseLine | `d2c80f1aa5157320ac7208f9506fcba5dcc7d4d8830fa872658df6e99486c221` |

No existe un ZIP original de distribución: se conserva la identidad del árbol.
El contrato inspeccionado admite configuración Moodle, workers, paquete, salida,
temporal y opciones de reutilización. `scripts/source-export.php` permite ejecución
no interactiva con `scope=lab`; el bridge usa ese contrato desde la copia verificada.
El estado tiene schema `1.0`, productor, `source_id`, etapa, resultado y contadores.
El wrapper humano usa `tee`; su salida cruda no se entrega al navegador.

La secuencia aplicada es BaseLine read-only → verificación → copia al workspace →
segunda verificación → configuración aprobada → comando registrado → observación →
terminación durable → auditoría independiente → artefactos → SourcePackage.
Ningún script de herramienta se ejecuta directamente desde BaseLine.

## 3A–3B: configuración y secretos

`collector-lab.v1` conserva revisiones inmutables, actor, fecha y SHA-256. El perfil
administrado `synthetic-moodle` fija servidor/conexión local, código, moodledata,
base PostgreSQL, identidad sintética, serie Moodle y referencia de credencial.
La interfaz elige el perfil y configura workers, nombre lógico del paquete,
capacidad y margen; no recibe rutas físicas, comandos ni valores de credenciales.
URLs con credenciales y rutas fuera del ámbito autorizado se rechazan.

Guardar una revisión invalida preflight y confirmación. La confirmación fija la
revisión y su fingerprint. Antes del lanzamiento se conserva además
`collector-runtime.v1` aprobado y ligado a Execution, distribución, PHP 8.3,
perfil, cuota, actor y referencias versionadas. PostgreSQL impide sustituir las
revisiones, el binding, la aprobación y la auditoría por actualizaciones directas.

`SecretProvider` ofrece una implementación de testing y otra de archivo LAB.
LAB exige archivo regular, un enlace, permisos `0600` y referencia/versionado
opacos; los consumidores reciben el valor únicamente mediante un callback del
backend. La rotación utiliza una nueva versión de referencia. Los servicios de
aplicación montan las referencias de laboratorio como solo lectura.

La configuración Moodle privada se materializa en `input/moodle-runtime.php`,
con `0600`, y se elimina en `finally`. El JSON aprobado de `state` contiene
referencias, nunca el valor. Argv, hashes de comandos, eventos, logs, auditoría,
evidencia del runner, artefactos y props de Inertia no reciben la credencial.
SIGKILL no ejecuta finally: un residuo solo se retira después de acreditar que
no queda proceso o supervisor de la operación. AWS Secrets Manager no se habilita.

## Preflight real

El preflight comprueba catálogo y flags, versión, árbol/manifiesto, capabilities,
entrypoint, Linux/Bash, PHP 8.3 y extensiones, permisos y ámbitos, configuración y
referencia de credencial, identidad sintética, acceso a código/moodledata/base,
serie Moodle, cursos/usuarios/OAuth, workers, cuota con margen y espacio disponible.
No inicia una recolección.

Checks y fingerprint se persisten. Un ERROR bloquea; un WARNING requiere
aceptación auditada de todas las advertencias vigentes. Cambiar configuración,
perfil, versión de referencia, distribución o capacidad vuelve obsoleta la
confirmación. El lanzamiento revalida las entradas aprobadas.

## 3C: adaptador, proveedor y duración

Frontend → dominio → `CollectorAdapter` → proveedor local → supervisor → bridge →
Recolector. React opera con acciones y UUID; no construye comandos ni interpreta
el ZIP. La selección del proveedor procede del binding inmutable. Un binding real
incompleto nunca vuelve silenciosamente a Fake.

El único comando real registrado es `collector.742.lab`. Su argv es cerrado:
identidades UUID y SHA de runtime; el ejecutable, bridge y parámetros permitidos
proceden del backend. El host/namespace queda fijado antes de crear el proceso.
Los jobs reales se enrutan a `redis-tool-runs/tool-runs`.

| Política registrada   | Recolector LAB                                           |
| --------------------- | -------------------------------------------------------- |
| Arranque              | 10 segundos                                              |
| Heartbeat             | 10 segundos                                              |
| Inactividad           | 7200 segundos                                            |
| Tiempo de pared       | `null`, sin recorte artificial de 24 horas               |
| Gracia de cancelación | 10 segundos                                              |
| CPU acumulada         | `null`, sin `prlimit --cpu=86400`                        |
| Recursos              | 2 GiB por proceso, 128 procesos, 20 GiB por archivo      |
| Job Laravel           | Máximo 120 segundos; lanzamiento y observación separados |

La política forma parte de la identidad/hash del comando. Cero es inválido.
El supervisor utiliza reloj monotónico; heartbeat no simula actividad de la
herramienta. La ausencia de límite de pared conserva vigilancia de inactividad,
recursos, capacidad y cancelación. Las pruebas de más de 24 horas usan reloj
controlado; no se afirma haber exportado durante un día completo.

La cuota de aplicación se mide periódicamente y antes de escrituras administradas;
no constituye una cuota dura del filesystem para escrituras externas. Se mantiene
una reserva técnica de hasta 64 KiB, con 16 KiB por archivo de evidencia, para poder
acreditar terminación cuando una herramienta consume la cuota. Se detiene el grupo,
se descarta salida adicional y se firman solo bytes realmente persistidos. Un fallo
físico de escritura o sincronización conserva `UNKNOWN` y exige reconciliación.

## 3D: eventos y seguimiento

El bridge publica señales comprobables y mensajes cerrados. `CollectorEventParser`
y `CollectorLogReader` toleran fragmentación, UTF-8 inválido, desconocidos,
duplicados y reinicio de lectura. El cursor durable conserva offset, identidad del
archivo y hash del prefijo; truncación, rotación o alteración bloquean cierre y
conservan evidencia. No se persiste texto crudo pendiente de una línea.

Cursor, evento, unidades y progreso se confirman juntos en PostgreSQL. Después del
commit, la outbox publica a Reverb; el polling recupera eventos por secuencia.
Cada evento conserva Execution, RemoteOperation, secuencia, tipo, paso, severidad,
unidades, mensaje/payload sanitizados y fecha. No se usa tiempo transcurrido para
calcular porcentajes. El progreso global real permanece `null` cuando no hay una
unidad total acreditada, incluso al finalizar.

Nginx resuelve Reverb con el DNS del contenedor, también después de un cambio de IP.
Cerrar navegador, perder Reverb o vencer la sesión no detiene el supervisor.
Modificar/cancelar requiere autorización vigente o reconfirmación de contraseña
dentro de la misma pantalla.

## 3E–3F: compatibilidad y nuevos intentos

Las nuevas recolecciones usan únicamente productor 7.4.2. El inspector y el registro
aceptan paquetes existentes 7.4.1 con contratos reconocidos, mantienen el productor
original y los MBZ, y enriquecen metadata incompleta solo con información verificable.
Versiones desconocidas, hashes/manifiestos alterados y combinaciones incompatibles
de capabilities se bloquean. El fixture legacy valida el contrato 7.4.1; no acredita
haber ejecutado el binario histórico 7.4.1.

La distribución posee checkpoints propios, pero el fingerprint incorpora la ruta
real de configuración. Cambiar de workspace cambia esa entrada: esta integración
no habilita una reanudación entre workspaces sin un contrato seguro acreditado.
`resume=false`, `pause=false`, `fresh_retry=true`, `cancel=true`. No se crean
checkpoints ficticios ni se presenta `--restart` como reanudación.

Tras FAILED/CANCELLED, “Nueva exportación LAB” exige actor autorizado, revisión
vigente, aceptación explícita y preflight real. Crea otra Execution, workspace,
binding, runtime y RemoteOperation, con secuencias desde 1 y linaje inmutable
`retried_from_execution_id`. Conserva el intento anterior y su evidencia.
El contrato de reanudación con checkpoint de Fake sigue separado.

## Artefactos, revisión y SourcePackage

La captura exige `launch.json` y `exit.json` íntegros, hashes de logs, identidad de
PID/grupo/start time/host y ausencia tanto del grupo como del supervisor registrado.
Un PID ausente, exit 0 aislado o un estado TERMINATED en DB no acreditan cierre.

Solo se capturan seis salidas declaradas: ZIP fuente, checksum lateral, manifiesto,
inventario, inventario visual y validación. Se verifican ruta relativa, categoría,
nombre, MIME, tamaño, SHA, sensibilidad, obligatoriedad y propiedad. Se rechazan
traversal, enlaces inseguros, archivos especiales y archivos externos al workspace.
La captura repetida reutiliza la referencia propia verificada; no admite hardlinks
ajenos como artefactos nuevos.

La auditoría independiente comprueba ZIP/MBZ, manifiestos, inventarios, hashes,
productor/capabilities y ausencia de contenido privado. `CollectorPackageAudit`
persiste un snapshot inmutable ligado a operación y Execution. Solo entonces se
registra un SourcePackage `VALID` del mismo proyecto, reutilizable por otra Execution.
La reutilización revalida bytes y estado; no se implementa consumo real por V8.

La interfaz muestra el paquete auditado, productor, schema, hashes, cursos y descargas.
La revisión real no inventa un árbol académico. FINALIZE revalida evidencia, audit,
SourcePackage, seis artefactos y fingerprint; genera cuatro reportes reales en jobs
acotados, termina con diez artefactos y conserva progreso global indeterminado.

## 3G: Moodle sintético y prueba de navegador

El overlay explícito prepara Moodle 4.5.10 en el commit
`b2c2f2a0c5f9a141896df6c322a0b6b29bb77646`, PHP 8.3 aislado y PostgreSQL.
La muestra tiene dos cursos, tres usuarios nologin además de admin/guest, dos páginas,
dos carpetas con archivos pequeños, temas Boost/Classic, proveedor OAuth y vínculo
sintético. No contiene datos institucionales; la preparación descarta salida cruda
y solo conserva códigos de etapa. Dependencias públicas se obtienen al construir
imágenes; las pruebas de ejecución no dependen de Internet.

Los dos casos Playwright, con `retries=0`, acreditan crear/configurar, persistir y
recargar configuración, preflight/aceptación/confirmación, inicio, sesión independiente,
polling, revisión, SourcePackage, descarga con SHA y cierre tras reconfirmación.
El segundo consume capacidad, observa FAILED, crea otro intento con linaje y lo
cancela; repetir su Idempotency-Key no duplica el comando.

## 3H y evidencia de calidad

| Puerta                       | Evidencia actual                                                                           |
| ---------------------------- | ------------------------------------------------------------------------------------------ |
| PHP completo PostgreSQL      | 535 pruebas, 6813 aserciones, 0 fallos/errores/omisiones en el último corte local          |
| Multiproceso                 | Lanzamiento concurrente, reconciliadores, cuota concurrente y worker SIGKILL incluidos     |
| LAB real                     | 3 pruebas, 154 aserciones en el corte local de reinicios; sin fallos/omisiones             |
| Contratos del Recolector     | Cuatro contratos desde copia verificada de 29 archivos/PHP 8.3                             |
| Playwright COLLECT LAB       | 2/2, retries=0; salud final 12 servicios y teardown PASSED                                 |
| Playwright Fake completo     | Último corte: 38 aprobadas, 4 fallidas, 2 LAB omitidas; retries=0; aceptación pendiente    |
| Análisis/frontend            | Pint, PHPStan, Vite Plus, ESLint, TypeScript aplicación/E2E, Vitest 5 y build              |
| Migraciones                  | Fresh, upgrade con datos, rollback y reapply incluidos en PHP completo                     |
| BaseLine                     | 423 archivos/hash esperado; read-only en siete servicios persistentes y Playwright LAB     |
| Reinicios con COLLECT activo | Redis, queue-worker y Reverb reales; mismo grupo/operación, seis artefactos y Source VALID |
| Secretos                     | Inspección de HTTP, reportes, 47 tablas y 73 archivos; configuración privada eliminada     |
| Publicación                  | Entrega para revisión mediante PR borrador hacia main; CI del SHA de entrega pendiente     |

La cobertura incluye configuración/distribución alteradas, autorización vencida,
ADMIN/OPERATOR asignado/AUDITOR/outsider/inactivo/revocación, idempotencia, secreto
fragmentado, paquete/manifiesto alterados, capacidad insuficiente, truncación de log,
reconciliación y limpieza conservadora. Las pruebas físicas de escritura/sync/exit
siguen rechazando un resultado no comprobable.

La prueba de reinicios inicia una exportación con el worker real y conserva su
identidad registrada. Un arnés CLI exclusivo de testing aplica SIGSTOP/SIGCONT al
grupo verificado para mantener esta pequeña muestra activa durante los reinicios.
No cambia las capabilities ni habilita una acción de pausa para usuarios. Después
comprueba la misma identidad, una sola operación, secuencias continuas, terminación
íntegra, cero checkpoints, progreso null y SourcePackage VALID. Por separado, una
prueba multiproceso mata con SIGKILL al worker que efectivamente lanza el Recolector
y acredita que el grupo continúa independiente antes de cancelarlo con seguridad.

Las aserciones que examinan el secreto real usan resultados booleanos y mensajes
cerrados: incluso un resultado JUnit fallido no imprime el valor esperado. La
inspección posterior a reinicios recorre todas las tablas de la aplicación y los
archivos del workspace/artefactos; la auditoría del paquete examina también contenido
descomprimido. Los conteos de aserciones, archivos y eventos pueden variar con la
observación idempotente; el JSON/JUnit del SHA publicado contiene el conteo definitivo.

Los reportes locales están en `quality-results/` (ignorados por Git). El corte
`mt1g-it3-cut22-fake1` pasó PHP, frontend, integridad y read-only de BaseLine,
pero falló la puerta Playwright. Sus once servicios terminaron saludables y
el driver retiró sus contenedores, volumen y red. No emitió una validación global
aprobada. El laboratorio completo anterior pasó con 3 pruebas PHP/154 aserciones,
2 casos de navegador, cuatro contratos y prueba de reinicios; su metadata
registra working tree modificado y construcción nativa de Podman, no una
validación limpia del SHA de entrega. Una ejecución anterior de las 42 pruebas
generales de navegador pasó; no sustituye los cuatro fallos del último corte.

La evidencia cerrada de contratos se conserva en
[`evidence/it3-collector-contracts.json`](evidence/it3-collector-contracts.json),
y la recuperación/inspección de secretos en
[`evidence/it3-collector-resilience.json`](evidence/it3-collector-resilience.json).
Son pruebas locales anteriores, no artifacts de CI del SHA de entrega.

El driver
registra SHA, working tree dirty, métricas, salud y teardown. CI construye imágenes
limpias y conserva `iteration2-validation-<SHA>` para la regresión completa y
`iteration3-lab-validation-<SHA>` para el laboratorio, con JSON/JUnit ligados al
checkout exacto. Ambos deben indicar `quality_gates=PASSED`, árbol limpio,
`clean_images_built_in_driver=true` y `teardown=PASSED`. El LAB conserva además
`collector-contracts.json` y `collector-resilience.json`. La publicación usa commits
normales, push normal y PR borrador hacia main, sin force-push ni merge. Estos
son los requisitos del cierre definitivo, que permanece pendiente.

### Cuatro fallos abiertos de navegador

| Caso                                              | Fallo observado                                                            | Comprobación pendiente                                                                                 |
| ------------------------------------------------- | -------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| `execution.spec.ts:282`, INTERVENTION             | Timeout al llenar el correo en login; no llegó a probar la decisión        | Acceso y continuación idempotente de la misma ejecución                                                |
| `review.spec.ts:83`, doble clic                   | `spawnSync runuser ETIMEDOUT` en `snapshot()` después del clic             | Verificar que existe una sola ejecución; el fallo no acredita duplicación                              |
| `temporal-auth.spec.ts:15`, autorización caducada | La pantalla permaneció RUNNING al 35 % cuando esperaba WAITING_USER_ACTION | Acreditar llegada a intervención y luego probar caducidad/reintento; falló antes de caducar            |
| `temporal-auth.spec.ts:157`, más de 24 horas      | Tras intentar caducar la autorización, FINALIZE devolvió 202 en vez de 423 | Determinar si caducidad del arnés y sesión concurrente fallaron o si existe un defecto de autorización |

Las causas no están confirmadas. El cuarto caso requiere atención prioritaria:
la aceptación de FINALIZE después del intento de caducidad no puede descartarse
como un simple timeout. No se han quitado aserciones, aumentado timeouts,
habilitado reintentos ni ocultado casos para dar estas puertas por aprobadas.
El requisito de regresiones completas y CI verde sigue abierto. La revisión del
código puede comenzar ahora; esta entrega no autoriza merge ni despliegue.

## Commits y archivos principales

| Corte | Commit    | Resultado                                                  |
| ----- | --------- | ---------------------------------------------------------- |
| 1     | `ad9199e` | Duración y recursos independientes                         |
| 2     | `42bc8a7` | Configuración LAB versionada y secretos efímeros           |
| 3     | `d360469` | Preflight real y pantalla de configuración                 |
| 4     | `9bc9192` | Host/namespace fijado y reconciliación local               |
| 5     | `095fc1f` | Señales reales y progreso sin éxito sintético              |
| 6     | `86de30f` | Runtime inmutable aprobado                                 |
| 7     | `6554736` | PHP compatible aislado                                     |
| 8     | `eefae99` | Bridge y comando LAB cerrado                               |
| 9     | `fe45263` | Moodle sintético y exportación real                        |
| 10    | `bdc79ac` | Auditoría de paquetes y productor legacy                   |
| 11    | `59b224d` | Outbox transaccional de eventos                            |
| 12    | `035a15c` | Observación/cursor durables                                |
| 13    | `d1e4b36` | Terminación verificada y captura idempotente               |
| 14    | `e04bf7c` | Binding y workflow con auditoría                           |
| 15    | `4e0257b` | Routing real y recuperación de observadores                |
| 16    | `f579a39` | Finalización real en jobs acotados                         |
| 17    | `d9ed2c0` | Nueva exportación con linaje inmutable                     |
| 18    | `4add843` | HTTP/UI LAB y revisión de paquetes reales                  |
| 19    | `c624a91` | Navegador real, evidencia de cuota y puertas reproducibles |
| 20    | `259c55e` | Reinicios reales, worker SIGKILL e higiene de secretos     |
| 21    | `44c6194` | Arranque fallido acotado y relojes monotónicos             |
| 22    | `8b7c7a6` | Barrera multiproceso monotónica y diagnóstico cerrado      |

El corte final actualiza esta documentación. La lista íntegra, incluido ese corte,
se obtiene con `git log --reverse 05f858bde44c4162bbec4a8710171b4b42d285f0..HEAD`;
su identidad final se conserva fuera del commit en los artifacts y el informe de entrega.

| Archivo o grupo                                                                                                                                            | Responsabilidad                                                      |
| ---------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------- |
| `app/Domain/Collector/CollectorConfiguration.php`, `CollectorRuntimeConfiguration.php`                                                                     | Revisiones y aprobación del runtime                                  |
| `app/Domain/Collector/Contracts/SecretProvider.php`, `LabFileSecretProvider.php`, `MoodleConfigurationMaterializer.php`                                    | Referencias opacas y CFG efímero                                     |
| `app/Domain/Collector/CollectorPreflight.php`, `SyntheticMoodleProbe.php`                                                                                  | Checks reales, fingerprint y acceso sintético                        |
| `app/Domain/Tools/CollectorAdapter.php`, `app/Domain/Collector/CollectorExecutionProvider.php`                                                             | Adaptador y despacho por binding                                     |
| `app/Domain/Collector/CollectorRegisteredCommand.php`, `CollectorBridge.php`, `bin/collector-bridge.php`                                                   | Comando registrado y exportación desde copia verificada              |
| `app/Domain/Processes/RegisteredCommandRunner.php`, `RegisteredExecutionPolicy.php`                                                                        | Supervisor, duración, recursos y evidencia terminal                  |
| `app/Domain/Collector/CollectorLogReader.php`, `CollectorEventObserver.php`, `app/Domain/Realtime/ExecutionEventOutboxPublisher.php`                       | Cursor, transacción y publicación después del commit                 |
| `app/Domain/Collector/CollectorWorkflow.php`, `CollectorPackageInspector.php`, `app/Domain/Tools/SourcePackageRegistry.php`                                | Auditoría, captura, SourcePackage y revisión                         |
| `app/Domain/Collector/RetryCollectorExecution.php`                                                                                                         | Nuevo intento y linaje sin checkpoint ficticio                       |
| `database/migrations/2026_10_08_*.php`                                                                                                                     | Revisiones, cursor, identidad de captura, auditoría, outbox y linaje |
| `resources/js/pages/projects/collector.tsx`, `resources/js/components/collector-configuration-form.tsx`, `resources/js/pages/projects/executions/show.tsx` | Configuración, seguimiento, revisión y acciones autorizadas          |
| `compose.collector-lab.yaml`, `docker/collector-lab/`, `docker/php/Dockerfile`                                                                             | Fixture aislado y runtimes compatibles                               |
| `tests/Laboratory/`, `tests/E2E/collector-lab.spec.ts`, `tests/Support/collector-resilience.php`                                                           | Exportación real, navegador y fallos de infraestructura              |
| `tests/Infrastructure/run-quality.ps1`, `.github/workflows/tests.yml`                                                                                      | Puertas completas y artifacts del SHA exacto                         |

## Fallos encontrados y desviaciones

- El runtime inicial de aproximadamente 900 MiB terminaba procesos con SIGKILL;
  se amplió WSL a 4 GiB con autorización del usuario.
- Docker Desktop no proporcionó un socket operativo. La validación local usa
  Docker CLI/Compose contra la API de Podman Linux; las imágenes se construyen con
  Podman nativo por una incompatibilidad de cache-from del API. `-SkipBuild` lo
  registra explícitamente. La puerta Docker Engine corresponde a CI.
- El objeto de tag de Moodle no era el commit; la preparación fija y comprueba
  el commit despejado indicado arriba.
- Nginx retenía la IP anterior de Reverb después de un reinicio de Podman; se
  cambió a resolución dinámica con el DNS del contenedor.
- Consumir la cuota bloqueaba también exit evidence. Se agregó reserva técnica
  acotada y se conservó el rechazo ante errores físicos de escritura/sync.
- Un arranque sin identidad confirmada dejó al supervisor esperando un hijo que
  aún no había creado su grupo. La nueva prueba detecta esa espera en el código
  anterior y pasa con la corrección: se detiene únicamente el hijo propio antes
  de abrir la compuerta. Arranque y gracia de cancelación usan reloj monotónico.
  La causa del primer arranque no confirmado no se atribuye a esta corrección.
- Una interrupción del transporte local invalidó un candidato de navegador;
  no se contó como prueba aprobada. La repetición completa COLLECT LAB pasó.
- La barrera de una prueba multiproceso falló antes de enviar las solicitudes
  HTTP. Se alineó su presupuesto monotónico de preparación con los 30 segundos
  permitidos al proceso, se añadieron etapas cerradas y se detienen los hijos
  propios antes de desbloquear una preparación fallida. Los 14 casos dirigidos
  pasaron, y el corte PHP completo posterior pasó 535 pruebas/6813 aserciones.
  La causa de la lentitud inicial no quedó demostrada.
- El arnés detenía su coordinador al vencer una unidad y contaminaba los casos
  posteriores. Ahora devuelve un fallo explícito y conserva solo etapas cerradas,
  sin payloads. Una regresión completa anterior pasó, pero la última tiene los
  cuatro pendientes descritos arriba. La prueba de lanzamiento reconcilia la misma
  operación antes de exigir una identidad RUNNING, sin volver a lanzarla.
- Traces/videos E2E se deshabilitaron porque pueden retener cuerpos con contraseñas
  sintéticas. Se conservan JUnit y capturas de fallo.
- A petición del usuario se adelanta la revisión del código y se detienen las
  baterías locales completas. Esta modificación de la secuencia de entrega no
  convierte los fallos abiertos en pruebas aprobadas ni cierra la aceptación.

## Reproducción, rollback y límites

Con Docker Engine/Compose y PowerShell disponibles, desde el checkout:

```powershell
./tests/Infrastructure/run-quality.ps1 -ProjectName mt1g-it3-fake-check
./tests/Infrastructure/run-quality.ps1 -ProjectName mt1g-it3-lab-check -CollectorLab
```

Cada prefijo debe ser nuevo. El driver genera secretos efímeros, valida Compose,
construye imágenes, verifica salud/fresh/pruebas y retira exclusivamente sus
contenedores, volúmenes y red. No cambia `.env` ni elimina volúmenes de desarrollo.
Para habilitar el catálogo sintético dentro del overlay se ejecuta
`tools:catalog:sync` y después `collector:enable-laboratory`; ambos están incorporados
en la puerta LAB. No habilitar flags reales en un entorno institucional.

Antes de rollback, conservar DB y evidencia y comprobar que no queden operaciones
activas. Cerrar flags impide nuevos lanzamientos; no constituye cancelación de un
proceso existente. La columna/FK histórica de linaje se conserva al retirar su guard
para evitar pérdida de datos; reapply restaura la validación. No borrar workspaces
activos ni evidencia desconocida. Los recursos propios del laboratorio se retiran
por prefijo, sin prune global.

Esta entrega acredita un laboratorio pequeño. No acredita supervivencia al reinicio
completo del contenedor/host, exportaciones institucionales de muchos GiB o duración
real de 24 horas. Sin prueba terminal íntegra se requiere reconciliación manual.
No hay pausa ni checkpoint de reanudación real acreditado. Para Iteración 4 quedan
el adaptador V8, consumo real de SourcePackage, destino/plugins/temas/identidades e
intervenciones académicas; esos trabajos no se iniciaron aquí.
